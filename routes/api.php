<?php

use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Middleware\EnsureUserIsActive;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1
|--------------------------------------------------------------------------
| Three groups, with different authentication:
|
|   auth/*    console sign-in. Public, but issues a token.
|   admin/*   the back office. Requires a token AND an active account.
|   punch/*   the public clock-in flow. No login at all — kitchen crew have no
|             account, and requiring one would mean they cannot clock in.
|
| SEE docs/sso.md before adding anything that calls the ordering system. Attendance
| must never depend on it being reachable.
*/

Route::prefix('v1')->group(function () {

    // ---- Console sign-in (public) ------------------------------------
    Route::post('auth/login', [AuthController::class, 'login'])
        // 6 attempts/minute: slow enough that guessing is impractical, generous
        // enough that a real person mistyping twice is not locked out.
        ->middleware('throttle:6,1')
        ->name('auth.login');

    Route::middleware(['auth:sanctum', EnsureUserIsActive::class])->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::get('auth/me', [AuthController::class, 'me'])->name('auth.me');

        /*
         * The back office. Role and outlet scoping are enforced by the SERVER on
         * every query, not by hiding navigation in the client.
         */
        Route::prefix('admin')->name('admin.')->group(function () {
            //
        });
    });

    /*
     * The punch flow is public by design. It is authorised by an outlet code plus
     * an employee PIN, exchanged for a short-lived punch session — see Phase 2.
     */
    Route::prefix('punch')->name('punch.')->group(function () {
        //
    });
});
