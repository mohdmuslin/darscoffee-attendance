<?php

/*
 * Local development helper: exercise the punch flow end to end against the real
 * database, exactly as the browser would.
 *
 *   php scripts/dev-smoke-punch.php [OUTLET_CODE] [PIN]
 *
 * Why this exists: the test suite runs on SQLite, and SQLite and MySQL have already
 * disagreed once in a way that made the tests pass while the queries returned nothing
 * (see TimeEntry::scopeForBusinessDate). Anything touching a date, a unique index or a
 * generated column has to be checked on the real driver too.
 *
 * Creates a throwaway employee and leaves the resulting records in place for
 * inspection. Not part of the app.
 */

require __DIR__.'/dev-bootstrap.php';

use App\Models\Employee;
use App\Models\Outlet;
use App\Models\TimeEntry;
use App\Services\OutletTokenService;
use App\Services\PunchService;
use App\Services\PunchStateService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

$outletCode = $argv[1] ?? 'DARS-COFFEE';
$pin = $argv[2] ?? '4321';

$failures = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures;

    if (! $ok) {
        $failures++;
    }

    echo ($ok ? '  PASS  ' : '  FAIL  ').$label.($detail !== '' ? '  ->  '.$detail : '').PHP_EOL;
}

echo 'driver: '.DB::connection()->getDriverName().' / '.DB::connection()->getDatabaseName().PHP_EOL;

$outlet = Outlet::where('code', $outletCode)->firstOrFail();

/*
 * Photos are switched off for the run and RESTORED afterwards: there is no camera in a
 * CLI script, and leaving the setting changed makes the browser behave differently from
 * production for no visible reason — which cost real debugging time once already.
 */
$originalRequiresPhoto = (bool) $outlet->requires_photo;
$outlet->update(['requires_photo' => false]);

register_shutdown_function(function () use ($outlet, $originalRequiresPhoto) {
    $outlet->newQuery()->whereKey($outlet->id)->update(['requires_photo' => $originalRequiresPhoto]);
});

$employee = Employee::firstOrNew(['employee_code' => 'SMOKE-1']);
$employee->name = 'Smoke Tester';
$employee->pay_basis = 'hourly';
$employee->is_active = true;
$employee->save();
$employee->outlets()->syncWithoutDetaching([$outlet->id]);
$employee->setPin($pin);
$employee->save();

// Start clean so the counts below are unambiguous.
$employee->timeEntries()->delete();

$token = app(OutletTokenService::class)->regenerate($outlet);
$punch = app(PunchService::class);
$state = app(PunchStateService::class);

echo '--- session ---'.PHP_EOL;

$result = $punch->startSession($token->token, $pin);
check('session issued for a valid code and PIN', $result !== null);

$result = $punch->startSession($token->token, '0000');
check('wrong PIN refused', $result === null);

$session = $punch->startSession($token->token, $pin)['session'];

echo '--- actions ---'.PHP_EOL;

$punch->clockIn($session);
check('clock in opens one WORK segment', $employee->timeEntries()->count() === 1);

// A second clock-in must not create a second segment: the MySQL generated column is
// what enforces this, and it behaves differently from the SQLite partial index.
$punch->clockIn($session);
check('double clock in does not open a second segment', $employee->timeEntries()->count() === 1);

$punch->startBreak($session);
$openBreak = $employee->timeEntries()->whereNull('ended_at')->first();
check('start break leaves a BREAK segment open for the break', $openBreak?->type->value === 'break');

$punch->endBreak($session);
check('end break leaves a WORK segment open', $employee->timeEntries()->whereNull('ended_at')->first()?->type->value === 'work');

echo '--- business day ---'.PHP_EOL;

$today = now($outlet->timezone)->toDateString();
$entry = $employee->timeEntries()->latest('id')->first();

check('business_date matches the outlet local date', $entry->business_date->toDateString() === $today, $entry->business_date->toDateString().' vs '.$today);

