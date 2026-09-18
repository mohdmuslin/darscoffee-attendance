<?php

namespace App\Enums;

/**
 * Everything the audit trail can record.
 *
 * The failed attempts matter as much as the successful ones: "I clocked in and it
 * says I didn't" is only answerable if the refusal was written down, and a run of
 * `pin_failed` against a single code is the signature of someone trying PINs rather
 * than a genuine mistake.
 */
enum PunchEventType: string
{
    /** A code was presented and accepted. */
    case SCAN = 'scan';

    /** A valid code, but no PIN at the outlet matched. */
    case PIN_FAILED = 'pin_failed';

    /** The PIN was right but the account was locked out. */
    case PIN_LOCKED = 'pin_locked';

    case CLOCK_IN = 'clock_in';
    case BREAK_START = 'break_start';
    case BREAK_END = 'break_end';
    case CLOCK_OUT = 'clock_out';

    /** Refused for a reason that is none of the above — an expired session, say. */
    case REJECTED = 'rejected';

    /**
     * A punch that happened on the phone while offline and was sent later.
     *
     * Its own event rather than being folded in with the ordinary clock-in, because the time
     * on it is CLIENT-REPORTED. The server did not witness it, so it cannot be treated as
     * equivalent to a punch that arrived live — and a manager reading the trail needs to see
     * which moments the system actually observed.
     */
    case OFFLINE_SYNC = 'offline_sync';

    /**
     * A punch that arrived twice with the same client id, so the first was kept.
     *
     * Recorded rather than ignored. A retry is normal and expected — the phone could not tell
     * whether its first attempt landed — but a FLOOD of them is worth seeing, because it means
     * something is wrong with a device or a connection that is otherwise invisible.
     */
    case DUPLICATE_IGNORED = 'duplicate_ignored';

    public function label(): string
    {
        return match ($this) {
            self::SCAN => 'Code scanned',
            self::PIN_FAILED => 'PIN did not match',
            self::PIN_LOCKED => 'PIN locked out',
            self::CLOCK_IN => 'Clocked in',
            self::BREAK_START => 'Break started',
            self::BREAK_END => 'Break ended',
            self::CLOCK_OUT => 'Clocked out',
            self::REJECTED => 'Refused',
            self::OFFLINE_SYNC => 'Synced from offline',
            self::DUPLICATE_IGNORED => 'Duplicate ignored',
        };
    }

    /**
     * Whether this event means something went wrong.
     *
     * Drives whether the console shows it plainly or flags it, so a manager scanning
     * the trail is drawn to the failures without having to read every row.
     */
    public function isFailure(): bool
    {
        return in_array($this, [self::PIN_FAILED, self::PIN_LOCKED, self::REJECTED], true);
    }

    /**
     * Map a punch action to its event.
     *
     * The two vocabularies deliberately differ: the API uses `start_break` / `end_break`
     * because that reads naturally as an instruction, while the trail uses
     * `break_start` / `break_end` so the break events sort together in a log. Rather
     * than rename either, this is the one place the mapping lives — a bare
     * `from($action)` would silently throw on the two break actions at runtime.
     */
    public static function forAction(string $action): self
    {
        return match ($action) {
            'clock_in' => self::CLOCK_IN,
            'start_break' => self::BREAK_START,
            'end_break' => self::BREAK_END,
            'clock_out' => self::CLOCK_OUT,
            default => throw new \InvalidArgumentException("Unknown punch action '{$action}'."),
        };
    }
}
