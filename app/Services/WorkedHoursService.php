<?php

namespace App\Services;

use App\Enums\TimeEntryStatus;
use App\Enums\TimeEntryType;
use App\Enums\TimesheetDayStatus;
use App\Models\Employee;
use App\Models\LabourRule;
use App\Models\Outlet;
use App\Models\Shift;
use App\Models\TimeEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Turns stored segments into the numbers a timesheet shows.
 *
 * This is the single place worked time is defined, and the definition is deliberately
 * narrow:
 *
 *     worked = sum of WORK segments. Breaks are not subtracted, they are simply a
 *              different kind of segment.
 *
 * Storing breaks as their own rows is what makes this a plain sum. A break column would
 * mean arithmetic here, and arithmetic is where a one-hour lunch quietly becomes an hour
 * of overtime.
 *
 * Nothing here writes. Reports are derived from `started_at`/`ended_at` rather than the
 * cached `duration_seconds`, so a stale cache cannot inflate hours — the cache exists
 * only to make some queries cheaper.
 */
class WorkedHoursService
{
    /**
     * One employee's day, as a timesheet row.
     *
     * `$entries` and `$rules` are pre-fetched by `forPeriod()`, which knows them for the whole
     * range. Passing them in is what removes the per-day queries: without them this method
     * issues its own, and a month-long report ran one query per day plus a labour-rule lookup
     * per day inside that.
     *
     * Both are optional so a single day can still be asked for on its own, which is what the
     * timesheet's day view does.
     *
     * @param  Collection<int, TimeEntry>|null  $entries
     * @param  array<string, LabourRule>|null  $rules  Keyed "{outletId}:{date}".
     * @return array<string, mixed>
     */
    public function forDay(
        Employee $employee,
        string $businessDate,
        ?Outlet $outlet = null,
        ?Collection $entries = null,
        ?array $rules = null,
    ): array {
        $entries ??= TimeEntry::query()
            ->with(['outlet', 'shift'])
            ->where('employee_id', $employee->id)
            ->forBusinessDate($businessDate)
            ->orderBy('started_at')
            ->get();

        /*
         * The rules are normalised to ONE shape here, keyed "{outletId}:{date}".
         *
         * Two callers arrive with different things: `forPeriod()` hands over a range-wide map it
         * already resolved, and a standalone day view passes nothing and needs the lookup done
         * now. Normalising at this point means everything below asks exactly one question —
         * "what rule applies to this outlet on this day?" — instead of branching on which caller
         * it was.
         */
        if ($rules === null) {
            $resolved = [];

            foreach ($entries->pluck('outlet_id')->unique() as $outletId) {
                $resolved[$outletId.':'.$businessDate] = LabourRule::forDate(
                    (int) $outletId,
                    $businessDate,
                );
            }

            $rules = $resolved;
        }

        /*
         * A day spanning outlets resolves each outlet separately. Falling back to the database
         * only when the key is absent keeps the eager path query-free while still being correct
         * if a caller passes an incomplete map.
         */
        $ruleFor = fn (int $outletId): LabourRule => $rules[$outletId.':'.$businessDate]
            ?? LabourRule::forDate($outletId, $businessDate);

        $worked = 0;
        $break = 0;
        $roundedWorked = 0;
        $overtime = 0;
        $open = false;
        $corrected = false;

        /*
         * Overtime is resolved PER OUTLET, not once for the day.
         *
         * A day can span outlets when staff cover between sites, and each outlet has its
         * own threshold and its own version of the rule. Folding them together would make
         * the answer depend on which outlet happened to be processed first.
         *
         * Within an outlet the threshold is cumulative ACROSS the day's work segments —
         * that is the point of "8 hours worked per day" — so two separate shifts in one
         * day share a single 8-hour allowance rather than each getting their own.
         */
        $workedByOutlet = [];

        foreach ($entries as $entry) {
            $seconds = $entry->durationSeconds();

            if ($entry->status === TimeEntryStatus::OPEN) {
                // Still running: counted, but the day is flagged as incomplete so the
                // total is never presented as final.
                $open = true;
            }

            if ($entry->status === TimeEntryStatus::CORRECTED) {
                $corrected = true;
            }

            $rule = $ruleFor($entry->outlet_id);

            if ($entry->type === TimeEntryType::BREAK) {
                $break += $seconds;

                continue;
            }

            $worked += $seconds;
            $roundedWorked += $rule->rounding_policy->apply($seconds);
            $workedByOutlet[$entry->outlet_id] = ($workedByOutlet[$entry->outlet_id] ?? 0) + $seconds;
        }

        foreach ($workedByOutlet as $outletId => $outletWorked) {
            $rule = $ruleFor((int) $outletId);

            /*
             * Overtime is measured on WORKED time by default, which is why the lunch
             * hour does not create overtime. `ot_basis = 'span'` exists for an outlet
             * that wants the other reading; it is not the default because it
             * systematically overpays every long shift.
             */
            $basis = $rule->ot_basis === 'span'
                ? $this->spanForOutlet($entries, (int) $outletId)
                : $outletWorked;

            if ($basis > $rule->ot_after_seconds) {
                $overtime += $basis - $rule->ot_after_seconds;
            }
        }

        return [
            'business_date' => $businessDate,
            'status' => $this->dayStatus($entries, $open, $corrected),
            'worked_seconds' => $worked,
            'worked_label' => $this->hm($worked),
            'break_seconds' => $break,
            'break_label' => $this->hm($break),
            'span_seconds' => $worked + $break,
            'span_label' => $this->hm($worked + $break),
            'overtime_seconds' => $overtime,
            'overtime_label' => $this->hm($overtime),
            'rounded_worked_seconds' => $roundedWorked,
            'rounded_worked_label' => $this->hm($roundedWorked),
            'rounding_policy' => $entries->isNotEmpty()
                ? $ruleFor((int) $entries->first()->outlet_id)->rounding_policy->value
                : 'exact',
            'entries' => $entries->map(fn (TimeEntry $entry) => $this->entryRow($entry))->all(),
            'lateness' => $this->lateness($entries, $ruleFor),
        ];
    }

