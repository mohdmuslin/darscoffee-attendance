<?php

namespace App\Services;

use App\Enums\TimeEntryType;
use App\Models\Employee;
use App\Models\Outlet;
use App\Models\Shift;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Building and copying the roster.
 *
 * The plan and the actual are separate tables, and nothing here writes a time entry. A
 * roster change must never alter recorded hours — that is the boundary the whole design
 * rests on, and a "helpful" adjustment here would quietly break it.
 *
 * Times are accepted as local wall-clock and converted using the OUTLET's timezone. The
 * browser is never asked to do that conversion: it would mean trusting the phone's clock
 * and its timezone setting, and the failure mode is a shift eight hours out.
 */
class ShiftService
{
    /**
     * Create a roster entry.
     *
     * @param  array<string, mixed>  $attributes  starts_at / ends_at as 'Y-m-d\TH:i' local strings
     */
    public function create(Outlet $outlet, Employee $employee, array $attributes, User $creator): Shift
    {
        [$startsAt, $endsAt] = $this->resolveLocalTimes($outlet, $attributes);

        if (! ($attributes['allow_overlap'] ?? false)) {
            $this->assertNoOverlap($employee, $startsAt, $endsAt);
        }

        return Shift::create([
            'employee_id' => $employee->id,
            'outlet_id' => $outlet->id,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'position' => $attributes['position'] ?? null,
            'note' => $attributes['note'] ?? null,
            'created_by' => $creator->id,
        ]);
    }

    /**
     * Amend a roster entry.
     *
     * Cancels the original and writes a replacement rather than editing in place, when the
     * TIMES move. A shift whose hours changed is not the same plan, and the person who
     * asked "why am I down for a late shift?" needs to see that it changed — an in-place
     * edit would leave the answer nowhere.
     *
     * A change to the note or position only is edited in place: nothing about the plan
     * moved, and duplicating the row for a typo fix would make the roster unreadable.
     */
    public function update(Shift $shift, array $attributes, User $editor): Shift
    {
        $outlet = $shift->outlet;
        $employee = $shift->employee;

        $timesMoved = isset($attributes['starts_at']) || isset($attributes['ends_at']);

        if (! $timesMoved) {
            $shift->forceFill([
                'position' => $attributes['position'] ?? $shift->position,
                'note' => $attributes['note'] ?? $shift->note,
            ])->save();

            return $shift->fresh();
        }

        $merged = [
            'starts_at' => $attributes['starts_at']
                ?? $shift->starts_at->setTimezone($outlet->timezone)->format('Y-m-d\TH:i'),
            'ends_at' => $attributes['ends_at']
                ?? $shift->ends_at->setTimezone($outlet->timezone)->format('Y-m-d\TH:i'),
            'allow_overlap' => $attributes['allow_overlap'] ?? false,
            'position' => $attributes['position'] ?? $shift->position,
            'note' => $attributes['note'] ?? $shift->note,
        ];

        return DB::transaction(function () use ($shift, $outlet, $employee, $merged, $editor) {
            /*
             * Cancel FIRST, then check for a clash.
             *
             * The other way round, moving a shift's times always reported "already rostered
             * for part of that time" — because the original was still live and overlapped the
             * replacement being created. The clash was the shift with itself.
             */
            $shift->cancel();

            return $this->create($outlet, $employee, $merged, $editor);
        });
    }

