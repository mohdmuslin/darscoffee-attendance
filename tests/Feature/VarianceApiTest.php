<?php

use App\Enums\TimeEntryStatus;
use App\Enums\TimeEntryType;
use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\Outlet;
use App\Models\Shift;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\ShiftService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * The variance report through the API.
 *
 * Behaviour is covered by VarianceTest; this is about the plumbing — scoping, the CSV, and
 * that the endpoints are genuinely read-only.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-18 18:00:00', 'Asia/Kuala_Lumpur'));

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

    $this->employee = Employee::create(['employee_code' => 'RAM-001', 'name' => 'Ali bin Ahmad', 'is_active' => true]);
    $this->employee->outlets()->attach($this->outlet->id);

    $this->shifts = app(ShiftService::class);
});

function planFor(Outlet $outlet, Employee $employee, string $date, string $start, string $end, ?User $owner = null): Shift
{
    return app(ShiftService::class)->create($outlet, $employee, [
        'starts_at' => "{$date}T{$start}",
        'ends_at' => "{$date}T{$end}",
    ], $owner ?? test()->owner);
}

function workedFor(Outlet $outlet, Employee $employee, string $date, string $start, string $end): TimeEntry
{
    $timezone = $outlet->timezone;
    $startedAt = CarbonImmutable::parse("{$date} {$start}", $timezone);

    return TimeEntry::create([
        'client_uuid' => (string) Str::uuid(),
        'employee_id' => $employee->id,
        'outlet_id' => $outlet->id,
        'type' => TimeEntryType::WORK,
        'started_at' => $startedAt,
        'ended_at' => CarbonImmutable::parse("{$date} {$end}", $timezone),
        'business_date' => $startedAt->toDateString(),
        'status' => TimeEntryStatus::CLOSED,
    ]);
}

// ---- Summary ----------------------------------------------------------

it('returns a variance summary', function () {
    planFor($this->outlet, $this->employee, '2026-09-14', '09:00', '17:00');
    workedFor($this->outlet, $this->employee, '2026-09-14', '09:00', '13:00');

    $response = $this->withHeaders(asUser($this->manager))
        ->getJson('/api/v1/admin/variance/summary?from=2026-09-14&to=2026-09-14')
        ->assertOk();

    expect($response->json('data.employees'))->toHaveCount(1);
    expect($response->json('data.employees.0.planned_label'))->toBe('8h 0m');
    expect($response->json('data.employees.0.worked_label'))->toBe('4h 0m');
    expect($response->json('data.employees.0.variance_label'))->toBe('-4h 0m');
    expect($response->json('data.employees.0.has_variance'))->toBeTrue();
});

it('reports totals across employees with formatted labels', function () {
    planFor($this->outlet, $this->employee, '2026-09-14', '09:00', '17:00');
    workedFor($this->outlet, $this->employee, '2026-09-14', '09:00', '17:00');

    $response = $this->withHeaders(asUser($this->manager))
        ->getJson('/api/v1/admin/variance/summary?from=2026-09-14&to=2026-09-14')
        ->assertOk();

    expect($response->json('data.totals.planned_label'))->toBe('8h 0m');
    expect($response->json('data.totals.variance_label'))->toBe('0m');
});

it('defaults the range to the last week in the business timezone', function () {
    /*
     * The frozen clock is 18:00 Kuala Lumpur, which is 10:00 UTC — so a UTC-anchored default
     * would be the same date here. The point of the test is that the range is COMPUTED rather
     * than absent, and that today is included.
     */
    $response = $this->withHeaders(asUser($this->manager))
        ->getJson('/api/v1/admin/variance/summary')
        ->assertOk();

    expect($response->json('data.to'))->toBe('2026-09-18');
    expect($response->json('data.from'))->toBe('2026-09-12');
});

it('corrects an inverted range rather than refusing it', function () {
    // A manager who typed the dates the wrong way round wants their report, not a lecture.
    $response = $this->withHeaders(asUser($this->manager))
        ->getJson('/api/v1/admin/variance/summary?from=2026-09-18&to=2026-09-14')
        ->assertOk();

    expect($response->json('data.from'))->toBe('2026-09-14');
    expect($response->json('data.to'))->toBe('2026-09-18');
});

it('clamps a range that is far too long', function () {
    // One request cannot be turned into a scan of every shift ever recorded. 62 days back
    // from 2026-09-18 is 2026-07-18.
    $response = $this->withHeaders(asUser($this->manager))
        ->getJson('/api/v1/admin/variance/summary?from=2020-01-01&to=2026-09-18')
        ->assertOk();

    expect($response->json('data.from'))->toBe('2026-07-18');
    expect($response->json('data.to'))->toBe('2026-09-18');
});

// ---- Detail -----------------------------------------------------------

it('returns the day-by-day detail for one employee', function () {
    planFor($this->outlet, $this->employee, '2026-09-14', '09:00', '17:00');
    workedFor($this->outlet, $this->employee, '2026-09-14', '09:00', '17:00');
    workedFor($this->outlet, $this->employee, '2026-09-16', '10:00', '12:00');

    $response = $this->withHeaders(asUser($this->manager))
        ->getJson("/api/v1/admin/variance/employees/{$this->employee->id}?from=2026-09-14&to=2026-09-16")
        ->assertOk();

    $rows = $response->json('data.variance.rows');

    expect($rows)->toHaveCount(2);
    expect($rows[0]['status'])->toBe('met');
    expect($rows[1]['type'])->toBe('unplanned');
    expect($response->json('data.variance.adhoc_label'))->toBe('2h 0m');
});

