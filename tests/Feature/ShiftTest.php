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
 * The roster.
 *
 * The plan and the actual are separate tables, and most of what follows is about keeping
 * them that way: a roster change must never touch recorded hours.
 *
 * The other theme is timezones. A shift is entered as a local wall-clock time and stored
 * UTC, and the outlet's zone decides where the day boundary falls — so a mistake here does
 * not throw an error, it silently moves a shift to the wrong day and the wrong lateness
 * figure follows.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-18 09:00:00', 'Asia/Kuala_Lumpur'));

    $this->outlet = Outlet::create([
        'code' => 'SG-RAMAL',
        'name' => 'Sg Ramal',
        'timezone' => 'Asia/Kuala_Lumpur',
        'token_mode' => 'printed',
        'requires_photo' => false,
    ]);

    $this->otherOutlet = Outlet::create([
        'code' => 'SEDAP-SANTAI',
        'name' => 'Sedap Santai',
        'timezone' => 'Asia/Kuala_Lumpur',
        'token_mode' => 'printed',
        'requires_photo' => false,
    ]);

    $this->owner = User::factory()->create(['role' => UserRole::OWNER]);
    $this->manager = User::factory()->create(['role' => UserRole::MANAGER]);
    $this->manager->outlets()->attach($this->outlet->id);

    $this->employee = Employee::create(['employee_code' => 'RAM-001', 'name' => 'Ali bin Ahmad', 'is_active' => true]);
    $this->employee->outlets()->attach($this->outlet->id);

    $this->service = app(ShiftService::class);
});

/** A shift via the service, so local-to-UTC conversion is exercised. */
function rosterViaService(Outlet $outlet, Employee $employee, string $start, string $end, ?User $creator = null): Shift
{
    return app(ShiftService::class)->create($outlet, $employee, [
        'starts_at' => $start,
        'ends_at' => $end,
    ], $creator ?? test()->owner);
}

// ---- Timezone handling ------------------------------------------------

it('stores a shift at the instant the local time names', function () {
    /*
     * 09:00 in Kuala Lumpur is 01:00 UTC. Getting this wrong is not a visible failure — the
     * shift simply appears eight hours later, and every lateness figure computed against it
     * is then wrong too.
     */
    $shift = rosterViaService($this->outlet, $this->employee, '2026-09-21T09:00', '2026-09-21T17:00');

    expect($shift->fresh()->starts_at->utc()->toIso8601String())->toBe('2026-09-21T01:00:00+00:00');
    expect($shift->fresh()->ends_at->utc()->toIso8601String())->toBe('2026-09-21T09:00:00+00:00');
});

it('groups an evening shift under the local day it starts', function () {
    /*
     * A 22:00 shift is 14:00 UTC the same day, so UTC and local agree here — but a shift
     * starting at 00:30 local is 16:30 UTC the PREVIOUS day, and grouping on the UTC date
     * would file it under yesterday.
     */
    $shift = rosterViaService($this->outlet, $this->employee, '2026-09-22T00:30', '2026-09-22T08:30');

    $grouped = $this->service->byDay(collect([$shift]), $this->outlet->timezone);

    expect(array_column($grouped, 'date'))->toBe(['2026-09-22']);
});

it('emits a day with nothing rostered rather than omitting it', function () {
    /*
     * REGRESSION. A plain `groupBy` omits days with no shifts, so a coverage gap simply did
     * not appear — and the "nobody rostered" warning on the roster screen was dead code that
     * could never fire. A missing row reads as "no data"; an empty day reads as "nobody is
     * on", which is the thing a manager actually needs to see.
     */
    rosterViaService($this->outlet, $this->employee, '2026-09-21T09:00', '2026-09-21T17:00');

    $from = CarbonImmutable::parse('2026-09-21 00:00', $this->outlet->timezone);
    $to = CarbonImmutable::parse('2026-09-25 00:00', $this->outlet->timezone);

    $days = $this->service->byDay(
        Shift::query()->get(),
        $this->outlet->timezone,
        $this->service->datesBetween($from, $to),
    );

    expect(array_column($days, 'date'))->toBe([
        '2026-09-21', '2026-09-22', '2026-09-23', '2026-09-24',
    ]);

    expect($days[0]['has_cover'])->toBeTrue();
    expect($days[1]['has_cover'])->toBeFalse();
    expect($days[1]['shifts'])->toBeEmpty();
});

