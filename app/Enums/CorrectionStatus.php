<?php

namespace App\Enums;

/**
 * Lifecycle of a correction request.
 *
 * A manager may request a change; whether it applies immediately or waits for the
 * owner is a setting. Either way the request itself is a permanent record — an
 * approved correction is not deleted once applied, it is the explanation for why the
 * timesheet no longer matches the original punch.
 */
enum CorrectionStatus: string
{
    case PENDING = 'pending';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Waiting for review',
            self::APPROVED => 'Approved',
            self::REJECTED => 'Rejected',
        };
    }

    public function isPending(): bool
    {
        return $this === self::PENDING;
    }
}
