<?php

namespace App\Enums;

/**
 * Lifecycle of a time entry segment.
 *
 * CORRECTED is not merely cosmetic: an approved correction moves the entry to
 * this state so reports can tell "what the employee punched" from "what a manager
 * later agreed it should have been".
 */
enum TimeEntryStatus: string
{
    case OPEN = 'open';
    case CLOSED = 'closed';
    case FLAGGED = 'flagged';
    case CORRECTED = 'corrected';

    public function label(): string
    {
        return match ($this) {
            self::OPEN => 'Open',
            self::CLOSED => 'Closed',
            self::FLAGGED => 'Flagged',
            self::CORRECTED => 'Corrected',
        };
    }

    public function isOpen(): bool
    {
        return $this === self::OPEN;
    }
}
