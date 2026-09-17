<?php

namespace App\Services;

use App\Enums\AnomalyType;
use App\Enums\CorrectionAction;
use App\Enums\CorrectionStatus;
use App\Enums\TimeEntryStatus;
use App\Enums\TimeEntryType;
use App\Enums\UserRole;
use App\Models\Anomaly;
use App\Models\AttendanceCorrection;
use App\Models\Employee;
use App\Models\Setting;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Changing recorded time, without destroying the record.
 *
 * Two decisions shape everything here.
 *
 * 1. **The original is kept.** A correction snapshots the affected columns before it
 *    applies, so after the fact you can still say what the punch said. Overwriting would
 *    destroy the evidence needed to answer a pay dispute, which is the one outcome this
 *    whole system exists to prevent.
 *
 * 2. **Nothing is edited in place.** Every change goes through a correction row that
 *    names who asked, who approved, and why. A manager may change their own staff's
 *    hours — that was decided deliberately — which makes this record the only control
 *    there is, so it has to be complete rather than convenient.
 *
 * The entry's status moves to CORRECTED, which is what lets a report tell "what the
 * employee punched" apart from "what a manager later agreed it should have been".
 */
class CorrectionService
{
    public function __construct(private readonly WorkedHoursService $hours) {}

    /**
     * Raise a correction against an existing entry.
     *
     * Applies immediately when the requester is an owner, and otherwise waits for
     * review. The distinction is not about trust: an owner is the final authority, so
     * asking them to approve their own change adds a step and no control.
     *
     * @param  array<string, mixed>  $changes
     */
    public function request(
        TimeEntry $entry,
        array $changes,
        string $reason,
        User $requester,
    ): AttendanceCorrection {
        $this->assertChangesAreAllowed($changes);

        return DB::transaction(function () use ($entry, $changes, $reason, $requester) {
            $correction = AttendanceCorrection::create([
                'time_entry_id' => $entry->id,
                'employee_id' => $entry->employee_id,
                'outlet_id' => $entry->outlet_id,
                'action' => CorrectionAction::UPDATE,
                /*
                 * Snapshotted from the ENTRY as it stands right now — not from the
                 * original punch. A second correction therefore records the state it is
                 * changing, so following the chain leads back one step at a time rather
                 * than to the same starting point twice.
                 */
                'original_values' => $this->snapshot($entry),
                'changes' => $changes,
                'reason' => $reason,
                'status' => CorrectionStatus::PENDING,
                'requested_by' => $requester->id,
            ]);

            if ($this->appliesImmediately($requester)) {
                $this->approve($correction, $requester);
            }

            return $correction->fresh();
        });
    }

