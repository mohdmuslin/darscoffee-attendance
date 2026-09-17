<?php

use App\Enums\TimeEntryStatus;
use App\Enums\TimeEntryType;
use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\Outlet;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\ShiftService;
use App\Services\VarianceService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Planned versus actual.
 *
 * Two themes. The first is MATCHING: which punch belongs to which shift, and getting that
 * wrong turns a normal day into two contradictory rows. The second is what counts as a
 * variance at all — a report that flags everything teaches managers to ignore it.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-18 18:00:00', 'Asia/Kuala_Lumpur'));

    $this->outlet = Outlet::create([
        'code' => 'SG-RAMAL',
        'name' => 'Sg Ramal',
        'timezone' => 'Asia/Kuala_Lumpur',
        'token_mode' => 'printed',
        'requires_photo' => false,
    ]);

    $this->owner = User::factory()->create(['role' => UserRole::OWNER]);

    $this->employee = Employee::create(['employee_code' => 'RAM-001', 'name' => 'Ali bin Ahmad', 'is_active' => true]);
    $this->employee->outlets()->attach($this->outlet->id);

    $this->shifts = app(ShiftService::class);
    $this->variance = app(VarianceService::class);
});

/** A rostered shift, entered in local wall-clock as a manager would. */
function planShift(Outlet $outlet, Employee $employee, string $date, string $start, string $end, ?User $owner = null): Shift
{
    return app(ShiftService::class)->create($outlet, $employee, [
        'starts_at' => "{$date}T{$start}",
        'ends_at' => "{$date}T{$end}",
    ], $owner ?? test()->owner);
}

/** A punch, given in local wall-clock so the intent reads clearly. */
function punch(Outlet $outlet, Employee $employee, string $date, string $start, ?string $end, ?int $shiftId = null): TimeEntry
{
    $timezone = $outlet->timezone;
    $startedAt = CarbonImmutable::parse("{$date} {$start}", $timezone);

    return TimeEntry::create([
        'client_uuid' => (string) Str::uuid(),
        'employee_id' => $employee->id,
        'outlet_id' => $outlet->id,
        'shift_id' => $shiftId,
        'type' => TimeEntryType::WORK,
        'started_at' => $startedAt,
        'ended_at' => $end === null ? null : CarbonImmutable::parse("{$date} {$end}", $timezone),
        'business_date' => $startedAt->toDateString(),
        'status' => $end === null ? TimeEntryStatus::OPEN : TimeEntryStatus::CLOSED,
    ]);
}

// ---- Matching ---------------------------------------------------------

it('matches a punch to the shift it covers', function () {
    planShift($this->outlet, $this->employee, '2026-09-14', '09:00', '17:00');
    punch($this->outlet, $this->employee, '2026-09-14', '09:00', '17:00');

    $report = $this->variance->forEmployee($this->employee, '2026-09-14', '2026-09-14');

    expect($report['rows'])->toHaveCount(1);
    expect($report['rows'][0]['status'])->toBe('met');
    expect($report['rows'][0]['variance_seconds'])->toBe(0);
});

it('still matches after the shift is amended', function () {
    /*
     * THE case that drove this design. `time_entries.shift_id` is a snapshot of which shift was
     * live at punch time, and amending a shift cancels it and writes a REPLACEMENT. So the
     * entry still points at the cancelled original while the live shift is a different row.
     *
     * Matching on that column would report this day as "a punch against a cancelled shift"
     * PLUS "a no-show for the replacement" — two wrong rows describing something that went
     * perfectly normally.
     */
    $shift = planShift($this->outlet, $this->employee, '2026-09-14', '09:00', '17:00');
    $entry = punch($this->outlet, $this->employee, '2026-09-14', '09:00', '17:00', $shift->id);

    // The manager moves the shift by an hour. The punch stays where it was.
    $this->shifts->update($shift, [
        'starts_at' => '2026-09-14T08:00',
        'ends_at' => '2026-09-14T16:00',
    ], $this->owner);

    expect($entry->fresh()->shift_id)->toBe($shift->id);
    expect($shift->fresh()->isCancelled())->toBeTrue();

    $report = $this->variance->forEmployee($this->employee, '2026-09-14', '2026-09-14');

    // One live shift row, matched. No phantom no-show.
    $live = collect($report['rows'])->where('type', 'shift')->reject(fn ($r) => $r['is_cancelled']);

    expect($live)->toHaveCount(1);
    expect($live->first()['worked_seconds'])->toBe(8 * 3600);
    expect($report['no_show_count'])->toBe(0);
    expect($report['unplanned_count'])->toBe(0);
});

