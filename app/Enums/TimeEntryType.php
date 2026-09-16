<?php

namespace App\Enums;

/**
 * The kind of segment a time entry represents.
 *
 * Work and breaks are SEPARATE ROWS rather than a break column on a shift. That
 * choice makes worked time a plain sum with nothing to subtract, so there is no
 * arithmetic to get wrong:
 *
 *   worked = SUM(duration) WHERE type = WORK
 *
 * A break is recorded, not merely excluded, which is what makes a dispute about
 * "I skipped lunch" answerable from data.
 */
enum TimeEntryType: string
{
    case WORK = 'work';
    case BREAK = 'break';

    public function label(): string
    {
        return match ($this) {
            self::WORK => 'Working',
            self::BREAK => 'On break',
        };
    }
}
