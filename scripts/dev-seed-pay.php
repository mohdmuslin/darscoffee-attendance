<?php

/*
 * Local development helper: seed pay rates, a period with a locked figure, and an adjustment.
 *
 *   php scripts/dev-seed-pay.php [EMPLOYEE_CODE]
 *
 * Every basis is represented, plus an employee with NO rate so the "unpriced" warning has
 * something to show, plus a mid-month raise so the "rate changed in this period" warning is
 * visible. Then a pay period that is locked, so the lock and its drift state can be exercised.
 *
 * Not part of the app.
 */

require __DIR__.'/dev-bootstrap.php';

use App\Enums\TimeEntryStatus;
use App\Enums\TimeEntryType;
use App\Models\CompensationRule;
use App\Models\Employee;
use App\Models\Outlet;
use App\Models\PayPeriod;
use App\Models\RateAdjustment;
use App\Models\Shift;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\CorrectionService;
use App\Services\PayPeriodService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

$code = $argv[1] ?? 'DEV-47D7';

$outlet = Outlet::where('code', 'DARS-COFFEE')->firstOrFail();
$owner = User::where('role', 'owner')->firstOrFail();
$timezone = $outlet->timezone;

$employee = Employee::where('employee_code', $code)->first();

if ($employee === null) {
    exit("No employee {$code}. Create one with dev-make-employee.php".PHP_EOL);
}

$today = CarbonImmutable::now($timezone)->startOfDay();

// This month so far, which is what the pay screen opens on.
$monthStart = $today->startOfMonth();
$lastMonthStart = $monthStart->subMonth();

echo "Seeding pay data for {$employee->name}".PHP_EOL;

// Clear this employee's pay history so the seed is reproducible.
CompensationRule::where('employee_id', $employee->id)->delete();
RateAdjustment::where('employee_id', $employee->id)->delete();
PayPeriod::where('name', 'like', '%2026%')->delete();
TimeEntry::where('employee_id', $employee->id)->where('started_at', '>=', $lastMonthStart)->delete();
Shift::where('employee_id', $employee->id)->where('starts_at', '>=', $lastMonthStart)->delete();

/** Record a work segment in local hours. */
$work = function (CarbonImmutable $day, string $start, string $end) use ($employee, $outlet, $timezone) {
    $startedAt = CarbonImmutable::parse($day->toDateString().' '.$start, $timezone);

    return TimeEntry::create([
        'client_uuid' => (string) Str::uuid(),
        'employee_id' => $employee->id,
        'outlet_id' => $outlet->id,
        'type' => TimeEntryType::WORK,
        'started_at' => $startedAt,
        'ended_at' => CarbonImmutable::parse($day->toDateString().' '.$end, $timezone),
        'business_date' => $startedAt->toDateString(),
        'status' => TimeEntryStatus::CLOSED,
    ]);
};

$rate = function (string $basis, float $amount, string $from, ?float $ot = null, ?string $to = null) use ($employee, $owner) {
    return CompensationRule::create([
        'employee_id' => $employee->id,
        'basis' => $basis,
        'rate' => $amount,
        'overtime_rate' => $ot,
        'effective_from' => $from,
        'effective_to' => $to,
        'note' => 'Seeded for development.',
        'created_by' => $owner->id,
    ]);
};

// Last month: at an older rate, so the history has more than one row.
$rate('hourly', 10.00, $lastMonthStart->subYear()->toDateString(), 15.00, $lastMonthStart->subDay()->toDateString());
echo '  last month rate: 10.00/hour (closed)'.PHP_EOL;

// This month: a raise mid-month, so the "rate changed in this period" warning shows.
$rate('hourly', 11.00, $lastMonthStart->toDateString(), 16.00, $monthStart->addDays(9)->toDateString());
$rate('hourly', 12.50, $monthStart->addDays(10)->toDateString(), 18.00, null);
echo '  this month: 11.00 then 12.50 from day '.($monthStart->addDays(10)->day).' (mid-month raise)'.PHP_EOL;