    /**
     * Copy a range of shifts forward, or onto another employee.
     *
     * "Copy last week" is the action a manager uses most, so it is built as one operation
     * rather than a loop of creates: a partial copy — half a week written, then an error —
     * is worse than none, because the manager cannot tell which half landed.
     *
     * @param  array<string, mixed>  $options
     * @return array{created: int, skipped: int}
     */
    public function copy(Collection $shifts, array $options, User $copier): array
    {
        $sourceFrom = CarbonImmutable::parse($options['source_from'].' 00:00:00', $this->timezoneOf($options));
        $targetFrom = CarbonImmutable::parse($options['target_from'].' 00:00:00', $this->timezoneOf($options));

        // The whole range shifts by the same number of DAYS, not seconds, so a copy across
        // a daylight-saving boundary keeps its local start time rather than drifting an hour.
        $dayOffset = $sourceFrom->diffInDays($targetFrom) * ($targetFrom->greaterThan($sourceFrom) ? 1 : -1);

        $onConflict = $options['on_conflict'] ?? 'skip';
        $targetEmployee = isset($options['employee_id'])
            ? Employee::find($options['employee_id'])
            : null;

        $created = 0;
        $skipped = 0;

        return DB::transaction(function () use ($shifts, $targetEmployee, $dayOffset, $onConflict, $copier, &$created, &$skipped) {
            foreach ($shifts as $shift) {
                $employee = $targetEmployee ?? $shift->employee;

                /*
                 * Re-wrapped as CarbonImmutable. `addDays()` on an Eloquent datetime cast
                 * returns Illuminate\Support\Carbon, not CarbonImmutable — so the type
                 * declaration on the overlap check rejected it, and the failure only
                 * appeared on the copy path.
                 */
                $targetStarts = CarbonImmutable::instance($shift->starts_at->addDays($dayOffset));
                $targetEnds = CarbonImmutable::instance($shift->ends_at->addDays($dayOffset));

                if ($this->overlapExists($employee, $targetStarts, $targetEnds)) {
                    if ($onConflict === 'skip') {
                        $skipped++;

                        continue;
                    }

                    // Replace: the existing entries are cancelled rather than deleted, so a
                    // roster that was overwritten can still be explained afterwards.
                    Shift::query()
                        ->where('employee_id', $employee->id)
                        ->active()
                        ->where('starts_at', '<', $targetEnds)
                        ->where('ends_at', '>', $targetStarts)
                        ->get()
                        ->each(fn (Shift $clash) => $clash->cancel());
                }

                Shift::create([
                    'employee_id' => $employee->id,
                    'outlet_id' => $shift->outlet_id,
                    'starts_at' => $targetStarts,
                    'ends_at' => $targetEnds,
                    'position' => $shift->position,
                    'note' => $shift->note,
                    'created_by' => $copier->id,
                ]);

                $created++;
            }

            return ['created' => $created, 'skipped' => $skipped];
        });
    }

    /**
     * The roster for a date range, grouped by day — INCLUDING days with nothing on them.
     *
     * Grouping in PHP rather than by the database: the grouping key is the LOCAL date, which
     * depends on the outlet's timezone, and SQL has no idea what that is. Doing it in a
     * `GROUP BY` on the UTC column is how an evening shift ends up under the wrong heading.
     *
     * Empty days are emitted deliberately. A `groupBy` alone omits them, so a day with
     * nobody rostered simply does not appear — and that is the single most important thing a
     * roster can tell a manager. A missing row reads as "no data"; an empty day reads as
     * "nobody is on", which is the actual problem.
     *
     * @param  Collection<int, Shift>  $shifts
     * @param  array<int, string>  $dates  the local dates to emit, in order
     * @return array<int, array<string, mixed>>
     */
    public function byDay(Collection $shifts, string $timezone, array $dates = []): array
    {
        $grouped = $shifts->groupBy(
            fn (Shift $shift) => $shift->starts_at->setTimezone($timezone)->toDateString()
        );

        /*
         * The dates come from the caller, which knows the requested range. Deriving them by
         * iterating from the earliest to the latest SHIFT would silently drop a trailing day
         * off the end of the range — the same bug in a different place.
         */
        $dates = $dates === [] ? $grouped->keys()->sort()->values()->all() : $dates;

        $days = [];

        foreach ($dates as $date) {
            $ofDay = $grouped->get($date, collect());

            $days[] = [
                'date' => $date,
                'shifts' => $ofDay->sortBy('starts_at')->values(),
                'planned_seconds' => $ofDay
                    ->filter(fn (Shift $shift) => ! $shift->isCancelled())
                    ->sum(fn (Shift $shift) => $shift->durationSeconds()),
                // Cancelled shifts do not count as cover. A day whose only shift was called
                // off is a day nobody is working, and reporting it as covered is how a shop
                // opens with nobody on.
                'has_cover' => $ofDay->contains(fn (Shift $shift) => ! $shift->isCancelled()),
            ];
        }

        return $days;
    }

    /**
     * The local dates in a range, inclusive of both ends.
     *
     * @return array<int, string>
     */
    public function datesBetween(CarbonImmutable $from, CarbonImmutable $toExclusive): array
    {
        $dates = [];

        for ($date = $from; $date->lessThan($toExclusive); $date = $date->addDay()) {
            $dates[] = $date->toDateString();
        }

        return $dates;
    }

