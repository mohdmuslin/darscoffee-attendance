<?php

/*
 * Local development helper: seed a roster for this week and next.
 *
 *   php scripts/dev-seed-roster.php [EMPLOYEE_CODE]
 *
 * A roster is the plan, and the reports read planned-versus-actual — so seeding one lets the
 * roster screen be exercised without hand-entering a week of shifts.
 *
 * Deliberately includes a day with NOBODY rostered and a cancelled shift, because those are
 * the two states a manager most needs to see and the easiest to leave untested.
 *
 * Not part of the app.
 */

require __DIR__.'/dev-bootstrap.php';

use App\Models\Employee;
use App\Models\Outlet;
use App\Models\Shift;
use App\Models\User;
use Carbon\CarbonImmutable;

$code = $argv[1] ?? 'DEV-47D7';

$employee = Employee::where('employee_code', $code)->first();

if ($employee === null) {
    exit("No employee {$code}. Create one with dev-make-employee.php".PHP_EOL);
}

$outlet = Outlet::where('code', 'DARS-COFFEE')->firstOrFail();
$manager = User::where('role', 'manager')->first() ?? User::where('role', 'owner')->firstOrFail();
$timezone = $outlet->timezone;

// Clear existing future rosters so the seed is reproducible rather than additive.
Shift::where('outlet_id', $outlet->id)->where('starts_at', '>=', CarbonImmutable::now($timezone)->startOfDay())->delete();

$weekStart = CarbonImmutable::now($timezone)->startOfWeek(CarbonImmutable::MONDAY);

/**
 * Roster a shift in the outlet's local time.
 *
 * Local, not UTC: writing 09:00 as UTC would file every shift eight hours out, and the
 * lateness figures computed against it would be wrong in a way nobody would spot.
 */
$roster = function (string $date, string $start, string $end, ?string $position = null) use ($employee, $outlet, $manager, $timezone) {
    return Shift::create([
        'employee_id' => $employee->id,
        'outlet_id' => $outlet->id,
        'starts_at' => CarbonImmutable::parse($date.' '.$start, $timezone),
        'ends_at' => CarbonImmutable::parse($date.' '.$end, $timezone),
        'position' => $position,
        'created_by' => $manager->id,
    ]);
};

$day = fn (int $offset) => $weekStart->addDays($offset)->toDateString();

echo "Seeding a roster for {$employee->name} at {$outlet->name}".PHP_EOL;
echo "Week of {$weekStart->toDateString()}".PHP_EOL;

// This week: a normal roster, with Monday already worked and Friday left open on purpose.
$roster($day(0), '09:00', '17:00', 'Front');
echo '  '.$day(0).'  09:00-17:00  Front'.PHP_EOL;

$roster($day(1), '09:00', '17:00', 'Front');
echo '  '.$day(1).'  09:00-17:00  Front'.PHP_EOL;

$roster($day(2), '12:00', '20:00', 'Kitchen');
echo '  '.$day(2).'  12:00-20:00  Kitchen (late shift)'.PHP_EOL;

// Thursday: deliberately nobody rostered, so the "no cover" warning has something to show.
echo '  '.$day(3).'  (nobody rostered — cover gap)'.PHP_EOL;

$cancelled = $roster($day(4), '09:00', '17:00', 'Front');
$cancelled->cancel();
echo '  '.$day(4).'  09:00-17:00  CANCELLED'.PHP_EOL;

// Saturday: a split shift, which is the case the overlap rule has to allow on request.
$roster($day(5), '08:00', '12:00', 'Front');
$roster($day(5), '17:00', '21:00', 'Front');
echo '  '.$day(5).'  08:00-12:00 + 17:00-21:00  split shift'.PHP_EOL;

// Next week, so "copy forward" has something to work with and the future is not empty.
$nextWeek = $weekStart->addDays(7);

$roster($nextWeek->toDateString(), '09:00', '17:00', 'Front');
$roster($nextWeek->addDays(1)->toDateString(), '09:00', '17:00', 'Front');
$roster($nextWeek->addDays(2)->toDateString(), '09:00', '17:00', 'Front');
echo '  next week: 3 shifts rostered'.PHP_EOL;

echo PHP_EOL.'Done. Open the console at /console/roster.'.PHP_EOL;
