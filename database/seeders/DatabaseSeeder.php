<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Seeds the facts about the business: the three outlets, the owner account, and
 * the runtime settings this system reads.
 *
 * NOTICE: `WithoutModelEvents` is deliberately NOT used.
 *
 * The ordering project used it and it silently broke token generation, because
 * models that rely on model events to populate a column get nulls when events are
 * suppressed. This system assigns PINs and outlet codes that will follow the same
 * pattern, so the trait is left off rather than rediscovered later.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            OutletSeeder::class,
            // The owner owns every outlet and needs no mapping.
            OwnerSeeder::class,
            // Siti Fatimah, mapped to all outlets for now. See ManagerSeeder.
            ManagerSeeder::class,
        ]);
    }
}