    /**
     * Invent a segment for a day nobody punched.
     *
     * Needed as well as an update: a shift with no punch at all has no row to amend, and
     * "a missed punch can be corrected" is not satisfied by only handling the cases that
     * happen to have left a trace.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function requestMissing(
        Employee $employee,
        int $outletId,
        array $attributes,
        string $reason,
        User $requester,
    ): AttendanceCorrection {
        $this->assertChangesAreAllowed($attributes);

        if (blank($attributes['started_at'] ?? null) || blank($attributes['ended_at'] ?? null)) {
            // A segment with no end is an open segment, and creating one by hand would
            // collide with the one-open-segment invariant for no good reason.
            throw new InvalidArgumentException('A missing punch needs both a start and an end.');
        }

        return DB::transaction(function () use ($employee, $outletId, $attributes, $reason, $requester) {
            $correction = AttendanceCorrection::create([
                'time_entry_id' => null,
                'employee_id' => $employee->id,
                'outlet_id' => $outletId,
                'action' => CorrectionAction::CREATE,
                'original_values' => null,
                'changes' => $attributes,
                'reason' => $reason,
                'status' => CorrectionStatus::PENDING,
                'requested_by' => $requester->id,
            ]);

            if ($this->appliesImmediately($requester)) {
                $this->approve($correction, $requester);
            }

            return $correction->fresh();
        });
    }

    /**
     * Apply a correction.
     *
     * Idempotent per correction: approving one that is already applied does nothing,
     * because a double-click on an approval button must not apply the change twice.
     */
    public function approve(AttendanceCorrection $correction, User $reviewer, ?string $note = null): AttendanceCorrection
    {
        if (! $correction->isPending()) {
            return $correction;
        }

        return DB::transaction(function () use ($correction, $reviewer, $note) {
            // Locked and re-read: two managers approving at once would otherwise both
            // see PENDING and both apply the change.
            $correction = AttendanceCorrection::query()
                ->lockForUpdate()
                ->findOrFail($correction->id);

            if (! $correction->isPending()) {
                return $correction;
            }

            $entry = $correction->action === CorrectionAction::CREATE
                ? $this->createEntry($correction)
                : $this->updateEntry($correction);

            $correction->forceFill([
                'time_entry_id' => $entry->id,
                'status' => CorrectionStatus::APPROVED,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'review_note' => $note,
            ])->save();

            /*
             * A manager amending their own outlet's hours is exactly the action that has
             * no second signature, so it is surfaced to the owner rather than left in a
             * log only the manager would read.
             *
             * Keyed on the REQUESTER, not the reviewer. With approval switched on, an
             * owner approves every manager request — so keying on the reviewer would mean
             * this flag never fired at all in the default configuration, which is the one
             * case it exists for.
             */
            if ($correction->requester?->role === UserRole::MANAGER) {
                Anomaly::raise(
                    $entry,
                    AnomalyType::MANAGER_CORRECTION,
                    'Corrected by '.$correction->requester->name.': '.$correction->reason,
                );
            }

            return $correction->fresh();
        });
    }

    public function reject(AttendanceCorrection $correction, User $reviewer, ?string $note = null): AttendanceCorrection
    {
        if (! $correction->isPending()) {
            return $correction;
        }

        $correction->forceFill([
            'status' => CorrectionStatus::REJECTED,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
            'review_note' => $note,
        ])->save();

        // The entry is deliberately untouched, so a rejection restores nothing and
        // breaks nothing.
        return $correction->fresh();
    }

    // ---- Internals ---------------------------------------------------

    /**
     * An owner's own change needs no second signature.
     *
     * A manager's waits, unless the owner has switched approval off — which is a
     * setting rather than a code change, so an owner can tighten this later without a
     * deploy.
     */
    private function appliesImmediately(User $requester): bool
    {
        if ($requester->role === UserRole::OWNER) {
            return true;
        }

        return ! Setting::bool(Setting::REQUIRE_CORRECTION_APPROVAL, true);
    }

    /**
     * Refuse a change to anything not explicitly correctable.
     *
     * An allow-list rather than a blocklist. `changes` arrives from a request, and a
     * blocklist would make every column added later writable by default — including
     * `employee_id` and `outlet_id`, which would move a punch to another person or
     * another outlet and quietly defeat outlet scoping.
     *
     * @param  array<string, mixed>  $changes
     */
    private function assertChangesAreAllowed(array $changes): void
    {
        $allowed = AttendanceCorrection::correctableFields();

        foreach (array_keys($changes) as $field) {
            if (! in_array($field, $allowed, true)) {
                throw new InvalidArgumentException("Field '{$field}' cannot be corrected.");
            }
        }

        if ($changes === []) {
            throw new InvalidArgumentException('A correction must change something.');
        }
    }

    /**
     * The affected columns as they stand, for the audit record.
     *
     * @return array<string, mixed>
     */
    private function snapshot(TimeEntry $entry): array
    {
        $snapshot = [];

        foreach (AttendanceCorrection::correctableFields() as $field) {
            $snapshot[$field] = match ($field) {
                'started_at', 'ended_at' => $entry->{$field}?->toIso8601String(),
                'type' => $entry->type->value,
                'status' => $entry->status->value,
                default => $entry->{$field},
            };
        }

        return $snapshot;
    }

