<?php

use App\Enums\TimeEntryStatus;
use App\Enums\TimeEntryType;
use App\Models\CompensationRule;
use App\Models\Employee;
use App\Models\LabourRule;
use App\Models\Outlet;
use App\Models\RateAdjustment;
use App\Models\Setting;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\PayService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Pricing hours.
 *
 * This is money, so the tests concentrate on the figures rather than the report's shape. Every
 * basis is asserted against a hand-computed amount, and the awkward cases — a mid-month raise,
 * a threshold change, an unapproved adjustment — are asserted by their monetary effect.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-30 18:00:00', 'Asia/Kuala_Lumpur'));

    $this->outlet = Outlet::create([
        'code' => 'SG-RAMAL', 'name' => 'Sg Ramal', 'timezone' => 'Asia/Kuala_Lumpur',
        'token_mode' => 'printed', 'requires_photo' => false,
    ]);

    $this->actor = User::factory()->create(['role' => 'owner']);

    $this->employee = Employee::create([
        'employee_code' => 'RAM-001', 'name' => 'Ali bin Ahmad',
        'is_active' => true, 'pay_basis' => 'hourly',
    ]);
    $this->employee->outlets()->attach($this->outlet->id);

    $this->pay = app(PayService::class);
});

/** A rate row, inserted rather than updated, as a raise would be. */
function giveRate(Employee $employee, string $basis, float $rate, string $from, ?string $to = null, ?float $otRate = null): CompensationRule
{
    return CompensationRule::create([
        'employee_id' => $employee->id,
        'basis' => $basis,
        'rate' => $rate,
        'overtime_rate' => $otRate,
        'effective_from' => $from,
        'effective_to' => $to,
        'created_by' => test()->actor->id,
    ]);
}

/** A work segment on a local date, from an hour count. */
function shift(Employee $employee, Outlet $outlet, string $date, float $hours): TimeEntry
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
        'duration_seconds' => (int) ($hours * 3600),
    ]);
}

// ---- The basis decides the arithmetic ---------------------------------

it('pays an hourly employee for the hours worked', function () {
    giveRate($this->employee, 'hourly', 12.00, '2026-01-01');
    shift($this->employee, $this->outlet, '2026-09-01', 8);
    shift($this->employee, $this->outlet, '2026-09-02', 6);

    $report = $this->pay->forEmployee($this->employee, '2026-09-01', '2026-09-30');

    // 14 hours at 12.00.
    expect($report['ordinary_amount'])->toBe(168.00);
    expect($report['total_amount'])->toBe(168.00);
});

it('pays a daily employee per day worked, not per hour', function () {
    /*
     * A daily rate is for turning up, so a nine-hour day and a seven-hour day both pay one
     * day. Dividing by a nominal day length would pay the short day as a fraction, which is not
     * what a daily agreement means.
     */
    giveRate($this->employee, 'daily', 90.00, '2026-01-01');
    shift($this->employee, $this->outlet, '2026-09-01', 9);
    shift($this->employee, $this->outlet, '2026-09-02', 7);

    $report = $this->pay->forEmployee($this->employee, '2026-09-01', '2026-09-30');

    expect($report['days_worked'])->toBe(2);
    expect($report['ordinary_amount'])->toBe(180.00);
});

it('pays a monthly employee exactly the monthly rate for a full month', function () {
    /*
     * The property that matters most, because a figure that misses by a few sen every month is
     * one somebody has to explain. A full calendar month must return exactly the rate.
     */
    giveRate($this->employee, 'monthly', 2400.00, '2026-01-01');
    shift($this->employee, $this->outlet, '2026-09-01', 8);

    $report = $this->pay->forEmployee($this->employee, '2026-09-01', '2026-09-30');

    expect($report['ordinary_amount'])->toBe(2400.00);
});

it('prorates a monthly rate for part of a month', function () {
    // September has 30 days, so a 15-day period is exactly half.
    giveRate($this->employee, 'monthly', 2400.00, '2026-01-01');
    shift($this->employee, $this->outlet, '2026-09-01', 8);

    $report = $this->pay->forEmployee($this->employee, '2026-09-01', '2026-09-15');

    expect($report['ordinary_amount'])->toBe(1200.00);
});

