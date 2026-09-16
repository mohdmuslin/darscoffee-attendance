<?php

namespace App\Enums;

/**
 * Anomalies raised automatically on a punch.
 *
 * These are what make buddy punching visible rather than impossible — no software
 * can prove who held the phone, so the design goal is that anything odd leaves a
 * trail a manager will see.
 *
 * Kept as its own table rather than booleans on the entry so new checks can be
 * added without a migration, and so "everything unreviewed" is one indexed query.
 */
enum AnomalyType: string
{
    /** Punched far outside any scheduled shift. */
    case OUTSIDE_SHIFT = 'outside_shift';

    /** A shift much longer than normal — a probable forgotten clock-out. */
    case LONG_SPAN = 'long_span';

    /** The same person punched at a different outlet the same day. */
    case DOUBLE_OUTLET = 'double_outlet';

    /** The outlet requires a photo and none was captured. */
    case NO_PHOTO = 'no_photo';

    /** Left open past the threshold without clocking out. */
    case MISSING_CLOCKOUT = 'missing_clockout';

    /** Arrived through the offline queue, so the time is client-reported. */
    case OFFLINE_SYNC = 'offline_sync';

    /** A manager set a pay rate for their own staff. Shown to the owner. */
    case MANAGER_RATE_CHANGE = 'manager_rate_change';

    /** A manager corrected an entry for their own outlet. */
    case MANAGER_CORRECTION = 'manager_correction';

    public function label(): string
    {
        return match ($this) {
            self::OUTSIDE_SHIFT => 'Outside scheduled shift',
            self::LONG_SPAN => 'Unusually long shift',
            self::DOUBLE_OUTLET => 'Punched at more than one outlet',
            self::NO_PHOTO => 'No photo captured',
            self::MISSING_CLOCKOUT => 'No clock-out recorded',
            self::OFFLINE_SYNC => 'Recorded offline',
            self::MANAGER_RATE_CHANGE => 'Manager changed a pay rate',
            self::MANAGER_CORRECTION => 'Manager corrected an entry',
        };
    }

    /**
     * Severity drives how loudly the console surfaces it.
     *
     * OFFLINE_SYNC is informational on purpose: a dropped connection is normal at
     * a shop, and treating it as suspicious would train managers to ignore flags.
     *
     * The manager-action entries are informational to the MANAGER who made them
     * but are surfaced to the OWNER, because a manager can set rates and correct
     * entries for their own staff without a second signature. The audit record is
     * the only control there, so it must be visible rather than merely stored.
     */
    public function severity(): AnomalySeverity
    {
        return match ($this) {
            self::MISSING_CLOCKOUT => AnomalySeverity::HIGH,
            self::DOUBLE_OUTLET, self::LONG_SPAN, self::NO_PHOTO => AnomalySeverity::WARN,
            self::OUTSIDE_SHIFT, self::OFFLINE_SYNC, self::MANAGER_RATE_CHANGE, self::MANAGER_CORRECTION => AnomalySeverity::INFO,
        };
    }

    /** Whether this row exists for the owner's oversight rather than as a fault. */
    public function isOversightRecord(): bool
    {
        return in_array($this, [self::MANAGER_RATE_CHANGE, self::MANAGER_CORRECTION], true);
    }
}
