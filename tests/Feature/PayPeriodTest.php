<?php

use App\Enums\TimeEntryStatus;
use App\Enums\TimeEntryType;
use App\Models\CompensationRule;
use App\Models\Employee;
use App\Models\Outlet;
use App\Models\PayPeriod;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\CorrectionService;
use App\Services\PayPeriodService;
use App\Services\PayService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Pay periods and the lock.
 *
 * This is success criterion 6: reproduce last month's figure exactly, including a mid-month
 * rate change. The tests concentrate on what happens to a COMMITTED figure when the data
 * underneath it later changes, because that is the case that makes a payslip untrustworthy.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-01 10:00:00', 'Asia/Kuala_Lumpur'));

    $this->outlet = Outlet::create([
        'code' => 'SG-RAMAL', 'name' => 'Sg Ramal', 'timezone' => 'Asia/Kuala_Lumpur',
        'token_mode' => 'printed', 'requires_photo' => false,
    ]);

    $this->owner = User::factory()->create(['role' => 'owner']);

    $this->employee = Employee::create([
        'employee_code' => 'RAM-001', 'name' => 'Ali bin Ahmad',
        'is_active' => true, 'pay_basis' => 'hourly',
    ]);
    $this->employee->outlets()->attach($this->outlet->id);

    $this->periods = app(PayPeriodService::class);
});

function rate(Employee $employee, float $amount, string $from, ?string $to = null, ?float $otRate = null): CompensationRule
{
    return CompensationRule::create([
        'employee_id' => $employee->id,
        'basis' => 'hourly',
        'rate' => $amount,
        'overtime_rate' => $otRate,
        'effective_from' => $from,
        'effective_to' => $to,
        'created_by' => test()->owner->id,
    ]);
}

function work(Employee $employee, Outlet $outlet, string $date, float $hours): TimeEntry
{
    $timezone = $outlet->timezone;
    $startedAt = CarbonImmutable::parse($date.' 09:00', $timezone);

    return TimeEntry::create([
        'client_uuid' => (string) Str::uuid(),
        'employee_id' => $employee->id,
        'outlet_id' => $outlet->id,
        'type' => TimeEntryType::WORK,
        'started_at' => $startedAt,
        'ended_at' => $startedAt->addSeconds((int) ($hours * 3600)),
        'business_date' => $startedAt->toDateString(),
        'status' => TimeEntryStatus::CLOSED,
    ]);
}

/** All employees the period should price. */
function everyone(): Collection
{
    return Employee::query()->orderBy('name')->get();
}

// ---- Creating periods -------------------------------------------------

it('creates a period and names the month by default', function () {
    $suggestion = $this->periods->suggestForMonth('2026-09');

    expect($suggestion['name'])->toBe('September 2026');
    expect($suggestion['starts_on'])->toBe('2026-09-01');
    expect($suggestion['ends_on'])->toBe('2026-09-30');
});

it('refuses a period that overlaps an existing one', function () {
    /*
     * Two overlapping periods would make "which figure is authoritative" unanswerable, and the
     * same punch would be paid twice.
     */
    $this->periods->create('September 2026', '2026-09-01', '2026-09-30', $this->owner);

    expect(fn () => $this->periods->create('Late September', '2026-09-15', '2026-09-30', $this->owner))
        ->toThrow(InvalidArgumentException::class);

    expect(PayPeriod::count())->toBe(1);
});

it('allows a period that starts the day another ends', function () {
    // Half-open in the calendar sense: September ends on the 30th, October starts on the 1st.
    $this->periods->create('September 2026', '2026-09-01', '2026-09-30', $this->owner);
    $october = $this->periods->create('October 2026', '2026-10-01', '2026-10-31', $this->owner);

    expect($october->exists)->toBeTrue();
});

it('refuses a period that ends before it starts', function () {
    expect(fn () => $this->periods->create('Backwards', '2026-09-30', '2026-09-01', $this->owner))
        ->toThrow(InvalidArgumentException::class);
});

it('finds the period covering a date', function () {
    $period = $this->periods->create('September 2026', '2026-09-01', '2026-09-30', $this->owner);

    expect($this->periods->covering('2026-09-15')?->id)->toBe($period->id);
    expect($this->periods->covering('2026-10-01'))->toBeNull();
});

// ---- The lock ---------------------------------------------------------

