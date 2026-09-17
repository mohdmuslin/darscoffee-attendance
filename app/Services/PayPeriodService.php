<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\PayPeriod;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Pay periods, and locking them.
 *
 * WHY LOCKING EXISTS
 *
 * Without it, "last month's total" is a figure that changes every time somebody fixes a
 * forgotten clock-out. A payslip already handed out stops matching the system, and there is no
 * way to answer "what did we actually pay?". Locking commits a number.
 *
 * WHY THE LOCK IS DETECTABLE RATHER THAN HARD
 *
 * A hard freeze — refusing corrections because a period is locked — sounds stronger and is
 * worse. A genuine missed clock-out does not stop being genuine because a period was closed,
 * and an attendance system that cannot fix it after month end is one a manager works around on
 * paper.
 *
 * So: the figures approved at lock time are STORED, and the inputs are fingerprinted. A later
 * correction remains possible, and the moment it happens the recomputed figure stops matching
 * the stored one. The console reports that the period has drifted rather than quietly showing
 * a different number as though nothing happened.
 *
 * This is success criterion 6: reproduce last month's figure exactly, including a mid-month
 * rate change.
 */
class PayPeriodService
{
    public function __construct(
        private readonly PayService $pay,
        private readonly WorkedHoursService $hours,
    ) {}

    /**
     * Create a period.
     *
     * Overlap is checked in the service rather than by a database constraint: MySQL has no
     * exclusion constraint, and the generated-column trick needed to fake one would be harder
     * to read than the rule it replaced.
     */
    public function create(string $name, string $startsOn, string $endsOn, User $creator, ?string $note = null): PayPeriod
    {
        if ($endsOn < $startsOn) {
            throw new InvalidArgumentException('The period ends before it starts.');
        }

        if ($this->overlapping($startsOn, $endsOn) !== null) {
            throw new InvalidArgumentException('That overlaps a pay period that already exists.');
        }

        return PayPeriod::create([
            'name' => $name,
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
            'note' => $note,
            'created_by' => $creator->id,
        ]);
    }

    /**
     * The period covering a date, if any.
     *
     * Used to warn before a correction touches a locked period, which is the moment a manager
     * most needs to know.
     */
    public function covering(string $date): ?PayPeriod
    {
        return PayPeriod::query()->covering($date)->orderByDesc('locked_at')->first();
    }

    /**
     * Lock a period, storing the approved figures.
     *
     * @param  Collection<int, Employee>  $employees
     */
    public function lock(PayPeriod $period, Collection $employees, User $locker): PayPeriod
    {
        if ($period->isLocked()) {
            // Idempotent: a double-click must not re-snapshot at a moment when the figures may
            // have moved, which would silently rewrite what was approved.
            return $period;
        }

        $from = $period->starts_on->toDateString();
        $to = $period->ends_on->toDateString();

        $period->forceFill([
            'locked_at' => now(),
            'locked_by' => $locker->id,
            'snapshot' => $this->pay->summarise($employees, $from, $to),
            'snapshot_hash' => $this->pay->fingerprint($employees, $from, $to),
        ])->save();

        return $period->fresh();
    }

    /**
     * Recompute a period and say whether it still matches what was approved.
     *
     * The three states matter and are distinct:
     *
     *  - `open`:      never locked. Nothing was committed, so nothing can have drifted.
     *  - `locked`:    locked and the inputs are unchanged. The stored figure is still right.
     *  - `drifted`:   locked, but the inputs have changed since. Someone corrected something,
     *                 and the stored figure is now the record of what was PAID while the
     *                 recomputed one is what is owed.
     *
     * @param  Collection<int, Employee>  $employees
     * @return array<string, mixed>
     */
    public function reconcile(PayPeriod $period, Collection $employees): array
    {
        $from = $period->starts_on->toDateString();
        $to = $period->ends_on->toDateString();

        $current = $this->pay->summarise($employees, $from, $to);
        $currentHash = $this->pay->fingerprint($employees, $from, $to);

        if (! $period->isLocked()) {
            return [
                'state' => 'open',
                'current' => $current,
                'approved' => null,
                'difference' => null,
            ];
        }

        $drifted = $period->snapshot_hash !== $currentHash;

        return [
            'state' => $drifted ? 'drifted' : 'locked',
            'current' => $current,
            'approved' => $period->snapshot,
            'approved_at' => $period->locked_at?->toIso8601String(),
            'approved_by' => $period->locker?->name,
            'difference' => $drifted ? $this->difference($period->snapshot, $current) : null,
        ];
    }

