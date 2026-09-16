<?php

namespace App\Enums;

/**
 * How an employee is paid.
 *
 * Stored per employee, but the amount is NOT derived here — v1 records hours and
 * rates and exports them. Tax, EPF and SOCSO are a different domain with legal
 * consequences, so payroll computation is deliberately out of scope.
 */
enum PayBasis: string
{
    case HOURLY = 'hourly';
    case DAILY = 'daily';
    case WEEKLY = 'weekly';
    case MONTHLY = 'monthly';

    public function label(): string
    {
        return match ($this) {
            self::HOURLY => 'Per hour',
            self::DAILY => 'Per day',
            self::WEEKLY => 'Per week',
            self::MONTHLY => 'Per month',
        };
    }

    /**
     * What the stored rate means, for display.
     *
     * A monthly rate is NOT an hourly rate, and treating it as one is how
     * overtime gets miscalculated — so the unit is always shown alongside the
     * figure rather than assumed.
     */
    public function rateUnit(): string
    {
        return match ($this) {
            self::HOURLY => 'hour',
            self::DAILY => 'day',
            self::WEEKLY => 'week',
            self::MONTHLY => 'month',
        };
    }
}
