<?php

namespace App\Services;

use App\Enums\PayBasis;
use App\Enums\TimeEntryType;
use App\Models\CompensationRule;
use App\Models\Employee;
use App\Models\LabourRule;
use App\Models\RateAdjustment;
use App\Models\Setting;
use App\Models\TimeEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Pricing hours.
 *
 * THE ONE RULE THIS FILE EXISTS TO PROTECT
 *
 * A monthly rate is not an hourly rate, and a daily rate is not a weekly one. Dividing a
 * monthly salary by the hours in a month produces a different hourly figure every month, and
 * multiplying that back out never returns the salary — so the business's agreed convention is
 * worked out per basis, in one place, and every screen reads the result from here.
 *
 * PLAIN FLOATS ARE USED INTERNALLY AND ROUNDED ONCE, AT THE END.
 *
 * Rounding each line as it is computed accumulates error across twenty staff and a hundred
 * lines: each line can be half a sen out in the same direction, and the total drifts by real
 * money. So the arithmetic runs at full precision and `round()` is applied once, to the final
 * figure. Money is never stored or compared as a float — only computed and immediately
 * rounded for presentation.
 *
 * WHAT THIS DOES NOT DO
 *
 * No tax, no EPF, no SOCSO. Those are a legal domain with real consequences, and the blueprint
 * is explicit: the system produces hours and amounts and exports them. Adding statutory
 * deductions is a decision with a lawyer in the room, not a commit.
 */
class PayService
{
    public function __construct(private readonly WorkedHoursService $hours) {}

    /**
     * Price one employee's period.
     *
     * @return array<string, mixed>
     */
    public function forEmployee(Employee $employee, string $from, string $to): array
    {
        $entries = $this->workSegments($employee, $from, $to);

        // Totalled PER DAY, because overtime is a daily threshold and a pay base is a period
        // one. Computing either from a summed period total would be wrong the moment an
        // employee works two short days and one long one.
        $dailyWorked = $this->dailyWorked($entries);

        $rate = CompensationRule::forDate($employee->id, $from);

        if ($rate === null) {
            /*
             * No rate set. Returned as an explicit state rather than a zero figure: a zero
             * flows into a total and looks like a real amount, and the difference between
             * "owes nothing" and "not yet configured" matters enormously on a payslip.
             */
            return [
                'employee' => $this->employeeSummary($employee),
                'from' => $from,
                'to' => $to,
                'has_rate' => false,
                'worked_seconds' => array_sum($dailyWorked),
                'worked_label' => $this->hours->hm((int) array_sum($dailyWorked)),
                'overtime_seconds' => 0,
                'overtime_label' => null,
                'ordinary_amount' => null,
                'overtime_amount' => null,
                'total_amount' => null,
                'currency' => 'MYR',
                'days' => [],
                'warnings' => ['No pay rate set for this period. The hours are recorded but cannot be priced.'],
            ];
        }

        [$ordinarySeconds, $overtimeSeconds, $days] = $this->splitOrdinaryAndOvertime($employee, $dailyWorked, $from);

        $ordinaryAmount = $this->ordinaryAmount($employee, $rate, $ordinarySeconds, $days, $from, $to);
        $overtimeAmount = $this->overtimeAmount($employee, $rate, $overtimeSeconds, $from, $to);

        $warnings = [];

        /*
         * A rate that changes mid-period is flagged rather than silently averaged. The figure
         * below prices the whole period at the rate in force on the FIRST day, and a manager
         * needs to know that is what happened — the alternative (prorating automatically)
         * would guess at an agreement nobody wrote down.
         */
        $ratesInPeriod = $this->ratesInPeriod($employee, $from, $to);

        if ($ratesInPeriod->count() > 1) {
            $warnings[] = 'The pay rate changed during this period. The whole period is priced at the '
                .'rate in force at its start; split it into two periods to price each at its own rate.';
        }

        return [
            'employee' => $this->employeeSummary($employee),
            'from' => $from,
            'to' => $to,
            'has_rate' => true,
            'basis' => $rate->basis->value,
            'basis_label' => $rate->basis->label(),
            'rate' => (float) $rate->rate,
            'currency' => $rate->currency,
            'worked_seconds' => (int) array_sum($dailyWorked),
            'worked_label' => $this->hours->hm((int) array_sum($dailyWorked)),
            'ordinary_seconds' => $ordinarySeconds,
            'ordinary_label' => $this->hours->hm($ordinarySeconds),
            'overtime_seconds' => $overtimeSeconds,
            'overtime_label' => $this->hours->hm($overtimeSeconds),
            // Days actually worked — what a daily or monthly rate is multiplied by.
            'days_worked' => count($days),
            'days' => $days,
            'ordinary_amount' => round($ordinaryAmount, 2),
            'overtime_amount' => round($overtimeAmount, 2),
            'total_amount' => round($ordinaryAmount + $overtimeAmount, 2),
            'rates_in_period' => $ratesInPeriod->count(),
            'warnings' => $warnings,
        ];
    }