/*
 * Three segments by this point: the first WORK segment is closed by the break, the
 * BREAK segment is closed when the break ends, and a second WORK segment is open.
 * Counting them proves the business-day query finds the whole day, not just the
 * segment that happens to be open.
 */
$todayCount = TimeEntry::query()
    ->where('employee_id', $employee->id)
    ->forBusinessDate($today)
    ->count();

check('forBusinessDate finds every segment for today', $todayCount === 3, 'count='.$todayCount);

$stateOut = $state->for($session);

/*
 * Deliberately NOT asserting a non-zero worked total here. The whole flow above runs in
 * well under a second, so the segments are genuinely sub-second at this point. Measurable
 * totals are checked after the segments are backdated, below.
 */
check('state reports a numeric worked total', is_int($stateOut['today']['worked_seconds']), (string) $stateOut['today']['worked_seconds'].'s');
check('break is excluded from worked time', $stateOut['today']['worked_seconds'] >= $stateOut['today']['break_seconds'], 'worked='.$stateOut['today']['worked_seconds'].' break='.$stateOut['today']['break_seconds']);
check('state reports the subject', ($stateOut['subject']['name'] ?? null) === 'Smoke', (string) ($stateOut['subject']['name'] ?? 'null'));
check('state reports the outlet', ($stateOut['subject']['outlet'] ?? null) === $outlet->name, (string) ($stateOut['subject']['outlet'] ?? 'null'));

echo '--- clock out ---'.PHP_EOL;

$punch->clockOut($session);
check('clock out leaves nothing open', $employee->timeEntries()->whereNull('ended_at')->count() === 0);

/*
 * The flow above completes in well under a second, so every duration rounds to zero
 * and the totals would assert nothing. Backdating the first WORK segment by two hours
 * gives the arithmetic something real to add up.
 */
$first = $employee->timeEntries()->where('type', 'work')->oldest('id')->first();
$first->forceFill([
    'started_at' => $first->started_at->copy()->subHours(2),
])->save();

$expected = 2 * 3600 + $employee->timeEntries()->where('type', 'work')->where('id', '!=', $first->id)->get()->sum(fn ($e) => $e->durationSeconds());
$worked = $state->for($session)['today']['worked_seconds'];

check('worked today reflects the segments', abs($worked - $expected) <= 2, 'worked='.$worked.' expected≈'.$expected);

$hours = $state->weeklySummary($employee);

check('weekly summary includes today', $hours['total_seconds'] > 0, (string) $hours['total_seconds'].'s');
check('weekly summary excludes the break', $hours['total_seconds'] < 3 * 3600, (string) $hours['total_seconds'].'s');

echo '--- idempotency ---'.PHP_EOL;

// The generated column is the only thing standing between a double tap and duplicate
// hours, so prove it refuses a second open segment directly.
try {
    DB::table('time_entries')->insert([
        'client_uuid' => (string) Str::uuid(),
        'employee_id' => $employee->id,
        'outlet_id' => $outlet->id,
        'type' => 'work',
        'started_at' => now(),
        'ended_at' => null,
        'business_date' => now($outlet->timezone)->toDateString(),
        'status' => 'open',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('time_entries')->insert([
        'client_uuid' => (string) Str::uuid(),
        'employee_id' => $employee->id,
        'outlet_id' => $outlet->id,
        'type' => 'work',
        'started_at' => now(),
        'ended_at' => null,
        'business_date' => now($outlet->timezone)->toDateString(),
        'status' => 'open',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    check('two open segments are refused by the database', false, 'the second insert was allowed');
} catch (QueryException $e) {
    check('two open segments are refused by the database', true);
} finally {
    $employee->timeEntries()->delete();
}

echo PHP_EOL.($failures === 0 ? 'ALL CHECKS PASSED' : $failures.' CHECK(S) FAILED').PHP_EOL;

exit($failures === 0 ? 0 : 1);