    /**
     * A whole period for one employee, day by day.
     *
     * @return array<string, mixed>
     */
    public function forPeriod(Employee $employee, string $from, string $to): array
    {
        $days = [];
        $totals = ['worked_seconds' => 0, 'break_seconds' => 0, 'overtime_seconds' => 0];

        /*
         * EVERY entry in the range, fetched ONCE.
         *
         * The first version of this method fetched the range only to work out which dates had
         * activity, then called `forDay()` per date — and `forDay()` issues its own query. A
         * month-long timesheet therefore ran one query per day, plus the labour-rule lookups
         * inside them: 80 queries for a single employee's month, measured. Nothing was wrong
         * with the numbers, which is why it went unnoticed; the cost simply grew with the
         * length of the period.
         *
         * The rows are grouped here and handed to `forDay()` directly.
         */
        $entries = TimeEntry::query()
            ->with(['outlet', 'shift'])
            ->where('employee_id', $employee->id)
            ->whereDate('business_date', '>=', $from)
            ->whereDate('business_date', '<=', $to)
            ->orderBy('started_at')
            ->get();

        $byDate = $entries->groupBy(fn (TimeEntry $entry) => $entry->business_date->toDateString());

        $shifts = Shift::query()
            ->active()
            ->where('employee_id', $employee->id)
            ->whereDate('starts_at', '>=', $from)
            ->whereDate('starts_at', '<=', $to)
            ->get();

        /*
         * Built from the dates that actually have entries plus the dates that have a
         * rostered shift, so a no-show appears as a row rather than vanishing — which
         * is the whole point of recording a roster.
         */
        $dates = $entries->pluck('business_date')->map(fn ($d) => $d->toDateString())
            ->merge($shifts->pluck('starts_at')->map(fn ($d) => $d->toDateString()))
            ->unique()
            ->sort()
            ->values();

        /*
         * Labour rules are resolved ONCE for the whole period, per outlet, and passed down.
         *
         * They are effective-dated, so a period spanning a rate change legitimately needs more
         * than one rule — but resolving per DAY cost a query per day per outlet and returned the
         * same row 26 times over. The lookup below is bounded by the number of distinct rules
         * actually in force across the period, which is normally one.
         */
        $rules = $this->rulesForPeriod($employee, $dates, $entries);

        /*
         * The set of dates this employee was rostered on, so a no-show can be spotted without
         * a query per day.
         */
        $rosteredDates = $shifts->pluck('starts_at')
            ->map(fn ($d) => $d->toDateString())
            ->unique()
            ->flip();

        foreach ($dates as $date) {
            $day = $this->forDay(
                $employee,
                $date,
                entries: $byDate->get($date, collect()),
                rules: $rules,
            );

            /*
             * A rostered day with no entries at all is a no-show, which `forDay()` cannot know
             * about because it only ever looks at punches.
             */
            if ($day['entries'] === [] && $rosteredDates->has($date)) {
                $day['status'] = TimesheetDayStatus::NO_SHOW;
            }

            $days[] = $day;

            $totals['worked_seconds'] += $day['worked_seconds'];
            $totals['break_seconds'] += $day['break_seconds'];
            $totals['overtime_seconds'] += $day['overtime_seconds'];
        }

        return [
            'employee' => [
                'id' => $employee->id,
                'name' => $employee->name,
                'employee_code' => $employee->employee_code,
                'pay_basis' => $employee->pay_basis?->value,
            ],
            'from' => $from,
            'to' => $to,
            'days' => $days,
            'worked_seconds' => $totals['worked_seconds'],
            'worked_label' => $this->hm($totals['worked_seconds']),
            'break_seconds' => $totals['break_seconds'],
            'break_label' => $this->hm($totals['break_seconds']),
            'overtime_seconds' => $totals['overtime_seconds'],
            'overtime_label' => $this->hm($totals['overtime_seconds']),
        ];
    }