it('does not count a cancelled shift as cover', function () {
    /*
     * The distinction that matters operationally: a day whose only shift was called off is a
     * day NOBODY IS WORKING. Counting the cancelled row as cover is how a shop opens with
     * nobody on.
     */
    $shift = rosterViaService($this->outlet, $this->employee, '2026-09-21T09:00', '2026-09-21T17:00');
    $shift->cancel();

    $from = CarbonImmutable::parse('2026-09-21 00:00', $this->outlet->timezone);
    $to = CarbonImmutable::parse('2026-09-22 00:00', $this->outlet->timezone);

    $days = $this->service->byDay(
        Shift::query()->get(),
        $this->outlet->timezone,
        $this->service->datesBetween($from, $to),
    );

    expect($days[0]['has_cover'])->toBeFalse();
    // But the shift is still listed, because a called-off shift is what explains a no-show.
    expect($days[0]['shifts'])->toHaveCount(1);
    expect($days[0]['planned_seconds'])->toBe(0);
});

it('interprets the range boundaries in the outlet timezone', function () {
    /*
     * A shift at 00:30 local on the 22nd is stored as the 21st in UTC. Requesting the range
     * for the 22nd must still find it — which only works if the day boundary is converted
     * rather than compared against the UTC column.
     */
    rosterViaService($this->outlet, $this->employee, '2026-09-22T00:30', '2026-09-22T08:30');

    $response = $this->withHeaders(asUser($this->manager))
        ->getJson('/api/v1/admin/shifts?from=2026-09-22&to=2026-09-22')
        ->assertOk();

    expect($response->json('data.days'))->toHaveCount(1);
    expect($response->json('data.days.0.date'))->toBe('2026-09-22');
});

// ---- The plan never writes the actual --------------------------------

it('does not create a time entry when a shift is rostered', function () {
    // The boundary the whole design rests on. Rostering someone is not evidence they worked.
    rosterViaService($this->outlet, $this->employee, '2026-09-21T09:00', '2026-09-21T17:00');

    expect(TimeEntry::count())->toBe(0);
});

it('does not alter recorded hours when a shift is cancelled', function () {
    $shift = rosterViaService($this->outlet, $this->employee, '2026-09-21T09:00', '2026-09-21T17:00');

    $started = CarbonImmutable::parse('2026-09-21 09:00', $this->outlet->timezone);

    TimeEntry::create([
        'client_uuid' => (string) Str::uuid(),
        'employee_id' => $this->employee->id,
        'outlet_id' => $this->outlet->id,
        'shift_id' => $shift->id,
        'type' => TimeEntryType::WORK,
        'started_at' => $started,
        'ended_at' => $started->addHours(8),
        'business_date' => $started->toDateString(),
        'status' => TimeEntryStatus::CLOSED,
    ]);

    $before = TimeEntry::first()->durationSeconds();

    $shift->cancel();

    expect(TimeEntry::first()->durationSeconds())->toBe($before);
    expect(TimeEntry::first()->status)->toBe(TimeEntryStatus::CLOSED);
});

// ---- Overlaps ---------------------------------------------------------

it('refuses a shift that overlaps one the employee already has', function () {
    // A double-booking is almost always a mistake, and it silently creates two shifts where
    // a manager believes there is one.
    rosterViaService($this->outlet, $this->employee, '2026-09-21T09:00', '2026-09-21T17:00');

    expect(fn () => rosterViaService($this->outlet, $this->employee, '2026-09-21T16:00', '2026-09-21T20:00'))
        ->toThrow(InvalidArgumentException::class);
});

it('allows a back-to-back shift', function () {
    /*
     * The half-open overlap test. Using <= / >= would make a shift that starts exactly when
     * the previous one ends count as a clash, refusing every legitimate handover.
     */
    rosterViaService($this->outlet, $this->employee, '2026-09-21T09:00', '2026-09-21T17:00');

    $second = rosterViaService($this->outlet, $this->employee, '2026-09-21T17:00', '2026-09-21T21:00');

    expect($second->exists)->toBeTrue();
});