it('stores the approved figures when a period is locked', function () {
    rate($this->employee, 10.00, '2026-01-01');
    work($this->employee, $this->outlet, '2026-09-10', 8);

    $period = $this->periods->create('September 2026', '2026-09-01', '2026-09-30', $this->owner);
    $locked = $this->periods->lock($period, everyone(), $this->owner);

    expect($locked->isLocked())->toBeTrue();
    expect($locked->locked_by)->toBe($this->owner->id);
    expect($locked->snapshot_hash)->not->toBeNull();
    expect($locked->snapshot['totals']['total_amount'])->toEqual(80.00);
});

it('is idempotent when locked twice', function () {
    /*
     * A double-click must not re-snapshot at a moment when the figures may have moved, which
     * would silently rewrite what was approved.
     */
    rate($this->employee, 10.00, '2026-01-01');
    work($this->employee, $this->outlet, '2026-09-10', 8);

    $period = $this->periods->create('September 2026', '2026-09-01', '2026-09-30', $this->owner);
    $first = $this->periods->lock($period, everyone(), $this->owner);

    // Change the data, then lock again. The stored snapshot must NOT move.
    work($this->employee, $this->outlet, '2026-09-11', 8);

    $second = $this->periods->lock($first->fresh(), everyone(), $this->owner);

    expect($second->snapshot['totals']['total_amount'])->toEqual(80.00);
    expect($second->snapshot_hash)->toBe($first->snapshot_hash);
});

it('reports a locked period as unchanged when nothing has moved', function () {
    rate($this->employee, 10.00, '2026-01-01');
    work($this->employee, $this->outlet, '2026-09-10', 8);

    $period = $this->periods->create('September 2026', '2026-09-01', '2026-09-30', $this->owner);
    $locked = $this->periods->lock($period, everyone(), $this->owner);

    $reconciled = $this->periods->reconcile($locked, everyone());

    expect($reconciled['state'])->toBe('locked');
    expect($reconciled['difference'])->toBeNull();
    expect($reconciled['current']['totals']['total_amount'])->toBe(80.00);
});

it('reports an open period as open', function () {
    // Nothing was committed, so nothing can have drifted.
    rate($this->employee, 10.00, '2026-01-01');
    work($this->employee, $this->outlet, '2026-09-10', 8);

    $period = $this->periods->create('September 2026', '2026-09-01', '2026-09-30', $this->owner);

    $reconciled = $this->periods->reconcile($period, everyone());

    expect($reconciled['state'])->toBe('open');
    expect($reconciled['approved'])->toBeNull();
    // But the current figures are still computed, so the screen has something to show.
    expect($reconciled['current']['totals']['total_amount'])->toBe(80.00);
});

it('detects drift after a correction lands on a locked period', function () {
    /*
     * THE case. A locked period is not frozen — a genuine missed clock-out does not stop being
     * genuine because a period was closed. So the change is ALLOWED and DETECTED: the figure
     * stops matching what was approved, and the console can say so instead of quietly showing a
     * different number.
     *
     * An overtime rate is configured here on purpose. Without one, extending this shift to ten
     * hours moves the INPUTS but not the amount — because the design refuses to invent a rate
     * nobody agreed. That behaviour is asserted separately below.
     */
    rate($this->employee, 10.00, '2026-01-01', null, 15.00);
    $entry = work($this->employee, $this->outlet, '2026-09-10', 8);

    $period = $this->periods->create('September 2026', '2026-09-01', '2026-09-30', $this->owner);
    $locked = $this->periods->lock($period, everyone(), $this->owner);

    expect($this->periods->reconcile($locked, everyone())['state'])->toBe('locked');

    // The employee actually worked two more hours, and a correction records it.
    app(CorrectionService::class)->request(
        $entry,
        ['ended_at' => CarbonImmutable::parse('2026-09-10 19:00', $this->outlet->timezone)->toIso8601String()],
        'Forgot to clock out; confirmed with the closing manager.',
        $this->owner,
    );

    $reconciled = $this->periods->reconcile($locked->fresh(), everyone());

    expect($reconciled['state'])->toBe('drifted');
    // The approved figure is preserved exactly as it was committed.
    expect($reconciled['approved']['totals']['total_amount'])->toEqual(80.00);
    // And the new figure is what is now owed: 8h ordinary + 2h at 15.00.
    expect($reconciled['current']['totals']['total_amount'])->toBe(110.00);
    expect($reconciled['difference']['delta_total'])->toBe(30.00);
});