    /**
     * Resolve every labour rule in force across a period, keyed by outlet and date.
     *
     * One query per outlet rather than one per outlet per day. A rule row carries
     * `effective_from`/`effective_to`, so the applicable rule for a date is the latest row
     * starting on or before it — resolved in memory here instead of by the database 26 times
     * over.
     *
     * @param  Collection<int, string>  $dates
     * @param  Collection<int, TimeEntry>  $entries
     * @return array<string, LabourRule> Keyed "{outletId}:{date}".
     */
    private function rulesForPeriod(Employee $employee, $dates, $entries): array
    {
        $outletIds = $entries->pluck('outlet_id')
            ->merge($employee->outlets->pluck('id'))
            ->unique()
            ->filter()
            ->values();

        $defaults = [];

        $rows = LabourRule::query()
            ->whereIn('outlet_id', $outletIds)
            ->orderBy('effective_from')
            ->get()
            ->groupBy('outlet_id');

        $resolved = [];

        foreach ($outletIds as $outletId) {
            $defaults[$outletId] = LabourRule::defaultsFor((int) $outletId);

            $outletRows = $rows->get($outletId) ?? collect();

            foreach ($dates as $date) {
                /*
                 * `whereDate` is not available on a plain collection, and comparing date strings
                 * directly avoids constructing 26 Carbons per outlet.
                 */
                $applicable = $outletRows
                    ->filter(fn (LabourRule $rule) => $rule->effective_from->toDateString() <= $date)
                    ->sortByDesc(fn (LabourRule $rule) => $rule->effective_from->toDateString())
                    ->first();

                $resolved[$outletId.':'.$date] = $applicable ?? $defaults[$outletId];
            }
        }

        return $resolved;
    }

    /**
     * Totals per employee for a period — the summary table a manager actually reads.
     *
     * @param  Collection<int, Employee>  $employees
     * @return array<int, array<string, mixed>>
     */
    public function summarise(Collection $employees, string $from, string $to): array
    {
        $rows = [];

        foreach ($employees as $employee) {
            $period = $this->forPeriod($employee, $from, $to);

            $rows[] = [
                'employee_id' => $employee->id,
                'name' => $employee->name,
                'employee_code' => $employee->employee_code,
                'worked_seconds' => $period['worked_seconds'],
                'worked_label' => $period['worked_label'],
                'break_seconds' => $period['break_seconds'],
                'overtime_seconds' => $period['overtime_seconds'],
                'days_worked' => collect($period['days'])
                    ->filter(fn (array $d) => $d['worked_seconds'] > 0)
                    ->count(),
                'incomplete_days' => collect($period['days'])
                    ->filter(fn (array $d) => ! $d['status']->isComplete())
                    ->count(),
            ];
        }

        return $rows;
    }

    // ---- Internals ---------------------------------------------------

    private function hasShiftOn(Employee $employee, string $date): bool
    {
        return Shift::query()
            ->active()
            ->where('employee_id', $employee->id)
            ->whereDate('starts_at', $date)
            ->exists();
    }

    /**
     * Total elapsed span for one outlet, breaks included.
     *
     * Only used when an outlet sets `ot_basis = 'span'`. Summing spans per segment
     * rather than measuring first start to last end avoids a break counted twice — once
     * as its own segment and again inside the span.
     *
     * @param  Collection<int, TimeEntry>  $entries
     */
    private function spanForOutlet(Collection $entries, int $outletId): int
    {
        return $entries
            ->where('outlet_id', $outletId)
            ->sum(fn (TimeEntry $entry) => $entry->durationSeconds());
    }

    /**
     * @param  Collection<int, TimeEntry>  $entries
     */
    private function dayStatus(Collection $entries, bool $open, bool $corrected): TimesheetDayStatus
    {
        if ($open) {
            return TimesheetDayStatus::OPEN;
        }

        if ($corrected) {
            return TimesheetDayStatus::CORRECTED;
        }

        if ($entries->isEmpty()) {
            return TimesheetDayStatus::NO_SHOW;
        }

        // No matched shift anywhere in the day means the work was adhoc, which is
        // normal for this business rather than a fault.
        if ($entries->every(fn (TimeEntry $entry) => $entry->shift_id === null)) {
            return TimesheetDayStatus::ADHOC;
        }

        return TimesheetDayStatus::OK;
    }

