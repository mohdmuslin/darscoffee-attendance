<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Outlet;
use App\Models\TimeEntry;
use App\Services\WorkedHoursService;
use App\Support\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The timesheet — the reason this system exists.
 *
 * Everything here is READ ONLY. Time is changed through a correction, never by an
 * endpoint that writes an entry directly, because a direct write would leave no record
 * of who changed what or why.
 *
 * Every query is scoped by outlet. That scoping is the security boundary: a manager at
 * Sg Ramal listing Sg Ramal staff must not be able to reach Sedap Santai's hours by
 * editing a query string.
 */
class AdminTimesheetController extends Controller
{
    public function __construct(private readonly WorkedHoursService $hours) {}

    /**
     * The business's "today".
     *
     * NOT `now()` in the application timezone. The app runs in UTC while the business runs
     * on Kuala Lumpur time, so after 16:00 UTC the two disagree about the date — and a
     * default window anchored on the UTC date then omits the shift currently in progress.
     * A timesheet that hides today's work for a third of every day is worse than no default
     * at all, and it would look like data loss rather than a boundary error.
     */
    private function businessToday(): CarbonImmutable
    {
        return CarbonImmutable::now(config('attendance.business_timezone'));
    }

    /**
     * One employee, day by day, over a period.
     */
    public function employee(Request $request, Employee $employee): JsonResponse
    {
        // 404 rather than 403 for an out-of-scope employee, so probing cannot reveal
        // which employees exist — the same choice the employee controller makes.
        if (! $request->user()->can('view', $employee)) {
            return ApiResponse::notFound('Employee not found.');
        }

        [$from, $to] = $this->range($request);

        $period = $this->hours->forPeriod($employee, $from, $to);

        /*
         * Attached here rather than in the service so the service stays about time and
         * this stays about what the console needs. Reviewers are what make an amended
         * day explainable: the total alone cannot say who changed it.
         */
        $period['corrections'] = $employee->corrections()
            ->with(['requester', 'reviewer'])
            ->whereDate('created_at', '>=', $from)
            ->whereDate('created_at', '<=', $to)
            ->orderByDesc('id')
            ->get()
            ->map(fn ($correction) => [
                'id' => $correction->id,
                'status' => $correction->status->value,
                'reason' => $correction->reason,
                'requested_by' => $correction->requester?->name,
                'reviewed_by' => $correction->reviewer?->name,
                'created_at' => $correction->created_at?->toIso8601String(),
            ])
            ->all();

        return ApiResponse::success(['timesheet' => $period]);
    }

    /**
     * Everyone the user can see, totalled over a period.
     *
     * This is the screen a manager opens on a Monday to work out who to pay.
     */
    public function summary(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Employee::class);

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

        $rows = $this->hours->summarise($employees, $from, $to);

