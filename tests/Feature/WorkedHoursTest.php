<?php

use App\Enums\RoundingPolicy;
use App\Enums\TimeEntryStatus;
use App\Enums\TimeEntryType;
use App\Enums\TimesheetDayStatus;
use App\Models\Employee;
use App\Models\LabourRule;
use App\Models\Outlet;
use App\Models\Shift;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\WorkedHoursService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Worked-hours arithmetic.
 *
 * This is the layer that decides what the business pays, so the tests concentrate on the
 * ways the sum can be wrong rather than on the report's shape. Every one of these has a
 * plausible-looking implementation that gets it backwards.
 */
beforeEach(function () {
    $this->freezeTime();

    $this->outlet = Outlet::create([
        'code' => 'SG-RAMAL',
        'name' => 'Sg Ramal',
        'token_mode' => 'printed',
        'requires_photo' => false,
    ]);

    $this->employee = Employee::create([
        'employee_code' => 'RAM-001',
        'name' => 'Ali bin Ahmad',
        'is_active' => true,
    ]);
    $this->employee->outlets()->attach($this->outlet->id);

    $this->hours = app(WorkedHoursService::class);
    $this->date = CarbonImmutable::now($this->outlet->timezone)->toDateString();
});

/**
 * Write a segment directly.
 *
 * Deliberately not going through PunchService: these tests are about arithmetic on
 * stored segments, and routing every case through the punch flow would make a failure
 * ambiguous between the two.
 *
 * The business date is derived in the OUTLET's timezone, exactly as PunchService does.
 * Computing it from the UTC date instead looks right and silently files every segment on
 * the previous day whenever the outlet's day has already rolled over — which is the
 * whole reason the business-day rule exists.
 */
function segment(
    Employee $employee,
    Outlet $outlet,
    TimeEntryType $type,
    string $start,
    ?string $end,
    ?Shift $shift = null,
): TimeEntry {
    $startedAt = CarbonImmutable::parse($start, $outlet->timezone);

    return TimeEntry::create([
        'client_uuid' => (string) Str::uuid(),
        'employee_id' => $employee->id,
        'outlet_id' => $outlet->id,
        'shift_id' => $shift?->id,
        'type' => $type,
        'started_at' => $startedAt->utc(),
        'ended_at' => $end === null ? null : CarbonImmutable::parse($end, $outlet->timezone)->utc(),
        'business_date' => $startedAt->toDateString(),
        'status' => $end === null ? TimeEntryStatus::OPEN : TimeEntryStatus::CLOSED,
    ]);
}

/**
 * Roster a shift in the outlet's local time.
 *
 * Same reasoning as segment(): a shift written as UTC '09:00' is a different day for a
 * Kuala Lumpur outlet, and the comparison against a punch would then be meaningless.
 */
function roster(Employee $employee, Outlet $outlet, string $start, string $end, string $date): Shift
{
    return Shift::create([
        'employee_id' => $employee->id,
        'outlet_id' => $outlet->id,
        'starts_at' => CarbonImmutable::parse($date.' '.$start, $outlet->timezone)->utc(),
        'ends_at' => CarbonImmutable::parse($date.' '.$end, $outlet->timezone)->utc(),
        'created_by' => User::factory()->create()->id,
    ]);
}

// ---- Worked time ------------------------------------------------------

it('counts work segments and excludes breaks from worked time', function () {
    /*
     * The rule that protects pay. A one-hour lunch must not become an hour of overtime,
     * which is exactly what happens if worked time is measured on the elapsed span.
     */
    segment($this->employee, $this->outlet, TimeEntryType::WORK, '09:00', '12:00');
    segment($this->employee, $this->outlet, TimeEntryType::BREAK, '12:00', '13:00');
    segment($this->employee, $this->outlet, TimeEntryType::WORK, '13:00', '18:00');

    $day = $this->hours->forDay($this->employee, $this->date);

    expect($day['worked_seconds'])->toBe(8 * 3600);
    expect($day['break_seconds'])->toBe(3600);
    expect($day['span_seconds'])->toBe(9 * 3600);
});

it('creates no overtime when the span exceeds the threshold but worked time does not', function () {
    /*
     * 9 hours of SPAN, 8 hours of WORK. Overtime is measured on worked time, so this is
     * zero — and getting it backwards systematically overpays every long shift.
     */
    segment($this->employee, $this->outlet, TimeEntryType::WORK, '09:00', '13:00');
    segment($this->employee, $this->outlet, TimeEntryType::BREAK, '13:00', '14:00');
    segment($this->employee, $this->outlet, TimeEntryType::WORK, '14:00', '18:00');

    $day = $this->hours->forDay($this->employee, $this->date);

    expect($day['span_seconds'])->toBe(9 * 3600);
    expect($day['worked_seconds'])->toBe(8 * 3600);
    expect($day['overtime_seconds'])->toBe(0);
});

