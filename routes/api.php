<?php

use App\Http\Controllers\Api\V1\Admin\AdminEmployeeController;
use App\Http\Controllers\Api\V1\Admin\AdminOutletController;
use App\Http\Controllers\Api\V1\Admin\AdminUserController;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\PhotoController;
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

    /*
     * Photographs are public but SIGNED. An <img> tag cannot send an Authorization
     * header, so a photo behind auth simply would not render. The signature is the
     * authorisation, and it expires.
     */
    Route::get('photos/{path}', [PhotoController::class, 'show'])
        ->where('path', '.*')
        ->middleware('signed')
        ->name('photos.show');

    Route::middleware(['auth:sanctum', EnsureUserIsActive::class])->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::get('auth/me', [AuthController::class, 'me'])->name('auth.me');

        /*
         * The back office. Role and outlet scoping are enforced by the SERVER on
         * every query, not by hiding navigation in the client.
         */
        Route::prefix('admin')->name('admin.')->group(function () {

            // ---- Outlets and their punch codes ------------------------
            Route::get('outlets', [AdminOutletController::class, 'index'])->name('outlets.index');
            Route::post('outlets', [AdminOutletController::class, 'store'])->name('outlets.store');
            Route::patch('outlets/{outlet}', [AdminOutletController::class, 'update'])->name('outlets.update');

            Route::get('outlets/{outlet}/token', [AdminOutletController::class, 'currentToken'])->name('outlets.token.show');
            /*
             * Reprinting is the REVOKE mechanism for a printed code — it is what kills
             * a leaked sheet — so it stays a single, unceremonious call.
             */
            Route::post('outlets/{outlet}/token', [AdminOutletController::class, 'regenerateToken'])->name('outlets.token.regenerate');
            Route::delete('outlets/{outlet}/token', [AdminOutletController::class, 'revokeToken'])->name('outlets.token.revoke');
            Route::get('outlets/{outlet}/print-sheet', [AdminOutletController::class, 'printSheet'])->name('outlets.print-sheet');

            // ---- Employees --------------------------------------------
            Route::get('employees', [AdminEmployeeController::class, 'index'])->name('employees.index');
            Route::post('employees', [AdminEmployeeController::class, 'store'])->name('employees.store');
            Route::get('employees/{employee}', [AdminEmployeeController::class, 'show'])->name('employees.show');
            Route::patch('employees/{employee}', [AdminEmployeeController::class, 'update'])->name('employees.update');

            /*
             * Deactivate rather than delete: history must survive someone leaving,
             * and a manager deleting staff ahead of a dispute is not a power worth
             * granting. There is deliberately no destroy route.
             */
            Route::post('employees/{employee}/deactivate', [AdminEmployeeController::class, 'deactivate'])->name('employees.deactivate');
            Route::post('employees/{employee}/activate', [AdminEmployeeController::class, 'activate'])->name('employees.activate');

            Route::put('employees/{employee}/pin', [AdminEmployeeController::class, 'setPin'])->name('employees.pin.set');
            Route::delete('employees/{employee}/pin', [AdminEmployeeController::class, 'clearPin'])->name('employees.pin.clear');
            Route::post('employees/{employee}/photo', [AdminEmployeeController::class, 'uploadPhoto'])->name('employees.photo');

            // ---- Console accounts (owner only) -------------------------
            Route::get('users', [AdminUserController::class, 'index'])->name('users.index');
            Route::post('users', [AdminUserController::class, 'store'])->name('users.store');
            Route::patch('users/{user}', [AdminUserController::class, 'update'])->name('users.update');
            Route::post('users/{user}/deactivate', [AdminUserController::class, 'deactivate'])->name('users.deactivate');
            Route::post('users/{user}/activate', [AdminUserController::class, 'activate'])->name('users.activate');
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