it('allows a split shift when the overlap is explicitly accepted', function () {
    rosterViaService($this->outlet, $this->employee, '2026-09-21T11:00', '2026-09-21T15:00');

    $split = $this->service->create($this->outlet, $this->employee, [
        'starts_at' => '2026-09-21T09:00',
        'ends_at' => '2026-09-21T17:00',
        'allow_overlap' => true,
    ], $this->owner);

    expect($split->exists)->toBeTrue();
});

it('does not let a cancelled shift block a replacement', function () {
    /*
     * Re-rostering after a cancellation is the common case, and a cancelled shift still
     * blocks it if the overlap check counts cancelled rows.
     */
    $first = rosterViaService($this->outlet, $this->employee, '2026-09-21T09:00', '2026-09-21T17:00');

    $first->cancel();

    $replacement = rosterViaService($this->outlet, $this->employee, '2026-09-21T09:00', '2026-09-21T17:00');

    expect($replacement->exists)->toBeTrue();
});

it('does not treat the same person at two outlets as an overlap', function () {
    // Covering two outlets in one day is legitimate, and is flagged by the punch anomaly
    // rather than refused at roster time.
    $this->employee->outlets()->attach($this->otherOutlet->id);

    rosterViaService($this->outlet, $this->employee, '2026-09-21T09:00', '2026-09-21T13:00');
    $second = rosterViaService($this->otherOutlet, $this->employee, '2026-09-21T14:00', '2026-09-21T18:00');

    expect($second->exists)->toBeTrue();
});

// ---- Amend ------------------------------------------------------------

it('cancels the original and writes a replacement when the times move', function () {
    /*
     * A shift whose hours changed is not the same plan. Editing in place would leave the
     * answer to "why am I down for a late shift?" nowhere, and the manager being asked has
     * no way to show that it changed.
     */
    $shift = rosterViaService($this->outlet, $this->employee, '2026-09-21T09:00', '2026-09-21T17:00');

    $updated = $this->service->update($shift, [
        'starts_at' => '2026-09-21T13:00',
        'ends_at' => '2026-09-21T21:00',
    ], $this->owner);

    expect($shift->fresh()->isCancelled())->toBeTrue();
    expect($updated->id)->not->toBe($shift->id);
    expect($updated->starts_at->setTimezone($this->outlet->timezone)->format('H:i'))->toBe('13:00');

    // The replacement is the only live shift, so the roster still shows one entry.
    expect(Shift::active()->count())->toBe(1);
});

it('edits a note in place without duplicating the shift', function () {
    // Nothing about the plan moved, so copying the row for a typo fix would make the roster
    // unreadable.
    $shift = rosterViaService($this->outlet, $this->employee, '2026-09-21T09:00', '2026-09-21T17:00');

    $updated = $this->service->update($shift, ['note' => 'Bring the key'], $this->owner);

    expect($updated->id)->toBe($shift->id);
    expect($updated->note)->toBe('Bring the key');
    expect($shift->fresh()->isCancelled())->toBeFalse();
    expect(Shift::query()->count())->toBe(1);
});

it('refuses a shift that ends before it starts', function () {
    expect(fn () => rosterViaService($this->outlet, $this->employee, '2026-09-21T17:00', '2026-09-21T09:00'))
        ->toThrow(InvalidArgumentException::class);
});

// ---- Cancel -----------------------------------------------------------

it('keeps a cancelled shift rather than deleting it', function () {
    // It explains a no-show. Deleting it erases the fact that someone was expected.
    $shift = rosterViaService($this->outlet, $this->employee, '2026-09-21T09:00', '2026-09-21T17:00');

    $shift->cancel();

    expect(Shift::query()->count())->toBe(1);
    expect(Shift::active()->count())->toBe(0);
    expect($shift->fresh()->cancelled_at)->not->toBeNull();
});