it('counts overtime on worked time past the threshold', function () {
    segment($this->employee, $this->outlet, TimeEntryType::WORK, '09:00', '19:00');

    $day = $this->hours->forDay($this->employee, $this->date);

    expect($day['worked_seconds'])->toBe(10 * 3600);
    expect($day['overtime_seconds'])->toBe(2 * 3600);
});

it('shares one overtime allowance across two shifts in the same day', function () {
    /*
     * The threshold is per DAY, not per shift. Two five-hour shifts are ten hours of
     * work in one day, so two hours are overtime — not zero, which is what an
     * implementation computing overtime per segment would produce.
     */
    segment($this->employee, $this->outlet, TimeEntryType::WORK, '07:00', '12:00');
    segment($this->employee, $this->outlet, TimeEntryType::WORK, '14:00', '19:00');

    $day = $this->hours->forDay($this->employee, $this->date);

    expect($day['worked_seconds'])->toBe(10 * 3600);
    expect($day['overtime_seconds'])->toBe(2 * 3600);
});

it('applies each outlet own overtime rule when a day spans outlets', function () {
    /*
     * Staff cover between sites, so a day can cross outlets — and each has its own
     * threshold. Folding them together would make the answer depend on which outlet was
     * processed first.
     */
    $second = Outlet::create(['code' => 'DARS-COFFEE', 'name' => 'Dars Coffee', 'token_mode' => 'printed', 'requires_photo' => false]);
    $this->employee->outlets()->attach($second->id);

    // Six hours at the first outlet, on a lower 4-hour threshold.
    LabourRule::create([
        'outlet_id' => $this->outlet->id,
        'ot_after_seconds' => 4 * 3600,
        'effective_from' => '2020-01-01',
    ]);

    // Four hours at the second, on the standard 8-hour threshold: no overtime there.
    segment($this->employee, $this->outlet, TimeEntryType::WORK, '08:00', '14:00');
    segment($this->employee, $second, TimeEntryType::WORK, '15:00', '19:00');

    $day = $this->hours->forDay($this->employee, $this->date);

    // 2h over at the first outlet, nothing over at the second.
    expect($day['worked_seconds'])->toBe(10 * 3600);
    expect($day['overtime_seconds'])->toBe(2 * 3600);
});

it('uses the rule version that was in force on the day', function () {
    /*
     * Success criterion 6: a month's figures must be re-derivable. Raising the threshold
     * today must not silently reduce last month's overtime.
     */
    LabourRule::create([
        'outlet_id' => $this->outlet->id,
        'ot_after_seconds' => 8 * 3600,
        'effective_from' => '2020-01-01',
        'effective_to' => '2026-01-01',
    ]);

    $old = LabourRule::forDate($this->outlet->id, '2025-06-01');
    $new = LabourRule::forDate($this->outlet->id, '2026-06-01');

    expect($old->ot_after_seconds)->toBe(8 * 3600);
    // No row covers the later date, so the business defaults apply rather than nothing.
    expect($new->ot_after_seconds)->toBe(LabourRule::DEFAULT_OT_AFTER_SECONDS);
});

it('falls back to agreed defaults when an outlet has no rule at all', function () {
    // A new outlet must report sensibly rather than crash or silently use a zero
    // threshold, which would make every minute overtime.
    $rule = LabourRule::forDate($this->outlet->id, $this->date);

    expect($rule->ot_after_seconds)->toBe(8 * 3600);
    expect($rule->grace_seconds)->toBe(300);
    expect($rule->rounding_policy)->toBe(RoundingPolicy::EXACT);
});

it('rounds only in the report, leaving the stored seconds exact', function () {
    /*
     * Stored seconds always state when someone actually arrived. Rounding is applied
     * when the figure is PRESENTED, which is what lets the policy change later without
     * re-entering data — and without the record ever misstating the punch.
     */
    LabourRule::create([
        'outlet_id' => $this->outlet->id,
        'rounding_policy' => 'down_15',
        'effective_from' => '2020-01-01',
    ]);

    segment($this->employee, $this->outlet, TimeEntryType::WORK, '09:00', '09:59');

    $day = $this->hours->forDay($this->employee, $this->date);

    expect($day['worked_seconds'])->toBe(59 * 60);
    // 59 minutes rounds DOWN to 45, never up to 60.
    expect($day['rounded_worked_seconds'])->toBe(45 * 60);
});

