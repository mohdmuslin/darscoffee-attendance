<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CopyShiftsRequest;
use App\Http\Requests\Admin\StoreShiftRequest;
use App\Http\Resources\ShiftResource;
use App\Models\Employee;
use App\Models\Outlet;
use App\Models\Shift;
use App\Services\ShiftService;
use App\Support\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * The roster.
 *
 * Managers build their own outlet's shifts — that is the point of the feature. What keeps
 * that safe is not a permission but the record: every shift stores who created it, and
 * cancelling keeps the row rather than deleting it, so a shift that was rostered and then
 * called off still explains a no-show.
 *
 * Nothing here writes a time entry. The plan and the actual are separate, and a roster
 * change must never alter recorded hours.
 */
class AdminShiftController extends Controller
{
    /** A hard ceiling on one roster request, so a range cannot be turned into a full scan. */
    private const MAX_RANGE_DAYS = 62;

    public function __construct(private readonly ShiftService $shifts) {}

    /**
     * The roster for a date range, grouped by local day.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Shift::class);

        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'outlet_id' => ['nullable', 'integer'],
            'employee_id' => ['nullable', 'integer'],
            'include_cancelled' => ['nullable', 'boolean'],
        ]);

        [$from, $to, $timezone] = $this->range($request, $validated);

        $shifts = Shift::query()
            ->with(['employee', 'outlet', 'creator'])
            // The scope, applied first so nothing below can widen it. A manager asking for
            // another outlet's id gets an empty list, not that outlet's roster.
            ->visibleTo($request->user())
            ->when(
                $request->boolean('include_cancelled'),
                fn ($q) => $q,
                fn ($q) => $q->active()
            )
            ->when($validated['outlet_id'] ?? null, fn ($q, $id) => $q->where('outlet_id', $id))
            ->when($validated['employee_id'] ?? null, fn ($q, $id) => $q->where('employee_id', $id))
            /*
             * Bounded on the UTC instant pair, computed from local day boundaries.
             *
             * `->utc()` is essential and not cosmetic. `starts_at` is stored UTC, and a plain
             * `where()` binds a Carbon using ITS OWN timezone — so a Kuala Lumpur midnight
             * boundary would be compared as "2026-09-22 00:00:00" against a column holding
             * "2026-09-21 16:30:00", and every shift in the first eight hours of the local
             * day would silently fall outside the range.
             */
            ->where('starts_at', '>=', $from->utc())
            ->where('starts_at', '<', $to->utc())
            ->orderBy('starts_at')
            ->limit(2000)
            ->get();

        $byDay = $this->shifts->byDay(
            $shifts,
            $timezone,
            // Every local date in the range, so days with nothing on them still appear. A
            // missing row reads as "no data"; an empty day reads as "nobody is on", which is
            // the thing a manager actually needs to see.
            $this->shifts->datesBetween($from, $to),
        );

