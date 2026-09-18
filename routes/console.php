<?php

use App\Console\Commands\PurgeRetainedPhotos;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled work
|--------------------------------------------------------------------------
|
| The cPanel target has no queue worker and no long-running process, so the only
| scheduling available is a single cron entry calling `schedule:run`. Everything
| periodic has to be expressed here.
|
| The cron entry itself is:
|
|     * * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
|
| IMPORTANT — the timezone below is not decoration. The application runs in UTC and
| the business does not, so a bare `dailyAt('03:00')` would fire at 3am UTC, which is
| 11am in Kuala Lumpur: in the middle of the working day, deleting photographs while
| the console is in use. Passing the business timezone makes 03:00 mean 03:00 local,
| when nobody is punching in and the overnight shifts are long finished.
|
*/

Schedule::command(PurgeRetainedPhotos::class)
    ->dailyAt('03:00')
    ->timezone(config('attendance.business_timezone'))
    /*
     * A long run must not overlap the next night's. Without this, a purge slow enough
     * to still be running at 03:00 the following day would have two runs deleting the
     * same files and double-counting in the log.
     */
    ->withoutOverlapping()
    /*
     * A missed run is not made up: retention is a nightly window, and the next night
     * catches the same files. Chasing a backlog here would just mean deleting data at
     * an unexpected hour.
     */
    ->onOneServer();