it('never rounds the worked time upwards under a down policy', function () {
    // "Down" has to mean down: rounding 7:59 to 8:00 would pay for a minute nobody worked.
    expect(RoundingPolicy::DOWN_15->apply(7 * 3600 + 59 * 60))->toBe(7 * 3600 + 45 * 60);
    expect(RoundingPolicy::DOWN_15->apply(59 * 60))->toBe(45 * 60);
    expect(RoundingPolicy::NEAREST_15->apply(59 * 60))->toBe(60 * 60);
    expect(RoundingPolicy::EXACT->apply(1234))->toBe(1234);
});

// ---- Day status -------------------------------------------------------

it('flags a day as incomplete while a segment is still open', function () {
    /*
     * An open segment's hours are still accumulating — and a forgotten clock-out would
     * add a full day to the total every day it is left. Presenting that as a final
     * figure is how a payslip ends up wrong.
     */
    segment($this->employee, $this->outlet, TimeEntryType::WORK, '09:00', null);

    $day = $this->hours->forDay($this->employee, $this->date);

    expect($day['status'])->toBe(TimesheetDayStatus::OPEN);
    expect($day['status']->isComplete())->toBeFalse();
});

it('reports adhoc work as adhoc rather than as an error', function () {
    // Adhoc work is real in this business, so a day with no shift is normal.
    segment($this->employee, $this->outlet, TimeEntryType::WORK, '09:00', '17:00');

    $day = $this->hours->forDay($this->employee, $this->date);

    expect($day['status'])->toBe(TimesheetDayStatus::ADHOC);
});

it('reports a complete day as complete when a shift was matched', function () {
    $shift = roster($this->employee, $this->outlet, '09:00', '17:00', $this->date);

    segment($this->employee, $this->outlet, TimeEntryType::WORK, '09:00', '17:00', $shift);

    $day = $this->hours->forDay($this->employee, $this->date);

    expect($day['status'])->toBe(TimesheetDayStatus::OK);
    expect($day['status']->isComplete())->toBeTrue();
});

it('shows a rostered day with no punches as a no-show', function () {
    /*
     * Built from the roster as well as from punches, so a no-show appears as a row rather
     * than vanishing — which is the entire point of recording a roster.
     */
    roster($this->employee, $this->outlet, '09:00', '17:00', $this->date);

    $period = $this->hours->forPeriod($this->employee, $this->date, $this->date);

    expect($period['days'])->toHaveCount(1);
    expect($period['days'][0]['status'])->toBe(TimesheetDayStatus::NO_SHOW);
    expect($period['worked_seconds'])->toBe(0);
});

// ---- Lateness ---------------------------------------------------------

it('does not mark an arrival inside the grace window as late', function () {
    /*
     * The grace window moves the FLAG only, never pay. Arriving at 09:04 for an 09:00
     * shift is on time; the hours still start at 09:04.
     */
    $shift = roster($this->employee, $this->outlet, '09:00', '17:00', $this->date);

    segment($this->employee, $this->outlet, TimeEntryType::WORK, '09:04', '17:00', $shift);

    $day = $this->hours->forDay($this->employee, $this->date);

    expect($day['lateness']['is_late'])->toBeFalse();
    expect($day['lateness']['late_by_seconds'])->toBe(0);
    // Pay still starts at the real minute.
    expect($day['worked_seconds'])->toBe(7 * 3600 + 56 * 60);
});

it('marks an arrival past the grace window as late', function () {
    $shift = roster($this->employee, $this->outlet, '09:00', '17:00', $this->date);

    segment($this->employee, $this->outlet, TimeEntryType::WORK, '09:25', '17:00', $shift);

    $day = $this->hours->forDay($this->employee, $this->date);

    expect($day['lateness']['is_late'])->toBeTrue();
    expect($day['lateness']['late_by_seconds'])->toBe(25 * 60);
});

it('does not report lateness for a day with no rostered shift', function () {
    // Nothing to be late for. Inventing a start time would make every adhoc day look
    // like a discipline problem.
    segment($this->employee, $this->outlet, TimeEntryType::WORK, '11:00', '19:00');

    $day = $this->hours->forDay($this->employee, $this->date);

    expect($day['lateness'])->toBeNull();
});

it('reports leaving early against the rostered end', function () {
    $shift = roster($this->employee, $this->outlet, '09:00', '17:00', $this->date);

    segment($this->employee, $this->outlet, TimeEntryType::WORK, '09:00', '16:30', $shift);

    $day = $this->hours->forDay($this->employee, $this->date);

    expect($day['lateness']['left_early_by_seconds'])->toBe(30 * 60);
});

it('does not report leaving early while the employee is still on shift', function () {
    /*
     * Comparing an open segment against the rostered end would report everyone as leaving
     * early for their entire shift.
     */
    $shift = roster($this->employee, $this->outlet, '09:00', '17:00', $this->date);

    segment($this->employee, $this->outlet, TimeEntryType::WORK, '09:00', null, $shift);

    $day = $this->hours->forDay($this->employee, $this->date);

    expect($day['lateness']['left_early_by_seconds'])->toBeNull();
});