it('does not let a manager read another outlet employee variance', function () {
    $otherEmployee = Employee::create(['employee_code' => 'SS-001', 'name' => 'Someone Else', 'is_active' => true]);
    $otherEmployee->outlets()->attach($this->otherOutlet->id);

    $this->withHeaders(asUser($this->manager))
        ->getJson("/api/v1/admin/variance/employees/{$otherEmployee->id}")
        ->assertStatus(404);
});

it('does not include another outlet employee in the summary', function () {
    $otherEmployee = Employee::create(['employee_code' => 'SS-001', 'name' => 'Someone Else', 'is_active' => true]);
    $otherEmployee->outlets()->attach($this->otherOutlet->id);

    $response = $this->withHeaders(asUser($this->manager))
        ->getJson('/api/v1/admin/variance/summary?from=2026-09-14&to=2026-09-14')
        ->assertOk();

    $names = collect($response->json('data.employees'))->pluck('name');

    expect($names)->toContain('Ali bin Ahmad');
    expect($names)->not->toContain('Someone Else');
});

it('does not leak another outlet hours through an outlet_id filter', function () {
    // The scope is applied first, so asking for an outlet the manager cannot see returns
    // nothing rather than that outlet's figures.
    $otherEmployee = Employee::create(['employee_code' => 'SS-001', 'name' => 'Someone Else', 'is_active' => true]);
    $otherEmployee->outlets()->attach($this->otherOutlet->id);

    planFor($this->otherOutlet, $otherEmployee, '2026-09-14', '09:00', '17:00', $this->owner);
    workedFor($this->otherOutlet, $otherEmployee, '2026-09-14', '09:00', '17:00');

    $response = $this->withHeaders(asUser($this->manager))
        ->getJson('/api/v1/admin/variance/summary?from=2026-09-14&to=2026-09-14&outlet_id='.$this->otherOutlet->id)
        ->assertOk();

    expect($response->json('data.employees'))->toBeEmpty();
});

// ---- Read-only --------------------------------------------------------

it('changes nothing when the report is run', function () {
    /*
     * Worth pinning: a report that quietly altered a shift or an entry would be reporting on
     * figures it had just changed, and the mistake would be invisible.
     */
    planFor($this->outlet, $this->employee, '2026-09-14', '09:00', '17:00');
    workedFor($this->outlet, $this->employee, '2026-09-14', '09:00', '13:00');

    $shiftsBefore = Shift::query()->count();
    $entriesBefore = TimeEntry::query()->count();
    $shiftSnapshot = Shift::query()->orderBy('id')->get()->map->toArray()->all();
    $entrySnapshot = TimeEntry::query()->orderBy('id')->get()->map->toArray()->all();

    $this->withHeaders(asUser($this->manager))
        ->getJson('/api/v1/admin/variance/summary?from=2026-09-14&to=2026-09-14')
        ->assertOk();

    $this->withHeaders(asUser($this->manager))
        ->getJson("/api/v1/admin/variance/employees/{$this->employee->id}?from=2026-09-14&to=2026-09-14")
        ->assertOk();

    expect(Shift::query()->count())->toBe($shiftsBefore);
    expect(TimeEntry::query()->count())->toBe($entriesBefore);
    expect(Shift::query()->orderBy('id')->get()->map->toArray()->all())->toBe($shiftSnapshot);
    expect(TimeEntry::query()->orderBy('id')->get()->map->toArray()->all())->toBe($entrySnapshot);
});

// ---- Export -----------------------------------------------------------

it('exports the variance as a CSV', function () {
    planFor($this->outlet, $this->employee, '2026-09-14', '09:00', '17:00');
    workedFor($this->outlet, $this->employee, '2026-09-14', '09:00', '13:00');

    $response = $this->withHeaders(asUser($this->manager))
        ->get('/api/v1/admin/variance/export?from=2026-09-14&to=2026-09-14');

    $response->assertOk();

    $body = $response->streamedContent();

    expect($body)->toStartWith("\xEF\xBB\xBF");
    expect($body)->toContain('Employee code');
    expect($body)->toContain('Variance hours');
    // Decimal hours, because a spreadsheet cannot sum "8h 30m".
    expect($body)->toContain('8.00');
    expect($body)->toContain('-4.00');
});

it('names the export with its range', function () {
    $response = $this->withHeaders(asUser($this->manager))
        ->get('/api/v1/admin/variance/export?from=2026-09-14&to=2026-09-18');

    expect($response->headers->get('content-disposition'))
        ->toContain('variance-2026-09-14-to-2026-09-18.csv');
});

it('does not export another outlet figures', function () {
    $otherEmployee = Employee::create(['employee_code' => 'SS-001', 'name' => 'Someone Else', 'is_active' => true]);
    $otherEmployee->outlets()->attach($this->otherOutlet->id);

    planFor($this->otherOutlet, $otherEmployee, '2026-09-14', '09:00', '17:00', $this->owner);

    $body = $this->withHeaders(asUser($this->manager))
        ->get('/api/v1/admin/variance/export?from=2026-09-14&to=2026-09-14')
        ->streamedContent();

    expect($body)->not->toContain('Someone Else');
    expect($body)->not->toContain('SS-001');
});

it('lets an owner see every outlet', function () {
    $otherEmployee = Employee::create(['employee_code' => 'SS-001', 'name' => 'Someone Else', 'is_active' => true]);
    $otherEmployee->outlets()->attach($this->otherOutlet->id);

    $response = $this->withHeaders(asUser($this->owner))
        ->getJson('/api/v1/admin/variance/summary?from=2026-09-14&to=2026-09-14')
        ->assertOk();

    $names = collect($response->json('data.employees'))->pluck('name');

    expect($names)->toContain('Ali bin Ahmad');
    expect($names)->toContain('Someone Else');
});
