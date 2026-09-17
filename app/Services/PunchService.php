<?php

namespace App\Services;

use App\Enums\AnomalyType;
use App\Enums\TimeEntryStatus;
use App\Enums\TimeEntryType;
use App\Models\Anomaly;
use App\Models\Employee;
use App\Models\Outlet;
use App\Models\PunchSession;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\TimeEntry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The clock-in flow.
 *
 * The employee's phone drives this: scan the outlet code, enter a PIN, take a photo,
 * then press one of the actions available in their current state.
 *
 * TWO RULES GOVERN EVERYTHING HERE
 *
 * 1. The CLIENT never supplies identity. The punch session names the employee, and
 *    that session was issued only after a PIN check. Accepting an employee id from the
 *    request would let anyone clock anyone in.
 *
 * 2. At most ONE segment is open per employee, enforced by a database constraint
 *    rather than a check here — see the note on `openSegment` below.
 */
class PunchService
{
    public function __construct(private readonly PhotoService $photos) {}

    // ---- Entry: scan a code, then a PIN ------------------------------

    /**
     * Issue a punch session for an employee at an outlet.
     *
     * Returns null for every failure, deliberately: an unknown code, a wrong PIN, a
     * locked PIN and an employee not mapped to the outlet all give the same answer, so
     * a probing client cannot learn which codes exist or which PINs are close.
     */
    public function startSession(
        string $plainToken,
        string $pin,
        ?string $device = null,
        ?string $ip = null,
    ): ?array {
        $token = app(OutletTokenService::class)->findValid($plainToken);

        if ($token === null || ! $token->outlet->is_active) {
            return null;
        }

        $outlet = $token->outlet;

        /*
         * The PIN identifies the employee, but only among those mapped to THIS outlet.
         * Without that restriction a valid PIN would let someone clock in at a site
         * they do not work at, and the outlet mapping would mean nothing.
         *
         * PINs are hashed, so the candidate is found by checking each — there is no way
         * to look one up directly. The list is small (one outlet's staff) and this runs
         * once per punch, so the cost is irrelevant.
         */
        $candidates = Employee::query()
            ->active()
            ->forOutlet($outlet->id)
            ->whereNotNull('pin_hash')
            ->get();

        $employee = $candidates->first(
            fn (Employee $candidate) => ! $candidate->isPinLocked() && $candidate->verifyPin($pin)
        );

        if ($employee === null) {
            /*
             * A failed attempt is counted HERE rather than by the caller.
             *
             * This was previously a separate method the controller never invoked, so the
             * lockout never engaged and a 4-digit PIN could be brute-forced in seconds.
             * Counting inside the failure path makes it impossible for a new caller to
             * forget it.
             *
             * Applied to every candidate at the outlet because the PIN is not tied to an
             * employee until it matches — there is no way to know which account was
             * being guessed.
             */
            $this->recordFailedAttempt($candidates);

            return null;
        }

        $employee->clearPinFailures();

        $plain = PunchSession::newToken();

        $session = PunchSession::create([
            'employee_id' => $employee->id,
            'outlet_id' => $outlet->id,
            'outlet_token_id' => $token->id,
            'token_hash' => PunchSession::hashToken($plain),
            'expires_at' => now()->addMinutes(Setting::int(Setting::PUNCH_SESSION_MINUTES, 10)),
            'device' => $device,
            'ip_address' => $ip,
        ]);

        // The plaintext is returned to the caller and NEVER stored, so this is the only
        // moment it exists.
        return ['session' => $session, 'token' => $plain];
    }

    /**
     * Count a failed PIN attempt against the candidates at an outlet.
     *
     * Without this a 4-digit PIN falls in seconds to a script. The lockout is
     * time-based rather than permanent so a genuine fat-finger does not need a manager
     * to intervene.
     *
     * @param  Collection<int, Employee>  $candidates
     */
    public function recordFailedAttempt(Collection $candidates): void
    {
        $max = Setting::int(Setting::PIN_MAX_ATTEMPTS, 5);
        $lockout = Setting::int(Setting::PIN_LOCKOUT_MINUTES, 15);

        $candidates->each(fn (Employee $employee) => $employee->recordPinFailure($max, $lockout));
    }