// Some hours this month, including a long day so overtime is priced.
$work($monthStart, '09:00', '17:00');
$work($monthStart->addDays(1), '09:00', '17:00');
// A ten-hour day: 8 ordinary, 2 overtime.
$work($monthStart->addDays(2), '09:00', '19:00');
echo '  3 days worked this month, one of them 10h (overtime)'.PHP_EOL;

// An adhoc overtime adjustment for a specific day.
RateAdjustment::create([
    'employee_id' => $employee->id,
    'applies_to_date' => $monthStart->addDays(2)->toDateString(),
    'applies_to_period' => 'day',
    'applies_to' => 'overtime',
    'rate' => 25.00,
    'reason' => 'Agreed double time for the stock take.',
    'created_by' => $owner->id,
    'approved_by' => $owner->id,
    'approved_at' => now(),
]);
echo '  adhoc overtime adjustment: 25.00/hour for '.$monthStart->addDays(2)->toDateString().PHP_EOL;

// A second employee with hours but NO rate, so the unpriced warning has something to show.
$unpriced = Employee::where('employee_code', 'SMOKE-1')->first();

if ($unpriced === null) {
    $unpriced = Employee::create([
        'employee_code' => 'SMOKE-1',
        'name' => 'No Rate Tester',
        'is_active' => true,
    ]);
    $unpriced->outlets()->attach($outlet->id);
}

CompensationRule::where('employee_id', $unpriced->id)->delete();
TimeEntry::where('employee_id', $unpriced->id)->where('started_at', '>=', $lastMonthStart)->delete();

$startedAt = CarbonImmutable::parse($monthStart->addDays(3)->toDateString().' 09:00', $timezone);

TimeEntry::create([
    'client_uuid' => (string) Str::uuid(),
    'employee_id' => $unpriced->id,
    'outlet_id' => $outlet->id,
    'type' => TimeEntryType::WORK,
    'started_at' => $startedAt,
    'ended_at' => $startedAt->addHours(6),
    'business_date' => $startedAt->toDateString(),
    'status' => TimeEntryStatus::CLOSED,
]);
echo '  one employee with hours and NO rate (unpriced warning)'.PHP_EOL;

// Last month's period, LOCKED, so the lock and its committed figure can be seen.
//
// A day of work is recorded FIRST, so the locked period contains something real. Locking an
// empty period would prove the mechanism exists while showing nothing about it.
$work($lastMonthStart->addDays(5), '09:00', '17:00');
$work($lastMonthStart->addDays(6), '09:00', '17:00');
echo '  last month: 2 days worked'.PHP_EOL;

$periods = app(PayPeriodService::class);

$lastMonthPeriod = $periods->create(
    $lastMonthStart->format('F Y'),
    $lastMonthStart->toDateString(),
    $lastMonthStart->endOfMonth()->toDateString(),
    $owner,
);

$periods->lock($lastMonthPeriod, Employee::query()->get(), $owner);
echo '  locked period: '.$lastMonthPeriod->name.' (committed '
    .number_format((float) $lastMonthPeriod->fresh()->snapshot['totals']['total_amount'], 2).')'.PHP_EOL;

/*
 * Now a correction lands on the LOCKED period, so the drift state is visible rather than
 * theoretical. This is the case the whole design exists for: a figure was committed, and the
 * data underneath it has since changed.
 */
$lockedEntry = TimeEntry::where('employee_id', $employee->id)
    ->whereDate('business_date', $lastMonthStart->addDays(6)->toDateString())
    ->first();

if ($lockedEntry !== null && $owner !== null) {
    app(CorrectionService::class)->request(
        $lockedEntry,
        ['ended_at' => CarbonImmutable::parse($lastMonthStart->addDays(6)->toDateString().' 13:00', $timezone)->toIso8601String()],
        'Left early; confirmed with the outlet manager.',
        $owner,
    );

    echo '  correction applied to the LOCKED period -> drift should be detected'.PHP_EOL;
}

// This month's period, left OPEN, so both states are visible side by side.
$periods->create(
    $monthStart->format('F Y'),
    $monthStart->toDateString(),
    $monthStart->endOfMonth()->toDateString(),
    $owner,
);
echo '  open period: '.$monthStart->format('F Y').PHP_EOL;

echo PHP_EOL.'Done. Open the console at /console/pay.'.PHP_EOL;
