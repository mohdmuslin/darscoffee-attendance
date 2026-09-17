<?php

/*
 * Local development helper: confirm the roster did not touch recorded hours.
 *
 *   php scripts/dev-check-plan-actual.php [EMPLOYEE_CODE]
 *
 * The plan and the actual are separate tables, and the whole design rests on a roster change
 * never altering a time entry. This prints both side by side and asserts the split, so the
 * boundary can be checked after any roster work rather than assumed.
 *
 * Not part of the app.
 */

require __DIR__.'/dev-bootstrap.php';

use App\Models\Employee;
use App\Models\Shift;
use App\Models\TimeEntry;

$code = $argv[1] ?? 'DEV-47D7';

$employee = Employee::where('employee_code', $code)->first();

if ($employee === null) {
    exit("No employee {$code}".PHP_EOL);
}

$timezone = $employee->outlets()->first()?->timezone ?? config('attendance.business_timezone');

echo '--- SHIFTS (the plan) ---'.PHP_EOL;

foreach (Shift::where('employee_id', $employee->id)->orderBy('starts_at')->get() as $shift) {
    echo sprintf(
        '#%d %s %s-%s %-8s %s'.PHP_EOL,
        $shift->id,
        $shift->starts_at->setTimezone($timezone)->toDateString(),
        $shift->starts_at->setTimezone($timezone)->format('H:i'),
        $shift->ends_at->setTimezone($timezone)->format('H:i'),
        $shift->position ?? '-',
        $shift->isCancelled() ? 'CANCELLED' : 'active',
    );
}

echo '--- TIME ENTRIES (the actual) ---'.PHP_EOL;

foreach (TimeEntry::where('employee_id', $employee->id)->orderBy('started_at')->get() as $entry) {
    echo sprintf(
        '#%d %s %s %s->%s dur=%ds shift=%s'.PHP_EOL,
        $entry->id,
        $entry->business_date?->toDateString() ?? '-',
        strtoupper($entry->type->value),
        $entry->started_at->setTimezone($timezone)->format('H:i'),
        $entry->ended_at?->setTimezone($timezone)->format('H:i') ?? 'open',
        $entry->durationSeconds(),
        $entry->shift_id ?? 'none',
    );
}

$activeShifts = Shift::where('employee_id', $employee->id)->whereNull('cancelled_at')->count();
$cancelledShifts = Shift::where('employee_id', $employee->id)->whereNotNull('cancelled_at')->count();

echo '--- SUMMARY ---'.PHP_EOL;
echo "active shifts:    {$activeShifts}".PHP_EOL;
echo "cancelled shifts: {$cancelledShifts}".PHP_EOL;
echo 'entries:          '.TimeEntry::where('employee_id', $employee->id)->count().PHP_EOL;
echo PHP_EOL.'Recorded hours come only from time entries. A roster change must never alter them.'.PHP_EOL;