it('reports inputs changed without the amount moving', function () {
    /*
     * Extending a shift into overtime at an outlet with NO overtime rate moves the inputs but
     * not the money — the design refuses to invent a rate nobody agreed. "Drifted" with an
     * empty difference list would read as a bug, so the state says which it is.
     */
    rate($this->employee, 10.00, '2026-01-01');
    $entry = work($this->employee, $this->outlet, '2026-09-10', 8);

    $period = $this->periods->create('September 2026', '2026-09-01', '2026-09-30', $this->owner);
    $locked = $this->periods->lock($period, everyone(), $this->owner);

    app(CorrectionService::class)->request(
        $entry,
        ['ended_at' => CarbonImmutable::parse('2026-09-10 19:00', $this->outlet->timezone)->toIso8601String()],
        'Forgot to clock out; no overtime rate agreed for this employee.',
        $this->owner,
    );

    $reconciled = $this->periods->reconcile($locked->fresh(), everyone());

    expect($reconciled['state'])->toBe('drifted');
    expect($reconciled['difference']['inputs_changed_only'])->toBeTrue();
    expect($reconciled['difference']['delta_total'])->toBe(0.0);
    // The hours did move, so the drift is real even though the money is not.
    expect($reconciled['current']['totals']['worked_seconds'])
        ->toBeGreaterThan($reconciled['approved']['totals']['worked_seconds']);
});

it('names the employee whose figure moved', function () {
    // "The total is 20 ringgit different" is not actionable; "Ali's is" is.
    rate($this->employee, 10.00, '2026-01-01');
    $entry = work($this->employee, $this->outlet, '2026-09-10', 8);

    $period = $this->periods->create('September 2026', '2026-09-01', '2026-09-30', $this->owner);
    $locked = $this->periods->lock($period, everyone(), $this->owner);

    app(CorrectionService::class)->request(
        $entry,
        ['ended_at' => CarbonImmutable::parse('2026-09-10 11:00', $this->outlet->timezone)->toIso8601String()],
        'Left early, confirmed.',
        $this->owner,
    );

    $difference = $this->periods->reconcile($locked->fresh(), everyone())['difference'];

    expect($difference['employees'])->toHaveCount(1);
    expect($difference['employees'][0]['name'])->toBe('Ali bin Ahmad');
    expect($difference['employees'][0]['before'])->toEqual(80.00);
    expect($difference['employees'][0]['after'])->toBe(20.00);
    expect($difference['employees'][0]['delta'])->toBe(-60.00);
});

it('detects drift when a rate is back-dated after locking', function () {
    /*
     * A back-dated rate change is the quietest way a committed figure can move: nobody edited
     * an entry, so nothing looks touched, but every figure for the period is now different.
     */
    rate($this->employee, 10.00, '2026-01-01');
    work($this->employee, $this->outlet, '2026-09-10', 8);

    $period = $this->periods->create('September 2026', '2026-09-01', '2026-09-30', $this->owner);
    $locked = $this->periods->lock($period, everyone(), $this->owner);

    // A raise, recorded retrospectively.
    rate($this->employee, 12.00, '2026-09-01');

    $reconciled = $this->periods->reconcile($locked->fresh(), everyone());

    expect($reconciled['state'])->toBe('drifted');
    expect($reconciled['current']['totals']['total_amount'])->toBe(96.00);
});

it('does not report drift for a change outside the period', function () {
    // A correction in October must not disturb a locked September.
    rate($this->employee, 10.00, '2026-01-01');
    work($this->employee, $this->outlet, '2026-09-10', 8);

    $september = $this->periods->create('September 2026', '2026-09-01', '2026-09-30', $this->owner);
    $locked = $this->periods->lock($september, everyone(), $this->owner);

    work($this->employee, $this->outlet, '2026-10-05', 8);

    expect($this->periods->reconcile($locked->fresh(), everyone())['state'])->toBe('locked');
});