it('hides cancelled shifts from the roster by default', function () {
    $shift = rosterViaService($this->outlet, $this->employee, '2026-09-21T09:00', '2026-09-21T17:00');
    $shift->cancel();

    $default = $this->withHeaders(asUser($this->manager))
        ->getJson('/api/v1/admin/shifts?from=2026-09-21&to=2026-09-21')
        ->assertOk();

    /*
     * The DAY is still returned — it has to be, or a coverage gap would be invisible — but
     * the cancelled shift is not listed and the day reports no cover.
     */
    expect($default->json('data.days'))->toHaveCount(1);
    expect($default->json('data.days.0.shifts'))->toBeEmpty();
    expect($default->json('data.days.0.has_cover'))->toBeFalse();

    $withCancelled = $this->withHeaders(asUser($this->manager))
        ->getJson('/api/v1/admin/shifts?from=2026-09-21&to=2026-09-21&include_cancelled=1')
        ->assertOk();

    expect($withCancelled->json('data.days.0.shifts'))->toHaveCount(1);
    expect($withCancelled->json('data.days.0.shifts.0.is_cancelled'))->toBeTrue();
});

it('cancelling is idempotent', function () {
    // A double-click, or a roster left open on a stale screen, must not error.
    $shift = rosterViaService($this->outlet, $this->employee, '2026-09-21T09:00', '2026-09-21T17:00');

    $this->withHeaders(asUser($this->manager))
        ->postJson("/api/v1/admin/shifts/{$shift->id}/cancel")->assertOk();

    $this->withHeaders(asUser($this->manager))
        ->postJson("/api/v1/admin/shifts/{$shift->id}/cancel")->assertOk();
});

it('appends a cancellation reason without losing the original note', function () {
    $shift = $this->service->create($this->outlet, $this->employee, [
        'starts_at' => '2026-09-21T09:00',
        'ends_at' => '2026-09-21T17:00',
        'note' => 'Covering for Aisyah',
    ], $this->owner);

    $this->withHeaders(asUser($this->manager))
        ->postJson("/api/v1/admin/shifts/{$shift->id}/cancel", ['reason' => 'Outlet closed for the holiday'])
        ->assertOk();

    $note = $shift->fresh()->note;

    expect($note)->toContain('Covering for Aisyah');
    expect($note)->toContain('Outlet closed for the holiday');
});

// ---- Copy -------------------------------------------------------------

it('copies a week forward by whole days', function () {
    rosterViaService($this->outlet, $this->employee, '2026-09-21T09:00', '2026-09-21T17:00');
    rosterViaService($this->outlet, $this->employee, '2026-09-22T09:00', '2026-09-22T17:00');

    $result = $this->service->copy(
        Shift::query()->active()->get(),
        ['source_from' => '2026-09-21', 'target_from' => '2026-09-28', 'outlet_id' => $this->outlet->id],
        $this->owner,
    );

    expect($result['created'])->toBe(2);

    $copied = Shift::query()->active()->where('starts_at', '>=', CarbonImmutable::parse('2026-09-28 00:00', $this->outlet->timezone))->get();

    expect($copied)->toHaveCount(2);
    // The local start time is preserved, not shifted by a fixed number of seconds.
    expect($copied->first()->starts_at->setTimezone($this->outlet->timezone)->format('H:i'))->toBe('09:00');
});

it('skips a copy onto a time already rostered, by default', function () {
    /*
     * The safe failure is to leave something alone. Silently overwriting a manager's roster
     * would destroy work they had done and give no sign it happened.
     */
    rosterViaService($this->outlet, $this->employee, '2026-09-21T09:00', '2026-09-21T17:00');
    rosterViaService($this->outlet, $this->employee, '2026-09-28T09:00', '2026-09-28T17:00');

    $result = $this->service->copy(
        Shift::query()->active()->where('starts_at', '<', CarbonImmutable::parse('2026-09-22 00:00', $this->outlet->timezone))->get(),
        ['source_from' => '2026-09-21', 'target_from' => '2026-09-28', 'outlet_id' => $this->outlet->id],
        $this->owner,
    );

    expect($result['created'])->toBe(0);
    expect($result['skipped'])->toBe(1);
});

