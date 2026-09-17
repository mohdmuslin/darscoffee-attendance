<?php

namespace App\Services;

use App\Enums\TimeEntryStatus;
use App\Enums\TimeEntryType;
use App\Models\Employee;
use App\Models\Outlet;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\TimeEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Planned versus actual.
 *
 * This is where "we also have adhoc tasks" becomes visible with numbers, and it is the
 * report the whole separate-tables design exists to make possible.
 *
 * MATCHING IS BY TIME OVERLAP, NOT BY `time_entries.shift_id`
 *
 * That column looks like the obvious key and is a trap. It records which shift was live when
 * the punch happened, which is a SNAPSHOT — and amending a shift cancels it and writes a
 * replacement. So after a manager moves a shift, the entry still points at the cancelled
 * original while the live shift is a different row. Keying on it would report such a day as
 * "a punch against a cancelled shift" PLUS "a no-show for the replacement": two wrong rows
 * describing something that went perfectly normally.
 *
 * Overlap matching is immune to that, and is also the only thing that works for a punch that
 * was never linked at all — a correction-added segment, an imported row, or anything
 * recorded before the roster existed.
 */
class VarianceService
{
    /** A punch within this much of a shift still counts as covering it. */
    private const DEFAULT_TOLERANCE_MINUTES = 30;

    public function __construct(private readonly WorkedHoursService $hours) {}

    /**
     * One employee's planned-versus-actual for a period, day by day.
     *
     * @return array<string, mixed>
     */
    public function forEmployee(Employee $employee, string $from, string $to, ?Outlet $outlet = null): array
    {
        $timezone = $outlet?->timezone ?? $employee->outlets()->first()?->timezone ?? config('attendance.business_timezone');

        // The UTC instant range for the local dates asked for. Comparing a LOCAL boundary
        // against a column stored UTC is the mistake that silently drops the first eight
        // hours of every day, so the conversion happens here, once.
        $rangeStart = CarbonImmutable::parse($from.' 00:00:00', $timezone)->utc();
        $rangeEnd = CarbonImmutable::parse($to.' 00:00:00', $timezone)->addDay()->utc();

        $shifts = Shift::query()
            ->with('outlet')
            ->where('employee_id', $employee->id)
            ->when($outlet !== null, fn ($q) => $q->where('outlet_id', $outlet->id))
            ->where('starts_at', '>=', $rangeStart)
            ->where('starts_at', '<', $rangeEnd)
            ->orderBy('starts_at')
            ->get();

        $entries = TimeEntry::query()
            ->with('outlet')
            ->where('employee_id', $employee->id)
            ->when($outlet !== null, fn ($q) => $q->where('outlet_id', $outlet->id))
            ->where('type', TimeEntryType::WORK->value)
            ->where('started_at', '>=', $rangeStart)
            ->where('started_at', '<', $rangeEnd)
            ->orderBy('started_at')
            ->get();

        $rows = $this->match($shifts, $entries, $timezone);

        return [
            'employee' => [
                'id' => $employee->id,
                'name' => $employee->name,
                'employee_code' => $employee->employee_code,
            ],
            'from' => $from,
            'to' => $to,
            'timezone' => $timezone,
            'rows' => $rows,
            ...$this->totals($rows),
        ];
    }

    /**
     * A period across many employees — the summary a manager actually reads.
     *
     * @param  Collection<int, Employee>  $employees
     * @return array<string, mixed>
     */
    public function summarise(Collection $employees, string $from, string $to, ?Outlet $outlet = null): array
    {
        $rows = [];

        foreach ($employees as $employee) {
            $report = $this->forEmployee($employee, $from, $to, $outlet);

            $rows[] = [
                'employee_id' => $employee->id,
                'name' => $employee->name,
                'employee_code' => $employee->employee_code,
                'planned_seconds' => $report['planned_seconds'],
                'planned_label' => $report['planned_label'],
                'worked_seconds' => $report['worked_seconds'],
                'worked_label' => $report['worked_label'],
                'variance_seconds' => $report['variance_seconds'],
                'variance_label' => $report['variance_label'],
                'adhoc_seconds' => $report['adhoc_seconds'],
                'adhoc_label' => $report['adhoc_label'],
                'no_show_count' => $report['no_show_count'],
                'partial_count' => $report['partial_count'],
                'unplanned_count' => $report['unplanned_count'],
                'has_variance' => $report['has_variance'],
            ];
        }

        return [
            'from' => $from,
            'to' => $to,
            'employees' => $rows,
            'totals' => [
                'planned_seconds' => collect($rows)->sum('planned_seconds'),
                'worked_seconds' => collect($rows)->sum('worked_seconds'),
                'variance_seconds' => collect($rows)->sum('variance_seconds'),
                'adhoc_seconds' => collect($rows)->sum('adhoc_seconds'),
                'no_show_count' => collect($rows)->sum('no_show_count'),
                'partial_count' => collect($rows)->sum('partial_count'),
                'unplanned_count' => collect($rows)->sum('unplanned_count'),
            ],
        ];
    }

