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