it('pays a weekly employee per week covered', function () {
    giveRate($this->employee, 'weekly', 600.00, '2026-01-01');
    shift($this->employee, $this->outlet, '2026-09-01', 8);

    // 1 to 14 September is exactly two weeks.
    $report = $this->pay->forEmployee($this->employee, '2026-09-01', '2026-09-14');

    expect($report['ordinary_amount'])->toBe(1200.00);
});

it('does not pay a monthly employee twice for a two-month period', function () {
    /*
     * A period spanning two months is not a month. Prorating it as one would pay two months of
     * work as a single salary — the most expensive possible mistake in this file.
     */
    giveRate($this->employee, 'monthly', 3000.00, '2026-01-01');
    shift($this->employee, $this->outlet, '2026-09-15', 8);

    $report = $this->pay->forEmployee($this->employee, '2026-09-01', '2026-10-31');

    // September (30/30) + October (31/31) = 2 months.
    expect($report['ordinary_amount'])->toBe(6000.00);
});

// ---- Overtime ---------------------------------------------------------

it('prices overtime at the flat overtime rate', function () {
    /*
     * A FLAT per-hour figure, per the business's decided rule — not a multiplier of the
     * ordinary rate. 10 hours in a day with an 8-hour threshold is 8 ordinary + 2 overtime.
     */
    giveRate($this->employee, 'hourly', 10.00, '2026-01-01', null, 15.00);
    shift($this->employee, $this->outlet, '2026-09-01', 10);

    $report = $this->pay->forEmployee($this->employee, '2026-09-01', '2026-09-30');

    expect($report['ordinary_seconds'])->toBe(8 * 3600);
    expect($report['overtime_seconds'])->toBe(2 * 3600);
    expect($report['ordinary_amount'])->toBe(80.00);
    expect($report['overtime_amount'])->toBe(30.00);
    expect($report['total_amount'])->toBe(110.00);
});

it('applies the overtime threshold per day, not per period', function () {
    /*
     * Three six-hour days are eighteen hours, but no day crosses the eight-hour threshold — so
     * there is no overtime. A period-level threshold would call ten hours of it overtime.
     */
    giveRate($this->employee, 'hourly', 10.00, '2026-01-01', null, 15.00);
    shift($this->employee, $this->outlet, '2026-09-01', 6);
    shift($this->employee, $this->outlet, '2026-09-02', 6);
    shift($this->employee, $this->outlet, '2026-09-03', 6);

    $report = $this->pay->forEmployee($this->employee, '2026-09-01', '2026-09-30');

    expect($report['overtime_seconds'])->toBe(0);
    expect($report['total_amount'])->toBe(180.00);
});

it('does not invent an overtime rate when none is set', function () {
    /*
     * Guessing a multiplier would invent an agreement nobody made, and overtime paid at the
     * wrong rate is worse than overtime visibly unpaid and queried. It is also reported as a
     * warning rather than silently dropped.
     */
    giveRate($this->employee, 'hourly', 10.00, '2026-01-01');
    shift($this->employee, $this->outlet, '2026-09-01', 10);

    $report = $this->pay->forEmployee($this->employee, '2026-09-01', '2026-09-30');

    expect($report['overtime_seconds'])->toBe(2 * 3600);
    expect($report['overtime_amount'])->toBe(0.0);
    // The ordinary hours are still paid — only the overtime premium is missing.
    expect($report['ordinary_amount'])->toBe(80.00);
});

it('uses the labour rule in force on the day', function () {
    // A threshold change must not rewrite an old period's overtime.
    LabourRule::create([
        'outlet_id' => $this->outlet->id,
        'ot_after_seconds' => 4 * 3600,
        'effective_from' => '2026-01-01',
        'effective_to' => '2026-08-31',
    ]);

    giveRate($this->employee, 'hourly', 10.00, '2026-01-01', null, 15.00);

    // September has no rule row, so the business default (8h) applies.
    shift($this->employee, $this->outlet, '2026-09-01', 6);

    $report = $this->pay->forEmployee($this->employee, '2026-09-01', '2026-09-30');

    expect($report['overtime_seconds'])->toBe(0);
});

// ---- Versioned rates --------------------------------------------------