    // ---- Matching ----------------------------------------------------

    /**
     * Pair shifts with the punches that cover them, and collect the rest.
     *
     * Greedy, by shift start order. That is the right shape here: a person's shifts do not
     * overlap (the roster refuses it), so each punch belongs to at most one shift and the
     * only ambiguity is a punch sitting across two adjacent shifts — decided in favour of the
     * earlier one, which is nearly always correct for a handover.
     *
     * @param  Collection<int, Shift>  $shifts
     * @param  Collection<int, TimeEntry>  $entries
     * @return array<int, array<string, mixed>>
     */
    private function match(Collection $shifts, Collection $entries, string $timezone): array
    {
        $tolerance = (int) Setting::int(
            Setting::VARIANCE_TOLERANCE_MINUTES,
            self::DEFAULT_TOLERANCE_MINUTES,
        ) * 60;

        $remaining = $entries->values()->all();
        $rows = [];

        foreach ($shifts as $shift) {
            /*
             * A cancelled shift never absorbs a punch.
             *
             * The plan for that day was withdrawn, so whatever was worked was NOT planned —
             * and letting the cancelled row claim the hours would report the day as met when
             * nobody had agreed to it. The hours fall through to the unplanned rows below,
             * which is the truth.
             */
            if ($shift->isCancelled()) {
                $rows[] = [
                    'type' => 'shift',
                    'date' => $shift->starts_at->setTimezone($timezone)->toDateString(),
                    'shift_id' => $shift->id,
                    'is_cancelled' => true,
                    'outlet' => $shift->outlet?->name,
                    'position' => $shift->position,
                    'planned_start' => $shift->starts_at->toIso8601String(),
                    'planned_end' => $shift->ends_at->toIso8601String(),
                    'planned_seconds' => 0,
                    'planned_label' => null,
                    'worked_seconds' => 0,
                    'worked_label' => $this->hours->hm(0),
                    'variance_seconds' => 0,
                    'variance_label' => null,
                    'status' => 'cancelled',
                    'entry_ids' => [],
                ];

                continue;
            }

            $matched = [];
            $kept = [];

            foreach ($remaining as $index => $entry) {
                if ($this->covers($entry, $shift, $tolerance)) {
                    $matched[] = $entry;
                } else {
                    $kept[] = $entry;
                }
            }

            $remaining = $kept;

            $worked = collect($matched)->sum(fn (TimeEntry $entry) => $entry->durationSeconds());

            $rows[] = [
                'type' => 'shift',
                'date' => $shift->starts_at->setTimezone($timezone)->toDateString(),
                'shift_id' => $shift->id,
                'is_cancelled' => $shift->isCancelled(),
                'outlet' => $shift->outlet?->name,
                'position' => $shift->position,
                'planned_start' => $shift->starts_at->toIso8601String(),
                'planned_end' => $shift->ends_at->toIso8601String(),
                'planned_seconds' => $shift->durationSeconds(),
                'planned_label' => $this->hours->hm($shift->durationSeconds()),
                'worked_seconds' => $worked,
                'worked_label' => $this->hours->hm($worked),
                'variance_seconds' => $worked - $shift->durationSeconds(),
                'variance_label' => $this->hours->hm($worked - $shift->durationSeconds()),
                'status' => $this->shiftStatus($shift, $matched, $worked, $tolerance),
                'entry_ids' => collect($matched)->pluck('id')->all(),
            ];
        }

        /*
         * Anything left over was worked with no rostered shift: the adhoc case. Reported as
         * its own row rather than attached to a shift, because attaching it to a nearby shift
         * would hide exactly the thing this report exists to show.
         *
         * A cancelled shift does not absorb a punch, even if the times overlap: the plan for
         * that day was withdrawn, so the work was not planned.
         */
        foreach ($remaining as $entry) {
            $rows[] = [
                'type' => 'unplanned',
                'date' => $entry->started_at->setTimezone($timezone)->toDateString(),
                'shift_id' => null,
                'is_cancelled' => false,
                'outlet' => $entry->outlet?->name,
                'position' => null,
                'planned_start' => null,
                'planned_end' => null,
                'planned_seconds' => 0,
                'planned_label' => null,
                'worked_seconds' => $entry->durationSeconds(),
                'worked_label' => $this->hours->hm($entry->durationSeconds()),
                // No plan to differ from, so there is no variance figure — the whole segment
                // is unplanned, which the adhoc total reports.
                'variance_seconds' => 0,
                'variance_label' => null,
                'status' => 'unplanned',
                'entry_ids' => [$entry->id],
                'is_corrected' => $entry->status === TimeEntryStatus::CORRECTED,
            ];
        }

        // Sorted by date so the report reads as a calendar, with unplanned rows alongside the
        // days they happened on rather than in a separate list at the bottom.
        usort($rows, fn (array $a, array $b) => [$a['date'], $a['planned_start'] ?? 'z'] <=> [$b['date'], $b['planned_start'] ?? 'z']);

        return $rows;
    }

