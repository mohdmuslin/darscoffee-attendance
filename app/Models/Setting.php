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

    /** Whether a manager's rate change must be approved by the owner. */
    public const REQUIRE_RATE_APPROVAL = 'require_rate_approval';

    /** Failed PIN attempts before lockout. */
    public const PIN_MAX_ATTEMPTS = 'pin_max_attempts';

    /** How long a locked PIN stays locked. */
    public const PIN_LOCKOUT_MINUTES = 'pin_lockout_minutes';

    /** How long a punch session lasts after a successful PIN entry. */
    public const PUNCH_SESSION_MINUTES = 'punch_session_minutes';

    /** Hours after which an open segment is assumed forgotten. */
    public const MISSING_CLOCKOUT_HOURS = 'missing_clockout_hours';

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