it('prices at the rate in force on the first day of the period', function () {
    /*
     * A mid-period raise is FLAGGED, not silently averaged. Prorating automatically would guess
     * at an agreement nobody wrote down; the warning tells the manager to split the period.
     */
    giveRate($this->employee, 'hourly', 10.00, '2026-01-01', '2026-09-14');
    giveRate($this->employee, 'hourly', 12.00, '2026-09-15');

    shift($this->employee, $this->outlet, '2026-09-01', 8);

    $report = $this->pay->forEmployee($this->employee, '2026-09-01', '2026-09-30');

    expect($report['rate'])->toBe(10.00);
    expect($report['rates_in_period'])->toBe(2);
    expect($report['warnings'])->toHaveCount(1);
    expect($report['warnings'][0])->toContain('pay rate changed');
});

it('reproduces an earlier period at the rate that applied then', function () {
    /*
     * Success criterion 6 in miniature: after a raise, asking for LAST month must still return
     * last month's figure. The old row is untouched — rows are inserted, never updated.
     */
    giveRate($this->employee, 'hourly', 10.00, '2026-01-01', '2026-08-31');
    giveRate($this->employee, 'hourly', 12.00, '2026-09-01');

    shift($this->employee, $this->outlet, '2026-08-10', 8);
    shift($this->employee, $this->outlet, '2026-09-10', 8);

    $august = $this->pay->forEmployee($this->employee, '2026-08-01', '2026-08-31');
    $september = $this->pay->forEmployee($this->employee, '2026-09-01', '2026-09-30');

    expect($august['rate'])->toBe(10.00);
    expect($august['total_amount'])->toBe(80.00);
    expect($september['rate'])->toBe(12.00);
    expect($september['total_amount'])->toBe(96.00);
});

it('reports an unpriced employee rather than zero', function () {
    /*
     * A zero flows into a total and looks like a real amount. The difference between "owes
     * nothing" and "not yet configured" matters enormously on a payslip, so it is its own state.
     */
    shift($this->employee, $this->outlet, '2026-09-01', 8);

    $report = $this->pay->forEmployee($this->employee, '2026-09-01', '2026-09-30');

    expect($report['has_rate'])->toBeFalse();
    expect($report['total_amount'])->toBeNull();
    expect($report['worked_seconds'])->toBe(8 * 3600);
    expect($report['warnings'][0])->toContain('No pay rate set');
});

// ---- Adhoc adjustments ------------------------------------------------

it('applies an adhoc overtime rate for the day it names', function () {
    giveRate($this->employee, 'hourly', 10.00, '2026-01-01', null, 15.00);
    shift($this->employee, $this->outlet, '2026-09-01', 10);

    RateAdjustment::create([
        'employee_id' => $this->employee->id,
        'applies_to_date' => '2026-09-01',
        'applies_to_period' => 'day',
        'applies_to' => 'overtime',
        'rate' => 25.00,
        'reason' => 'Agreed double time for the stock take.',
        'created_by' => $this->actor->id,
        'approved_at' => now(),
    ]);

    $report = $this->pay->forEmployee($this->employee, '2026-09-01', '2026-09-30');

    expect($report['overtime_amount'])->toBe(50.00);
    expect($report['total_amount'])->toBe(130.00);
});

it('applies a month adjustment across the period', function () {
    giveRate($this->employee, 'hourly', 10.00, '2026-01-01', null, 15.00);
    shift($this->employee, $this->outlet, '2026-09-01', 10);

    RateAdjustment::create([
        'employee_id' => $this->employee->id,
        'applies_to_date' => '2026-09-01',
        'applies_to_period' => 'month',
        'applies_to' => 'overtime',
        'rate' => 20.00,
        'reason' => 'Ramadan hours.',
        'created_by' => $this->actor->id,
        'approved_at' => now(),
    ]);

    $report = $this->pay->forEmployee($this->employee, '2026-09-01', '2026-09-30');

    expect($report['overtime_amount'])->toBe(40.00);
});

