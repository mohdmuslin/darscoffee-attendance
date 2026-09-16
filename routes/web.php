<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| SPA entry points
|--------------------------------------------------------------------------
| Two Vue apps on one Laravel install, mirroring the ordering system:
|
|   /punch    the public clock-in PWA. NO login — kitchen crew have no account,
|             and requiring one would mean they cannot clock in at all.
|   /console  the owner/manager back office, role and outlet gated.
|
| Both use history mode, so each needs a catch-all returning its shell. /console
| must be declared BEFORE the punch catch-all, or the punch shell would swallow
| every console URL.
*/

Route::view('/console/{any?}', 'console')
    ->where('any', '.*')
    ->name('console.spa');

Route::redirect('/', '/punch');

/*
 * The catch-all serves the punch app, and excludes /api, /console and /up so it
 * cannot shadow the API or the health check. Storage is excluded too, so signed
 * photo URLs are never swallowed by the SPA.
 */
Route::view('/{any?}', 'punch')
    ->where('any', '^(?!api|console|up|storage).*$')
    ->name('punch.spa');
