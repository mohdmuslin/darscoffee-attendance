<?php

/*
 * Shared bootstrap for the local development helper scripts.
 *
 * The `use` statement must stay at the TOP of the file. An earlier version referenced the
 * kernel by its full class name inline, and Pint's `fully_qualified_strict_types` fixer rewrote
 * it to a short name while placing the import at the bottom of the file — where a `use`
 * statement does not apply. Every script then died with
 * "Target class [Kernel] does not exist".
 *
 * Not part of the app. `php artisan` handles all of this for the real console.
 *
 * ---------------------------------------------------------------------------
 * SAFETY GATE — these scripts must never run against a live database
 * ---------------------------------------------------------------------------
 *
 * These helpers are development tools and several of them DELETE data:
 * `dev-reset-punches.php` removes an employee's time entries, `dev-seed-pay.php` and
 * `dev-seed-timesheet.php` clear ranges before reseeding, `dev-delete-employee.php`
 * force-deletes a record. They are committed to the repository, so they are present on the
 * server after a deploy.
 *
 * Running one there would destroy the attendance record — silently, because they print a
 * friendly line and exit zero. The check lives in `dev-guard.php` and runs BEFORE the kernel
 * boots, so there is no database connection to damage by the time it decides.
 */

require_once __DIR__.'/dev-guard.php';

dev_require_local();

use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

return $app;