it('applies a day adjustment even when the pay period starts before it', function () {
    /*
     * A single busy Saturday must price that Saturday's overtime whether the pay period begins
     * before it or on it. Requiring the period start to fall inside the adjustment's span would
     * silently ignore it — the adjustment would exist, look approved, and do nothing.
     */
    giveRate($this->employee, 'hourly', 10.00, '2026-01-01', null, 15.00);
    shift($this->employee, $this->outlet, '2026-09-20', 10);

    RateAdjustment::create([
        'employee_id' => $this->employee->id,
        'applies_to_date' => '2026-09-20',
        'applies_to_period' => 'day',
        'applies_to' => 'overtime',
        'rate' => 30.00,
        'reason' => 'Deep clean Saturday.',
        'created_by' => $this->actor->id,
        'approved_at' => now(),
    ]);

    $report = $this->pay->forEmployee($this->employee, '2026-09-01', '2026-09-30');

    expect($report['overtime_amount'])->toBe(60.00);
});

it('ignores an adjustment that does not reach the period', function () {
    giveRate($this->employee, 'hourly', 10.00, '2026-01-01', null, 15.00);
    shift($this->employee, $this->outlet, '2026-09-01', 10);

    RateAdjustment::create([
        'employee_id' => $this->employee->id,
        'applies_to_date' => '2026-08-05',
        'applies_to_period' => 'day',
        'applies_to' => 'overtime',
        'rate' => 99.00,
        'reason' => 'Last month.',
        'created_by' => $this->actor->id,
        'approved_at' => now(),
    ]);

    $report = $this->pay->forEmployee($this->employee, '2026-09-01', '2026-09-30');

    expect($report['overtime_amount'])->toBe(30.00);
});

it('ignores an unapproved adjustment when approval is required', function () {
    /*
     * An unapproved adjustment must not move money — that is the whole point of an approval
     * step. With approval off (the decided default) it applies, and that difference is asserted
     * in the next test.
     */
    Setting::set(Setting::REQUIRE_RATE_APPROVAL, '1');

    giveRate($this->employee, 'hourly', 10.00, '2026-01-01', null, 15.00);
    shift($this->employee, $this->outlet, '2026-09-01', 10);

    RateAdjustment::create([
        'employee_id' => $this->employee->id,
        'applies_to_date' => '2026-09-01',
        'applies_to_period' => 'day',
        'applies_to' => 'overtime',
        'rate' => 25.00,
        'reason' => 'Pending the owner.',
        'created_by' => $this->actor->id,
        'approved_at' => null,
    ]);

    $report = $this->pay->forEmployee($this->employee, '2026-09-01', '2026-09-30');

    expect($report['overtime_amount'])->toBe(30.00);
});

it('applies a manager adjustment directly when approval is not required', function () {
    // Blueprint §10.2: a manager may set rates for their own staff, and the audit record is the
    // control rather than a second signature.
    Setting::set(Setting::REQUIRE_RATE_APPROVAL, '0');

    giveRate($this->employee, 'hourly', 10.00, '2026-01-01', null, 15.00);
    shift($this->employee, $this->outlet, '2026-09-01', 10);

    RateAdjustment::create([
        'employee_id' => $this->employee->id,
        'applies_to_date' => '2026-09-01',
        'applies_to_period' => 'day',
        'applies_to' => 'overtime',
        'rate' => 25.00,
        'reason' => 'Agreed with the owner.',
        'created_by' => $this->actor->id,
        'approved_at' => null,
    ]);

    $report = $this->pay->forEmployee($this->employee, '2026-09-01', '2026-09-30');

    expect($report['overtime_amount'])->toBe(50.00);
});

it('prefers the more specific adjustment when two overlap', function () {
    // The person who wrote the day adjustment was being more specific about what they wanted.
    giveRate($this->employee, 'hourly', 10.00, '2026-01-01', null, 15.00);
    shift($this->employee, $this->outlet, '2026-09-01', 10);

    RateAdjustment::create([
        'employee_id' => $this->employee->id,
        'applies_to_date' => '2026-09-01',
        'applies_to_period' => 'month',
        'applies_to' => 'overtime',
        'rate' => 20.00,
        'reason' => 'Whole month.',
        'created_by' => $this->actor->id,
        'approved_at' => now(),
    ]);

    RateAdjustment::create([
        'employee_id' => $this->employee->id,
        'applies_to_date' => '2026-09-01',
        'applies_to_period' => 'day',
        'applies_to' => 'overtime',
        'rate' => 30.00,
        'reason' => 'This particular day.',
        'created_by' => $this->actor->id,
        'approved_at' => now(),
    ]);

    $report = $this->pay->forEmployee($this->employee, '2026-09-01', '2026-09-30');

    expect($report['overtime_amount'])->toBe(60.00);
});

