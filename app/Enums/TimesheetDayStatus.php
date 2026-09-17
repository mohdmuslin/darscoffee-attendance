<?php

namespace App\Enums;

/**
 * How a day is classified on a timesheet.
 *
 * These drive the badge a manager sees, and they are deliberately about *what needs
 * attention* rather than about pay — pay bases differ per employee and are a Phase 6
 * concern, while "this day is incomplete and nobody has fixed it" is true for everyone.
 */
enum TimesheetDayStatus: string
{
    /** Punched, closed, and every check passed. */
    case OK = 'ok';

    /** A segment is still open — someone never clocked out. */
    case OPEN = 'open';

    /** The times were changed by an approved correction. */
    case CORRECTED = 'corrected';

    /** Worked, but outside any scheduled shift. Legitimate here, so not an error. */
    case ADHOC = 'adhoc';

    /** A shift was rostered and nobody punched for it. */
    case NO_SHOW = 'no_show';

    public function label(): string
    {
        return match ($this) {
            self::OK => 'Complete',
            self::OPEN => 'Not clocked out',
            self::CORRECTED => 'Corrected',
            self::ADHOC => 'Adhoc work',
            self::NO_SHOW => 'No show',
        };
    }

    /**
     * Whether the day's total can be relied on.
     *
     * An open segment cannot: the hours are still accumulating, and a forgotten
     * clock-out would otherwise add a full day to the total every day it is left.
     */
    public function isComplete(): bool
    {
        return $this !== self::OPEN && $this !== self::NO_SHOW;
    }
}
