<?php

/*
 * Load check at three times expected volume.
 *
 *   php scripts/dev-load-check.php              # seed 3x volume and time the console queries
 *   php scripts/dev-load-check.php --scale=5    # a different multiple
 *   php scripts/dev-load-check.php --clean      # remove the seeded rows
 *
 * Not part of the app.
 *
 * WHY THIS EXISTS, AND WHAT IT IS HONESTLY FOR
 *
 * The expected scale is 3 outlets and about 30 staff — a few hundred punches a day. This
 * application is nowhere near a performance limit, and the purpose of this script is to
 * PROVE that rather than to discover a problem. Reporting a comfortable margin is a real
 * result: the alternative is carrying an unquantified worry about performance for a system
 * whose actual load is trivial.
 *
 * The queries timed are the ones that GROW — a period timesheet, variance, pay, and the
 * anomaly queue. Each is scoped by outlet and bounded by a date range, and that is what keeps
 * them from degrading as history accumulates: the console never offers an unbounded
 * "everything" view, because a query that is fast at one year is not fast at five.
 *
 * WHAT THESE NUMBERS DO NOT TELL YOU
 *
 * They measure the DATABASE on a development machine with rows written in bulk. They do not
 * measure HTTP and PHP overhead per request, concurrency, or cPanel's shared disk and CPU
 * limits — the last of which is the most likely source of a real surprise.
 */

require __DIR__.'/dev-bootstrap.php';

use App\Models\Employee;
use App\Models\Outlet;
use App\Models\TimeEntry;
use App\Services\PayService;
use App\Services\VarianceService;
use App\Services\WorkedHoursService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

$options = getopt('', ['scale::', 'clean', 'days::']);

$scale = (int) ($options['scale'] ?? 3);
$days = (int) ($options['days'] ?? 90);

if ($scale < 1) {
    exit('--scale must be at least 1'.PHP_EOL);
}

/** Time a closure, in milliseconds, running it a few times and taking the median. */
function timeIt(callable $fn, int $runs = 3): float
{
    $samples = [];

    for ($i = 0; $i < $runs; $i++) {
        $start = microtime(true);
        $fn();
        $samples[] = (microtime(true) - $start) * 1000;
    }

    sort($samples);

    return $samples[intdiv(count($samples), 2)];
}

$marker = 'LOADTEST';

/*
 * A short marker for the generated employee codes, because `employee_code` is VARCHAR(20) and
 * the readable prefix plus a full outlet code plus an index overflows it. Kept distinct from
 * `$marker` so the note-based cleanup can stay descriptive.
 */
$codePrefix = 'LT';

// ---- Clean ------------------------------------------------------------------

if (isset($options['clean'])) {
    $removed = TimeEntry::query()->where('note', 'like', $marker.'%')->delete();

    echo 'Removed '.$removed.' seeded entries.'.PHP_EOL;
    exit(0);
}

// ---- Seed -------------------------------------------------------------------

$outlets = Outlet::query()->get();

if ($outlets->isEmpty()) {
    exit('No outlets. Run the seeders first.'.PHP_EOL);
}

echo 'BEFORE'.PHP_EOL;
echo '  employees:    '.Employee::count().PHP_EOL;
echo '  time entries: '.TimeEntry::count().PHP_EOL;

/*
 * The realistic shape: 30 staff across 3 outlets is ~10 per outlet. Scaled, and each works
 * roughly 5 days in 7 — the same mix the reports are built around.
 *
 * Staff are created ONCE and reused across every run, so re-running with a larger --scale adds
 * volume rather than duplicating people. Names are prefixed so `--clean` can find its own
 * entries and nothing else.
 */
$perOutlet = max(2, (int) round(10 * $scale / 3));
$createdEmployees = 0;

foreach ($outlets as $outlet) {
    for ($i = 0; $i < $perOutlet; $i++) {
        $code = sprintf('%s-%s-%02d', $codePrefix, substr($outlet->code, 0, 8), $i);

        $employee = Employee::firstOrCreate(
            ['employee_code' => $code],
            ['name' => 'Load Test '.$outlet->code.' '.$i, 'is_active' => true],
        );

        if ($employee->wasRecentlyCreated) {
            $createdEmployees++;
            $employee->outlets()->syncWithoutDetaching([$outlet->id => ['is_primary' => true]]);
        }
    }
}

echo '  created '.$createdEmployees.' employee(s) this run'.PHP_EOL;

/*
 * Punches are written in bulk rather than one row at a time. A punch-per-insert loop for
 * ~40k rows would take minutes and would be measuring the seeding, not the queries.
 */
$rows = [];
$start = CarbonImmutable::now()->subDays($days)->startOfDay();

$staff = Employee::query()
    ->where('employee_code', 'like', $codePrefix.'-%')
    ->with('outlets')
    ->get();

