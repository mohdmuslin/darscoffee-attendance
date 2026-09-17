<?php

/*
 * Local development helper: seed a week of realistic punches so the Phase 3 screens can
 * be exercised against plausible data.
 *
 *   php scripts/dev-seed-timesheet.php [EMPLOYEE_CODE]
 *
 * Why not just use the punch flow: the reports are about patterns across days, and driving
 * a week of clock-ins by hand would take far longer than it takes to write them down. The
 * data includes an open segment, a missed clock-out, an adhoc day and a late arrival, so
 * every badge on the timesheet has something to show.
 *
 * Not part of the app.
 */

require __DIR__.'/dev-bootstrap.php';

use App\Enums\AnomalyType;
use App\Enums\PunchEventType;
use App\Enums\TimeEntryStatus;
use App\Enums\TimeEntryType;
use App\Models\Anomaly;
use App\Models\AttendanceCorrection;
use App\Models\Employee;
use App\Models\Outlet;
use App\Models\PunchEvent;
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
$manager = User::where('role', 'manager')->first();

$timezone = $outlet->timezone;

// Clear this employee's history so the seed is reproducible rather than additive.
$employee->timeEntries()->delete();
$employee->shifts()->delete();

// Anomalies point at entries, so they cascade away with them; punch events do not, and
// would otherwise accumulate a set per run and make the trail confusing to read.
PunchEvent::where('employee_id', $employee->id)->delete();
AttendanceCorrection::where('employee_id', $employee->id)->delete();

/**
 * Write one segment.
 *
 * Times are given in the OUTLET's local zone and converted to UTC, exactly as the punch
 * flow does — writing them as UTC would file every evening shift on the wrong day.
 */
$write = function (string $date, string $start, ?string $end, TimeEntryType $type, ?int $shiftId = null) use ($employee, $outlet, $timezone) {
    $started = CarbonImmutable::parse($date.' '.$start, $timezone);

    return TimeEntry::create([
        'client_uuid' => (string) Str::uuid(),
        'employee_id' => $employee->id,
        'outlet_id' => $outlet->id,
        'shift_id' => $shiftId,
        'type' => $type,
        'started_at' => $started,
        'ended_at' => $end === null ? null : CarbonImmutable::parse($date.' '.$end, $timezone),
        'business_date' => $started->toDateString(),
        'status' => $end === null ? TimeEntryStatus::OPEN : TimeEntryStatus::CLOSED,
    ]);
};

$roster = function (string $date, string $start, string $end) use ($employee, $outlet, $manager, $timezone) {
    return Shift::create([
        'employee_id' => $employee->id,
        'outlet_id' => $outlet->id,
        'starts_at' => CarbonImmutable::parse($date.' '.$start, $timezone),
        'ends_at' => CarbonImmutable::parse($date.' '.$end, $timezone),
        'created_by' => $manager?->id ?? User::factory()->create()->id,
    ])->id;
};

// Six days back from today, so the window lands inside a default "last 7 days" view.
$today = CarbonImmutable::now($timezone);

$date = fn (int $daysAgo) => $today->subDays($daysAgo)->toDateString();

echo "Seeding a week for {$employee->name} ({$code}) at {$outlet->name}".PHP_EOL;

// Day 1: a normal, complete shift against a roster.
$shift1 = $roster($date(5), '09:00', '17:00');
$write($date(5), '09:00', '12:00', TimeEntryType::WORK, $shift1);
$write($date(5), '12:00', '13:00', TimeEntryType::BREAK, $shift1);
$write($date(5), '13:00', '17:00', TimeEntryType::WORK, $shift1);
echo '  '.$date(5).'  complete 8h shift, on time'.PHP_EOL;

// Day 2: a long day that crosses the 8-hour overtime threshold.
$shift2 = $roster($date(4), '09:00', '19:00');
$write($date(4), '09:00', '13:00', TimeEntryType::WORK, $shift2);
$write($date(4), '13:00', '14:00', TimeEntryType::BREAK, $shift2);
$write($date(4), '14:00', '19:00', TimeEntryType::WORK, $shift2);
echo '  '.$date(4).'  long day with overtime'.PHP_EOL;

// Day 3: a late arrival, well past the 5-minute grace window.
$shift3 = $roster($date(3), '09:00', '17:00');
$write($date(3), '09:26', '17:00', TimeEntryType::WORK, $shift3);
echo '  '.$date(3).'  late arrival (26 min)'.PHP_EOL;

// Day 4: adhoc work with no roster at all. Legitimate, so it must not read as an error.
$write($date(2), '10:00', '15:00', TimeEntryType::WORK);
echo '  '.$date(2).'  adhoc work, no roster'.PHP_EOL;

// Day 5: an early departure against the roster.
$shift5 = $roster($date(1), '09:00', '17:00');
$write($date(1), '09:00', '16:15', TimeEntryType::WORK, $shift5);
echo '  '.$date(1).'  left 45 min early'.PHP_EOL;

// Day 6 (today): still open, so the day reads as incomplete and the total is not final.
//
// Started relative to NOW, not at a fixed 09:05. A fixed wall-clock time is in the future
// whenever the seed is run before it, which produced an open segment that had not started
// yet — and then every correction against it ended up "before it starts".
$startedRecently = CarbonImmutable::now($timezone)->subHours(2);

$write($date(0), $startedRecently->format('H:i'), null, TimeEntryType::WORK);
echo '  '.$date(0).'  ON SHIFT — open segment, day incomplete'.PHP_EOL;

// A rostered day nobody punched: the no-show row.
$roster($date(6), '09:00', '17:00');
echo '  '.$date(6).'  rostered, no punches -> no-show'.PHP_EOL;

/*
 * Anomalies and a punch trail.
 *
 * Written directly because the seeded segments above bypass PunchService — and without
 * them the review queue and the audit screen have nothing to show, so those screens could
 * not be verified at all. The mix is deliberate: one of each severity, plus a failed PIN,
 * which is the row that answers "I clocked in and it says I didn't".
 */
$entries = TimeEntry::where('employee_id', $employee->id)->orderBy('id')->get();

foreach ($entries as $index => $entry) {
    PunchEvent::record(
        PunchEventType::forAction($entry->type === TimeEntryType::WORK ? 'clock_in' : 'start_break'),
        employeeId: $employee->id,
        outletId: $outlet->id,
        timeEntryId: $entry->id,
        ipAddress: '203.0.113.'.(10 + $index),
        meta: ['seeded' => true],
    );
}

// A failed PIN: no employee id, which is exactly the point — identity is what is unknown.
PunchEvent::record(
    PunchEventType::PIN_FAILED,
    outletId: $outlet->id,
    ipAddress: '203.0.113.99',
    meta: ['candidates' => 3, 'seeded' => true],
);

// One flag per severity, tied to real entries so the scoping has something to filter.
$flagged = $entries->values();

Anomaly::raise($flagged[0], AnomalyType::NO_PHOTO, 'No photo captured on clock-in, but this outlet requires one.');
Anomaly::raise($flagged[1], AnomalyType::LONG_SPAN, 'Shift ran unusually long.');
Anomaly::raise($flagged[2], AnomalyType::OUTSIDE_SHIFT, 'Clocked in with no scheduled shift within 2 hours.');

echo '  seeded '.$entries->count().' punch events (incl. one failed PIN) and 3 anomaly flags'.PHP_EOL;

echo PHP_EOL.'Done. Open the console at /console/timesheets.'.PHP_EOL;