        return ApiResponse::success([
            'from' => $from,
            'to' => $to,
            'employees' => $rows,
            'totals' => [
                'worked_seconds' => collect($rows)->sum('worked_seconds'),
                'worked_label' => $this->hours->hm((int) collect($rows)->sum('worked_seconds')),
                'overtime_seconds' => collect($rows)->sum('overtime_seconds'),
                'overtime_label' => $this->hours->hm((int) collect($rows)->sum('overtime_seconds')),
                'incomplete_days' => collect($rows)->sum('incomplete_days'),
            ],
        ]);
    }

    /**
     * Every segment in a period, as flat rows.
     *
     * The summary answers "how many hours"; this answers "which punches". A manager
     * chasing a discrepancy needs the second, and re-deriving it from the first is
     * impossible.
     */
    public function entries(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Employee::class);

        [$from, $to] = $this->range($request);

        $entries = $this->entriesQuery($request, $from, $to)
            ->with(['employee', 'outlet'])
            ->orderByDesc('started_at')
            ->limit(config('attendance.entry_page_limit'))
            ->get();

        return ApiResponse::success([
            'from' => $from,
            'to' => $to,
            'entries' => $entries->map(fn (TimeEntry $entry) => [
                'id' => $entry->id,
                'employee' => $entry->employee?->name,
                'employee_code' => $entry->employee?->employee_code,
                'outlet' => $entry->outlet?->name,
                'outlet_id' => $entry->outlet_id,
                'type' => $entry->type->value,
                'type_label' => $entry->type->label(),
                'business_date' => $entry->business_date?->toDateString(),
                'started_at' => $entry->started_at?->toIso8601String(),
                'ended_at' => $entry->ended_at?->toIso8601String(),
                'duration_seconds' => $entry->durationSeconds(),
                'duration_label' => $this->hours->hm($entry->durationSeconds()),
                'status' => $entry->status->value,
                'status_label' => $entry->status->label(),
                'is_offline_sync' => (bool) $entry->is_offline_sync,
                'note' => $entry->note,
            ]),
        ]);
    }

    /**
     * CSV export.
     *
     * STREAMED rather than built in memory: a month across thirty staff is a few
     * thousand rows, and handing that to a shared-hosting PHP process as one string is
     * how an export becomes an outage. `cphp`-style hosts have tight memory limits and
     * this is the endpoint most likely to be run on the last day of the month.
     */
    public function export(Request $request): StreamedResponse
    {
        $this->authorize('viewAny', Employee::class);

        [$from, $to] = $this->range($request);

        $entries = $this->entriesQuery($request, $from, $to)
            ->with(['employee', 'outlet'])
            ->orderBy('started_at')
            ->cursor();

        $filename = 'timesheet-'.$from.'-to-'.$to.'.csv';

        return response()->streamDownload(function () use ($entries) {
            $out = fopen('php://output', 'w');

            /*
             * A BOM, because Excel on Windows reads a plain UTF-8 CSV as Latin-1 and
             * turns every accented name into mojibake. Staff names in this business are
             * ordinary Malay names, so this is not a hypothetical.
             */
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, [
                'Date', 'Employee code', 'Employee', 'Outlet', 'Type',
                'Started', 'Ended', 'Hours', 'Status', 'Note',
            ]);

            foreach ($entries as $entry) {
                fputcsv($out, [
                    $entry->business_date?->toDateString(),
                    $entry->employee?->employee_code,
                    $entry->employee?->name,
                    $entry->outlet?->name,
                    $entry->type->label(),
                    $entry->started_at?->setTimezone($entry->outlet->timezone)->format('Y-m-d H:i'),
                    $entry->ended_at?->setTimezone($entry->outlet->timezone)->format('Y-m-d H:i') ?? '',
                    $this->hours->durationAsDecimalHours($entry->durationSeconds()),
                    $entry->status->label(),
                    $entry->note,
                ]);
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    // ---- Internals ---------------------------------------------------

    /**
     * @return Builder<TimeEntry>
     */
    private function entriesQuery(Request $request, string $from, string $to)
    {
        return TimeEntry::query()
            // Scoped by the outlets the user may see. Applied first so nothing below
            // can widen it again.
            ->whereIn('outlet_id', $this->visibleOutletIds($request))
            ->whereDate('business_date', '>=', $from)
            ->whereDate('business_date', '<=', $to)
            ->when($request->filled('employee_id'), fn ($q) => $q->where('employee_id', $request->integer('employee_id')))
            ->when($request->filled('outlet_id'), fn ($q) => $q->where('outlet_id', $request->integer('outlet_id')))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')->toString()));
    }

    /**
     * The outlet ids this user may see.
     *
     * @return array<int>
     */
    private function visibleOutletIds(Request $request): array
    {
        $ids = $request->user()->visibleOutletIds();

        // null means an owner: every outlet. Resolved to a concrete list because the
        // query needs a whereIn, and an empty list must mean "nothing", not "everything".
        return $ids ?? Outlet::query()->pluck('id')->all();
    }

    /**
     * The reporting window, clamped.
     *
     * @return array{0: string, 1: string}
     */
    private function range(Request $request): array
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $to = isset($validated['to'])
            ? CarbonImmutable::parse($validated['to'], config('attendance.business_timezone'))->startOfDay()
            : $this->businessToday()->startOfDay();

        $from = isset($validated['from'])
            ? CarbonImmutable::parse($validated['from'], config('attendance.business_timezone'))->startOfDay()
            : $to->subDays(config('attendance.default_window_days') - 1);

        /*
         * An inverted range is corrected rather than refused: a manager who typed the
         * dates the wrong way round wants their timesheet, not a lecture.
         */
        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        // Clamped, so one request cannot be turned into a scan of every segment ever
        // recorded — which is what an export endpoint on shared hosting has to survive.
        $max = config('attendance.max_window_days');

        if ($from->diffInDays($to) > $max) {
            $from = $to->subDays($max);
        }

        return [$from->toDateString(), $to->toDateString()];
    }
}
