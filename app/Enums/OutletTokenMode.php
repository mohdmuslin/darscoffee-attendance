<?php

namespace App\Enums;

/**
 * How an outlet delivers its punch code.
 *
 * ROTATING  — a device at the outlet displays a short-lived code. A photograph
 *             of it is worthless in ~90 seconds.
 * PRINTED   — a sheet the owner or manager prints. Valid until revoked, so a
 *             photograph of it CAN be reused. Accepted deliberately, on the
 *             understanding that the photo, shift window and review queue are
 *             what carry the load there.
 *
 * Set per outlet because the trade-off differs per site: rotating is stronger,
 * printed survives a dead phone or flaky wifi.
 */
enum OutletTokenMode: string
{
    case ROTATING = 'rotating';
    case PRINTED = 'printed';

    public function label(): string
    {
        return match ($this) {
            self::ROTATING => 'Rotating code (device)',
            self::PRINTED => 'Printed code',
        };
    }

    /** Printed codes stay valid until revoked; rotating ones expire. */
    public function expires(): bool
    {
        return $this === self::ROTATING;
    }
}