it('keeps an ordinary adjustment separate from an overtime one', function () {
    /*
     * `applies_to` is explicit because an employee can have both rates, and "changed the rate"
     * would be ambiguous the moment they do.
     */
    giveRate($this->employee, 'hourly', 10.00, '2026-01-01', null, 15.00);
    shift($this->employee, $this->outlet, '2026-09-01', 4);

    RateAdjustment::create([
        'employee_id' => $this->employee->id,
        'applies_to_date' => '2026-09-01',
        'applies_to_period' => 'month',
        'applies_to' => 'ordinary',
        'rate' => 20.00,
        'reason' => 'Temporary rate for covering the kitchen.',
        'created_by' => $this->actor->id,
        'approved_at' => now(),
    ]);

    $report = $this->pay->forEmployee($this->employee, '2026-09-01', '2026-09-30');

    // 4 hours at the OVERRIDDEN ordinary rate of 20.00.
    expect($report['ordinary_amount'])->toBe(80.00);
});

// ---- Rounding ---------------------------------------------------------

it('rounds once at the end rather than per line', function () {
    /*
     * Rounding each line accumulates error across twenty staff and a hundred lines, and the
     * total then drifts by real money.
     *
     * Three twenty-minute stints at 10.00/hour. Each is 3.3333, so rounding per line gives
     * 3.33 x 3 = 9.99 — a sen lost, from nothing but the order of operations. Computed at full
     * precision it is 10.00 exactly.
     *
     * The hours are fractional rather than the rate: a rate is stored as DECIMAL(12,2) and so
     * cannot hold sub-sen precision in the first place, which is itself the correct design.
     */
    giveRate($this->employee, 'hourly', 10.00, '2026-01-01');

    foreach (['2026-09-01', '2026-09-02', '2026-09-03'] as $date) {
        $timezone = $this->outlet->timezone;
        $startedAt = CarbonImmutable::parse($date.' 09:00', $timezone);

        TimeEntry::create([
            'client_uuid' => (string) Str::uuid(),
            'employee_id' => $this->employee->id,
            'outlet_id' => $this->outlet->id,
            'type' => TimeEntryType::WORK,
            'started_at' => $startedAt,
            'ended_at' => $startedAt->addMinutes(20),
            'business_date' => $startedAt->toDateString(),
            'status' => TimeEntryStatus::CLOSED,
        ]);
    }

    $report = $this->pay->forEmployee($this->employee, '2026-09-01', '2026-09-30');

    // One hour at 10.00. Rounded per line it would be 9.99.
    expect($report['worked_seconds'])->toBe(3600);
    expect($report['ordinary_amount'])->toBe(10.00);
});

it('stores a rate to two decimals only', function () {
    // Money is 2dp: a rate with sub-sen precision is not storable, which prevents a whole class
    // of drift before the arithmetic even runs.
    $rate = giveRate($this->employee, 'hourly', 10.005, '2026-01-01');

    expect((float) $rate->fresh()->rate)->toBe(10.01);
});

it('totals the rounded per-employee amounts', function () {
    /*
     * What the business pays is the sum of the amounts on each payslip, so the total must be
     * the sum of the ROUNDED figures. Summing raw floats would give a total that does not match
     * the parts, and someone would have to reconcile it by hand.
     */
    $second = Employee::create(['employee_code' => 'RAM-002', 'name' => 'Siti Aminah', 'is_active' => true]);
    $second->outlets()->attach($this->outlet->id);

    // A third of an hour at 10.00 is 3.3333 per person, so each rounds to 3.33.
    giveRate($this->employee, 'hourly', 10.00, '2026-01-01');
    giveRate($second, 'hourly', 10.00, '2026-01-01');

    shift($this->employee, $this->outlet, '2026-09-01', 1 / 3);
    shift($second, $this->outlet, '2026-09-01', 1 / 3);

    $summary = $this->pay->summarise(
        Employee::query()->orderBy('name')->get(),
        '2026-09-01',
        '2026-09-30',
    );

    $ali = $summary['employees'][0]['ordinary_amount'];
    $aminah = $summary['employees'][1]['ordinary_amount'];

    // Sum of the parts, not a separately-rounded whole (which would be 6.67).
    expect($summary['totals']['ordinary_amount'])->toBe(round($ali + $aminah, 2));
    expect($summary['totals']['ordinary_amount'])->toBe(6.66);
});

