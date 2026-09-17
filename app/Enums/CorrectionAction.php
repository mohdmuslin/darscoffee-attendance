<?php

namespace App\Enums;

/**
 * What a correction does to the record.
 *
 * Two cases, not one, because a missing punch comes in two shapes and only one of
 * them has an existing row to amend:
 *
 *  - UPDATE: the employee punched, but the times are wrong — a forgotten clock-out
 *    (an open segment), or a wrong clock-in time.
 *  - CREATE: nothing was ever recorded. A day they worked and did not punch at all,
 *    which no amount of amending can fix because there is nothing to amend.
 */
enum CorrectionAction: string
{
    case UPDATE = 'update';
    case CREATE = 'create';

    public function label(): string
    {
        return match ($this) {
            self::UPDATE => 'Change a recorded punch',
            self::CREATE => 'Add a missing punch',
        };
    }

    /** Whether this correction needs an existing entry to act on. */
    public function needsEntry(): bool
    {
        return $this === self::UPDATE;
    }
}