    /**
     * Price a period across many employees.
     *
     * @param  Collection<int, Employee>  $employees
     * @return array<string, mixed>
     */
    public function summarise(Collection $employees, string $from, string $to): array
    {
        $rows = [];

        foreach ($employees as $employee) {
            $report = $this->forEmployee($employee, $from, $to);

            $rows[] = [
                'employee_id' => $employee->id,
                'name' => $employee->name,
                'employee_code' => $employee->employee_code,
                'has_rate' => $report['has_rate'],
                'basis' => $report['basis'] ?? null,
                'rate' => $report['rate'] ?? null,
                'worked_seconds' => $report['worked_seconds'],
                'worked_label' => $report['worked_label'],
                'overtime_seconds' => $report['overtime_seconds'],
                'overtime_label' => $report['overtime_label'],
                'ordinary_amount' => $report['ordinary_amount'],
                'overtime_amount' => $report['overtime_amount'],
                'total_amount' => $report['total_amount'],
                'warnings' => $report['warnings'],
            ];
        }

        $priced = collect($rows)->where('has_rate', true);

        return [
            'from' => $from,
            'to' => $to,
            'currency' => 'MYR',
            'employees' => $rows,
            'totals' => [
                // Summed from the ROUNDED per-employee figures, not from raw floats: what the
                // business pays is the sum of the amounts on each payslip, and summing anything
                // else produces a total that does not match the parts.
                'ordinary_amount' => round($priced->sum('ordinary_amount'), 2),
                'overtime_amount' => round($priced->sum('overtime_amount'), 2),
                'total_amount' => round($priced->sum('total_amount'), 2),
                'worked_seconds' => collect($rows)->sum('worked_seconds'),
                'unpriced_count' => collect($rows)->where('has_rate', false)->count(),
            ],
        ];
    }

    /**
     * A digest of everything that determined a period's figures.
     *
     * Stored when a period is locked so drift is DETECTABLE later. It covers the inputs that
     * change an amount: the work segments, the rates, the adjustments, and the labour rules
     * that decide the overtime threshold. If a correction lands after locking, the hash no
     * longer matches and the console can say the figure has moved instead of showing a new one
     * as though nothing happened.
     *
     * @param  Collection<int, Employee>  $employees
     */
    public function fingerprint(Collection $employees, string $from, string $to): string
    {
        $parts = [];

        foreach ($employees as $employee) {
            $parts[] = $employee->id;

            foreach ($this->workSegments($employee, $from, $to)->sortBy('id') as $entry) {
                // Timestamps AND duration: a correction can move either, and both change money.
                $parts[] = implode(':', [
                    $entry->id,
                    $entry->type->value,
                    $entry->started_at?->toIso8601String() ?? '-',
                    $entry->ended_at?->toIso8601String() ?? 'open',
                    $entry->durationSeconds(),
                    $entry->status->value,
                ]);
            }

            foreach ($this->ratesInPeriod($employee, $from, $to) as $rate) {
                $parts[] = implode(':', [
                    'rate',
                    $rate->id,
                    $rate->basis->value,
                    $rate->rate,
                    $rate->overtime_rate ?? '-',
                    $rate->effective_from->toDateString(),
                ]);
            }

            foreach ($this->adjustmentsFor($employee, $from, $to) as $adjustment) {
                $parts[] = implode(':', [
                    'adj',
                    $adjustment->id,
                    $adjustment->applies_to,
                    $adjustment->rate,
                    $adjustment->hours ?? '-',
                    $adjustment->applies_to_date->toDateString(),
                ]);
            }
        }

        // Sorting makes the digest independent of query order, so the same data always produces
        // the same hash.
        sort($parts);

        return hash('sha256', implode('|', $parts));
    }

