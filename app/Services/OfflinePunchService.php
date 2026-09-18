<?php

namespace App\Services;

use App\Enums\AnomalyType;
use App\Models\Anomaly;
use App\Models\PunchEvent;
use App\Models\Setting;
use App\Models\TimeEntry;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Offline punches: idempotency, and a bounded trust in the client's clock.
 *
 * WHY THIS EXISTS
 *
 * A kitchen has no signal. Someone clocks out at 15:00, the phone cannot reach the server,
 * and the only alternatives without this are: lose the punch, or have them stand there
 * retrying. The queue lets the phone record the moment it happened and send it later.
 *
 * TWO PROBLEMS, AND ONLY ONE OF THEM IS OBVIOUS
 *
 * 1. RETRIES MUST NOT DUPLICATE.
 *
 *    A queued punch is retried until it succeeds, and the phone cannot tell the difference
 *    between "the request never arrived" and "the reply never arrived" — so the retry may well
 *    be a punch the server already recorded. `client_uuid` is what makes that safe: the first
 *    one is kept and every later attempt with the same id returns it instead of adding a
 *    second segment. Without this, one bad connection produces two clock-outs and a duplicate
 *    work segment that inflates someone's hours.
 *
 * 2. A CLIENT-REPORTED TIME IS THE ONE THING THE CLIENT CONTROLS.
 *
 *    The time has to come from the phone, or a 7am punch synced at 2pm is recorded at 2pm and
 *    the whole feature is pointless. But it is also the only field a crafted request can lie
 *    about, and the consequence of believing it is money.
 *
 *    Everywhere else in this app the client never supplies identity — the session does. Here
 *    the client supplies a TIME, and the design response is not to refuse it but to BOUND it:
 *
 *      - it may not be in the future, beyond a small skew allowance for a phone whose clock
 *        runs fast;
 *      - it may not be older than the backdating window, so a queue cannot be used to rewrite
 *        a pay period that has already been approved;
 *      - every accepted offline punch raises an OFFLINE_SYNC anomaly, so a client-reported time
 *        is never indistinguishable from one the server witnessed.
 *
 *    A punch outside those bounds is REFUSED rather than clamped. Clamping would silently
 *    record a different time from the one claimed, which is worse than refusing: the employee
 *    would have no idea their punch had been altered, and the manager would have nothing to
 *    review. A refusal is visible, and the correction path exists for exactly this.
 */
class OfflinePunchService
{
    /** Tolerance for a phone clock that runs slightly fast. */
    public const DEFAULT_FUTURE_SKEW_MINUTES = 15;

    /** How far back a queued punch may claim to have happened. */
    public const DEFAULT_MAX_BACKDATE_HOURS = 24;

    /**
     * Find an action already applied for this client id.
     *
     * Keyed on the TRAIL, not on the segment table, and that distinction is a bug that shipped
     * in the first version: `time_entries.client_uuid` only covers actions that CREATE a
     * segment, so a clock-out — which closes one — stored the client's id nowhere. A retried
     * clock-out then matched nothing, closed the segment again, and was refused as out-of-order.
     * The employee was left on the clock with no way to fix it at the screen.
     *
     * The trail records every action, so it is the only table that can answer "have I already
     * applied this?" for closures as well as creations.
     */
    public function findAppliedEvent(?string $clientUuid, int $employeeId): ?PunchEvent
    {
        if (blank($clientUuid)) {
            return null;
        }

        return PunchEvent::query()
            ->where('client_uuid', $clientUuid)
            // Scoped to the employee, so one person cannot replay another's id and read back
            // their entry id and state.
            ->where('employee_id', $employeeId)
            ->first();
    }