// ---- Boundaries -------------------------------------------------------

it('does not price a punch outside the period', function () {
    giveRate($this->employee, 'hourly', 10.00, '2026-01-01');
    shift($this->employee, $this->outlet, '2026-08-31', 8);
    shift($this->employee, $this->outlet, '2026-09-01', 8);

    $report = $this->pay->forEmployee($this->employee, '2026-09-01', '2026-09-30');

    expect($report['worked_seconds'])->toBe(8 * 3600);
});

it('prices an early-morning shift on the right day', function () {
    /*
     * 00:30 local is 16:30 UTC the PREVIOUS day, so a UTC-derived range would price this shift
     * in the wrong period — or drop it entirely.
     */
    giveRate($this->employee, 'hourly', 10.00, '2026-01-01');

    $timezone = $this->outlet->timezone;
    $startedAt = CarbonImmutable::parse('2026-09-01 00:30', $timezone);

    TimeEntry::create([
        'client_uuid' => (string) Str::uuid(),
        'employee_id' => $this->employee->id,
        'outlet_id' => $this->outlet->id,
        'type' => TimeEntryType::WORK,
        'started_at' => $startedAt,
        'ended_at' => $startedAt->addHours(4),
        'business_date' => $startedAt->toDateString(),
        'status' => TimeEntryStatus::CLOSED,
    ]);

    $report = $this->pay->forEmployee($this->employee, '2026-09-01', '2026-09-30');

    expect($report['worked_seconds'])->toBe(4 * 3600);
    expect($report['ordinary_amount'])->toBe(40.00);
});

it('groups a shift crossing midnight under the day it started', function () {
    // The business-day rule, asserted through its monetary effect: a night shift must not be
    // split across two days and so across two overtime thresholds.
    giveRate($this->employee, 'hourly', 10.00, '2026-01-01', null, 15.00);

    $timezone = $this->outlet->timezone;
    $startedAt = CarbonImmutable::parse('2026-09-01 22:00', $timezone);

    TimeEntry::create([
        'client_uuid' => (string) Str::uuid(),
        'employee_id' => $this->employee->id,
        'outlet_id' => $this->outlet->id,
        'type' => TimeEntryType::WORK,
        'started_at' => $startedAt,
        'ended_at' => $startedAt->addHours(10),
        'business_date' => '2026-09-01',
        'status' => TimeEntryStatus::CLOSED,
    ]);

    $report = $this->pay->forEmployee($this->employee, '2026-09-01', '2026-09-30');

    // One day of 10 hours: 8 ordinary, 2 overtime — not two days of 5 hours.
    expect($report['days_worked'])->toBe(1);
    expect($report['overtime_seconds'])->toBe(2 * 3600);
});

it('does not price a break segment', function () {
    // Breaks are unpaid by default, and a break segment is not work.
    giveRate($this->employee, 'hourly', 10.00, '2026-01-01');

    $timezone = $this->outlet->timezone;
    $startedAt = CarbonImmutable::parse('2026-09-01 12:00', $timezone);

    TimeEntry::create([
        'client_uuid' => (string) Str::uuid(),
        'employee_id' => $this->employee->id,
        'outlet_id' => $this->outlet->id,
        'type' => TimeEntryType::BREAK,
        'started_at' => $startedAt,
        'ended_at' => $startedAt->addHour(),
        'business_date' => $startedAt->toDateString(),
        'status' => TimeEntryStatus::CLOSED,
    ]);

    $report = $this->pay->forEmployee($this->employee, '2026-09-01', '2026-09-30');

    expect($report['worked_seconds'])->toBe(0);
    expect($report['ordinary_amount'])->toBe(0.0);
});
