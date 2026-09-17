<?php

use App\Enums\TimeEntryStatus;
use App\Enums\TimeEntryType;
use App\Enums\UserRole;
use App\Models\Anomaly;
use App\Models\CompensationRule;
use App\Models\Employee;
use App\Models\Outlet;
use App\Models\PayPeriod;
use App\Models\RateAdjustment;
use App\Models\Setting;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\CorrectionService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Pay through the API.
 *
 * Behaviour is covered by PayTest and PayPeriodTest; this is about the plumbing — versioned
 * rate storage, scoping, the locked-period endpoints, and the CSV.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-01 10:00:00', 'Asia/Kuala_Lumpur'));

    $this->outlet = Outlet::create([
        'code' => 'SG-RAMAL', 'name' => 'Sg Ramal', 'timezone' => 'Asia/Kuala_Lumpur',
        'token_mode' => 'printed', 'requires_photo' => false,
    ]);

    $this->otherOutlet = Outlet::create([
        'code' => 'SEDAP-SANTAI', 'name' => 'Sedap Santai', 'timezone' => 'Asia/Kuala_Lumpur',
        'token_mode' => 'printed', 'requires_photo' => false,
    ]);

    $this->owner = User::factory()->create(['role' => UserRole::OWNER]);
    $this->manager = User::factory()->create(['role' => UserRole::MANAGER]);
    $this->manager->outlets()->attach($this->outlet->id);

    $this->employee = Employee::create([
        'employee_code' => 'RAM-001', 'name' => 'Ali bin Ahmad',
        'is_active' => true, 'pay_basis' => 'hourly',
    ]);
    $this->employee->outlets()->attach($this->outlet->id);
});

function worked(Employee $employee, Outlet $outlet, string $date, float $hours): TimeEntry
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

// ---- Setting rates ----------------------------------------------------

it('sets a rate and keeps the employee pay basis in step', function () {
    // The rate and the basis describe the same arrangement, so letting them disagree would be
    // a trap for whoever reads the employee record next.
    $this->withHeaders(asUser($this->manager))
        ->postJson("/api/v1/admin/rates/employees/{$this->employee->id}", [
            'basis' => 'monthly',
            'rate' => '2400.00',
            'effective_from' => '2026-10-01',
        ])
        ->assertStatus(201);

    expect($this->employee->fresh()->pay_basis->value)->toBe('monthly');
});

it('closes the previous rate row rather than overwriting it', function () {
    /*
     * Success criterion 6. Overwriting the old figure is exactly what makes "what was he paid in
     * March?" unanswerable, so a raise closes the current row and inserts a new one.
     */
    $this->withHeaders(asUser($this->owner))
        ->postJson("/api/v1/admin/rates/employees/{$this->employee->id}", [
            'basis' => 'hourly', 'rate' => '10.00', 'effective_from' => '2026-01-01',
        ])
        ->assertStatus(201);

    $this->withHeaders(asUser($this->owner))
        ->postJson("/api/v1/admin/rates/employees/{$this->employee->id}", [
            'basis' => 'hourly', 'rate' => '12.00', 'effective_from' => '2026-10-01',
        ])
        ->assertStatus(201);

    $rows = CompensationRule::query()->orderBy('effective_from')->get();

    expect($rows)->toHaveCount(2);
    expect((float) $rows[0]->rate)->toBe(10.00);
    // Closed the day BEFORE the new rate, so the two never overlap.
    expect($rows[0]->effective_to->toDateString())->toBe('2026-09-30');
    expect((float) $rows[1]->rate)->toBe(12.00);
    expect($rows[1]->effective_to)->toBeNull();
});

it('replaces a rate starting on the same day rather than closing it', function () {
    /*
     * Closing a same-day row would give it an effective_to before its effective_from — a row
     * that can never apply, and one that would confuse every future reader.
     */
    $this->withHeaders(asUser($this->owner))
        ->postJson("/api/v1/admin/rates/employees/{$this->employee->id}", [
            'basis' => 'hourly', 'rate' => '10.00', 'effective_from' => '2026-10-01',
        ]);

    $this->withHeaders(asUser($this->owner))
        ->postJson("/api/v1/admin/rates/employees/{$this->employee->id}", [
            'basis' => 'hourly', 'rate' => '11.00', 'effective_from' => '2026-10-01',
        ]);

    $rows = CompensationRule::query()->get();

    expect($rows)->toHaveCount(1);
    expect((float) $rows[0]->rate)->toBe(11.00);
});