    // ---- Pricing -----------------------------------------------------

    /**
     * The ordinary (non-overtime) portion of a period, in seconds.
     *
     * @param  array<string, int>  $dailyWorked
     * @return array{0: int, 1: int, 2: array<int, array<string, mixed>>}
     */
    private function splitOrdinaryAndOvertime(Employee $employee, array $dailyWorked, string $from): array
    {
        $ordinary = 0;
        $overtime = 0;
        $days = [];

        foreach ($dailyWorked as $date => $seconds) {
            if ($seconds <= 0) {
                continue;
            }

            $outletId = $this->outletForDay($employee, $date);

            // The rule in force on THAT day, so a threshold change does not rewrite an old
            // period's overtime.
            $rule = $outletId !== null
                ? LabourRule::forDate($outletId, $date)
                : LabourRule::defaultsFor(0);

            $threshold = $rule->ot_after_seconds;

            $ordinary += min($seconds, $threshold);
            $overtime += max(0, $seconds - $threshold);

            $days[] = [
                'date' => $date,
                'worked_seconds' => $seconds,
                'worked_label' => $this->hours->hm($seconds),
                'overtime_seconds' => max(0, $seconds - $threshold),
                'overtime_label' => $this->hours->hm(max(0, $seconds - $threshold)),
                'threshold_seconds' => $threshold,
            ];
        }

        return [$ordinary, $overtime, $days];
    }

    /**
     * What the ordinary hours are worth.
     *
     * The basis decides the arithmetic, and each one is a different question:
     *
     *  - HOURLY: rate x hours. The straightforward case.
     *  - DAILY: rate x days actually worked. Not hours divided by some nominal day length,
     *    which would pay a half day as half a day even when the agreement is a full day's pay
     *    for turning up.
     *  - WEEKLY and MONTHLY: the agreed figure for the period, subject to proration when the
     *    employee only worked part of it.
     */
    private function ordinaryAmount(
        Employee $employee,
        CompensationRule $rate,
        int $ordinarySeconds,
        array $days,
        string $from,
        string $to,
    ): float {
        $amount = (float) $rate->rate;

        // An adhoc ordinary-rate override wins over the standing rate for the days it covers.
        $override = $this->ordinaryRateOverride($employee, $from, $to);

        if ($override !== null) {
            return $override * ($ordinarySeconds / 3600);
        }

        return match ($rate->basis) {
            PayBasis::HOURLY => $amount * ($ordinarySeconds / 3600),
            PayBasis::DAILY => $amount * count($days),
            PayBasis::WEEKLY => $amount * $this->weeksCovered($from, $to),
            /*
             * A monthly salary is prorated by the proportion of the month actually covered by
             * the period. A full calendar month returns exactly the monthly rate — which is the
             * property that matters, because a figure that misses by a sen every month is one
             * somebody has to explain.
             */
            PayBasis::MONTHLY => $amount * $this->monthFraction($from, $to),
        };
    }

    /**
     * What the overtime hours are worth.
     *
     * A FLAT per-hour figure, per the business's decided rule — not a multiplier of the
     * ordinary rate. An adhoc adjustment for the period wins over the standing figure, which is
     * what "adjustable adhoc" means in practice.
     */
    private function overtimeAmount(Employee $employee, CompensationRule $rate, int $overtimeSeconds, string $from, string $to): float
    {
        if ($overtimeSeconds <= 0) {
            return 0.0;
        }

        $perHour = $this->overtimeRateOverride($employee, $from, $to)
            ?? ($rate->overtime_rate !== null ? (float) $rate->overtime_rate : null);

        /*
         * No overtime rate anywhere. Returns zero rather than falling back to the ordinary
         * rate: guessing a multiplier would invent an agreement, and overtime paid at the wrong
         * rate is worse than overtime visibly unpaid and queried. The console surfaces it.
         */
        if ($perHour === null) {
            return 0.0;
        }

        return $perHour * ($overtimeSeconds / 3600);
    }