it('matches a punch that has no shift_id at all', function () {
    /*
     * A correction-added segment, an imported row, or anything recorded before the roster
     * existed has no link. Overlap matching does not care, and a report keyed on the column
     * would show every one of them as adhoc.
     */
    planShift($this->outlet, $this->employee, '2026-09-14', '09:00', '17:00');
    punch($this->outlet, $this->employee, '2026-09-14', '09:00', '17:00', null);

    $report = $this->variance->forEmployee($this->employee, '2026-09-14', '2026-09-14');

    expect($report['rows'][0]['status'])->toBe('met');
    expect($report['adhoc_seconds'])->toBe(0);
});

it('matches a punch that starts late and ends early', function () {
    // Overlap with a tolerance, not containment. Required containment would call this a
    // no-show, which is plainly wrong.
    planShift($this->outlet, $this->employee, '2026-09-14', '09:00', '17:00');
    punch($this->outlet, $this->employee, '2026-09-14', '10:00', '15:00');

    $report = $this->variance->forEmployee($this->employee, '2026-09-14', '2026-09-14');

    expect($report['rows'][0]['status'])->toBe('short');
    expect($report['rows'][0]['worked_seconds'])->toBe(5 * 3600);
    expect($report['rows'][0]['variance_seconds'])->toBe(-3 * 3600);
});

it('counts an open punch as covering a shift in progress', function () {
    /*
     * Otherwise the report would call today's shift a no-show — or a shortfall half way
     * through — while the employee is standing there working it.
     *
     * The clock is frozen at 18:00, so this shift has to RUN THROUGH that moment to be in
     * progress. A 09:00-17:00 shift would have legitimately ended, and the earlier version of
     * this test proved nothing.
     */
    planShift($this->outlet, $this->employee, '2026-09-18', '14:00', '22:00');
    punch($this->outlet, $this->employee, '2026-09-18', '14:00', null);

    $report = $this->variance->forEmployee($this->employee, '2026-09-18', '2026-09-18');

    expect($report['rows'][0]['status'])->toBe('in_progress');
    expect($report['no_show_count'])->toBe(0);
    expect($report['partial_count'])->toBe(0);
    expect($report['upcoming_count'])->toBe(1);
});

it('does not call a shift that has not started a no-show', function () {
    // A shift later today has not been missed. Saying so would make the report cry wolf every
    // morning, and a report that flags everything is one nobody reads.
    planShift($this->outlet, $this->employee, '2026-09-18', '20:00', '23:00');

    $report = $this->variance->forEmployee($this->employee, '2026-09-18', '2026-09-18');

    expect($report['rows'][0]['status'])->toBe('not_started');
    expect($report['no_show_count'])->toBe(0);
    expect($report['has_variance'])->toBeFalse();
});

// ---- Statuses ---------------------------------------------------------

it('reports a rostered shift nobody punched as a no-show', function () {
    planShift($this->outlet, $this->employee, '2026-09-14', '09:00', '17:00');

    $report = $this->variance->forEmployee($this->employee, '2026-09-14', '2026-09-14');

    expect($report['rows'][0]['status'])->toBe('no_show');
    expect($report['no_show_count'])->toBe(1);
    expect($report['rows'][0]['worked_seconds'])->toBe(0);
    // The plan still counts: this is a real shortfall against a real commitment.
    expect($report['planned_seconds'])->toBe(8 * 3600);
    expect($report['variance_seconds'])->toBe(-8 * 3600);
});