it('reproduces a month exactly after a later rate change', function () {
    /*
     * SUCCESS CRITERION 6, end to end: lock a month, raise the rate, then ask for last month
     * again. The committed figure must still be the committed figure.
     */
    rate($this->employee, 10.00, '2026-01-01');
    work($this->employee, $this->outlet, '2026-09-10', 8);

    $period = $this->periods->create('September 2026', '2026-09-01', '2026-09-30', $this->owner);
    $locked = $this->periods->lock($period, everyone(), $this->owner);

    // A raise from October, which is the ordinary way a rate changes.
    rate($this->employee, 15.00, '2026-10-01');

    // The stored snapshot is the record of what was paid, and it has not moved.
    $stored = $locked->fresh();

    expect($stored->snapshot['totals']['total_amount'])->toEqual(80.00);
    expect($stored->snapshot['employees'][0]['rate'])->toEqual(10.00);

    // And re-deriving September from the rate that applied THEN reproduces it exactly.
    $recomputed = app(PayService::class)
        ->forEmployee($this->employee, '2026-09-01', '2026-09-30');

    expect($recomputed['total_amount'])->toBe(80.00);
    expect($recomputed['rate'])->toBe(10.00);
});

it('reproduces a month that contained a rate change', function () {
    /*
     * The hardest version, and the one the blueprint names: a MID-MONTH rate change. Because
     * rows are inserted rather than updated, both rates survive, and the period prices at the
     * one in force at its start — with a warning that it did.
     */
    rate($this->employee, 10.00, '2026-01-01', '2026-09-14');
    rate($this->employee, 12.00, '2026-09-15');

    work($this->employee, $this->outlet, '2026-09-10', 8);
    work($this->employee, $this->outlet, '2026-09-20', 8);

    $period = $this->periods->create('September 2026', '2026-09-01', '2026-09-30', $this->owner);
    $locked = $this->periods->lock($period, everyone(), $this->owner);

    // Priced at 10.00 throughout, since that was the rate at the period's start.
    expect($locked->snapshot['totals']['total_amount'])->toEqual(160.00);
    expect($locked->snapshot['employees'][0]['warnings'])->not->toBeEmpty();

    // And still reproducible later, with both rate rows intact.
    $recomputed = app(PayService::class)
        ->forEmployee($this->employee, '2026-09-01', '2026-09-30');

    expect($recomputed['total_amount'])->toBe(160.00);
    expect($recomputed['rates_in_period'])->toBe(2);
});

it('lists locked and open periods separately', function () {
    rate($this->employee, 10.00, '2026-01-01');

    $september = $this->periods->create('September 2026', '2026-09-01', '2026-09-30', $this->owner);
    $this->periods->create('October 2026', '2026-10-01', '2026-10-31', $this->owner);

    $this->periods->lock($september, everyone(), $this->owner);

    expect(PayPeriod::query()->locked()->count())->toBe(1);
    expect(PayPeriod::query()->open()->count())->toBe(1);
});

it('reports an employee with no rate as unpriced in the snapshot', function () {
    // Recorded hours with no rate to price them at. The snapshot says so rather than showing
    // a zero that looks like a real amount.
    work($this->employee, $this->outlet, '2026-09-10', 8);

    $period = $this->periods->create('September 2026', '2026-09-01', '2026-09-30', $this->owner);
    $locked = $this->periods->lock($period, everyone(), $this->owner);

    expect($locked->snapshot['totals']['unpriced_count'])->toBe(1);
    expect($locked->snapshot['employees'][0]['total_amount'])->toBeNull();
});

it('counts the days in a period inclusively', function () {
    $period = $this->periods->create('September 2026', '2026-09-01', '2026-09-30', $this->owner);

    expect($period->dayCount())->toBe(30);
});

it('asks a correction to warn before it changes a locked period', function () {
    /*
     * A manager correcting a forgotten clock-out after month end most needs to know that the
     * period is already closed. The check exists so the console can say so at the moment of the
     * change, rather than the drift being discovered later.
     */
    rate($this->employee, 10.00, '2026-01-01');
    $entry = work($this->employee, $this->outlet, '2026-09-10', 8);

    $period = $this->periods->create('September 2026', '2026-09-01', '2026-09-30', $this->owner);
    $this->periods->lock($period, everyone(), $this->owner);

    // The lookup a controller would do before applying a correction.
    $covering = $this->periods->covering($entry->business_date->toDateString());

    expect($covering)->not->toBeNull();
    expect($covering->isLocked())->toBeTrue();
});