    /**
     * Resolve the moment a punch should be recorded at.
     *
     * Returns null when the client's claim is out of bounds, which the caller treats as a
     * refusal. Server time is the answer whenever no client time is supplied, so the ordinary
     * online flow is entirely unaffected by any of this.
     */
    public function resolveTimestamp(?string $claimedAt, ?string $clientUuid = null): ?CarbonImmutable
    {
        // No claim: an ordinary online punch, stamped by the server as always.
        if (blank($claimedAt)) {
            return CarbonImmutable::now();
        }

        try {
            $claimed = CarbonImmutable::parse($claimedAt)->utc();
        } catch (\Throwable) {
            return null;
        }

        $now = CarbonImmutable::now();

        $skew = Setting::int(Setting::OFFLINE_FUTURE_SKEW_MINUTES, self::DEFAULT_FUTURE_SKEW_MINUTES);

        if ($claimed->greaterThan($now->addMinutes($skew))) {
            return null;
        }

        $maxHours = Setting::int(Setting::OFFLINE_MAX_BACKDATE_HOURS, self::DEFAULT_MAX_BACKDATE_HOURS);

        if ($claimed->lessThan($now->subHours($maxHours))) {
            return null;
        }

        return $claimed;
    }

    /**
     * Whether this punch is client-reported, and therefore worth flagging.
     *
     * A claim within the skew allowance of now is treated as an ordinary punch: the phone and
     * the server agree closely enough that there is nothing to review, and flagging every one
     * would bury the cases that matter.
     */
    public function isOfflineReport(?string $claimedAt): bool
    {
        if (blank($claimedAt)) {
            return false;
        }

        try {
            $claimed = CarbonImmutable::parse($claimedAt)->utc();
        } catch (\Throwable) {
            return false;
        }

        $skew = Setting::int(Setting::OFFLINE_FUTURE_SKEW_MINUTES, self::DEFAULT_FUTURE_SKEW_MINUTES);

        return $claimed->lessThan(CarbonImmutable::now()->subMinutes($skew));
    }

    /**
     * Flag a punch whose time the client reported, and label the segment as such.
     *
     * Both, because they serve different readers: `is_offline_sync` is what the timesheet and
     * pay code can filter on, while the anomaly is what a manager actually sees in the review
     * queue. Setting only one would leave the other audience unable to tell.
     */
    public function markSynced(TimeEntry $entry, CarbonImmutable $claimedAt, CarbonImmutable $serverTime): void
    {
        /*
         * `abs()`, and it is not cosmetic.
         *
         * Carbon's `diffInMinutes` is SIGNED in this version: asked for the difference between the
         * sync time and an earlier claim, it returns a negative number. The first version rendered
         * that straight into the message, so a real offline punch read "received -180 minute(s)
         * later" in the manager's review queue — nonsense on its face, and worse, it reads as a
         * figure that has been computed wrongly rather than one that has been phrased wrongly,
         * which sends whoever reads it looking for a problem in the arithmetic.
         */
        $lateness = (int) abs($serverTime->diffInMinutes($claimedAt));

        $delay = match (true) {
            $lateness < 60 => "{$lateness} minute(s) later",
            $lateness < 60 * 48 => round($lateness / 60).' hour(s) later',
            default => round($lateness / 60 / 24).' day(s) later',
        };

        Anomaly::raise(
            $entry,
            AnomalyType::OFFLINE_SYNC,
            sprintf(
                /*
                 * The claimed moment is shown in the BUSINESS timezone, not UTC. A manager checking
                 * "was this person here at eight" needs the wall clock they would have seen, and
                 * UTC would be eight hours off — which looks exactly like the bug it is not.
                 */
                'Punched offline at %s, sent to the server %s.',
                $claimedAt->setTimezone(config('attendance.business_timezone'))->format('d M H:i'),
                $delay,
            ),
        );
    }

    /**
     * Guard the case an out-of-order queue can produce.
     *
     * A queue can present actions in the wrong order: a clock-out that overtakes the clock-in
     * it followed, because the phone retried one and not the other. Closing nothing is
     * meaningless, and recording it would create a segment that ends before it starts — a
     * negative duration, which is the corruption `durationSeconds()` clamps against.
     *
     * Refused rather than ignored. An ignored clock-out leaves the employee believing they are
     * off the clock while a segment is still open, accruing hours — the expensive failure. A
     * refusal is visible and the correction path exists for it.
     *
     * @throws InvalidArgumentException
     */
    public function assertActionIsCoherent(string $action, ?TimeEntry $open): void
    {
        $needsSomethingOpen = in_array($action, ['start_break', 'end_break', 'clock_out'], true);

        if ($needsSomethingOpen && $open === null) {
            throw new InvalidArgumentException(
                'That punch arrived out of order — there is nothing open for it to close. Ask a manager to correct it.'
            );
        }
    }
}
