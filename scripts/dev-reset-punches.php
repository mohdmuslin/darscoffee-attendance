<?php

/*
 * Local development helper: clear an employee's punches and optionally set an outlet's
 * photo requirement.
 *
 *   php scripts/dev-reset-punches.php EMPLOYEE_CODE [requires_photo:true|false]
 *
 * The punch flow is stateful — an open segment changes what the screen offers — so
 * reproducing a scenario by hand needs a clean starting point. Deleting is used rather
 * than clocking out because a leftover open segment is exactly what gets in the way.
 *
 * Not part of the app.
 */

require __DIR__.'/dev-bootstrap.php';

use App\Models\Employee;
use App\Models\Outlet;
use App\Models\PunchSession;

$code = $argv[1] ?? null;

if ($code === null) {
    exit('Usage: php scripts/dev-reset-punches.php EMPLOYEE_CODE [requires_photo:true|false]'.PHP_EOL);
}

$employee = Employee::where('employee_code', $code)->firstOrFail();

$deleted = $employee->timeEntries()->delete();

// Sessions are deleted directly: the employee model does not expose a relation for them,
// and a stale session would let the browser resume into a state that no longer exists.
$sessions = PunchSession::where('employee_id', $employee->id)->delete();

echo "Deleted {$deleted} entr(ies) and {$sessions} session(s) for {$code}".PHP_EOL;

if (isset($argv[2])) {
    [$flag, $value] = array_pad(explode(':', $argv[2], 2), 2, null);

    if ($flag === 'requires_photo' && $value !== null) {
        $on = filter_var($value, FILTER_VALIDATE_BOOLEAN);

        foreach (Outlet::all() as $outlet) {
            $outlet->update(['requires_photo' => $on]);
        }

        echo 'All outlets now require_photo='.($on ? 'yes' : 'no').PHP_EOL;
    }
}
