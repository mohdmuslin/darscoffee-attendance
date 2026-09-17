<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\PayPeriod;
use App\Models\Shift;
use App\Services\PayPeriodService;
use App\Services\PayService;
use App\Services\WorkedHoursService;
use App\Support\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * What a period is worth, and its lock.
 *
 * The lock is what turns a computed figure into a committed one. It is a DETECTABLE freeze
 * rather than a hard one: corrections stay possible after month end, because a genuine missed
 * clock-out does not stop being genuine, and the drift is reported instead of being prevented.
 *
 * This is where success criterion 6 lives — reproduce last month's figure exactly.
 */
class AdminPayController extends Controller
{
    /** A hard ceiling on one request, so a range cannot become a full scan. */
    private const MAX_RANGE_DAYS = 366;

    public function __construct(
        private readonly PayService $pay,
        private readonly PayPeriodService $periods,
        private readonly WorkedHoursService $hours,
    ) {}

    /**
     * Pay for a range, per employee.
     */
    public function summary(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Shift::class);

        [$from, $to] = $this->range($request);

        $report = $this->pay->summarise($this->employees($request), $from, $to);

        // The period covering the range, so the screen can say whether the figure is committed,
        // open, or has drifted since it was.
        $period = $this->periods->covering($from);

        return ApiResponse::success([
            ...$report,
            'period' => $period === null ? null : [
                'id' => $period->id,
                'name' => $period->name,
                'is_locked' => $period->isLocked(),
                'locked_at' => $period->locked_at?->toIso8601String(),
                'locked_by' => $period->locker?->name,
                // A range that only partly overlaps its period is flagged, because the figure
                // would then be a slice of a committed one and comparing the two would mislead.
                'covers_range' => $period->starts_on->toDateString() <= $from
                    && $period->ends_on->toDateString() >= $to,
            ],
        ]);
    }

    /**
     * One employee's pay, day by day.
     */
    public function employee(Request $request, Employee $employee): JsonResponse
    {
        if (! $request->user()->can('view', $employee)) {
            return ApiResponse::notFound('Employee not found.');
        }

        [$from, $to] = $this->range($request);

        return ApiResponse::success([
            'pay' => $this->pay->forEmployee($employee, $from, $to),
        ]);
    }

    /**
     * CSV of the pay summary.
     *
     * Amounts as plain decimals, and the hours beside them, so the file can be handed to
     * whoever does the actual payroll. Streamed, because this is the endpoint most likely to run
     * on the last day of the month.
     */
    public function export(Request $request): StreamedResponse
    {
        $this->authorize('viewAny', Shift::class);

        [$from, $to] = $this->range($request);

        $employees = $this->employees($request);

        $filename = "pay-{$from}-to-{$to}.csv";

        return response()->streamDownload(function () use ($employees, $from, $to) {
            $out = fopen('php://output', 'w');

            // BOM, or Excel on Windows renders Malay names as mojibake.
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, [
                'Employee code', 'Employee', 'Basis', 'Rate',
                'Worked hours', 'Overtime hours', 'Ordinary amount', 'Overtime amount', 'Total',
                'Currency', 'Note',
            ]);

            foreach ($employees as $employee) {
                $report = $this->pay->forEmployee($employee, $from, $to);

                fputcsv($out, [
                    $employee->employee_code,
                    $employee->name,
                    $report['basis'] ?? '',
                    /*
                     * Money is formatted explicitly to two decimals.
                     *
                     * An unformatted float reaches a CSV as `10` rather than `10.00`, and this
                     * file is read by whoever does the actual payroll — a column of mixed
                     * `10`, `10.5`, `10.55` invites misreading, and some spreadsheet imports
                     * infer a wrong column type from it.
                     */
                    $report['has_rate'] ? number_format((float) $report['rate'], 2, '.', '') : '',
                    $this->hours->durationAsDecimalHours($report['worked_seconds']),
                    $this->hours->durationAsDecimalHours($report['overtime_seconds']),
                    $report['ordinary_amount'] !== null
                        ? number_format((float) $report['ordinary_amount'], 2, '.', '')
                        : '',
                    $report['overtime_amount'] !== null
                        ? number_format((float) $report['overtime_amount'], 2, '.', '')
                        : '',
                    // Blank rather than 0.00 for an unpriced employee: a zero in a payroll
                    // spreadsheet is a figure somebody will pay.
                    $report['total_amount'] !== null
                        ? number_format((float) $report['total_amount'], 2, '.', '')
                        : '',
                    $report['currency'],
                    implode(' ', $report['warnings']),
                ]);
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    // ---- Pay periods -------------------------------------------------

    public function periods(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Shift::class);

        $periods = PayPeriod::query()
            ->with('locker')
            ->orderByDesc('starts_on')
            ->limit(60)
            ->get()
            ->map(fn (PayPeriod $period) => $this->periodPayload($period));

        return ApiResponse::success([
            'periods' => $periods,
            'suggestion' => $this->periods->suggestForMonth(
                CarbonImmutable::now(config('attendance.business_timezone'))->format('Y-m')
            ),
        ]);
    }

    public function storePeriod(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Shift::class);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $period = $this->periods->create(
                $validated['name'],
                $validated['starts_on'],
                $validated['ends_on'],
                $request->user(),
                $validated['note'] ?? null,
            );
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), null, 422, 'INVALID_PERIOD');
        }

        return ApiResponse::created(['period' => $this->periodPayload($period)], 'Pay period created.');
    }

    /**
     * Lock a period, committing its figures.
     */
    public function lockPeriod(Request $request, PayPeriod $period): JsonResponse
    {
        if (! $request->user()->isOwner()) {
            // Owner-only. Locking is the act that makes a figure the record of what was paid, and
            // a manager committing their own outlet's payroll is not a power to hand out.
            return ApiResponse::forbidden('Only the owner can lock a pay period.');
        }

        $locked = $this->periods->lock($period, $this->employeesFor($request, null), $request->user());

        return ApiResponse::success(
            ['period' => $this->periodPayload($locked)],
            'Pay period locked. The figures as they stand are now the record.',
        );
    }

    /**
     * Recompute a period and report whether it still matches what was approved.
     */
    public function reconcilePeriod(Request $request, PayPeriod $period): JsonResponse
    {
        $this->authorize('viewAny', Shift::class);

        $reconciled = $this->periods->reconcile($period, $this->employeesFor($request, null));

        return ApiResponse::success([
            'period' => $this->periodPayload($period),
            'state' => $reconciled['state'],
            'current' => $reconciled['current'],
            'approved' => $reconciled['approved'],
            'difference' => $reconciled['difference'],
            'approved_at' => $reconciled['approved_at'] ?? null,
            'approved_by' => $reconciled['approved_by'] ?? null,
        ]);
    }

    // ---- Internals ---------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function periodPayload(PayPeriod $period): array
    {
        return [
            'id' => $period->id,
            'name' => $period->name,
            'starts_on' => $period->starts_on->toDateString(),
            'ends_on' => $period->ends_on->toDateString(),
            'day_count' => $period->dayCount(),
            'is_locked' => $period->isLocked(),
            'locked_at' => $period->locked_at?->toIso8601String(),
            'locked_by' => $period->locker?->name,
            'note' => $period->note,
            // The committed total, straight from the snapshot. Reproducing the figure does not
            // depend on re-running the calculation against data that may have changed.
            'committed_total' => $period->snapshot['totals']['total_amount'] ?? null,
            'committed_hours' => $period->snapshot['totals']['worked_seconds'] ?? null,
        ];
    }

    /**
     * The employees a report should cover, scoped to what the user may see.
     *
     * @return Collection<int, Employee>
     */
    private function employees(Request $request): Collection
    {
        return $this->employeesFor($request, $request->input('outlet_id'));
    }

    /**
     * @return Collection<int, Employee>
     */
    private function employeesFor(Request $request, mixed $outletId): Collection
    {
        return Employee::query()
            ->with('outlets')
            ->visibleTo($request->user())
            ->when($outletId, fn ($q, $id) => $q->whereHas(
                'outlets',
                fn ($o) => $o->where('outlets.id', $id)
            ))
            ->when($request->boolean('active_only', true), fn ($q) => $q->where('is_active', true))
            ->orderBy('name')
            ->get();
    }

    /**
     * The reporting window, in the business timezone.
     *
     * Anchoring on `now()` in the app timezone (UTC) would end the window yesterday for the
     * eight hours after 16:00 UTC. Phase 3 hit exactly this.
     *
     * @return array{0: string, 1: string}
     */
    private function range(Request $request): array
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'outlet_id' => ['nullable', 'integer'],
            'active_only' => ['nullable', 'boolean'],
        ]);

        $timezone = config('attendance.business_timezone');

        $to = isset($validated['to'])
            ? CarbonImmutable::parse($validated['to'], $timezone)->startOfDay()
            : CarbonImmutable::now($timezone)->startOfDay();

        // Defaults to the current calendar month, which is how this business thinks about pay.
        $from = isset($validated['from'])
            ? CarbonImmutable::parse($validated['from'], $timezone)->startOfDay()
            : $to->startOfMonth();

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        if ($from->diffInDays($to) > self::MAX_RANGE_DAYS) {
            $from = $to->subDays(self::MAX_RANGE_DAYS);
        }

        return [$from->toDateString(), $to->toDateString()];
    }
}
