<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Runtime settings, same key/value pattern as the ordering system.
 *
 * Only values an owner may change without a deploy live here: retention windows,
 * lockout policy, whether rate changes need approval. Anything that changes
 * behaviour in a way that must be auditable belongs in a table with history, not
 * here.
 */
#[Fillable(['key', 'value'])]
class Setting extends Model
{
    /** How long punch photos are kept (PDPA minimisation). */
    public const PHOTO_RETENTION_DAYS = 'photo_retention_days';

    /**
     * Whether a manager's rate change must be approved by the owner.
     *
     * DECIDED OFF (2026-09-16): a manager may set rates directly for their own
     * staff. That makes the audit record the only control, so rate changes always
     * require a reason and always keep the previous value — see
     * `AnomalyType::MANAGER_RATE_CHANGE` and the `rate_adjustments` table.
     *
     * Kept as a setting rather than removed, because an owner may reasonably want
     * to switch approval on later without a code change.
     */
    public const REQUIRE_RATE_APPROVAL = 'require_rate_approval';

    /** Failed PIN attempts before lockout. */
    public const PIN_MAX_ATTEMPTS = 'pin_max_attempts';

    /** How long a locked PIN stays locked. */
    public const PIN_LOCKOUT_MINUTES = 'pin_lockout_minutes';

    /** How long a punch session lasts after a successful PIN entry. */
    public const PUNCH_SESSION_MINUTES = 'punch_session_minutes';

    /** Hours after which an open segment is assumed forgotten. */
    public const MISSING_CLOCKOUT_HOURS = 'missing_clockout_hours';

    /**
     * Whether a staff member must have a consent record before they are photographed.
     *
     * DECIDED OFF (2026-09-21), and the reasoning matters because the opposite choice looks
     * more obviously correct.
     *
     * Requiring consent before capture is the strictest reading of the PDPA, and it was the
     * first implementation. It was switched off because every existing employee has no
     * consent record, so turning it on in production does not merely refuse a few
     * photographs — it silently stops photographs being taken AT ALL, disabling the
     * anti-buddy-punching control the whole system rests on, on the first morning, with no
     * obvious symptom.
     *
     * That is a decision for the owner to make deliberately, once the consent backlog in the
     * console has actually been cleared. So the default is off, the console shows who has
     * not consented, and switching this on is a conscious act that fails loudly rather than
     * a code change that fails quietly.
     *
     * A WITHDRAWAL is different, and is honoured regardless of this setting: someone who has
     * actively withdrawn consent is never photographed, because their withdrawal was an
     * explicit instruction and cannot be overridden by a default.
     */
    public const REQUIRE_CONSENT_FOR_PHOTOS = 'require_consent_for_photos';

    /**
     * How far back an offline punch may claim to have happened.
     *
     * An offline queue reports its own timestamp, because the phone captured the punch at a
     * moment when it could not reach the server. That timestamp is the whole point of the
     * feature — without it a 7am clock-in synced at 2pm would be recorded at 2pm — and it is
     * also the one part of a punch the client fully controls.
     *
     * So it is bounded. Beyond this window the claim is refused and the punch has to be
     * corrected by a manager, who is accountable and leaves an audit row. 24 hours by default:
     * comfortably longer than any real connectivity gap on the premises, and far shorter than
     * the span over which backdating could quietly rewrite a pay period.
     *
     * Every accepted offline punch also raises an OFFLINE_SYNC anomaly, so the client-reported
     * times are visible rather than indistinguishable from server-stamped ones.
     */
    public const OFFLINE_MAX_BACKDATE_HOURS = 'offline_max_backdate_hours';

    /**
     * Clock skew tolerated on a client-reported punch time.
     *
     * A phone whose clock runs a minute or two fast would otherwise have every offline punch
     * refused as "in the future", which reads to the employee as the app being broken. Small
     * enough that it cannot be used to claim work that has not happened.
     */
    public const OFFLINE_FUTURE_SKEW_MINUTES = 'offline_future_skew_minutes';

    /**
     * Whether a manager's correction needs the owner's approval.
     *
     * DECIDED ON (default true), unlike rate changes. The two are not equivalent: a rate
     * change alters what future hours are worth and is visible in the rate history, while
     * a correction alters the hours themselves — the record the whole system exists to
     * make trustworthy. A manager being able to rewrite their own staff's hours without
     * anyone countersigning makes the timesheet self-certifying.
     *
     * An owner's own corrections never need approval, so this does not slow down the
     * person who is already the final authority.
     */
    public const REQUIRE_CORRECTION_APPROVAL = 'require_correction_approval';

    /**
     * Slack, in minutes, when deciding whether a punch covered a rostered shift.
     *
     * Used by VarianceService for two things: whether a punch overlaps a shift at all, and
     * whether the hours worked are close enough that the shift counts as met rather than
     * short or over.
     *
     * 30 minutes by default. Tightening it would report every ordinary late arrival as a
     * variance, and a report that flags everything teaches managers to ignore it.
     */
    public const VARIANCE_TOLERANCE_MINUTES = 'variance_tolerance_minutes';

    public static function get(string $key, mixed $default = null): mixed
    {
        return static::query()->where('key', $key)->value('value') ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
    }

    /** Read as a boolean, with a default so a missing row is decisive. */
    public static function bool(string $key, bool $default = false): bool
    {
        $value = static::get($key);

        if ($value === null || $value === '') {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /** Read as an integer, falling back when unset or nonsensical. */
    public static function int(string $key, int $default): int
    {
        $value = static::get($key);

        return is_numeric($value) && (int) $value > 0 ? (int) $value : $default;
    }
}