    /**
     * An adhoc ordinary-rate override covering the period, if any.
     *
     * Latest and most specific wins: a day adjustment beats a month one, because the person who
     * wrote it was being more specific about what they wanted.
     */
    private function ordinaryRateOverride(Employee $employee, string $from, string $to): ?float
    {
        $adjustment = $this->adjustmentsFor($employee, $from, $to)
            ->where('applies_to', 'ordinary')
            ->sortByDesc(fn (RateAdjustment $a) => [$this->periodWeight($a->applies_to_period), $a->applies_to_date->toDateString(), $a->id])
            ->first();

        return $adjustment !== null ? (float) $adjustment->rate : null;
    }

    private function overtimeRateOverride(Employee $employee, string $from, string $to): ?float
    {
        $adjustment = $this->adjustmentsFor($employee, $from, $to)
            ->where('applies_to', 'overtime')
            ->sortByDesc(fn (RateAdjustment $a) => [$this->periodWeight($a->applies_to_period), $a->applies_to_date->toDateString(), $a->id])
            ->first();

        return $adjustment !== null ? (float) $adjustment->rate : null;
    }

    /** More specific scopes win. Used only for ordering. */
    private function periodWeight(string $period): int
    {
        return match ($period) {
            'day' => 3,
            'week' => 2,
            'month' => 1,
            default => 0,
        };
    }

    // ---- Period arithmetic -------------------------------------------

    /**
     * How many whole weeks a period covers.
     *
     * A weekly rate is per week, so a period that is not a whole number of weeks pays the
     * fraction it actually covers. Rounding to whole weeks would either underpay a long period
     * or overpay a short one, every time.
     */
    private function weeksCovered(string $from, string $to): float
    {
        $start = CarbonImmutable::parse($from, config('attendance.business_timezone'));
        $end = CarbonImmutable::parse($to, config('attendance.business_timezone'));

        $days = (int) $start->diffInDays($end) + 1;

        return $days / 7;
    }

    /**
     * The fraction of a calendar month a period covers.
     *
     * A full month returns exactly 1.0, so a monthly salary paid over a full month returns
     * exactly the salary. Anything else is prorated by days.
     */
    private function monthFraction(string $from, string $to): float
    {
        $timezone = config('attendance.business_timezone');
        $start = CarbonImmutable::parse($from, $timezone);
        $end = CarbonImmutable::parse($to, $timezone);

        // A period spanning more than one month is not a month, and prorating it as one would
        // pay a two-month period as a single salary. Reported as the sum of the months it
        // touches, so the figure stays explicable.
        if ($start->format('Y-m') !== $end->format('Y-m')) {
            $months = 0.0;
            $cursor = $start->startOfMonth();

            while ($cursor->lessThanOrEqualTo($end)) {
                $monthStart = $cursor->startOfMonth();
                $monthEnd = $cursor->endOfMonth();

                $overlapStart = $monthStart->greaterThan($start) ? $monthStart : $start;
                $overlapEnd = $monthEnd->lessThan($end) ? $monthEnd : $end;

                if ($overlapEnd->greaterThanOrEqualTo($overlapStart)) {
                    $covered = (int) $overlapStart->diffInDays($overlapEnd) + 1;
                    $months += $covered / (int) $monthStart->daysInMonth;
                }

                $cursor = $cursor->addMonth();
            }

            return $months;
        }

        $daysInMonth = (int) $start->daysInMonth;
        $covered = (int) $start->diffInDays($end) + 1;

        return $covered / $daysInMonth;
    }

    // ---- Data --------------------------------------------------------