it('refuses a rate with more than two decimals', function () {
    // Money is 2dp. A sub-sen rate is not storable and would silently round.
    $this->withHeaders(asUser($this->owner))
        ->postJson("/api/v1/admin/rates/employees/{$this->employee->id}", [
            'basis' => 'hourly', 'rate' => '10.005', 'effective_from' => '2026-10-01',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('rate');
});

it('lists the current rates and flags an employee with none', function () {
    $this->withHeaders(asUser($this->owner))
        ->postJson("/api/v1/admin/rates/employees/{$this->employee->id}", [
            'basis' => 'hourly', 'rate' => '10.00', 'effective_from' => '2026-01-01',
        ]);

    $response = $this->withHeaders(asUser($this->manager))
        ->getJson('/api/v1/admin/rates')
        ->assertOk();

    expect($response->json('data.employees.0.has_rate'))->toBeTrue();
    expect($response->json('data.employees.0.rate'))->toEqual(10.00);
});

it('returns the whole rate history, not just the current row', function () {
    foreach ([['2026-01-01', '10.00'], ['2026-06-01', '11.00'], ['2026-10-01', '12.00']] as [$from, $amount]) {
        $this->withHeaders(asUser($this->owner))
            ->postJson("/api/v1/admin/rates/employees/{$this->employee->id}", [
                'basis' => 'hourly', 'rate' => $amount, 'effective_from' => $from,
            ]);
    }

    $response = $this->withHeaders(asUser($this->manager))
        ->getJson("/api/v1/admin/rates/employees/{$this->employee->id}")
        ->assertOk();

    expect($response->json('data.rates'))->toHaveCount(3);
    // Newest first, and only the newest is open-ended.
    expect($response->json('data.rates.0.is_current'))->toBeTrue();
    expect($response->json('data.rates.1.is_current'))->toBeFalse();
});

it('does not let a manager set a rate for another outlet employee', function () {
    $otherEmployee = Employee::create(['employee_code' => 'SS-001', 'name' => 'Someone Else', 'is_active' => true]);
    $otherEmployee->outlets()->attach($this->otherOutlet->id);

    $this->withHeaders(asUser($this->manager))
        ->postJson("/api/v1/admin/rates/employees/{$otherEmployee->id}", [
            'basis' => 'hourly', 'rate' => '10.00', 'effective_from' => '2026-10-01',
        ])
        ->assertStatus(404);

    expect(CompensationRule::count())->toBe(0);
});

it('does not show another outlet employee in the rate list', function () {
    $otherEmployee = Employee::create(['employee_code' => 'SS-001', 'name' => 'Someone Else', 'is_active' => true]);
    $otherEmployee->outlets()->attach($this->otherOutlet->id);

    $response = $this->withHeaders(asUser($this->manager))
        ->getJson('/api/v1/admin/rates')
        ->assertOk();

    $names = collect($response->json('data.employees'))->pluck('name');

    expect($names)->toContain('Ali bin Ahmad');
    expect($names)->not->toContain('Someone Else');
});

// ---- Adjustments ------------------------------------------------------

it('requires a reason for an adjustment', function () {
    // The record is the only control over a manager setting their own staff's rates, so it has
    // to be complete rather than convenient.
    $this->withHeaders(asUser($this->manager))
        ->postJson('/api/v1/admin/rates/adjustments', [
            'employee_id' => $this->employee->id,
            'applies_to_date' => '2026-10-01',
            'applies_to_period' => 'day',
            'applies_to' => 'overtime',
            'rate' => '25.00',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('reason');
});

it('requires the adjustment to name which rate it changes', function () {
    $this->withHeaders(asUser($this->manager))
        ->postJson('/api/v1/admin/rates/adjustments', [
            'employee_id' => $this->employee->id,
            'applies_to_date' => '2026-10-01',
            'applies_to_period' => 'day',
            'rate' => '25.00',
            'reason' => 'Double time for the stock take.',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('applies_to');
});

it('records a manager adjustment without owner approval by default', function () {
    // Blueprint §10.2: the audit record is the control, not a second signature.
    Setting::set(Setting::REQUIRE_RATE_APPROVAL, '0');

    $this->withHeaders(asUser($this->manager))
        ->postJson('/api/v1/admin/rates/adjustments', [
            'employee_id' => $this->employee->id,
            'applies_to_date' => '2026-10-01',
            'applies_to_period' => 'day',
            'applies_to' => 'overtime',
            'rate' => '25.00',
            'reason' => 'Agreed double time for the stock take.',
        ])
        ->assertStatus(201);

    $adjustment = RateAdjustment::first();

    expect($adjustment->approved_at)->not->toBeNull();
    expect($adjustment->reason)->toBe('Agreed double time for the stock take.');
    expect($adjustment->created_by)->toBe($this->manager->id);
});

it('leaves an adjustment pending when approval is required', function () {
    Setting::set(Setting::REQUIRE_RATE_APPROVAL, '1');

    $this->withHeaders(asUser($this->manager))
        ->postJson('/api/v1/admin/rates/adjustments', [
            'employee_id' => $this->employee->id,
            'applies_to_date' => '2026-10-01',
            'applies_to_period' => 'day',
            'applies_to' => 'overtime',
            'rate' => '25.00',
            'reason' => 'Requested by the outlet.',
        ])
        ->assertStatus(201);

    expect(RateAdjustment::first()->approved_at)->toBeNull();
});

it('lets only the owner approve a pending adjustment', function () {
    Setting::set(Setting::REQUIRE_RATE_APPROVAL, '1');

    $this->withHeaders(asUser($this->manager))
        ->postJson('/api/v1/admin/rates/adjustments', [
            'employee_id' => $this->employee->id,
            'applies_to_date' => '2026-10-01',
            'applies_to_period' => 'day',
            'applies_to' => 'overtime',
            'rate' => '25.00',
            'reason' => 'Requested by the outlet.',
        ]);

    $adjustment = RateAdjustment::first();

    $this->withHeaders(asUser($this->manager))
        ->postJson("/api/v1/admin/rates/adjustments/{$adjustment->id}/approve")
        ->assertStatus(403);

    // freshRequest() between requests: a feature test reuses ONE application instance, so the
    // auth guard holds the previous request's user and a later request would still be seen as
    // the manager. Production has a fresh process per request, so this is harness-only.
    freshRequest();

    $this->withHeaders(asUser($this->owner))
        ->postJson("/api/v1/admin/rates/adjustments/{$adjustment->id}/approve")
        ->assertOk();

    expect($adjustment->fresh()->approved_at)->not->toBeNull();
});

it('raises an oversight flag when a manager sets a rate', function () {
    /*
     * A manager may set rates for their own staff, and their change carries no second
     * signature — so it is surfaced to the owner rather than left in a log only the manager
     * would read.
     */
    worked($this->employee, $this->outlet, '2026-09-10', 8);

    $this->withHeaders(asUser($this->manager))
        ->postJson('/api/v1/admin/rates/adjustments', [
            'employee_id' => $this->employee->id,
            'applies_to_date' => '2026-10-01',
            'applies_to_period' => 'day',
            'applies_to' => 'overtime',
            'rate' => '25.00',
            'reason' => 'Agreed double time.',
        ])
        ->assertStatus(201);

    expect(Anomaly::where('type', 'manager_rate_change')->exists())->toBeTrue();
});

it('does not raise an oversight flag for an owner rate change', function () {
    // The owner is the final authority, so there is nobody to tell.
    worked($this->employee, $this->outlet, '2026-09-10', 8);

    $this->withHeaders(asUser($this->owner))
        ->postJson('/api/v1/admin/rates/adjustments', [
            'employee_id' => $this->employee->id,
            'applies_to_date' => '2026-10-01',
            'applies_to_period' => 'day',
            'applies_to' => 'overtime',
            'rate' => '25.00',
            'reason' => 'Owner decision.',
        ])
        ->assertStatus(201);

    expect(Anomaly::where('type', 'manager_rate_change')->exists())->toBeFalse();
});

// ---- Pay summary ------------------------------------------------------

it('returns a pay summary for the current month by default', function () {
    $this->withHeaders(asUser($this->owner))
        ->postJson("/api/v1/admin/rates/employees/{$this->employee->id}", [
            'basis' => 'hourly', 'rate' => '10.00', 'effective_from' => '2026-01-01',
        ]);

    // The clock is frozen at 1 October, so this is inside the default month-to-date range.
    worked($this->employee, $this->outlet, '2026-10-01', 8);

    $response = $this->withHeaders(asUser($this->manager))
        ->getJson('/api/v1/admin/pay/summary')
        ->assertOk();

    expect($response->json('data.from'))->toBe('2026-10-01');
    expect($response->json('data.to'))->toBe('2026-10-01');
    expect($response->json('data.totals.total_amount'))->toEqual(80.00);
});

it('marks an employee with no rate as unpriced rather than zero', function () {
    // A zero in a payroll screen is a figure somebody will pay.
    worked($this->employee, $this->outlet, '2026-10-05', 8);

    $response = $this->withHeaders(asUser($this->manager))
        ->getJson('/api/v1/admin/pay/summary?from=2026-10-01&to=2026-10-31')
        ->assertOk();

    expect($response->json('data.employees.0.has_rate'))->toBeFalse();
    expect($response->json('data.employees.0.total_amount'))->toBeNull();
    expect($response->json('data.totals.unpriced_count'))->toBe(1);
});

it('does not price another outlet employee', function () {
    $otherEmployee = Employee::create(['employee_code' => 'SS-001', 'name' => 'Someone Else', 'is_active' => true]);
    $otherEmployee->outlets()->attach($this->otherOutlet->id);

    CompensationRule::create([
        'employee_id' => $otherEmployee->id, 'basis' => 'hourly', 'rate' => 100.00,
        'effective_from' => '2026-01-01', 'created_by' => $this->owner->id,
    ]);

    worked($otherEmployee, $this->otherOutlet, '2026-10-05', 8);

    $response = $this->withHeaders(asUser($this->manager))
        ->getJson('/api/v1/admin/pay/summary?from=2026-10-01&to=2026-10-31')
        ->assertOk();

    $names = collect($response->json('data.employees'))->pluck('name');

    expect($names)->not->toContain('Someone Else');
    expect($response->json('data.totals.total_amount'))->toEqual(0.0);
});

it('does not let a manager read another outlet employee pay', function () {
    $otherEmployee = Employee::create(['employee_code' => 'SS-001', 'name' => 'Someone Else', 'is_active' => true]);
    $otherEmployee->outlets()->attach($this->otherOutlet->id);

    $this->withHeaders(asUser($this->manager))
        ->getJson("/api/v1/admin/pay/employees/{$otherEmployee->id}")
        ->assertStatus(404);
});

it('exports pay as a CSV with blank amounts for unpriced employees', function () {
    $this->withHeaders(asUser($this->owner))
        ->postJson("/api/v1/admin/rates/employees/{$this->employee->id}", [
            'basis' => 'hourly', 'rate' => '10.00', 'effective_from' => '2026-01-01',
        ]);

    worked($this->employee, $this->outlet, '2026-10-05', 8);

    $otherEmployee = Employee::create(['employee_code' => 'RAM-002', 'name' => 'No Rate', 'is_active' => true]);
    $otherEmployee->outlets()->attach($this->outlet->id);
    worked($otherEmployee, $this->outlet, '2026-10-05', 8);

    $body = $this->withHeaders(asUser($this->manager))
        ->get('/api/v1/admin/pay/export?from=2026-10-01&to=2026-10-31')
        ->streamedContent();

    expect($body)->toStartWith("\xEF\xBB\xBF");
    expect($body)->toContain('Ordinary amount');
    /*
     * Money is formatted to two decimals. An unformatted float reaches a CSV as `10`, and a
     * column of mixed 10 / 10.5 / 10.55 invites misreading and can make a spreadsheet import
     * infer a wrong column type.
     */
    expect($body)->toContain('10.00');
    expect($body)->toContain('80.00');
    expect($body)->toContain('No Rate');
});

// ---- Pay periods ------------------------------------------------------

it('creates a pay period and refuses an overlapping one', function () {
    $this->withHeaders(asUser($this->owner))
        ->postJson('/api/v1/admin/pay-periods', [
            'name' => 'September 2026', 'starts_on' => '2026-09-01', 'ends_on' => '2026-09-30',
        ])
        ->assertStatus(201);

    $this->withHeaders(asUser($this->owner))
        ->postJson('/api/v1/admin/pay-periods', [
            'name' => 'Late September', 'starts_on' => '2026-09-15', 'ends_on' => '2026-09-30',
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'INVALID_PERIOD');

    expect(PayPeriod::count())->toBe(1);
});

it('lets only the owner lock a period', function () {
    /*
     * Locking is the act that makes a figure the record of what was paid. A manager committing
     * their own outlet's payroll is not a power to hand out.
     */
    $this->withHeaders(asUser($this->owner))
        ->postJson('/api/v1/admin/pay-periods', [
            'name' => 'September 2026', 'starts_on' => '2026-09-01', 'ends_on' => '2026-09-30',
        ]);

    $period = PayPeriod::first();

    /*
     * freshRequest() BEFORE the manager's attempt, not only after it.
     *
     * A feature test reuses ONE application instance, so after the owner created the period the
     * auth guard still holds the OWNER. Without this reset the manager's request is evaluated as
     * the owner and succeeds — which would look like the check being broken when the check is
     * fine. Production has a fresh process per request, so this is harness-only.
     */
    freshRequest();

    $this->withHeaders(asUser($this->manager))
        ->postJson("/api/v1/admin/pay-periods/{$period->id}/lock")
        ->assertStatus(403);

    freshRequest();

    $this->withHeaders(asUser($this->owner))
        ->postJson("/api/v1/admin/pay-periods/{$period->id}/lock")
        ->assertOk();

    expect($period->fresh()->isLocked())->toBeTrue();
});

it('reports the committed total from the period', function () {
    $this->withHeaders(asUser($this->owner))
        ->postJson("/api/v1/admin/rates/employees/{$this->employee->id}", [
            'basis' => 'hourly', 'rate' => '10.00', 'effective_from' => '2026-01-01',
        ]);

    worked($this->employee, $this->outlet, '2026-09-10', 8);

    $this->withHeaders(asUser($this->owner))
        ->postJson('/api/v1/admin/pay-periods', [
            'name' => 'September 2026', 'starts_on' => '2026-09-01', 'ends_on' => '2026-09-30',
        ]);

    $period = PayPeriod::first();

    // Unlocked: nothing committed yet.
    expect($period->snapshot)->toBeNull();

    $this->withHeaders(asUser($this->owner))
        ->postJson("/api/v1/admin/pay-periods/{$period->id}/lock")
        ->assertOk();

    $response = $this->withHeaders(asUser($this->owner))
        ->getJson('/api/v1/admin/pay-periods')
        ->assertOk();

    expect($response->json('data.periods.0.committed_total'))->toEqual(80.00);
});

it('reports a locked period as unchanged, then drifted after a correction', function () {
    $this->withHeaders(asUser($this->owner))
        ->postJson("/api/v1/admin/rates/employees/{$this->employee->id}", [
            'basis' => 'hourly', 'rate' => '10.00', 'effective_from' => '2026-01-01',
        ]);

    $entry = worked($this->employee, $this->outlet, '2026-09-10', 8);

    $this->withHeaders(asUser($this->owner))
        ->postJson('/api/v1/admin/pay-periods', [
            'name' => 'September 2026', 'starts_on' => '2026-09-01', 'ends_on' => '2026-09-30',
        ]);

    $period = PayPeriod::first();

    $this->withHeaders(asUser($this->owner))
        ->postJson("/api/v1/admin/pay-periods/{$period->id}/lock");

    $clean = $this->withHeaders(asUser($this->owner))
        ->getJson("/api/v1/admin/pay-periods/{$period->id}/reconcile")
        ->assertOk();

    expect($clean->json('data.state'))->toBe('locked');

    // A correction lands after the period was closed.
    app(CorrectionService::class)->request(
        $entry,
        ['ended_at' => CarbonImmutable::parse('2026-09-10 13:00', $this->outlet->timezone)->toIso8601String()],
        'Left early; confirmed with the manager.',
        $this->owner,
    );

    $drifted = $this->withHeaders(asUser($this->owner))
        ->getJson("/api/v1/admin/pay-periods/{$period->id}/reconcile")
        ->assertOk();

    expect($drifted->json('data.state'))->toBe('drifted');
    // The approved figure is preserved exactly as it was committed.
    expect($drifted->json('data.approved.totals.total_amount'))->toEqual(80.00);
    expect($drifted->json('data.current.totals.total_amount'))->toEqual(40.00);
    expect($drifted->json('data.difference.delta_total'))->toEqual(-40.00);
});

it('includes the covering period in the pay summary', function () {
    $this->withHeaders(asUser($this->owner))
        ->postJson('/api/v1/admin/pay-periods', [
            'name' => 'October 2026', 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-31',
        ]);

    $period = PayPeriod::first();

    $this->withHeaders(asUser($this->owner))
        ->postJson("/api/v1/admin/pay-periods/{$period->id}/lock");

    $response = $this->withHeaders(asUser($this->manager))
        ->getJson('/api/v1/admin/pay/summary?from=2026-10-01&to=2026-10-31')
        ->assertOk();

    expect($response->json('data.period.is_locked'))->toBeTrue();
    expect($response->json('data.period.covers_range'))->toBeTrue();
});
