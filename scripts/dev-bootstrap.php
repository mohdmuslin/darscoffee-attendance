<?php

/*
 * Shared bootstrap for the local development helper scripts.
 *
 * The `use` statement must stay at the TOP of the file. An earlier version referenced
 * the kernel by its full class name inline, and Pint's `fully_qualified_strict_types`
 * fixer rewrote it to a short name while placing the import at the bottom of the file —
 * where a `use` statement does not apply. Every script then died with
 * "Target class [Kernel] does not exist".
 *
 * Not part of the app. `php artisan` handles all of this for the real console.
 */

use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

return $app;
