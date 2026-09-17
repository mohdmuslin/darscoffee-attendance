<?php

/*
 * Local development helper: exercise the Phase 3 endpoints the way the console does.
 *
 *   php scripts/dev-smoke-phase3.php
 *
 * Calls the real HTTP API against the real database, so a scoping mistake or a query that
 * works on SQLite but not MySQL shows up here rather than in the browser. The test suite
 * covers behaviour; this covers the plumbing between the screens and the server.
 *
 * Not part of the app.
 */

require __DIR__.'/dev-bootstrap.php';

use App\Models\AttendanceCorrection;
use App\Models\Employee;
use App\Models\Outlet;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

$base = 'http://127.0.0.1:8100/api/v1';

$owner = User::where('role', 'owner')->first();
$manager = User::where('role', 'manager')->first();

if ($owner === null || $manager === null) {
    exit('Needs an owner and a manager account.'.PHP_EOL);
}

$failures = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures;

    if (! $ok) {
        $failures++;
    }

    echo ($ok ? '  PASS  ' : '  FAIL  ').$label.($detail !== '' ? '  ->  '.$detail : '').PHP_EOL;
}

/** Log in and return the bearer token, exactly as the console does. */
$login = function (User $user) use ($base): string {
    $response = Http::post($base.'/auth/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    return (string) $response->json('data.token');
};

$ownerToken = $login($owner);
$managerToken = $login($manager);

$get = fn (string $path, string $token, array $query = []) => Http::withToken($token)->get($base.$path, $query);
$post = fn (string $path, string $token, array $body = []) => Http::withToken($token)->post($base.$path, $body);

/*
 * Dates are computed in the BUSINESS timezone, not the server's.
 *
 * The application runs in UTC while the business does not, so after 16:00 UTC a UTC-derived
 * window omits today — which is exactly how this script first reported "no open entry": the
 * shift was there, the window simply stopped yesterday.
 */
$timezone = config('attendance.business_timezone');
$from = CarbonImmutable::now($timezone)->subDays(7)->toDateString();
$to = CarbonImmutable::now($timezone)->toDateString();

echo '=== timesheet summary ==='.PHP_EOL;

$summary = $get('/admin/timesheets/summary', $ownerToken, ['from' => $from, 'to' => $to]);

check('summary returns 200', $summary->status() === 200, 'status='.$summary->status());
check('summary lists employees', count($summary->json('data.employees') ?? []) > 0);
check('summary totals are present', $summary->json('data.totals.worked_label') !== null, 'worked='.$summary->json('data.totals.worked_label'));

$employeeId = collect($summary->json('data.employees') ?? [])
    ->firstWhere('worked_seconds', '>', 0)['employee_id'] ?? null;

check('at least one employee has hours', $employeeId !== null);

echo '=== employee timesheet ==='.PHP_EOL;

$sheet = $get("/admin/timesheets/employees/{$employeeId}", $ownerToken, ['from' => $from, 'to' => $to]);

check('employee timesheet returns 200', $sheet->status() === 200, 'status='.$sheet->status());

$days = $sheet->json('data.timesheet.days') ?? [];
$statuses = collect($days)->pluck('status')->unique()->values()->all();

check('days include an open (incomplete) day', in_array('open', $statuses, true), implode(',', $statuses));
check('days include a no-show row', in_array('no_show', $statuses, true));
check('days include an adhoc day', in_array('adhoc', $statuses, true));
check('days include a corrected or ok day', in_array('ok', $statuses, true) || in_array('corrected', $statuses, true));

$lateDay = collect($days)->first(fn ($d) => ($d['lateness']['is_late'] ?? false) === true);
check('a late arrival is reported', $lateDay !== null, $lateDay ? 'late by '.$lateDay['lateness']['late_by_label'] : 'none');

$earlyDay = collect($days)->first(fn ($d) => ($d['lateness']['left_early_by_label'] ?? null) !== null);
check('an early departure is reported', $earlyDay !== null, $earlyDay ? 'left '.$earlyDay['lateness']['left_early_by_label'].' early' : 'none');

$otDay = collect($days)->first(fn ($d) => ($d['overtime_seconds'] ?? 0) > 0);
check('overtime is reported on a long day', $otDay !== null, $otDay ? $otDay['overtime_label'].' OT' : 'none');

// The rule that protects pay: 9 hours of span containing a 1 hour break is 8 hours worked.
$broken = collect($days)->first(fn ($d) => collect($d['entries'])->contains(fn ($e) => $e['type'] === 'break'));
check(
    'breaks are excluded from worked time',
    $broken !== null && $broken['worked_seconds'] === $broken['span_seconds'] - $broken['break_seconds'],
    $broken ? 'worked='.$broken['worked_seconds'].' span='.$broken['span_seconds'].' break='.$broken['break_seconds'] : 'no day with a break',
);

echo '=== CSV export ==='.PHP_EOL;

$csv = Http::withToken($ownerToken)->get($base.'/admin/timesheets/export', ['from' => $from, 'to' => $to]);

check('export returns 200', $csv->status() === 200, 'status='.$csv->status());
check('export is a CSV', str_contains($csv->header('Content-Type') ?? '', 'text/csv'), $csv->header('Content-Type') ?? 'none');

$body = $csv->body();
check('CSV starts with a BOM so Excel reads UTF-8', str_starts_with($body, "\xEF\xBB\xBF"));
/*
 * fputcsv quotes the header fields, so the row reads "Date","Employee code". Comparing
 * the unquoted form was the bug here, not the CSV.
 */
check('CSV has a header row', str_contains($body, 'Date') && str_contains($body, 'Employee code'));
check('CSV contains decimal hours a spreadsheet can sum', (bool) preg_match('/,\d+\.\d{2},/', $body));

echo '=== corrections ==='.PHP_EOL;

// Correct the open segment, so the flow has something real to act on.
$openEntryId = collect($days)->flatMap(fn ($d) => $d['entries'])->firstWhere('status', 'open')['id'] ?? null;

check('there is an open entry to correct', $openEntryId !== null);

if ($openEntryId === null) {
    exit('Cannot continue without an open entry. Seed one with dev-seed-timesheet.php'.PHP_EOL);
}

$request = $post("/admin/corrections/entries/{$openEntryId}", $managerToken, [
    /*
     * Ended eight hours after the segment actually started, read from the record rather
     * than guessed from the clock.
     *
     * Earlier versions of this script built the end from `now()`, which lands before the
     * clock-in whenever the seed was run with a future start — and the service then
     * (correctly) refused an end that precedes the start. Reading the real start removes
     * the guesswork.
     */
    'changes' => [
        'ended_at' => TimeEntry::find($openEntryId)->started_at->addHours(8)->toIso8601String(),
    ],
    'reason' => 'Employee forgot to clock out; confirmed with the closing manager.',
]);

check('manager can raise a correction', $request->status() === 201, 'status='.$request->status().' '.$request->json('message'));
check('manager correction waits for the owner', $request->json('data.correction.status') === 'pending', (string) $request->json('data.correction.status'));

$correctionId = $request->json('data.correction.id');

// Crucially, nothing has changed yet.
check('the entry is unchanged while pending', TimeEntry::find($openEntryId)->ended_at === null);

$pending = $get('/admin/corrections/pending-count', $ownerToken);
check('the owner sees a pending count', ($pending->json('data.pending') ?? 0) >= 1, 'pending='.$pending->json('data.pending'));

$ownApproval = $post("/admin/corrections/{$correctionId}/approve", $managerToken);
check('a manager cannot approve their own correction', $ownApproval->status() === 403, 'status='.$ownApproval->status());

$approve = $post("/admin/corrections/{$correctionId}/approve", $ownerToken, ['note' => 'Checked against the rota.']);
check('the owner can approve it', $approve->status() === 200, 'status='.$approve->status());

$correction = AttendanceCorrection::find($correctionId);
check('the correction records the original values', $correction->original_values['ended_at'] === null, json_encode($correction->original_values));
check('the correction records who asked', $correction->requested_by === $manager->id);
check('the correction records who approved', $correction->reviewed_by === $owner->id);
check('the entry is now closed', TimeEntry::find($openEntryId)->ended_at !== null);
check('the entry is marked corrected', TimeEntry::find($openEntryId)->status->value === 'corrected', TimeEntry::find($openEntryId)->status->value);

echo '=== anomaly queue ==='.PHP_EOL;

$anomalies = $get('/admin/anomalies', $ownerToken, ['unreviewed_only' => true]);
check('anomaly list returns 200', $anomalies->status() === 200, 'status='.$anomalies->status());
check('anomaly counts are returned', $anomalies->json('data.counts.unreviewed') !== null, 'unreviewed='.$anomalies->json('data.counts.unreviewed'));

$first = collect($anomalies->json('data.anomalies') ?? [])->first();
check('at least one flag exists', $first !== null);

if ($first !== null) {
    $review = $post("/admin/anomalies/{$first['id']}/review", $managerToken, ['note' => 'Looked at.']);
    check('a manager can review a flag for their outlet', $review->status() === 200, 'status='.$review->status());
    check('the flag is marked reviewed', $review->json('data.anomaly.is_reviewed') === true);
}

echo '=== punch trail ==='.PHP_EOL;

$events = $get('/admin/punch-events', $ownerToken);
check('trail returns 200', $events->status() === 200, 'status='.$events->status());
check('trail has entries', count($events->json('data.events') ?? []) > 0, 'count='.count($events->json('data.events') ?? []));

$types = collect($events->json('data.events') ?? [])->pluck('event')->unique()->values()->all();
check('trail records successful actions', count(array_intersect($types, ['clock_in', 'scan', 'break_start'])) > 0, implode(',', $types));

echo '=== outlet scoping ==='.PHP_EOL;

/*
 * The boundary that matters most: a manager must not reach another outlet's hours, even
 * by asking for them directly.
 */
$otherOutlet = Outlet::whereNotIn('id', $manager->visibleOutletIds() ?? [])->first();

if ($otherOutlet === null) {
    echo '  SKIP  no outlet outside the manager scope to test against'.PHP_EOL;
} else {
    $scoped = $get('/admin/timesheets/entries', $managerToken, ['outlet_id' => $otherOutlet->id]);
    check('a manager asking for another outlet gets no rows', count($scoped->json('data.entries') ?? []) === 0, 'count='.count($scoped->json('data.entries') ?? []));

    $otherEmployee = Employee::whereHas('outlets', fn ($q) => $q->where('outlets.id', $otherOutlet->id))->first();

    if ($otherEmployee !== null) {
        $reach = $get("/admin/timesheets/employees/{$otherEmployee->id}", $managerToken, ['from' => $from, 'to' => $to]);
        check('a manager cannot read another outlet employee timesheet', $reach->status() === 404, 'status='.$reach->status());
    }
}

echo PHP_EOL.($failures === 0 ? 'ALL CHECKS PASSED' : $failures.' CHECK(S) FAILED').PHP_EOL;

exit($failures === 0 ? 0 : 1);
