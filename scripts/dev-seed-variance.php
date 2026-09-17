<?php

/*
 * Local development helper: seed a week with deliberate variance.
 *
 *   php scripts/dev-seed-variance.php [EMPLOYEE_CODE]
 *
 * Every kind of variance is represented on purpose: a day to plan, a shift worked short, a
 * shift worked long, a no-show, adhoc work with no roster, and a cancelled shift that was
 * nevertheless worked. The last is the case most worth eyeballing, because it is how an outlet
 * quietly opens on a day it said it was closed.
 *
 * Not part of the app.
 */

require __DIR__.'/dev-bootstrap.php';

use App\Enums\TimeEntryStatus;
use App\Enums\TimeEntryType;
use App\Models\Employee;
use App\Models\Outlet;
use App\Models\Shift;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

$code = $argv[1] ?? 'DEV-47D7';

$employee = Employee::where('employee_code', $code)->first();

if ($employee === null) {
    exit("No employee {$code}. Create one with dev-make-employee.php".PHP_EOL);
}

$outlet = Outlet::where('code', 'DARS-COFFEE')->firstOrFail();
$creator = User::where('role', 'manager')->first() ?? User::where('role', 'owner')->firstOrFail();
$timezone = $outlet->timezone;

$today = CarbonImmutable::now($timezone)->startOfDay();

// Clear the last fortnight so the seed is reproducible rather than additive.
$from = $today->subDays(14);

Shift::where('employee_id', $employee->id)->where('starts_at', '>=', $from)->delete();
TimeEntry::where('employee_id', $employee->id)->where('started_at', '>=', $from)->delete();

/** Roster a shift in local wall-clock, as a manager would. */
$plan = function (CarbonImmutable $day, string $start, string $end, ?string $position = null) use ($employee, $outlet, $creator, $timezone) {
    return Shift::create([
        'employee_id' => $employee->id,
        'outlet_id' => $outlet->id,
        'starts_at' => CarbonImmutable::parse($day->toDateString().' '.$start, $timezone),
        'ends_at' => CarbonImmutable::parse($day->toDateString().' '.$end, $timezone),
        'position' => $position,
        'created_by' => $creator->id,
    ]);
};

/** Record a punch, also in local wall-clock. */
$work = function (CarbonImmutable $day, string $start, string $end) use ($employee, $outlet, $timezone) {
    $started = CarbonImmutable::parse($day->toDateString().' '.$start, $timezone);

    return TimeEntry::create([
        'client_uuid' => (string) Str::uuid(),
        'employee_id' => $employee->id,
        'outlet_id' => $outlet->id,
        'type' => TimeEntryType::WORK,
        'started_at' => $started,
        'ended_at' => CarbonImmutable::parse($day->toDateString().' '.$end, $timezone),
        'business_date' => $started->toDateString(),
        'status' => TimeEntryStatus::CLOSED,
    ]);
};

echo "Seeding a week of variance for {$employee->name}".PHP_EOL;

// Day -6: to plan. The control, so the report has something that is NOT a variance.
$d = $today->subDays(6);
$plan($d, '09:00', '17:00', 'Front');
$work($d, '09:00', '17:00');
echo '  '.$d->toDateString().'  to plan (8h rostered, 8h worked)'.PHP_EOL;

// Day -5: worked short. Rostered 8h, worked 5h — an early finish nobody recorded as a change.
$d = $today->subDays(5);
$plan($d, '09:00', '17:00', 'Front');
$work($d, '09:00', '14:00');
echo '  '.$d->toDateString().'  worked short (8h rostered, 5h worked)'.PHP_EOL;

// Day -4: worked long. Rostered 8h, worked 11h — unagreed overtime.
$d = $today->subDays(4);
$plan($d, '09:00', '17:00', 'Kitchen');
$work($d, '09:00', '20:00');
echo '  '.$d->toDateString().'  worked long (8h rostered, 11h worked)'.PHP_EOL;

// Day -3: no show. Rostered, nothing punched at all.
$d = $today->subDays(3);
$plan($d, '09:00', '17:00', 'Front');
echo '  '.$d->toDateString().'  NO SHOW (8h rostered, nothing punched)'.PHP_EOL;

// Day -2: adhoc. Worked with no roster — the "we also have adhoc tasks" case.
$d = $today->subDays(2);
$work($d, '10:00', '15:00');
echo '  '.$d->toDateString().'  ADHOC (nothing rostered, 5h worked)'.PHP_EOL;

// Day -1: cancelled, then worked anyway. The case most worth eyeballing.
$d = $today->subDays(1);
$cancelled = $plan($d, '09:00', '17:00', 'Front');
$cancelled->cancel();
$work($d, '09:00', '13:00');
echo '  '.$d->toDateString().'  CANCELLED then worked 4h -> shows as unplanned'.PHP_EOL;

// Today: rostered and on shift now, with an open punch. Must read as in progress, not short.
//
// The shift is built around the CURRENT time rather than a fixed 08:00-20:00. A fixed window
// is in the future whenever the seed is run before it, which produced a punch that had not
// started yet and a negative "worked" figure.
$now = CarbonImmutable::now($timezone);

$todayStart = $now->subHours(3);
$todayEnd = $now->addHours(5);

$plan($today, $todayStart->format('H:i'), $todayEnd->format('H:i'), 'Front');
$work($today, $todayStart->format('H:i'), $now->format('H:i'));

// Left open, so the report has to treat it as covering the shift while the employee is still
// standing there working it.
TimeEntry::where('employee_id', $employee->id)
    ->whereDate('business_date', $today->toDateString())
    ->latest('id')
    ->first()
    ?->forceFill(['ended_at' => null, 'status' => TimeEntryStatus::OPEN])
    ->save();

echo '  '.$today->toDateString().'  ON SHIFT ('.$todayStart->format('H:i').'-'.$todayEnd->format('H:i').', open punch -> in progress)'.PHP_EOL;

// Tomorrow: rostered and not started. Must not read as a no-show.
$plan($today->addDay(), '09:00', '17:00', 'Front');
echo '  '.$today->addDay()->toDateString().'  STILL TO COME (must not read as a no-show)'.PHP_EOL;

echo PHP_EOL.'Done. Open the console at /console/variance.'.PHP_EOL;