    public function resolveSession(string $plainToken): ?PunchSession
    {
        $session = PunchSession::query()
            ->with(['employee', 'outlet'])
            ->where('token_hash', PunchSession::hashToken($plainToken))
            ->first();

        if ($session === null || $session->isExpired()) {
            return null;
        }

        if ($session->employee === null || ! $session->employee->is_active) {
            return null;
        }

        // Touched on use so an abandoned session is visible afterwards.
        $session->forceFill(['last_used_at' => now()])->save();

        return $session;
    }

    // ---- The three actions ------------------------------------------

    /** Open a WORK segment. */
    public function clockIn(PunchSession $session, ?string $photoBytes = null, ?string $extension = 'jpg'): TimeEntry
    {
        return DB::transaction(function () use ($session, $photoBytes, $extension) {
            $employee = $session->employee;

            $open = $this->openSegment($employee);

            if ($open !== null) {
                /*
                 * Already clocked in. Return what exists rather than creating a second
                 * segment — the database would refuse anyway, and a 500 is a poor answer
                 * to a double tap.
                 *
                 * But RUN THE CHECKS, because an open segment at a DIFFERENT outlet is a
                 * real signal: it means the same person clocked in somewhere else, which
                 * is exactly the buddy-punching pattern worth surfacing. Returning early
                 * without checking would hide the one case that matters most.
                 */
                if ($open->outlet_id !== $session->outlet_id) {
                    $this->flagDoubleOutlet($open, $session->outlet);
                }

                return $open;
            }

            return $this->createSegment($session, TimeEntryType::WORK, $photoBytes, $extension);
        });
    }

    /**
     * Start a break: close the work segment, open a break segment.
     *
     * Breaks are their own segments rather than a column, so worked time is a plain sum
     * with nothing to subtract — and a one-hour lunch can never become an hour of
     * overtime.
     */
    public function startBreak(PunchSession $session, ?string $photoBytes = null, ?string $extension = 'jpg'): ?TimeEntry
    {
        return DB::transaction(function () use ($session, $photoBytes, $extension) {
            $employee = $session->employee;

            $this->closeOpenSegment($employee, $session);

            return $this->createSegment($session, TimeEntryType::BREAK, $photoBytes, $extension);
        });
    }

    /** End a break: close the break segment, open a work segment again. */
    public function endBreak(PunchSession $session, ?string $photoBytes = null, ?string $extension = 'jpg'): ?TimeEntry
    {
        return DB::transaction(function () use ($session, $photoBytes, $extension) {
            $employee = $session->employee;

            $this->closeOpenSegment($employee, $session);

            return $this->createSegment($session, TimeEntryType::WORK, $photoBytes, $extension);
        });
    }

    /**
     * Clock out: close whatever is open and leave nothing open.
     *
     * From a break this closes the break, which is correct — the employee stopped
     * working when the break started.
     */
    public function clockOut(PunchSession $session, ?string $photoBytes = null, ?string $extension = 'jpg'): ?TimeEntry
    {
        return DB::transaction(function () use ($session, $photoBytes, $extension) {
            $employee = $session->employee;

            $open = $this->openSegment($employee);

            if ($open === null) {
                return null;
            }

            $photoPath = $photoBytes !== null
                ? $this->photos->storeBytes($photoBytes, 'punches', $extension)
                : null;

            $open->close(now(), $photoPath, $session->outlet_token_id);

            $this->flagOnClose($open);

            return $open;
        });
    }

    // ---- Internals ---------------------------------------------------

    public function openSegment(Employee $employee): ?TimeEntry
    {
        return TimeEntry::query()
            ->where('employee_id', $employee->id)
            ->open()
            ->first();
    }

    private function closeOpenSegment(Employee $employee, PunchSession $session): void
    {
        $open = $this->openSegment($employee);

        if ($open === null) {
            return;
        }

        $open->close(now(), null, $session->outlet_token_id);

        $this->flagOnClose($open);
    }