    /**
     * @return Collection<int, TimeEntry>
     */
    private function workSegments(Employee $employee, string $from, string $to): Collection
    {
        $timezone = config('attendance.business_timezone');

        // A UTC instant pair derived from the LOCAL range. Comparing a local boundary against
        // the UTC column would drop the first eight hours of every day.
        $start = CarbonImmutable::parse($from.' 00:00:00', $timezone)->utc();
        $end = CarbonImmutable::parse($to.' 00:00:00', $timezone)->addDay()->utc();

        return TimeEntry::query()
            ->where('employee_id', $employee->id)
            ->where('type', TimeEntryType::WORK->value)
            ->where('started_at', '>=', $start)
            ->where('started_at', '<', $end)
            ->orderBy('started_at')
            ->get();
    }

    /**
     * Worked seconds per local business date.
     *
     * @param  Collection<int, TimeEntry>  $entries
     * @return array<string, int>
     */
    private function dailyWorked(Collection $entries): array
    {
        $timezone = config('attendance.business_timezone');
        $days = [];

        foreach ($entries as $entry) {
            /*
             * Keyed on the stored `business_date`, not on a timezone conversion of `started_at`.
             * The business date is the day the hours belong to — a shift crossing midnight
             * belongs to the day it STARTED — and re-deriving it here would be a second,
             * disagreeing definition of the same rule.
             */
            $date = $entry->business_date->toDateString();

            $days[$date] = ($days[$date] ?? 0) + $entry->durationSeconds();
        }

        ksort($days);

        return $days;
    }

    /** The outlet a day's work happened at, for the overtime rule lookup. */
    private function outletForDay(Employee $employee, string $date): ?int
    {
        return TimeEntry::query()
            ->where('employee_id', $employee->id)
            ->whereDate('business_date', $date)
            ->orderBy('started_at')
            ->value('outlet_id');
    }

    /**
     * Every rate row that applies anywhere in the period.
     *
     * @return Collection<int, CompensationRule>
     */
    private function ratesInPeriod(Employee $employee, string $from, string $to): Collection
    {
        return CompensationRule::query()
            ->where('employee_id', $employee->id)
            ->whereDate('effective_from', '<=', $to)
            ->where(fn ($q) => $q
                ->whereNull('effective_to')
                ->orWhereDate('effective_to', '>=', $from))
            ->orderBy('effective_from')
            ->get();
    }

    /**
     * Adhoc adjustments that touch the period, ignoring any still awaiting approval.
     *
     * An unapproved adjustment must not move money — that is the whole point of having an
     * approval step at all.
     *
     * QUALIFIES BY OVERLAP, not by containment of the period's first day. An adjustment for a
     * single busy Saturday is meant to price that day's overtime whether the pay period starts
     * before it or on it, and requiring the period start to fall inside the adjustment's span
     * would silently ignore it.
     *
     * Where several overlap, the most specific and most recent wins (see `periodWeight`), and
     * the overridden figure applies to the whole period. That is deliberately simple: a
     * portion-by-portion allocation would be a different feature, and guessing at one on a
     * payslip is worse than a rule a manager can state in a sentence.
     *
     * @return Collection<int, RateAdjustment>
     */
    private function adjustmentsFor(Employee $employee, string $from, string $to): Collection
    {
        $requiresApproval = Setting::bool(Setting::REQUIRE_RATE_APPROVAL, false);

        return RateAdjustment::query()
            ->where('employee_id', $employee->id)
            // Cheapest useful bound: an adjustment cannot reach a period that starts after its
            // own span could possibly end. The precise per-type check is below in PHP, where
            // the scope rules live on the model rather than being duplicated in SQL.
            ->whereDate('applies_to_date', '<=', $to)
            ->when($requiresApproval, fn ($q) => $q->approved())
            ->orderBy('applies_to_date')
            ->get()
            ->filter(fn (RateAdjustment $adjustment) => $adjustment->overlapsRange($from, $to));
    }

    /**
     * @return array<string, mixed>
     */
    private function employeeSummary(Employee $employee): array
    {
        return [
            'id' => $employee->id,
            'name' => $employee->name,
            'employee_code' => $employee->employee_code,
        ];
    }
}