it('reports work with no roster as unplanned rather than attaching it to a shift', function () {
    /*
     * Attaching it to a nearby shift would hide exactly the thing this report exists to show:
     * that people are working hours nobody planned for.
     */
    planShift($this->outlet, $this->employee, '2026-09-14', '09:00', '17:00');
    punch($this->outlet, $this->employee, '2026-09-16', '10:00', '15:00');

    $report = $this->variance->forEmployee($this->employee, '2026-09-14', '2026-09-16');

    $unplanned = collect($report['rows'])->where('type', 'unplanned');

    expect($unplanned)->toHaveCount(1);
    expect($report['adhoc_seconds'])->toBe(5 * 3600);
    expect($report['unplanned_count'])->toBe(1);
});

it('does not let a cancelled shift absorb a punch', function () {
    /*
     * The plan for that day was withdrawn, so the work was not planned. Letting the cancelled
     * row claim the hours would report the day as met when nobody had agreed to it — which is
     * the exact case a manager needs to see, since cancelled-then-worked is how an outlet
     * quietly opens on a day it said it was closed.
     */
    $shift = planShift($this->outlet, $this->employee, '2026-09-14', '09:00', '17:00');
    $shift->cancel();

    punch($this->outlet, $this->employee, '2026-09-14', '09:00', '17:00');

    $report = $this->variance->forEmployee($this->employee, '2026-09-14', '2026-09-14');

    $cancelled = collect($report['rows'])->firstWhere('type', 'shift');

    expect($cancelled['status'])->toBe('cancelled');
    expect($cancelled['worked_seconds'])->toBe(0);
    // And the hours show as unplanned, which is the truth.
    expect($report['adhoc_seconds'])->toBe(8 * 3600);
    expect($report['unplanned_count'])->toBe(1);
});

it('excludes a cancelled shift from the planned total', function () {
    // Counting it would show a shortfall that never existed: nobody was expected.
    $shift = planShift($this->outlet, $this->employee, '2026-09-14', '09:00', '17:00');
    $shift->cancel();

    $report = $this->variance->forEmployee($this->employee, '2026-09-14', '2026-09-14');

    expect($report['planned_seconds'])->toBe(0);
});

// ---- Statuses ---------------------------------------------------------

it('reports working more than planned as over', function () {
    planShift($this->outlet, $this->employee, '2026-09-14', '09:00', '17:00');
    punch($this->outlet, $this->employee, '2026-09-14', '09:00', '20:00');

    $report = $this->variance->forEmployee($this->employee, '2026-09-14', '2026-09-14');

    expect($report['rows'][0]['status'])->toBe('over');
    expect($report['rows'][0]['variance_seconds'])->toBe(3 * 3600);
});

it('treats a small difference as met rather than a variance', function () {
    /*
     * The tolerance doubles as the threshold. Arriving 20 minutes late and leaving on time is
     * not worth a colour — flagging it would train managers to ignore the report.
     */
    planShift($this->outlet, $this->employee, '2026-09-14', '09:00', '17:00');
    punch($this->outlet, $this->employee, '2026-09-14', '09:20', '17:00');

    $report = $this->variance->forEmployee($this->employee, '2026-09-14', '2026-09-14');

    expect($report['rows'][0]['status'])->toBe('met');
    expect($report['partial_count'])->toBe(0);
});

it('marks a break segment as not covering a shift on its own', function () {
    /*
     * Only WORK segments are matched. Counting a break as covering the shift would let a
     * stray clock-in during a break make a no-show look attended.
     */
    planShift($this->outlet, $this->employee, '2026-09-14', '09:00', '17:00');

    $timezone = $this->outlet->timezone;
    $startedAt = CarbonImmutable::parse('2026-09-14 12:00', $timezone);

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

    $report = $this->variance->forEmployee($this->employee, '2026-09-14', '2026-09-14');

    expect($report['rows'][0]['status'])->toBe('no_show');
    expect($report['worked_seconds'])->toBe(0);
});

// ---- Timezones --------------------------------------------------------