foreach ($staff as $employee) {
    $outlet = $employee->outlets->first();

    if ($outlet === null) {
        continue;
    }

    for ($d = 0; $d < $days; $d++) {
        $day = $start->addDays($d);

        // Two days in seven off.
        if ($day->dayOfWeek === 0) {
            continue;
        }

        // A typical shift: 09:00–17:00 with a one-hour break, in the outlet's local day.
        $clockIn = $day->setTime(9, 0)->setTimezone('UTC');
        $breakStart = $day->setTime(13, 0)->setTimezone('UTC');
        $breakEnd = $day->setTime(14, 0)->setTimezone('UTC');
        $clockOut = $day->setTime(17, 0)->setTimezone('UTC');

        $segments = [
            ['work', $clockIn, $breakStart],
            ['break', $breakStart, $breakEnd],
            ['work', $breakEnd, $clockOut],
        ];

        foreach ($segments as [$type, $from, $to]) {
            $rows[] = [
                'client_uuid' => (string) Str::uuid(),
                'employee_id' => $employee->id,
                'outlet_id' => $outlet->id,
                'type' => $type,
                'started_at' => $from->format('Y-m-d H:i:s'),
                'ended_at' => $to->format('Y-m-d H:i:s'),
                'duration_seconds' => $from->diffInSeconds($to),
                'business_date' => $day->toDateString(),
                'status' => 'closed',
                'note' => $marker,
                'is_offline_sync' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
    }
}

foreach (array_chunk($rows, 500) as $chunk) {
    DB::table('time_entries')->insert($chunk);
}

echo 'AFTER'.PHP_EOL;
echo '  employees:    '.Employee::count().PHP_EOL;
echo '  time entries: '.TimeEntry::count().PHP_EOL;
echo '---'.PHP_EOL;

// ---- The queries the console actually runs ----------------------------------

$hours = app(WorkedHoursService::class);
$variance = app(VarianceService::class);
$pay = app(PayService::class);

$from = CarbonImmutable::now()->subDays(30)->toDateString();
$to = CarbonImmutable::now()->toDateString();

$sample = Employee::query()
    ->where('employee_code', 'like', $codePrefix.'-%')
    ->with('outlets')
    ->first();

$results = [];

$results['timesheet: one employee, 30 days'] = timeIt(
    fn () => $hours->forPeriod($sample, $from, $to)
);

$results['timesheet: an outlet, 30 days (all staff)'] = timeIt(function () use ($hours, $outlets, $from, $to, $codePrefix) {
    $outlet = $outlets->first();

    foreach (Employee::query()
        ->where('employee_code', 'like', $codePrefix.'-%')
        ->whereHas('outlets', fn ($q) => $q->where('outlets.id', $outlet->id))
        ->get() as $employee) {
        $hours->forPeriod($employee, $from, $to);
    }
});

$results['variance: an outlet, 30 days'] = timeIt(function () use ($variance, $outlets, $from, $to, $codePrefix) {
    $outlet = $outlets->first();

    $employees = Employee::query()
        ->where('employee_code', 'like', $codePrefix.'-%')
        ->whereHas('outlets', fn ($q) => $q->where('outlets.id', $outlet->id))
        ->get();

    $variance->summarise($employees, $from, $to, $outlet);
});

$results['pay: an outlet, 30 days'] = timeIt(function () use ($pay, $outlets, $from, $to, $codePrefix) {
    $outlet = $outlets->first();

    $employees = Employee::query()
        ->where('employee_code', 'like', $codePrefix.'-%')
        ->whereHas('outlets', fn ($q) => $q->where('outlets.id', $outlet->id))
        ->with('outlets')
        ->get();

    $pay->summarise($employees, $from, $to);
});

$results['anomaly queue: 100 rows'] = timeIt(
    fn () => DB::table('anomalies')
        ->join('time_entries', 'time_entries.id', '=', 'anomalies.time_entry_id')
        ->whereNull('anomalies.reviewed_at')
        ->orderByRaw("FIELD(anomalies.severity, 'high', 'warn', 'info')")
        ->orderByDesc('time_entries.started_at')
        ->limit(100)
        ->get()
);

$results['unreviewed anomaly count (dashboard badge)'] = timeIt(
    fn () => DB::table('anomalies')->whereNull('reviewed_at')->count()
);

echo 'TIMINGS (median of 3, milliseconds)'.PHP_EOL;

$worst = 0.0;

foreach ($results as $label => $ms) {
    $worst = max($worst, $ms);

    printf('  %-48s %7.1f ms%s', $label, $ms, PHP_EOL);
}

echo '---'.PHP_EOL;
printf('Slowest: %.1f ms at %dx volume.%s', $worst, $scale, PHP_EOL);

/*
 * A deliberately generous threshold. This is not a pass/fail benchmark — it is a tripwire for a
 * regression that turns a screen into a multi-second wait, which is the failure mode that would
 * actually be noticed by someone standing at a counter.
 */
if ($worst > 1000) {
    echo PHP_EOL.'WARNING: a query took over a second. Something is scanning without an index.'.PHP_EOL;
} else {
    echo PHP_EOL.'Comfortable margin. Nothing here is remotely near a limit.'.PHP_EOL;
}

echo PHP_EOL.'These are DATABASE timings on a development machine. They exclude HTTP and PHP'.PHP_EOL;
echo 'overhead, concurrency, and cPanel disk/CPU limits.'.PHP_EOL;
echo 'Clean up with: php scripts/dev-load-check.php --clean'.PHP_EOL;
