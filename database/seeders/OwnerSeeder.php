<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * The owner account.
 *
 * Only the owner is seeded. Managers are created in the console, along with their
 * outlet mapping, because who manages which site is a business decision rather
 * than a fact about the system — and because a manager with no mapping sees
 * nothing (the outlet scope fails closed), so a half-configured manager account
 * would be a trap rather than a convenience.
 *
 * The owner needs no mapping: `User::visibleOutletIds()` returns null for an
 * owner, meaning every outlet.
 */
class OwnerSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'owner@darscoffee.com'],
            [
                'name' => 'Owner',
                'password' => 'password',
                'role' => UserRole::OWNER,
                'is_active' => true,
                /*
                 * Marked verified so a fresh install needs no confirmation flow.
                 * A local convenience, not a security control.
                 */
                'email_verified_at' => now(),
            ],
        );

        $this->command->warn('Owner account: owner@darscoffee.com / password — change this before any real use.');
    }
}