it('does not report leaving early when the employee stayed past the rostered end', function () {
    /*
     * The mirror of the early-out case, and the reason the sign convention is asserted
     * rather than assumed: an inverted subtraction makes leaving 30 minutes EARLY and
     * staying 30 minutes LATE produce the same number, so only one of the two can pass.
     */
    $shift = roster($this->employee, $this->outlet, '09:00', '17:00', $this->date);

    segment($this->employee, $this->outlet, TimeEntryType::WORK, '09:00', '17:30', $shift);

    $day = $this->hours->forDay($this->employee, $this->date);

    expect($day['lateness']['left_early_by_seconds'])->toBeNull();
});

it('does not report leaving early when the departure is inside the grace window', function () {
    // 16:58 for a 17:00 end is not a discipline matter.
    $shift = roster($this->employee, $this->outlet, '09:00', '17:00', $this->date);

    segment($this->employee, $this->outlet, TimeEntryType::WORK, '09:00', '16:58', $shift);

    $day = $this->hours->forDay($this->employee, $this->date);

    expect($day['lateness']['left_early_by_seconds'])->toBeNull();
});

it('does not treat arriving early as lateness', function () {
    // The delta is signed. An unsigned difference would make a 20-minute early arrival
    // look 20 minutes late.
    $shift = roster($this->employee, $this->outlet, '09:00', '17:00', $this->date);

    segment($this->employee, $this->outlet, TimeEntryType::WORK, '08:40', '17:00', $shift);

    $day = $this->hours->forDay($this->employee, $this->date);

    expect($day['lateness']['is_late'])->toBeFalse();
    expect($day['lateness']['late_by_seconds'])->toBe(0);
});

// ---- Periods ----------------------------------------------------------

it('totals a period across several days', function () {
    segment($this->employee, $this->outlet, TimeEntryType::WORK, '2026-09-14 09:00', '2026-09-14 17:00');
    segment($this->employee, $this->outlet, TimeEntryType::WORK, '2026-09-15 09:00', '2026-09-15 13:00');

    $period = $this->hours->forPeriod($this->employee, '2026-09-14', '2026-09-15');

    expect($period['worked_seconds'])->toBe(12 * 3600);
    expect($period['worked_label'])->toBe('12h 0m');
    expect($period['days'])->toHaveCount(2);
});

it('does not count a segment belonging to the next day', function () {
    /*
     * A shift starting at 23:00 belongs to the day it STARTED, so it must not appear on
     * the following day's timesheet. This is the boundary the business-day rule exists
     * for, and it is deliberately asserted through the period query rather than the
     * single-day one.
     */
    segment($this->employee, $this->outlet, TimeEntryType::WORK, '2026-09-14 23:00', '2026-09-15 07:00');

    $first = $this->hours->forPeriod($this->employee, '2026-09-14', '2026-09-14');
    $second = $this->hours->forPeriod($this->employee, '2026-09-15', '2026-09-15');

    expect($first['worked_seconds'])->toBe(8 * 3600);
    expect($second['worked_seconds'])->toBe(0);
});

it('summarises several employees with their incomplete days counted', function () {
    $other = Employee::create(['employee_code' => 'RAM-002', 'name' => 'Siti Aminah', 'is_active' => true]);
    $other->outlets()->attach($this->outlet->id);

    segment($this->employee, $this->outlet, TimeEntryType::WORK, '09:00', '17:00');
    segment($other, $this->outlet, TimeEntryType::WORK, '09:00', null);

    $rows = $this->hours->summarise(
        Employee::query()->orderBy('name')->get(),
        $this->date,
        $this->date,
    );

    expect($rows)->toHaveCount(2);

    $ali = collect($rows)->firstWhere('employee_code', 'RAM-001');
    $aminah = collect($rows)->firstWhere('employee_code', 'RAM-002');

    expect($ali['worked_seconds'])->toBe(8 * 3600);
    expect($ali['days_worked'])->toBe(1);
    expect($ali['incomplete_days'])->toBe(0);

    // The open day is counted as incomplete, so the total is never presented as final.
    expect($aminah['incomplete_days'])->toBe(1);
});

it('formats durations for a spreadsheet as decimal hours', function () {
    // "8h 30m" is a string no spreadsheet can sum, and a CSV is for arithmetic.
    expect($this->hours->durationAsDecimalHours(8 * 3600 + 30 * 60))->toBe('8.50');
    expect($this->hours->durationAsDecimalHours(0))->toBe('0.00');
    expect($this->hours->durationAsDecimalHours(45 * 60))->toBe('0.75');
});

it('formats negative deltas without losing the sign', function () {
    expect($this->hours->hm(-30 * 60))->toBe('-30m');
});