        return ApiResponse::success([
            'from' => $from->toDateString(),
            'to' => $to->subDay()->toDateString(),
            'timezone' => $timezone,
            'days' => collect($byDay)->map(fn (array $day) => [
                'date' => $day['date'],
                'shifts' => ShiftResource::collection($day['shifts'])->resolve(),
                'planned_seconds' => $day['planned_seconds'],
                'has_cover' => $day['has_cover'],
            ])->values(),
            'totals' => $this->shifts->plannedTotals($shifts),
        ]);
    }

    /**
     * This week and next, for the manager's landing view.
     *
     * A convenience endpoint rather than something the client assembles, so "this week"
     * is defined once, in the business timezone, instead of on every screen that wants it.
     */
    public function current(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Shift::class);

        $today = CarbonImmutable::now(config('attendance.business_timezone'));

        // Monday-based, which is how a Malaysian roster week is read.
        $weekStart = $today->startOfWeek(CarbonImmutable::MONDAY);

        $request->merge([
            'from' => $weekStart->toDateString(),
            'to' => $weekStart->addDays(14)->toDateString(),
        ]);

        return $this->index($request);
    }

    public function store(StoreShiftRequest $request): JsonResponse
    {
        $outlet = Outlet::find($request->integer('outlet_id'));

        if ($outlet === null || ! $request->user()->can('create', [Shift::class, $outlet])) {
            // 404 rather than 403: a manager probing outlet ids must not learn which exist.
            return ApiResponse::notFound('Outlet not found.');
        }

        $employee = Employee::find($request->integer('employee_id'));

        if ($employee === null || ! $request->user()->can('view', $employee)) {
            return ApiResponse::notFound('Employee not found.');
        }

        /*
         * Both checks are needed. Rostering someone at an outlet the manager cannot see
         * would place that person somewhere the manager then cannot manage — the employee
         * would appear to vanish from their roster.
         */
        if (! $employee->isVisibleTo($request->user())) {
            return ApiResponse::notFound('Employee not found.');
        }

        try {
            $shift = $this->shifts->create($outlet, $employee, $request->validated(), $request->user());
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), null, 422, 'INVALID_SHIFT');
        }

        return ApiResponse::created(
            ['shift' => new ShiftResource($shift->load(['employee', 'outlet', 'creator']))],
            'Shift added to the roster.',
        );
    }

    public function show(Request $request, Shift $shift): JsonResponse
    {
        $this->authorizeOnRecord('view', $shift);

        return ApiResponse::success([
            'shift' => new ShiftResource($shift->load(['employee', 'outlet', 'creator'])),
        ]);
    }

    /**
     * Amend a shift.
     *
     * Moving the times cancels the original and writes a replacement, so a roster that
     * changed can still be explained. A note or position fix is edited in place.
     */
    public function update(StoreShiftRequest $request, Shift $shift): JsonResponse
    {
        $this->authorizeOnRecord('update', $shift);

        if ($shift->isCancelled()) {
            return ApiResponse::error(
                'That shift was cancelled. Add a new one instead.',
                null,
                422,
                'SHIFT_CANCELLED',
            );
        }

        // A shift may only be moved to an outlet the user can see, since the times are
        // interpreted in that outlet's timezone.
        if ($request->filled('outlet_id') && (int) $request->integer('outlet_id') !== $shift->outlet_id) {
            $target = Outlet::find($request->integer('outlet_id'));

            if ($target === null || ! $request->user()->can('create', [Shift::class, $target])) {
                return ApiResponse::notFound('Outlet not found.');
            }
        }

        try {
            $updated = $this->shifts->update($shift, $request->validated(), $request->user());
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), null, 422, 'INVALID_SHIFT');
        }

        return ApiResponse::success(
            ['shift' => new ShiftResource($updated->load(['employee', 'outlet', 'creator']))],
            'Shift updated.',
        );
    }

    /**
     * Cancel a shift.
     *
     * A timestamp, not a delete: a shift that was rostered and then called off is
     * information — it explains a no-show, and deleting it would erase the fact that
     * someone was expected.
     */
    public function cancel(Request $request, Shift $shift): JsonResponse
    {
        $this->authorizeOnRecord('cancel', $shift);

        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:255']])['reason'] ?? null;

        if ($shift->isCancelled()) {
            // Idempotent: a double-click, or a stale roster left open, must not error.
            return ApiResponse::success(
                ['shift' => new ShiftResource($shift->load(['employee', 'outlet', 'creator']))],
                'That shift was already cancelled.',
            );
        }

        // The reason is appended rather than replacing the note, so a manager's own note
        // about the shift is not lost by cancelling it.
        if ($reason !== null) {
            $shift->forceFill([
                'note' => trim(($shift->note ?? '').' — cancelled: '.$reason),
            ])->save();
        }

        $shift->cancel();

        return ApiResponse::success(
            ['shift' => new ShiftResource($shift->fresh(['employee', 'outlet', 'creator']))],
            'Shift cancelled.',
        );
    }

    /**
     * Copy a range of shifts forward, or onto another employee.
     *
     * The transaction lives in the service: a partial copy — half a week written, then an
     * error — is worse than none, because the manager cannot tell which half landed.
     */
    public function copy(CopyShiftsRequest $request): JsonResponse
    {
        $this->authorize('viewAny', Shift::class);

        [$from, $to, $timezone] = $this->range($request, [
            'from' => $request->validated('source_from'),
            'to' => $request->validated('source_to'),
        ]);

        $shifts = Shift::query()
            ->with(['employee', 'outlet'])
            ->visibleTo($request->user())
            ->active()
            ->when($request->filled('outlet_id'), fn ($q) => $q->where('outlet_id', $request->integer('outlet_id')))
            // ->utc() for the same reason as the index: the column is UTC and a local
            // boundary would be bound as a local string.
            ->where('starts_at', '>=', $from->utc())
            ->where('starts_at', '<', $to->utc())
            ->orderBy('starts_at')
            ->get();

        if ($shifts->isEmpty()) {
            return ApiResponse::error(
                'Nothing to copy in that range.',
                null,
                422,
                'NOTHING_TO_COPY',
            );
        }

        // Copying onto another employee needs visibility of that employee, or a manager
        // could write shifts for someone at an outlet they cannot see.
        if ($request->filled('employee_id')) {
            $target = Employee::find($request->integer('employee_id'));

            if ($target === null || ! $target->isVisibleTo($request->user())) {
                return ApiResponse::notFound('Employee not found.');
            }
        }

        $result = $this->shifts->copy($shifts, [
            ...$request->validated(),
            'outlet_id' => $request->input('outlet_id') ?? $shifts->first()->outlet_id,
        ], $request->user());

        $message = $result['created'].' shift(s) copied.';

        if ($result['skipped'] > 0) {
            // Said plainly rather than hidden: the manager needs to know that the roster they
            // are looking at is not a complete copy of the source.
            $message .= ' '.$result['skipped'].' skipped where a shift already existed.';
        }

        return ApiResponse::created($result, $message);
    }

    // ---- Internals ---------------------------------------------------

    /**
     * Authorise against a single record, answering 404 when refused.
     *
     * A manager asking for shift 42 must not be able to tell "that shift is at another
     * outlet" from "no such shift". A 403 would confirm the record EXISTS, so probing
     * sequential ids would reveal how many shifts another outlet has rostered — and that
     * the outlet is busy enough to roster them.
     *
     * 404 for both makes the two indistinguishable, matching AdminEmployeeController.
     * Collection-level actions still use authorize(), because there the caller learns
     * nothing about individual records and a clear 403 is more useful than a misleading 404.
     */
    private function authorizeOnRecord(string $ability, Shift $shift): void
    {
        if (! request()->user()->can($ability, $shift)) {
            abort(404);
        }
    }

    /**
     * Resolve a local date range into a UTC instant pair plus the timezone used.
     *
     * Half-open: `from` inclusive, `to` exclusive. That is what makes "to = tomorrow" mean
     * "the whole of today" without the usual off-by-one at the end of the range.
     *
     * @param  array<string, mixed>  $validated
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: string}
     */
    private function range(Request $request, array $validated): array
    {
        $timezone = $this->resolveTimezone($request, $validated);
        $today = CarbonImmutable::now($timezone)->startOfDay();

        $from = isset($validated['from'])
            ? CarbonImmutable::parse($validated['from'], $timezone)->startOfDay()
            : $today->startOfWeek(CarbonImmutable::MONDAY);

        $to = isset($validated['to'])
            // Exclusive, so the caller's "to" is the last day they want INCLUDED.
            ? CarbonImmutable::parse($validated['to'], $timezone)->startOfDay()->addDay()
            : $from->addDays(7);

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        // Clamped so one request cannot scan the whole roster history.
        if ($from->diffInDays($to) > self::MAX_RANGE_DAYS) {
            $to = $from->addDays(self::MAX_RANGE_DAYS);
        }

        return [$from, $to, $timezone];
    }

    /**
     * The timezone the requested range is expressed in.
     *
     * An outlet's own when one is named, otherwise the business default. Using the app
     * timezone (UTC) would put the week boundary eight hours early, so "this week" would
     * start on Sunday morning for a Malaysian roster.
     *
     * @param  array<string, mixed>  $validated
     */
    private function resolveTimezone(Request $request, array $validated): string
    {
        if (isset($validated['outlet_id'])) {
            $outlet = Outlet::find($validated['outlet_id']);

            if ($outlet !== null) {
                return $outlet->timezone;
            }
        }

        /*
         * No outlet named: use the FIRST outlet the user can see. A manager has one or two,
         * and they are all in the same zone in practice. This matters because the roster
         * screen without an outlet filter is the common case.
         */
        $visible = $request->user()->visibleOutletIds();

        $outletId = $visible === null
            ? Outlet::query()->value('id')
            : ($visible[0] ?? null);

        return $outletId !== null
            ? (Outlet::find($outletId)?->timezone ?? config('attendance.business_timezone'))
            : config('attendance.business_timezone');
    }
}
