<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * The manager account.
 *
 * Seeded because Siti Fatimah is the manager the owner named, and having her
 * account exist means the outlet scoping can be verified against a real person
 * rather than a fixture. Who manages which site is a business fact, like the
 * outlets themselves.
 *
 * FAILS CLOSED if she has no outlet mapping: `User::visibleOutletIds()` returns an
 * empty array for an unmapped manager, so she would see nothing at all. That is
 * deliberate — forgetting to grant access locks someone out rather than exposing
 * every outlet — but it means a missing mapping looks like a broken account, so the
 * mapping is part of this seeder rather than a step to remember later.
 */
class ManagerSeeder extends Seeder
{
    public function run(): void
    {
        $manager = User::updateOrCreate(
            ['email' => 'fatimahbokhare@gmail.com'],
            [
                'name' => 'Siti Fatimah',
                'password' => 'password',
                'role' => UserRole::MANAGER,
                'is_active' => true,
                'email_verified_at' => now(),
            ],
        );

        /*
         * Mapped to all three outlets for now.
         *
         * The owner has one manager covering the business, so scoping her to a single
         * outlet would hide two-thirds of the staff from the only person managing
         * them. The scoping mechanism is unchanged — she is a manager with a mapping,
         * not an owner — so removing an outlet here immediately narrows what she can
         * see.
         */
        $outletIds = Outlet::pluck('id')->all();

        $manager->outlets()->sync($outletIds);

        $this->command->warn(
            'Manager: fatimahbokhare@gmail.com / password — mapped to '
            .count($outletIds).' outlet(s). CHANGE THIS PASSWORD before real use.'
        );
    }
}