    /**
     * Whether a punch counts as covering a shift.
     *
     * Overlap with a tolerance, not containment. A punch that starts ten minutes late and ends
     * four hours in still covers the shift, and required containment would call that a no-show.
     *
     * @param  int  $tolerance  seconds of slack at either end
     */
    private function covers(TimeEntry $entry, Shift $shift, int $tolerance): bool
    {
        $entryStart = CarbonImmutable::instance($entry->started_at);
        $entryEnd = $entry->ended_at !== null
            ? CarbonImmutable::instance($entry->ended_at)
            // An OPEN segment runs to now, so a shift in progress is covered rather than
            // reported as a no-show while the employee is standing there working.
            : CarbonImmutable::now();

        return $entryStart->lessThan($shift->ends_at->addSeconds($tolerance))
            && $entryEnd->greaterThan($shift->starts_at->subSeconds($tolerance));
    }

    /**
     * How a shift compares to what actually happened.
     *
     * @param  array<int, TimeEntry>  $matched
     */
    private function shiftStatus(Shift $shift, array $matched, int $worked, int $tolerance): string
    {
        if ($shift->isCancelled()) {
            // A cancelled shift is not a shortfall however the day went. Reporting it as one
            // would make every called-off shift look like a problem.
            return 'cancelled';
        }

        $now = CarbonImmutable::now();

        /*
         * A shift still RUNNING is not compared at all.
         *
         * An open punch keeps accumulating, so half way through a shift the hours are always
         * less than planned — calling that "short" would report every shift as a shortfall
         * until the moment it ended, and a report that cries wolf is one nobody reads.
         *
         * Checked BEFORE the no-show case, because a shift in progress with no punch yet is
         * "not started", not "nobody came".
         */
        if ($shift->ends_at->greaterThan($now)) {
            return $matched === [] ? 'not_started' : 'in_progress';
        }

        if ($matched === []) {
            return 'no_show';
        }

        // The tolerance doubles as the shortfall threshold: arriving 20 minutes late and
        // leaving at the right time is not a variance worth a colour.
        if ($worked < $shift->durationSeconds() - $tolerance) {
            return 'short';
        }

        if ($worked > $shift->durationSeconds() + $tolerance) {
            return 'over';
        }

        return 'met';
    }

    // ---- Totals ------------------------------------------------------

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function totals(array $rows): array
    {
        // Cancelled shifts are excluded from the planned figure entirely: the plan for that
        // day was withdrawn, so counting it would show a shortfall that never existed.
        $planned = collect($rows)
            ->reject(fn (array $row) => $row['type'] === 'shift' && $row['is_cancelled'])
            ->sum('planned_seconds');

        $worked = collect($rows)->sum('worked_seconds');

        $adhoc = collect($rows)
            ->where('type', 'unplanned')
            ->sum('worked_seconds');

        $noShows = collect($rows)->where('status', 'no_show')->count();
        $partial = collect($rows)->whereIn('status', ['short', 'over'])->count();
        $unplanned = collect($rows)->where('type', 'unplanned')->count();

        return [
            'planned_seconds' => $planned,
            'planned_label' => $this->hours->hm($planned),
            'worked_seconds' => $worked,
            'worked_label' => $this->hours->hm($worked),
            // Positive means more was worked than planned. The sign is kept rather than an
            // absolute value, because "under by 4h" and "over by 4h" need different responses.
            'variance_seconds' => $worked - $planned,
            'variance_label' => $this->hours->hm($worked - $planned),
            'adhoc_seconds' => $adhoc,
            'adhoc_label' => $this->hours->hm($adhoc),
            'no_show_count' => $noShows,
            'partial_count' => $partial,
            'unplanned_count' => $unplanned,
            /*
             * Shifts not yet finished, and shifts not yet started.
             *
             * Reported so a screen can say "3 shifts still to come" rather than leaving the
             * reader to wonder whether the shortfall they are looking at is real. Neither is a
             * variance — the day has not happened yet.
             */
            'upcoming_count' => collect($rows)->whereIn('status', ['in_progress', 'not_started'])->count(),
            // One flag rather than five, so a screen can say "something needs looking at"
            // without re-implementing the rule.
            'has_variance' => $noShows > 0 || $partial > 0 || $unplanned > 0,
        ];
    }
}
