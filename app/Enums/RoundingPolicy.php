<?php

namespace App\Enums;

/**
 * How stored seconds are presented in a report.
 *
 * Applied in REPORTING ONLY. Stored durations are always exact, because the record has
 * to state when someone actually arrived — and because re-applying a rounding policy to
 * exact seconds is possible, while recovering exact seconds from rounded ones is not.
 *
 * Changing the policy therefore re-derives history rather than rewriting it.
 */
enum RoundingPolicy: string
{
    /** No rounding. The default, and the only setting that needs no explanation. */
    case EXACT = 'exact';

    /** To the nearest quarter hour. */
    case NEAREST_15 = 'nearest_15';

    /** Down to the quarter hour, never up. */
    case DOWN_15 = 'down_15';

    public function label(): string
    {
        return match ($this) {
            self::EXACT => 'Exact minutes',
            self::NEAREST_15 => 'Nearest 15 minutes',
            self::DOWN_15 => 'Down to 15 minutes',
        };
    }

    public function apply(int $seconds): int
    {
        return match ($this) {
            self::EXACT => $seconds,
            self::NEAREST_15 => (int) (round($seconds / 900) * 900),
            /*
             * Integer division, not round(). "Down" has to mean down: rounding 7:59:00
             * to 8:00:00 would pay for a minute nobody worked.
             */
            self::DOWN_15 => intdiv($seconds, 900) * 900,
        };
    }
}