    /**
     * Planned hours per employee for the range.
     *
     * Used by the roster footer, and in Phase 5 by the planned-versus-actual variance. Kept
     * here rather than in the report so the two can never disagree about what "planned"
     * means.
     *
     * @return array<int, array<string, mixed>>
     */
    public function plannedTotals(Collection $shifts): array
    {
        return $shifts
            ->filter(fn (Shift $shift) => ! $shift->isCancelled())
            ->groupBy('employee_id')
            ->map(function (Collection $group) {
                $employee = $group->first()->employee;
                $seconds = $group->sum(fn (Shift $shift) => $shift->durationSeconds());

                return [
                    'employee_id' => $employee?->id,
                    'name' => $employee?->name,
                    'employee_code' => $employee?->employee_code,
                    'shift_count' => $group->count(),
                    'planned_seconds' => $seconds,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Whether a punch already exists inside a shift window.
     *
     * Shown on the roster so a manager can see at a glance which of next week's shifts were
     * actually worked. Read-only: the plan never writes the actual.
     */
    public function workedWithin(Shift $shift): bool
    {
        return TimeEntry::query()
            ->where('employee_id', $shift->employee_id)
            ->where('type', TimeEntryType::WORK->value)
            ->where('started_at', '<', $shift->ends_at)
            ->where(fn ($q) => $q
                ->whereNull('ended_at')
                ->orWhere('ended_at', '>', $shift->starts_at))
            ->exists();
    }

    // ---- Internals ---------------------------------------------------

    /**
     * Convert local wall-clock input into UTC instants.
     *
     * @param  array<string, mixed>  $attributes
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function resolveLocalTimes(Outlet $outlet, array $attributes): array
    {
        if (blank($attributes['starts_at'] ?? null) || blank($attributes['ends_at'] ?? null)) {
            throw new InvalidArgumentException('A shift needs both a start and an end.');
        }

        $timezone = $outlet->timezone ?? config('attendance.business_timezone');

        // Parsed IN the outlet's timezone, then stored UTC. Parsing without the zone would
        // interpret the wall-clock time as UTC and produce exactly the eight-hour error this
        // protects against.
        $startsAt = CarbonImmutable::parse($attributes['starts_at'], $timezone)->utc();
        $endsAt = CarbonImmutable::parse($attributes['ends_at'], $timezone)->utc();

        if ($endsAt->lessThanOrEqualTo($startsAt)) {
            throw new InvalidArgumentException('A shift has to end after it starts.');
        }

        return [$startsAt, $endsAt];
    }

    /**
     * Refuse a shift that collides with one the person already has.
     *
     * Cancelled shifts are ignored: a called-off shift must not block a replacement, which
     * is exactly what re-rostering after a cancellation would otherwise fail at.
     */
    private function assertNoOverlap(Employee $employee, CarbonImmutable $startsAt, CarbonImmutable $endsAt): void
    {
        if ($this->overlapExists($employee, $startsAt, $endsAt)) {
            throw new InvalidArgumentException(
                $employee->name.' is already rostered for part of that time.'
            );
        }
    }

    private function overlapExists(Employee $employee, CarbonImmutable $startsAt, CarbonImmutable $endsAt): bool
    {
        return Shift::query()
            ->where('employee_id', $employee->id)
            ->active()
            /*
             * The standard half-open overlap test: two ranges collide when one starts before
             * the other ends AND ends after the other starts. Using `<=`/`>=` here would make
             * a shift that starts exactly when the previous one ends count as a clash, which
             * would refuse every back-to-back roster.
             */
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt)
            ->exists();
    }

    /**
     * The timezone to interpret copy dates in.
     *
     * A copy is a date arithmetic operation, so it needs SOME zone, and the outlet's own is
     * the only defensible choice. Falls back to the business default when the copy spans
     * outlets, which is why the fallback is config rather than a hard-coded string.
     *
     * @param  array<string, mixed>  $options
     */
    private function timezoneOf(array $options): string
    {
        if (isset($options['outlet_id'])) {
            return Outlet::find($options['outlet_id'])?->timezone
                ?? config('attendance.business_timezone');
        }

        if (isset($options['employee_id'])) {
            $outlet = Employee::find($options['employee_id'])?->outlets()->first();

            if ($outlet !== null) {
                return $outlet->timezone;
            }
        }

        return config('attendance.business_timezone');
    }
}
