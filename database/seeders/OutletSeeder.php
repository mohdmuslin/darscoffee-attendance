<?php

namespace Database\Seeders;

use App\Enums\OutletTokenMode;
use App\Models\Outlet;
use App\Models\Setting;
use Illuminate\Database\Seeder;

/**
 * The three outlets, plus the runtime settings this system reads.
 *
 * Outlets are seeded because they are facts about the business, not test data.
 * They are matched by `code` so re-running is a no-op rather than creating
 * duplicates — the ordering project's seeder matched a store by NAME once and
 * quietly created a second location with its own tables when the name changed.
 */
class OutletSeeder extends Seeder
{
    public function run(): void
    {
        $outlets = [
            [
                'code' => 'SG-RAMAL',
                'name' => 'Diberanda Sg Ramal',
                'address' => null,
            ],
            [
                'code' => 'SEDAP-SANTAI',
                'name' => 'Diberanda Sedap Santai',
                'address' => null,
            ],
            [
                'code' => 'DARS-COFFEE',
                'name' => 'Dars Coffee',
                'address' => null,
            ],
        ];

        foreach ($outlets as $outlet) {
            Outlet::updateOrCreate(['code' => $outlet['code']], [
                'name' => $outlet['name'],
                'address' => $outlet['address'],
                'timezone' => 'Asia/Kuala_Lumpur',
                /*
                 * Rotating by default: it is the stronger mode, and an outlet that
                 * would rather print a sheet can be switched to `printed` in the
                 * console without a deploy.
                 */
                'token_mode' => OutletTokenMode::ROTATING,
                'qr_ttl_seconds' => 90,
                'requires_photo' => true,
                'is_active' => true,
            ]);
        }

        $this->settings();
    }

    /**
     * Defaults for the runtime settings, only where unset.
     *
     * Written with a guard rather than updateOrCreate so re-seeding never
     * overwrites a choice an owner has already made in the console.
     */
    private function settings(): void
    {
        $defaults = [
            // 90 days: enough to settle a dispute, short enough to be defensible.
            Setting::PHOTO_RETENTION_DAYS => '90',

            /*
             * OFF, per the owner's decision: a manager may set rates directly for
             * their own staff. The audit record is the control instead, so every
             * change requires a reason and keeps the previous value.
             */
            Setting::REQUIRE_RATE_APPROVAL => '0',

            // A 4-digit PIN falls in seconds without this.
            Setting::PIN_MAX_ATTEMPTS => '5',
            Setting::PIN_LOCKOUT_MINUTES => '15',

            // Long enough to take a photo and confirm, short enough that a borrowed
            // phone does not stay authorised.
            Setting::PUNCH_SESSION_MINUTES => '10',

            /*
             * 16 hours: a shift longer than this is a forgotten clock-out rather
             * than a long day, so it gets flagged and clipped.
             */
            Setting::MISSING_CLOCKOUT_HOURS => '16',
        ];

        foreach ($defaults as $key => $value) {
            if (Setting::get($key) === null) {
                Setting::set($key, $value);
            }
        }
    }
}
