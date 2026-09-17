<?php

namespace App\Enums;

/**
 * Console login roles.
 *
 * Originally only owner and manager, on the reasoning that most staff clock in
 * without ever signing in. Single sign-on with the ordering system reverses that:
 * if accounts are shared between the two apps, every staff member needs one. So
 * `staff` was added, and it grants the narrowest access of the three.
 *
 * Note the distinction that remains: having a `users` row means you can SIGN IN;
 * having an `employees` row means you WORK SHIFTS and are paid. A person is often
 * both, but they are different facts and live in different tables.
 */
enum UserRole: string
{
    case OWNER = 'owner';
    case MANAGER = 'manager';
    case STAFF = 'staff';

    public function label(): string
    {
        return match ($this) {
            self::OWNER => 'Owner',
            self::MANAGER => 'Manager',
            self::STAFF => 'Staff',
        };
    }

    /** Owners see every outlet; managers are scoped by outlet_user. */
    public function seesAllOutlets(): bool
    {
        return $this === self::OWNER;
    }

    /**
     * Whether this role may administer the system.
     *
     * Staff are deliberately excluded even if mapped to an outlet: seeing your own
     * timesheet is not the same as seeing everyone else's.
     */
    public function canAdminister(): bool
    {
        return $this === self::OWNER || $this === self::MANAGER;
    }

    /**
     * Whether this role sees only its own records.
     *
     * Staff land on their own hours and nothing else — which is also the only
     * screen they need, and the reason `staff` is not simply "a manager with no
     * outlets mapped".
     */
    public function isSelfServiceOnly(): bool
    {
        return $this === self::STAFF;
    }
}