    /**
     * Apply an update to an existing segment.
     */
    private function updateEntry(AttendanceCorrection $correction): TimeEntry
    {
        $entry = $correction->timeEntry;

        if ($entry === null) {
            throw new InvalidArgumentException('The entry this correction refers to no longer exists.');
        }

        $attributes = $this->normalise($correction->changes);

        /*
         * Timestamps are re-derived here rather than trusted from the request: the
         * business date decides which day the hours land on, and a correction that moved
         * the clock-in across midnight without moving the date would put the hours on the
         * wrong day with no visible sign.
         */
        if (isset($attributes['started_at'])) {
            $outlet = $entry->outlet ?? $correction->outlet;
            $startedAt = CarbonImmutable::parse($attributes['started_at']);

            $entry->started_at = $startedAt;
            $entry->business_date = $startedAt->setTimezone($outlet->timezone)->toDateString();
        }

        if (array_key_exists('ended_at', $attributes)) {
            $entry->ended_at = $attributes['ended_at'] === null
                ? null
                : CarbonImmutable::parse($attributes['ended_at']);
        }

        if (isset($attributes['type'])) {
            $entry->type = TimeEntryType::from($attributes['type']);
        }

        if (array_key_exists('note', $attributes)) {
            $entry->note = $attributes['note'];
        }

        /*
         * The resulting segment has to make sense.
         *
         * A end before the start is the obvious way a correction can go wrong — a manager
         * mistyping the date, or picking yesterday from the date picker. Without this the
         * duration is negative, which either lands in the database as a nonsense figure or
         * (MySQL, on an UNSIGNED column) fails with a 500 that tells the manager nothing.
         * Checked after applying so it catches combinations, not just the individual fields.
         */
        if ($entry->ended_at !== null && $entry->started_at !== null
            && $entry->ended_at->lessThanOrEqualTo($entry->started_at)) {
            throw new InvalidArgumentException(
                'The corrected times end before they start. Check the dates.'
            );
        }

        $entry->status = TimeEntryStatus::CORRECTED;

        // Recomputed from the timestamps so the cached column cannot disagree with them.
        if ($entry->ended_at !== null && $entry->started_at !== null) {
            $entry->duration_seconds = (int) $entry->started_at->diffInSeconds($entry->ended_at);
        }

        $entry->save();

        return $entry;
    }

    /**
     * Create the segment a missing punch should have produced.
     */
    private function createEntry(AttendanceCorrection $correction): TimeEntry
    {
        $attributes = $this->normalise($correction->changes);

        $outlet = $correction->outlet;
        $startedAt = CarbonImmutable::parse($attributes['started_at']);
        $endedAt = CarbonImmutable::parse($attributes['ended_at']);

        if ($endedAt->lessThanOrEqualTo($startedAt)) {
            throw new InvalidArgumentException('A segment must end after it starts.');
        }

        return TimeEntry::create([
            /*
             * Generated here rather than requested: this row was never produced by a
             * phone, so there is no client identity to preserve. The column exists so an
             * offline queue can populate it later.
             */
            'client_uuid' => (string) Str::uuid(),
            'employee_id' => $correction->employee_id,
            'outlet_id' => $correction->outlet_id,
            'type' => TimeEntryType::from($attributes['type'] ?? TimeEntryType::WORK->value),
            'started_at' => $startedAt,
            'ended_at' => $endedAt,
            'duration_seconds' => (int) $startedAt->diffInSeconds($endedAt),
            'business_date' => $startedAt->setTimezone($outlet->timezone)->toDateString(),
            /*
             * CORRECTED, not CLOSED. This segment did not come from a punch, and a report
             * has to be able to say so — otherwise an invented shift is indistinguishable
             * from one the employee actually recorded.
             */
            'status' => TimeEntryStatus::CORRECTED,
            'note' => $attributes['note'] ?? 'Added by correction: '.$correction->reason,
        ]);
    }

    /**
     * Coerce the stored change values into something writable.
     *
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function normalise(array $changes): array
    {
        return array_intersect_key($changes, array_flip(AttendanceCorrection::correctableFields()));
    }
}