    /**
     * What moved between the approved figures and the current ones.
     *
     * Reported per employee, because "the total is 4 sen different" is not actionable while
     * "Ali's overtime is 4 sen different" is.
     *
     * @param  array<string, mixed>|null  $approved
     * @param  array<string, mixed>  $current
     * @return array<string, mixed>
     */
    private function difference(?array $approved, array $current): array
    {
        $approvedRows = collect($approved['employees'] ?? [])->keyBy('employee_id');
        $moved = [];

        foreach ($current['employees'] as $row) {
            $before = $approvedRows->get($row['employee_id']);

            if ($before === null) {
                // Not in the approved figures at all: a rate was set, or hours appeared, for
                // somebody who was not priced when the period was locked.
                if ($row['total_amount'] !== null) {
                    $moved[] = [
                        'employee_id' => $row['employee_id'],
                        'name' => $row['name'],
                        'before' => null,
                        'after' => $row['total_amount'],
                        'delta' => $row['total_amount'],
                        'reason' => 'Not priced when the period was locked.',
                    ];
                }

                continue;
            }

            $delta = round((float) ($row['total_amount'] ?? 0) - (float) ($before['total_amount'] ?? 0), 2);

            if ($delta !== 0.0) {
                $moved[] = [
                    'employee_id' => $row['employee_id'],
                    'name' => $row['name'],
                    'before' => $before['total_amount'],
                    'after' => $row['total_amount'],
                    'delta' => $delta,
                ];
            }
        }

        $approvedTotal = (float) ($approved['totals']['total_amount'] ?? 0);
        $currentTotal = (float) ($current['totals']['total_amount'] ?? 0);

        return [
            'employees' => $moved,
            /*
             * The hours can change while the MONEY does not.
             *
             * A shift extended into overtime, at an outlet with no overtime rate configured,
             * moves the inputs without moving the amount — because the design deliberately
             * refuses to invent a rate nobody agreed. Reported explicitly rather than leaving
             * an empty list beside a "drifted" state, which would read as a bug.
             */
            'inputs_changed_only' => $moved === [] && $approvedTotal === $currentTotal,
            'before_total' => round($approvedTotal, 2),
            'after_total' => round($currentTotal, 2),
            'delta_total' => round($currentTotal - $approvedTotal, 2),
        ];
    }

    /**
     * A default period for a month, for the "new period" form.
     *
     * Monthly is how this business thinks, and a form that opens on something sensible is one
     * fewer thing to get wrong.
     *
     * @return array{name: string, starts_on: string, ends_on: string}
     */
    public function suggestForMonth(string $month): array
    {
        $timezone = config('attendance.business_timezone');
        $start = CarbonImmutable::parse($month.'-01', $timezone);

        return [
            'name' => $start->format('F Y'),
            'starts_on' => $start->toDateString(),
            'ends_on' => $start->endOfMonth()->toDateString(),
        ];
    }

    /** Any period sharing a date with the given range. */
    private function overlapping(string $startsOn, string $endsOn): ?PayPeriod
    {
        return PayPeriod::query()
            ->whereDate('starts_on', '<=', $endsOn)
            ->whereDate('ends_on', '>=', $startsOn)
            ->first();
    }
}