it('replaces an existing shift when asked to', function () {
    rosterViaService($this->outlet, $this->employee, '2026-09-21T09:00', '2026-09-21T17:00');
    $existing = rosterViaService($this->outlet, $this->employee, '2026-09-28T09:00', '2026-09-28T17:00');

    $sourceShifts = Shift::query()->active()
        ->where('starts_at', '<', CarbonImmutable::parse('2026-09-22 00:00', $this->outlet->timezone))
        ->get();

    $result = $this->service->copy($sourceShifts, [
        'source_from' => '2026-09-21',
        'target_from' => '2026-09-28',
        'outlet_id' => $this->outlet->id,
        'on_conflict' => 'replace',
    ], $this->owner);

    expect($result['created'])->toBe(1);
    // Cancelled rather than deleted, so the overwritten roster is still explainable.
    expect($existing->fresh()->isCancelled())->toBeTrue();
});

it('copies one employee roster onto another', function () {
    $other = Employee::create(['employee_code' => 'RAM-002', 'name' => 'Siti Aminah', 'is_active' => true]);
    $other->outlets()->attach($this->outlet->id);

    rosterViaService($this->outlet, $this->employee, '2026-09-21T09:00', '2026-09-21T17:00');

    $result = $this->service->copy(
        Shift::query()->active()->get(),
        [
            'source_from' => '2026-09-21',
            'target_from' => '2026-09-21',
            'employee_id' => $other->id,
            'outlet_id' => $this->outlet->id,
        ],
        $this->owner,
    );

    expect($result['created'])->toBe(1);
    expect(Shift::query()->where('employee_id', $other->id)->count())->toBe(1);
});

// ---- Planned totals ---------------------------------------------------

it('totals planned hours per employee, excluding cancelled shifts', function () {
    rosterViaService($this->outlet, $this->employee, '2026-09-21T09:00', '2026-09-21T17:00');

    $cancelled = rosterViaService($this->outlet, $this->employee, '2026-09-22T09:00', '2026-09-22T17:00');
    $cancelled->cancel();

    $totals = $this->service->plannedTotals(Shift::query()->with('employee')->get());

    expect($totals)->toHaveCount(1);
    expect($totals[0]['planned_seconds'])->toBe(8 * 3600);
    expect($totals[0]['shift_count'])->toBe(1);
});

// ---- Scoping ----------------------------------------------------------

it('does not show a manager another outlet roster', function () {
    $otherEmployee = Employee::create(['employee_code' => 'SS-001', 'name' => 'Someone Else', 'is_active' => true]);
    $otherEmployee->outlets()->attach($this->otherOutlet->id);

    rosterViaService($this->otherOutlet, $otherEmployee, '2026-09-21T09:00', '2026-09-21T17:00');
    rosterViaService($this->outlet, $this->employee, '2026-09-21T10:00', '2026-09-21T18:00');

    $response = $this->withHeaders(asUser($this->manager))
        ->getJson('/api/v1/admin/shifts?from=2026-09-21&to=2026-09-22')
        ->assertOk();

    $names = collect($response->json('data.days'))
        ->flatMap(fn ($day) => $day['shifts'])
        ->pluck('employee.name')
        ->unique();

    expect($names)->toContain('Ali bin Ahmad');
    expect($names)->not->toContain('Someone Else');
});

it('refuses to roster an employee at another outlet', function () {
    // Otherwise the employee would appear to vanish from the manager's own roster.
    $otherEmployee = Employee::create(['employee_code' => 'SS-001', 'name' => 'Someone Else', 'is_active' => true]);
    $otherEmployee->outlets()->attach($this->otherOutlet->id);

    $this->withHeaders(asUser($this->manager))
        ->postJson('/api/v1/admin/shifts', [
            'employee_id' => $otherEmployee->id,
            'outlet_id' => $this->outlet->id,
            'starts_at' => '2026-09-21T09:00',
            'ends_at' => '2026-09-21T17:00',
        ])
        ->assertStatus(404);
});

it('refuses to create a shift at another outlet with a 404', function () {
    $this->withHeaders(asUser($this->manager))
        ->postJson('/api/v1/admin/shifts', [
            'employee_id' => $this->employee->id,
            'outlet_id' => $this->otherOutlet->id,
            'starts_at' => '2026-09-21T09:00',
            'ends_at' => '2026-09-21T17:00',
        ])
        ->assertStatus(404);
});