it('resolves the range from local dates, not UTC', function () {
    /*
     * A shift at 00:30 local is 16:30 UTC the PREVIOUS day. Comparing a local boundary
     * against the UTC column drops the first eight hours of every local day, which is exactly
     * where an early shift lives.
     */
    planShift($this->outlet, $this->employee, '2026-09-14', '00:30', '08:30');
    punch($this->outlet, $this->employee, '2026-09-14', '00:30', '08:30');

    $report = $this->variance->forEmployee($this->employee, '2026-09-14', '2026-09-14');

    expect($report['rows'])->toHaveCount(1);
    expect($report['rows'][0]['date'])->toBe('2026-09-14');
    expect($report['rows'][0]['status'])->toBe('met');
});

it('does not reach into the next day', function () {
    planShift($this->outlet, $this->employee, '2026-09-15', '09:00', '17:00');

    $report = $this->variance->forEmployee($this->employee, '2026-09-14', '2026-09-14');

    expect($report['rows'])->toHaveCount(0);
});

// ---- The tolerance setting -------------------------------------------

it('honours a tightened tolerance', function () {
    // An owner may reasonably decide ten minutes late IS a variance for their outlet.
    Setting::set(Setting::VARIANCE_TOLERANCE_MINUTES, 5);

    planShift($this->outlet, $this->employee, '2026-09-14', '09:00', '17:00');
    punch($this->outlet, $this->employee, '2026-09-14', '09:20', '17:00');

    $report = $this->variance->forEmployee($this->employee, '2026-09-14', '2026-09-14');

    expect($report['rows'][0]['status'])->toBe('short');
});

// ---- Summary ----------------------------------------------------------

it('summarises several employees', function () {
    $other = Employee::create(['employee_code' => 'RAM-002', 'name' => 'Siti Aminah', 'is_active' => true]);
    $other->outlets()->attach($this->outlet->id);

    planShift($this->outlet, $this->employee, '2026-09-14', '09:00', '17:00');
    punch($this->outlet, $this->employee, '2026-09-14', '09:00', '17:00');

    planShift($this->outlet, $other, '2026-09-14', '09:00', '17:00');
    // Siti did not turn up.

    $summary = $this->variance->summarise(
        Employee::query()->orderBy('name')->get(),
        '2026-09-14',
        '2026-09-14',
    );

    expect($summary['employees'])->toHaveCount(2);

    $ali = collect($summary['employees'])->firstWhere('employee_code', 'RAM-001');
    $aminah = collect($summary['employees'])->firstWhere('employee_code', 'RAM-002');

    expect($ali['has_variance'])->toBeFalse();
    expect($ali['variance_seconds'])->toBe(0);

    expect($aminah['no_show_count'])->toBe(1);
    expect($aminah['has_variance'])->toBeTrue();
    expect($aminah['variance_label'])->toBe('-8h 0m');
});

it('keeps the sign of the variance', function () {
    // "Under by 4h" and "over by 4h" need different responses, so an absolute value would
    // lose the only piece of information that matters.
    planShift($this->outlet, $this->employee, '2026-09-14', '09:00', '17:00');
    punch($this->outlet, $this->employee, '2026-09-14', '09:00', '13:00');

    $report = $this->variance->forEmployee($this->employee, '2026-09-14', '2026-09-14');

    expect($report['variance_seconds'])->toBe(-4 * 3600);
    expect($report['variance_label'])->toBe('-4h 0m');
});

it('reports planned and worked as separate totals', function () {
    // The two must not be conflated: worked covers unplanned hours, planned does not.
    planShift($this->outlet, $this->employee, '2026-09-14', '09:00', '17:00');
    punch($this->outlet, $this->employee, '2026-09-14', '09:00', '17:00');
    punch($this->outlet, $this->employee, '2026-09-16', '10:00', '12:00');

    $report = $this->variance->forEmployee($this->employee, '2026-09-14', '2026-09-16');

    expect($report['planned_seconds'])->toBe(8 * 3600);
    expect($report['worked_seconds'])->toBe(10 * 3600);
    expect($report['adhoc_seconds'])->toBe(2 * 3600);
    // Variance is worked minus planned, INCLUDING the unplanned hours — because that is what
    // the business actually paid for against what it agreed to.
    expect($report['variance_seconds'])->toBe(2 * 3600);
});