    /**
     * Lateness against the first rostered shift of the day.
     *
     * Only against a SHIFT. Without one there is nothing to be late for, and inventing
     * a start time would make every adhoc day look like a discipline problem.
     *
     * The grace window moves the FLAG only. Pay always starts at the real minute, so
     * arriving inside the grace period is reported as on time without being paid as if
     * they had arrived at the rostered time.
     *
     * @param  Collection<int, TimeEntry>  $entries
     * @param  Collection<int, LabourRule>  $rules
     * @return array<string, mixed>|null
     */
    private function lateness(Collection $entries, callable $ruleFor): ?array
    {
        $firstWork = $entries->firstWhere('type', TimeEntryType::WORK);

        if ($firstWork === null || $firstWork->shift_id === null) {
            return null;
        }

        $shift = $firstWork->shift;

        if ($shift === null) {
            return null;
        }

        $rule = $ruleFor((int) $firstWork->outlet_id);

        $arrivedAt = CarbonImmutable::instance($firstWork->started_at);
        $rosteredAt = CarbonImmutable::instance($shift->starts_at);

        // Negative means early, which is not lateness.
        $lateBy = $rosteredAt->diffInSeconds($arrivedAt, false);
        $late = $lateBy > $rule->grace_seconds;

        /*
         * Early-out is measured against the rostered END but only when the day is
         * closed. Comparing an open segment would report everyone as leaving early
         * while they are still on shift.
         */
        $lastEntry = $entries->where('type', TimeEntryType::WORK)->last();
        $leftEarlyBy = null;

        if ($lastEntry?->ended_at !== null) {
            $left = CarbonImmutable::instance($lastEntry->ended_at);

            /*
             * Argument order is what sets the sign: Carbon returns `second - first`, so
             * `$left->diffInSeconds($shiftEnd)` is POSITIVE when the shift was due to end
             * after they left — that is, when they left early. Reversing the two silently
             * inverts the answer, and the grace-window comparison below then discards
             * every early departure as if it were overtime.
             */
            $leftEarlyBy = (int) $left->diffInSeconds(CarbonImmutable::instance($shift->ends_at));

            // Anything at or inside the grace window, and anything past the rostered end,
            // is not an early departure.
            if ($leftEarlyBy <= $rule->grace_seconds) {
                $leftEarlyBy = null;
            }
        }

        return [
            'rostered_start' => $shift->starts_at->toIso8601String(),
            'rostered_end' => $shift->ends_at->toIso8601String(),
            'arrived_at' => $firstWork->started_at->toIso8601String(),
            'late_by_seconds' => $late ? (int) $lateBy : 0,
            'late_by_label' => $late ? $this->hm((int) $lateBy) : null,
            'is_late' => $late,
            'left_early_by_seconds' => $leftEarlyBy !== null ? (int) $leftEarlyBy : null,
            'left_early_by_label' => $leftEarlyBy !== null ? $this->hm((int) $leftEarlyBy) : null,
            'grace_seconds' => $rule->grace_seconds,
        ];
    }

    /**
     * One segment, as the timesheet shows it.
     *
     * @return array<string, mixed>
     */
    private function entryRow(TimeEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'type' => $entry->type->value,
            'type_label' => $entry->type->label(),
            'outlet' => $entry->outlet?->name,
            'outlet_id' => $entry->outlet_id,
            'started_at' => $entry->started_at?->toIso8601String(),
            'ended_at' => $entry->ended_at?->toIso8601String(),
            'duration_seconds' => $entry->durationSeconds(),
            'duration_label' => $this->hm($entry->durationSeconds()),
            'status' => $entry->status->value,
            'status_label' => $entry->status->label(),
            /*
             * Reported so a manager can tell a recorded punch from a corrected one
             * without opening the audit trail. The times shown have already been
             * changed by that correction, so the flag is the only visible cue.
             */
            'is_corrected' => $entry->status === TimeEntryStatus::CORRECTED,
            'has_photo' => $entry->started_photo_path !== null || $entry->ended_photo_path !== null,
            'is_offline_sync' => (bool) $entry->is_offline_sync,
            'note' => $entry->note,
        ];
    }

    /** Seconds as "8h 30m", the form a timesheet is read in. */
    public function hm(int $seconds): string
    {
        $negative = $seconds < 0;
        $seconds = abs($seconds);

        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        $text = $hours > 0 ? "{$hours}h {$minutes}m" : "{$minutes}m";

        return $negative ? '-'.$text : $text;
    }

    /**
     * Seconds as decimal hours, for a spreadsheet.
     *
     * A CSV is for arithmetic, and "8h 30m" is a string no spreadsheet can sum. Two
     * decimal places is a hundredth of an hour — 36 seconds — which is finer than any
     * pay period needs and still adds up without accumulating a rounding drift.
     */
    public function durationAsDecimalHours(int $seconds): string
    {
        return number_format($seconds / 3600, 2, '.', '');
    }
}
