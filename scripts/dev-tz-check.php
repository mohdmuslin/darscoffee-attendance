<?php

/*
 * Local development helper: show the clock and timezone situation.
 *
 *   php scripts/dev-tz-check.php
 *
 * A shift that crosses midnight is only assigned to the right business day if the
 * outlet timezone and the stored timestamps agree. This prints both so a mismatch is
 * obvious rather than showing up later as hours on the wrong day.
 *
 * Not part of the app.
 */

require __DIR__.'/dev-bootstrap.php';

use App\Models\Outlet;
use App\Models\TimeEntry;
use Carbon\CarbonImmutable;

echo 'app.timezone  = '.config('app.timezone').PHP_EOL;
echo 'php.timezone  = '.date_default_timezone_get().PHP_EOL;
echo 'now (UTC)     = '.CarbonImmutable::now()->toDateTimeString().PHP_EOL;
echo 'now (KL)      = '.CarbonImmutable::now('Asia/Kuala_Lumpur')->toDateTimeString().PHP_EOL;
echo '---'.PHP_EOL;

foreach (Outlet::all() as $outlet) {
    echo sprintf(
        '%s | tz=%s | local now=%s'.PHP_EOL,
        $outlet->code,
        $outlet->timezone,
        CarbonImmutable::now($outlet->timezone)->toDateTimeString(),
    );
}

echo '---'.PHP_EOL;

foreach (TimeEntry::latest('id')->take(5)->get() as $entry) {
    echo sprintf(
        '#%d started_at=%s (utc) | stored business_date=%s | local=%s'.PHP_EOL,
        $entry->id,
        $entry->started_at?->utc()->toDateTimeString(),
        $entry->business_date?->toDateString(),
        $entry->started_at?->setTimezone($entry->outlet->timezone)->toDateTimeString(),
    );
}