it('refuses to read another outlet shift with a 404', function () {
    $otherEmployee = Employee::create(['employee_code' => 'SS-001', 'name' => 'Someone Else', 'is_active' => true]);
    $otherEmployee->outlets()->attach($this->otherOutlet->id);

    $shift = rosterViaService($this->otherOutlet, $otherEmployee, '2026-09-21T09:00', '2026-09-21T17:00');

    $this->withHeaders(asUser($this->manager))
        ->getJson("/api/v1/admin/shifts/{$shift->id}")
        ->assertStatus(404);
});

it('refuses to cancel another outlet shift with a 404', function () {
    $otherEmployee = Employee::create(['employee_code' => 'SS-001', 'name' => 'Someone Else', 'is_active' => true]);
    $otherEmployee->outlets()->attach($this->otherOutlet->id);

    $shift = rosterViaService($this->otherOutlet, $otherEmployee, '2026-09-21T09:00', '2026-09-21T17:00');

    $this->withHeaders(asUser($this->manager))
        ->postJson("/api/v1/admin/shifts/{$shift->id}/cancel")
        ->assertStatus(404);

    expect($shift->fresh()->isCancelled())->toBeFalse();
});

it('lets a manager roster their own outlet', function () {
    $this->withHeaders(asUser($this->manager))
        ->postJson('/api/v1/admin/shifts', [
            'employee_id' => $this->employee->id,
            'outlet_id' => $this->outlet->id,
            'starts_at' => '2026-09-21T09:00',
            'ends_at' => '2026-09-21T17:00',
            'position' => 'Kitchen',
        ])
        ->assertStatus(201);
});

// ---- Validation through the API ---------------------------------------

it('requires the local wall-clock format', function () {
    /*
     * `date_format` rather than `date`, because `date` would accept an ISO string with an
     * offset and silently reinterpret it in the app timezone — exactly the eight-hour shift
     * that made every lateness figure wrong.
     */
    $this->withHeaders(asUser($this->manager))
        ->postJson('/api/v1/admin/shifts', [
            'employee_id' => $this->employee->id,
            'outlet_id' => $this->outlet->id,
            'starts_at' => '2026-09-21T09:00:00+08:00',
            'ends_at' => '2026-09-21T17:00:00+08:00',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('starts_at');
});

it('refuses an end before the start through the API', function () {
    $this->withHeaders(asUser($this->manager))
        ->postJson('/api/v1/admin/shifts', [
            'employee_id' => $this->employee->id,
            'outlet_id' => $this->outlet->id,
            'starts_at' => '2026-09-21T17:00',
            'ends_at' => '2026-09-21T09:00',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('ends_at');
});

it('reports a conflicting shift as a 422 with an explanation', function () {
    rosterViaService($this->outlet, $this->employee, '2026-09-21T09:00', '2026-09-21T17:00');

    $this->withHeaders(asUser($this->manager))
        ->postJson('/api/v1/admin/shifts', [
            'employee_id' => $this->employee->id,
            'outlet_id' => $this->outlet->id,
            'starts_at' => '2026-09-21T16:00',
            'ends_at' => '2026-09-21T20:00',
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'INVALID_SHIFT');
});

it('sends both an ISO instant and a local wall-clock string', function () {
    /*
     * The two consumers want different things and neither should re-derive the other.
     * Reconstructing the local form in the browser means re-implementing the outlet
     * timezone, which is where an eight-hour error creeps in.
     */
    rosterViaService($this->outlet, $this->employee, '2026-09-21T09:00', '2026-09-21T17:00');

    $shift = $this->withHeaders(asUser($this->manager))
        ->getJson('/api/v1/admin/shifts?from=2026-09-21&to=2026-09-21')
        ->assertOk()
        ->json('data.days.0.shifts.0');

    expect($shift['starts_at'])->toBe('2026-09-21T01:00:00+00:00');
    expect($shift['starts_local'])->toBe('2026-09-21T09:00');
    expect($shift['local_date'])->toBe('2026-09-21');
    expect($shift['duration_seconds'])->toBe(8 * 3600);
});
