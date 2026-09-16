<?php

namespace App\Enums;

/**
 * Console login roles.
 *
 * Deliberately only two: employees are NOT users — most of them (kitchen crew in
 * particular) clock in without ever logging in to anything. Only the people who
 * administer the system have accounts.
 */
enum UserRole: string
{
    case OWNER = 'owner';
    case MANAGER = 'manager';

    public function label(): string
    {
        return match ($this) {
            self::OWNER => 'Owner',
            self::MANAGER => 'Manager',
        };
    }

    /** Owners see every outlet; managers are scoped by outlet_user. */
    public function seesAllOutlets(): bool
    {
        return $this === self::OWNER;
    }
}
