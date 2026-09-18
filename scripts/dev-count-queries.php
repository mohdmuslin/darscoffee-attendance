<?php

/*
 * Local development helper: count the queries one screen's worth of work issues.
 *
 *   php scripts/dev-count-queries.php [EMPLOYEE_CODE] [DAYS]
 *
 * Not part of the app. Written while chasing an N+1 found by the load check: a single employee's
 * 30-day timesheet was issuing 80 queries. Timings tell you something is slow; this tells you
 * WHY, which is the part that leads to a fix rather than a guess.
 *
 * Kept rather than thrown away because the same trap is easy to walk back into: any report that
 * loops over days and calls a per-day method will do this.
 */

require __DIR__.'/dev-bootstrap.php';

use App\Models\Employee;
use App\Services\WorkedHoursService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

$code = $argv[1] ?? null;
$days = (int) ($argv[2] ?? 30);

$query = Employee::query()->with('outlets')->orderBy('id');

if ($code !== null) {
    $query->where('employee_code', $code);
}

$employee = $query->first();

if ($employee === null) {
    exit('No matching employees. Seed some first.'.PHP_EOL);
}

$from = CarbonImmutable::now()->subDays($days)->toDateString();
$to = CarbonImmutable::now()->toDateString();

echo 'Employee: '.$employee->name.' ('.$employee->employee_code.')'.PHP_EOL;
echo 'Range:    '.$from.' to '.$to.PHP_EOL;

DB::enableQueryLog();

$hours = app(WorkedHoursService::class);

$period = $hours->forPeriod($employee, $from, $to);

$log = DB::getQueryLog();

echo 'Days returned: '.count($period['days']).PHP_EOL;
echo 'Queries:       '.count($log).PHP_EOL;
echo '---'.PHP_EOL;

/*
 * Grouped by shape rather than listed, because the same query repeated 26 times is the signal —
 * and a raw list of 80 lines hides it.
 */
$shapes = [];

foreach ($log as $row) {
    $sql = preg_replace('/\s+/', ' ', $row['query']);

    // Trim the variable tail so identical queries group together.
    $shape = substr($sql, 0, 70);

    $shapes[$shape] = ($shapes[$shape] ?? 0) + 1;
}

arsort($shapes);

$shown = 0;

foreach ($shapes as $shape => $count) {
    printf('%4d x %s%s', $count, $shape, PHP_EOL);

    if (++$shown >= 8) {
        break;
    }
}

echo '---'.PHP_EOL;
echo count($log) > 20
    ? 'LIKELY N+1: more queries than days in the range.'."\n"
    : 'Looks proportionate to the range.'."\n";
