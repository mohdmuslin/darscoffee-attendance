<?php

use App\Http\Controllers\Api\V1\Admin\AdminAnomalyController;
use App\Http\Controllers\Api\V1\Admin\AdminCorrectionController;
use App\Http\Controllers\Api\V1\Admin\AdminEmployeeController;
use App\Http\Controllers\Api\V1\Admin\AdminOutletController;
use App\Http\Controllers\Api\V1\Admin\AdminPayController;
use App\Http\Controllers\Api\V1\Admin\AdminRateController;
use App\Http\Controllers\Api\V1\Admin\AdminShiftController;
use App\Http\Controllers\Api\V1\Admin\AdminTimesheetController;
use App\Http\Controllers\Api\V1\Admin\AdminUserController;
use App\Http\Controllers\Api\V1\Admin\AdminVarianceController;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\PhotoController;
use App\Http\Controllers\Api\V1\Punch\PunchController;
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
            /*
             * `{employee}` is constrained to digits throughout, so a word can never be
             * parsed as an employee id. Without it, a mistyped or hand-crafted URL like
             * `/employees/consent` matches `employees/{employee}` and fails as a missing
             * model — which reads as a broken endpoint rather than as a bad id, and makes
             * route ordering something future edits have to keep getting right.
             */
            Route::get('employees', [AdminEmployeeController::class, 'index'])->name('employees.index');
            Route::post('employees', [AdminEmployeeController::class, 'store'])->name('employees.store');
            Route::get('employees/{employee}', [AdminEmployeeController::class, 'show'])->whereNumber('employee')->name('employees.show');
            Route::patch('employees/{employee}', [AdminEmployeeController::class, 'update'])->whereNumber('employee')->name('employees.update');

            /*
             * Deactivate rather than delete: history must survive someone leaving,
             * and a manager deleting staff ahead of a dispute is not a power worth
             * granting. There is deliberately no destroy route.
             */
            Route::post('employees/{employee}/deactivate', [AdminEmployeeController::class, 'deactivate'])->whereNumber('employee')->name('employees.deactivate');
            Route::post('employees/{employee}/activate', [AdminEmployeeController::class, 'activate'])->whereNumber('employee')->name('employees.activate');

            Route::put('employees/{employee}/pin', [AdminEmployeeController::class, 'setPin'])->whereNumber('employee')->name('employees.pin.set');
            Route::delete('employees/{employee}/pin', [AdminEmployeeController::class, 'clearPin'])->whereNumber('employee')->name('employees.pin.clear');
            Route::post('employees/{employee}/photo', [AdminEmployeeController::class, 'uploadPhoto'])->whereNumber('employee')->name('employees.photo');

            // ---- PDPA consent -----------------------------------------
            Route::get('employees/consent/backlog', [AdminEmployeeController::class, 'consentBacklog'])->name('employees.consent.backlog');
            Route::get('employees/consent/methods', [AdminEmployeeController::class, 'consentMethods'])->name('employees.consent.methods');
            Route::post('employees/{employee}/consent', [AdminEmployeeController::class, 'recordConsent'])->whereNumber('employee')->name('employees.consent.record');
            Route::delete('employees/{employee}/consent', [AdminEmployeeController::class, 'withdrawConsent'])->whereNumber('employee')->name('employees.consent.withdraw');

            // ---- Console accounts (owner only) -------------------------
            Route::get('users', [AdminUserController::class, 'index'])->name('users.index');
            Route::post('users', [AdminUserController::class, 'store'])->name('users.store');
            Route::patch('users/{user}', [AdminUserController::class, 'update'])->name('users.update');
            Route::post('users/{user}/deactivate', [AdminUserController::class, 'deactivate'])->name('users.deactivate');
            Route::post('users/{user}/activate', [AdminUserController::class, 'activate'])->name('users.activate');

            /*
             * ---- Pay rates --------------------------------------------
             *
             * A manager may set rates for their own staff with NO owner approval (blueprint
             * §10.2). What keeps that safe is not a permission but the record: every change
             * stores who made it, when, why, and what it was before.
             */
            Route::get('rates', [AdminRateController::class, 'index'])->name('rates.index');
            Route::post('rates/adjustments', [AdminRateController::class, 'storeAdjustment'])->name('rates.adjustments.store');
            Route::get('rates/employees/{employee}', [AdminRateController::class, 'history'])->name('rates.history');
            Route::post('rates/employees/{employee}', [AdminRateController::class, 'store'])->name('rates.store');
            Route::post('rates/adjustments/{adjustment}/approve', [AdminRateController::class, 'approveAdjustment'])->name('rates.adjustments.approve');

            /*
             * ---- Pay and pay periods ----------------------------------
             *
             * The lock is what turns a computed figure into a committed one: without it, "last
             * month's total" changes every time somebody fixes a forgotten clock-out, and a
             * payslip already handed out stops matching the system.
             *
             * It is a DETECTABLE freeze rather than a hard one. Corrections stay possible, and
             * the drift is reported instead of being prevented.
             */
            Route::get('pay/summary', [AdminPayController::class, 'summary'])->name('pay.summary');
            // Declared before the {employee} route so "export" is not read as an id.
            Route::get('pay/export', [AdminPayController::class, 'export'])->name('pay.export');
            Route::get('pay/employees/{employee}', [AdminPayController::class, 'employee'])->name('pay.employee');

            Route::get('pay-periods', [AdminPayController::class, 'periods'])->name('pay-periods.index');
            Route::post('pay-periods', [AdminPayController::class, 'storePeriod'])->name('pay-periods.store');
            // Locking commits figures, so it is owner-only — a manager locking their own
            // outlet's payroll is not a power worth handing out.
            Route::post('pay-periods/{period}/lock', [AdminPayController::class, 'lockPeriod'])->name('pay-periods.lock');
            Route::get('pay-periods/{period}/reconcile', [AdminPayController::class, 'reconcilePeriod'])->name('pay-periods.reconcile');

            /*
             * ---- Shifts (the roster) ----------------------------------
             *
             * The PLAN, kept apart from `time_entries`, which is the ACTUAL. Nothing here
             * writes an entry: a roster change must never alter recorded hours.
             *
             * There is deliberately no destroy route. Cancelling keeps the row, because a
             * shift that was rostered and then called off is what explains a no-show.
             */
            Route::get('shifts', [AdminShiftController::class, 'index'])->name('shifts.index');
            Route::get('shifts/current', [AdminShiftController::class, 'current'])->name('shifts.current');
            Route::post('shifts', [AdminShiftController::class, 'store'])->name('shifts.store');
            // Declared before the {shift} route so "copy" is not read as an id.
            Route::post('shifts/copy', [AdminShiftController::class, 'copy'])->name('shifts.copy');
            Route::get('shifts/{shift}', [AdminShiftController::class, 'show'])->name('shifts.show');
            Route::patch('shifts/{shift}', [AdminShiftController::class, 'update'])->name('shifts.update');
            Route::post('shifts/{shift}/cancel', [AdminShiftController::class, 'cancel'])->name('shifts.cancel');

            /*
             * ---- Planned versus actual --------------------------------
             *
             * Read only. A variance report that could alter a shift or a time entry would be
             * reporting on figures it had just changed.
             */
            Route::get('variance/summary', [AdminVarianceController::class, 'summary'])->name('variance.summary');
            // Declared before the {employee} route so "export" is not read as an id.
            Route::get('variance/export', [AdminVarianceController::class, 'export'])->name('variance.export');
            Route::get('variance/employees/{employee}', [AdminVarianceController::class, 'employee'])->name('variance.employee');

            /*
             * ---- Timesheets -------------------------------------------
             *
             * Read only, all of it. Time is changed through a correction and nothing
             * else, so there is deliberately no endpoint here that writes an entry —
             * a direct write would leave no record of who changed what, or why.
             */
            Route::get('timesheets/summary', [AdminTimesheetController::class, 'summary'])->name('timesheets.summary');
            Route::get('timesheets/entries', [AdminTimesheetController::class, 'entries'])->name('timesheets.entries');
            // Declared before the {employee} route so "export" is not read as an id.
            Route::get('timesheets/export', [AdminTimesheetController::class, 'export'])->name('timesheets.export');
            Route::get('timesheets/employees/{employee}', [AdminTimesheetController::class, 'employee'])->name('timesheets.employee');

            /*
             * ---- Corrections ------------------------------------------
             *
             * A manager may raise one for their own outlet; only an owner may approve.
             * That asymmetry is the control — see AdminCorrectionController.
             */
            Route::get('corrections', [AdminCorrectionController::class, 'index'])->name('corrections.index');
            Route::get('corrections/pending-count', [AdminCorrectionController::class, 'pendingCount'])->name('corrections.pending-count');
            Route::post('corrections/missing', [AdminCorrectionController::class, 'storeMissing'])->name('corrections.store-missing');
            Route::post('corrections/entries/{entry}', [AdminCorrectionController::class, 'store'])->name('corrections.store');
            Route::get('corrections/entries/{entry}/history', [AdminCorrectionController::class, 'history'])->name('corrections.history');
            Route::post('corrections/{correction}/approve', [AdminCorrectionController::class, 'approve'])->name('corrections.approve');
            Route::post('corrections/{correction}/reject', [AdminCorrectionController::class, 'reject'])->name('corrections.reject');

            /*
             * ---- Anomaly queue and the punch audit trail ---------------
             *
             * The compensating control for buddy punching: nothing odd passes silently.
             */
            Route::get('anomalies', [AdminAnomalyController::class, 'index'])->name('anomalies.index');
            Route::post('anomalies/{anomaly}/review', [AdminAnomalyController::class, 'review'])->name('anomalies.review');
            Route::get('punch-events', [AdminAnomalyController::class, 'events'])->name('punch-events.index');
        });
    });

    /*
     * The punch flow is public by design. It is authorised by an outlet code plus an
     * employee PIN, exchanged for a short-lived punch session.
     *
     * No login: kitchen crew have no account, and requiring one would mean they cannot
     * clock in at all. Rate-limited because the PIN is a short numeric secret.
     */
    Route::prefix('punch')->name('punch.')->group(function () {
        Route::post('start', [PunchController::class, 'start'])
            // 20/minute per IP. PIN lockout is per-employee and would not slow
            // someone walking through a list of outlet codes.
            ->middleware('throttle:20,1')
            ->name('start');

        // Everything below needs the session issued by /start.
        Route::get('state', [PunchController::class, 'state'])->name('state');
        Route::post('act', [PunchController::class, 'act'])->name('act');
        Route::get('hours', [PunchController::class, 'myHours'])->name('hours');
    });
});