    private function createSegment(
        PunchSession $session,
        TimeEntryType $type,
        ?string $photoBytes,
        string $extension,
    ): TimeEntry {
        $employee = $session->employee;
        $outlet = $session->outlet;

        $photoPath = $photoBytes !== null
            ? $this->photos->storeBytes($photoBytes, 'punches', $extension)
            : null;

        $startedAt = now();

        $entry = TimeEntry::create([
            /*
             * Generated server-side rather than on the phone: this app has no offline
             * queue yet, so a client-supplied id would be a value the client controls
             * with no benefit. The column exists now so an offline queue can populate
             * it later without a migration.
             */
            'client_uuid' => (string) Str::uuid(),
            'employee_id' => $employee->id,
            'outlet_id' => $outlet->id,
            'shift_id' => $this->matchingShiftId($employee, $outlet, $startedAt),
            'type' => $type,
            'started_at' => $startedAt,
            /*
             * The business day comes from when the segment STARTED, not when it ended.
             * A shift running past midnight belongs to the day it began, or overtime
             * thresholds misfire across the boundary.
             */
            'business_date' => $startedAt->copy()->setTimezone($outlet->timezone)->toDateString(),
            'status' => TimeEntryStatus::OPEN,
            'started_photo_path' => $photoPath,
            'started_token_id' => $session->outlet_token_id,
            'started_device' => $session->device,
        ]);

        $this->flagOnOpen($entry);

        return $entry;
    }

    /** The active shift covering this moment, if any. NULL means adhoc work. */
    private function matchingShiftId(Employee $employee, Outlet $outlet, \DateTimeInterface $at): ?int
    {
        return Shift::query()
            ->active()
            ->where('employee_id', $employee->id)
            ->where('outlet_id', $outlet->id)
            ->where('starts_at', '<=', $at)
            ->where('ends_at', '>=', $at)
            ->value('id');
    }

    // ---- Anomaly checks --------------------------------------------

    private function flagOnOpen(TimeEntry $entry): void
    {
        $employee = $entry->employee;
        $outlet = $entry->outlet;

        /*
         * A punch far outside a scheduled shift is the signature of someone using a
         * photographed code from home. Only flagged, never refused: adhoc work is real
         * in this business, and refusing it would block a genuine early start.
         */
        if ($entry->shift_id === null) {
            $nearby = Shift::query()
                ->active()
                ->where('employee_id', $employee->id)
                ->where('starts_at', '>=', $entry->started_at->copy()->subHours(2))
                ->where('starts_at', '<=', $entry->started_at->copy()->addHours(2))
                ->exists();

            if (! $nearby) {
                Anomaly::raise(
                    $entry,
                    AnomalyType::OUTSIDE_SHIFT,
                    'Clocked in with no scheduled shift within 2 hours.',
                );
            }
        }

        // The same person punching at a different outlet the same day.
        if ($this->punchedElsewhereToday($employee, $outlet, $entry->business_date)) {
            $this->flagDoubleOutlet($entry, $outlet);
        }

        if ($outlet->requires_photo && blank($entry->started_photo_path)) {
            Anomaly::raise(
                $entry,
                AnomalyType::NO_PHOTO,
                'No photo captured on clock-in, but this outlet requires one.',
            );
        }
    }

    /**
     * Has this employee punched at a DIFFERENT outlet on the same business day?
     *
     * Working across sites in one day is legitimate for staff who cover outlets, so
     * this is a flag rather than a refusal — but doing so is also what clocking in for
     * someone else looks like, so it must be visible.
     */
    private function punchedElsewhereToday(Employee $employee, Outlet $outlet, mixed $businessDate): bool
    {
        return TimeEntry::query()
            ->where('employee_id', $employee->id)
            /*
             * whereDate rather than where: `business_date` is date-cast, and binding the
             * Carbon as a datetime only matches on MySQL, where the column truncates it.
             * See TimeEntry::scopeForBusinessDate.
             */
            ->whereDate('business_date', $businessDate)
            ->where('outlet_id', '!=', $outlet->id)
            ->exists();
    }

    private function flagDoubleOutlet(TimeEntry $entry, Outlet $attempted): void
    {
        // Avoid piling up duplicates when someone taps twice at the second outlet.
        $alreadyFlagged = Anomaly::query()
            ->where('time_entry_id', $entry->id)
            ->where('type', AnomalyType::DOUBLE_OUTLET->value)
            ->exists();

        if ($alreadyFlagged) {
            return;
        }

        Anomaly::raise(
            $entry,
            AnomalyType::DOUBLE_OUTLET,
            'Also clocked in at '.$attempted->name.' on the same day.',
        );
    }

    private function flagOnClose(TimeEntry $entry): void
    {
        $hours = Setting::int(Setting::MISSING_CLOCKOUT_HOURS, 16);

        if ($entry->durationSeconds() > $hours * 3600) {
            Anomaly::raise(
                $entry,
                AnomalyType::LONG_SPAN,
                'Segment ran longer than '.$hours.' hours — check for a missed punch.',
            );
        }
    }
}
