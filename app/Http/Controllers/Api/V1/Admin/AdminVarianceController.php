<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Outlet;
use App\Models\Shift;
use App\Services\VarianceService;
use App\Services\WorkedHoursService;
use App\Support\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Planned versus actual.
 *
 * The report that makes the roster worth keeping: without it, a roster is a document nobody
 * checks, and unplanned work stays invisible.
 *
 * Read only. Nothing here writes a shift or a time entry — a variance report that could alter
 * either would be reporting on figures it had just changed.
 */
class AdminVarianceController extends Controller
{
    /** A hard ceiling on one request, so a range cannot be turned into a full scan. */
    private const MAX_RANGE_DAYS = 62;

    public function __construct(
        private readonly VarianceService $variance,
        private readonly WorkedHoursService $hours,
    ) {}

    /**
     * Per-employee planned and actual, with the variance.
     */
    public function summary(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Shift::class);

        [$from, $to] = $this->range($request);

        $employees = Employee::query()
            ->with('outlets')
            ->visibleTo($request->user())
            ->when($request->filled('outlet_id'), fn ($q) => $q->whereHas(
                'outlets',
                fn ($o) => $o->where('outlets.id', $request->integer('outlet_id'))
            ))
            ->when($request->boolean('active_only', true), fn ($q) => $q->where('is_active', true))
            ->orderBy('name')
            ->get();

        $outlet = $request->filled('outlet_id')
            ? Outlet::find($request->integer('outlet_id'))
            : null;

        $report = $this->variance->summarise($employees, $from, $to, $outlet);

        return ApiResponse::success([
            'from' => $from,
            'to' => $to,
            'employees' => $report['employees'],
            'totals' => [
                ...$report['totals'],
                'planned_label' => $this->hm($report['totals']['planned_seconds']),
                'worked_label' => $this->hm($report['totals']['worked_seconds']),
                'variance_label' => $this->hm($report['totals']['variance_seconds']),
                'adhoc_label' => $this->hm($report['totals']['adhoc_seconds']),
            ],
        ]);
    }

    /**
     * One employee, day by day — the detail behind a number in the summary.
     */
    public function employee(Request $request, Employee $employee): JsonResponse
    {
        // 404 rather than 403, matching the employee controller: a manager probing ids must not
        // learn which employees exist at another outlet.
        if (! $request->user()->can('view', $employee)) {
            return ApiResponse::notFound('Employee not found.');
        }

        [$from, $to] = $this->range($request);

        return ApiResponse::success([
            'variance' => $this->variance->forEmployee($employee, $from, $to),
        ]);
    }

    /**
     * CSV of the summary.
     *
     * Streamed, and with decimal hours rather than "8h 30m": a CSV is for arithmetic, and a
     * spreadsheet cannot sum a string. Same rules as the timesheet export, deliberately, so
     * the two files can be joined.
     */
    public function export(Request $request): StreamedResponse
    {
        $this->authorize('viewAny', Shift::class);

        [$from, $to] = $this->range($request);

        $employees = Employee::query()
            ->with('outlets')
            ->visibleTo($request->user())
            ->when($request->filled('outlet_id'), fn ($q) => $q->whereHas(
                'outlets',
                fn ($o) => $o->where('outlets.id', $request->integer('outlet_id'))
            ))
            ->when($request->boolean('active_only', true), fn ($q) => $q->where('is_active', true))
            ->orderBy('name')
            ->cursor();

        $outlet = $request->filled('outlet_id')
            ? Outlet::find($request->integer('outlet_id'))
            : null;

        $filename = "variance-{$from}-to-{$to}.csv";

        return response()->streamDownload(function () use ($employees, $from, $to, $outlet) {
            $out = fopen('php://output', 'w');

            // BOM so Excel on Windows reads UTF-8, or Malay names arrive as mojibake.
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, [
                'Employee code', 'Employee', 'Planned hours', 'Worked hours',
                'Variance hours', 'Unplanned hours', 'No-shows', 'Short/over', 'Unplanned shifts',
            ]);

            /*
             * Cursored one employee at a time and written immediately. Building the whole
             * report in memory would be fine for three staff and fatal for thirty across a
             * quarter on shared hosting — and the export is the endpoint most likely to be run
             * on the last day of the month.
             */
            foreach ($employees as $employee) {
                $report = $this->variance->forEmployee($employee, $from, $to, $outlet);

                fputcsv($out, [
                    $employee->employee_code,
                    $employee->name,
                    $this->decimal($report['planned_seconds']),
                    $this->decimal($report['worked_seconds']),
                    $this->decimal($report['variance_seconds']),
                    $this->decimal($report['adhoc_seconds']),
                    $report['no_show_count'],
                    $report['partial_count'],
                    $report['unplanned_count'],
                ]);
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    // ---- Internals ---------------------------------------------------

    /**
     * The reporting window, in the BUSINESS timezone.
     *
     * Anchoring on `now()` in the app timezone (UTC) would end the window yesterday for the
     * eight hours after 16:00 UTC, silently omitting the shift in progress. Phase 3 hit exactly
     * this, which is why `config('attendance.business_timezone')` exists.
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

        $from = isset($validated['from'])
            ? CarbonImmutable::parse($validated['from'], $timezone)->startOfDay()
            : $to->subDays(config('attendance.default_window_days') - 1);

        // An inverted range is corrected rather than refused: a manager who typed the dates the
        // wrong way round wants their report, not a lecture.
        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        if ($from->diffInDays($to) > self::MAX_RANGE_DAYS) {
            $from = $to->subDays(self::MAX_RANGE_DAYS);
        }

        return [$from->toDateString(), $to->toDateString()];
    }

    private function hm(int $seconds): string
    {
        return $this->hours->hm($seconds);
    }

    private function decimal(int $seconds): string
    {
        return $this->hours->durationAsDecimalHours($seconds);
    }
}
