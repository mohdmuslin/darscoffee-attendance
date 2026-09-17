<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCorrectionRequest;
use App\Http\Requests\Admin\StoreMissingPunchRequest;
use App\Http\Resources\CorrectionResource;
use App\Models\AttendanceCorrection;
use App\Models\Employee;
use App\Models\Outlet;
use App\Models\TimeEntry;
use App\Services\CorrectionService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Corrections — the correction flow with its mandatory reason and full audit.
 *
 * A manager may raise a correction for their own outlet; only an owner may approve one.
 * That split is the control: a manager being able to rewrite their own staff's hours
 * unattended would make the timesheet self-certifying, and the timesheet is the whole
 * point of the system.
 *
 * Nothing here deletes. A rejected correction stays on the record, because "someone
 * tried to change this and was refused" is exactly the kind of thing worth being able to
 * see later.
 */
class AdminCorrectionController extends Controller
{
    public function __construct(private readonly CorrectionService $corrections) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', AttendanceCorrection::class);

        $validated = $request->validate([
            'status' => ['nullable', 'in:pending,approved,rejected'],
            'outlet_id' => ['nullable', 'integer'],
            'employee_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $corrections = AttendanceCorrection::query()
            ->with(['employee', 'outlet', 'requester', 'reviewer'])
            // The scope, applied first so nothing below can widen it. A manager asking
            // for another outlet's id gets an empty list, not that outlet's records.
            ->visibleTo($request->user())
            ->when($validated['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($validated['outlet_id'] ?? null, fn ($q, $id) => $q->where('outlet_id', $id))
            ->when($validated['employee_id'] ?? null, fn ($q, $id) => $q->where('employee_id', $id))
            ->orderByDesc('id')
            ->paginate($validated['per_page'] ?? 25);

        return ApiResponse::success([
            'corrections' => CorrectionResource::collection($corrections->items()),
            'meta' => [
                'current_page' => $corrections->currentPage(),
                'last_page' => $corrections->lastPage(),
                'total' => $corrections->total(),
            ],
        ]);
    }

    /**
     * What is waiting for the owner, counted.
     *
     * A badge, so it is cheap: one indexed count rather than a paginated fetch.
     */
    public function pendingCount(Request $request): JsonResponse
    {
        $this->authorize('viewAny', AttendanceCorrection::class);

        return ApiResponse::success([
            'pending' => AttendanceCorrection::query()
                ->visibleTo($request->user())
                ->pending()
                ->count(),
        ]);
    }

    /** Raise a correction against a recorded entry. */
    public function store(StoreCorrectionRequest $request, TimeEntry $entry): JsonResponse
    {
        $outlet = $entry->outlet;

        if (! $request->user()->can('create', [AttendanceCorrection::class, $outlet])) {
            // 404, not 403. A manager guessing entry ids must not learn which ones exist
            // at an outlet they cannot see.
            return ApiResponse::notFound('Time entry not found.');
        }

        try {
            $correction = $this->corrections->request(
                $entry,
                $request->validated('changes'),
                $request->validated('reason'),
                $request->user(),
            );
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), null, 422, 'INVALID_CORRECTION');
        }

        return ApiResponse::created(
            ['correction' => new CorrectionResource($correction->load(['employee', 'outlet', 'requester', 'reviewer']))],
            $correction->isPending()
                ? 'Correction recorded and waiting for the owner to approve.'
                : 'Correction applied.',
        );
    }

    /** Record a punch that never happened at all. */
    public function storeMissing(StoreMissingPunchRequest $request): JsonResponse
    {
        $outlet = Outlet::find($request->integer('outlet_id'));

        if ($outlet === null || ! $request->user()->can('create', [AttendanceCorrection::class, $outlet])) {
            return ApiResponse::notFound('Outlet not found.');
        }

        $employee = Employee::find($request->integer('employee_id'));

        if ($employee === null || ! $request->user()->can('correct', $employee)) {
            return ApiResponse::notFound('Employee not found.');
        }

        try {
            $correction = $this->corrections->requestMissing(
                $employee,
                $outlet->id,
                $request->safe()->only(['started_at', 'ended_at', 'type', 'note']),
                $request->validated('reason'),
                $request->user(),
            );
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), null, 422, 'INVALID_CORRECTION');
        }

        return ApiResponse::created(
            ['correction' => new CorrectionResource($correction->load(['employee', 'outlet', 'requester', 'reviewer']))],
            $correction->isPending()
                ? 'Missing punch recorded and waiting for the owner to approve.'
                : 'Missing punch recorded.',
        );
    }

    /**
     * Approve a correction, applying it.
     *
     * Owner-only via the policy. The service is idempotent, so a double-click cannot
     * apply the same change twice.
     */
    public function approve(Request $request, AttendanceCorrection $correction): JsonResponse
    {
        $this->authorize('review', $correction);

        $reason = $request->validate(['note' => ['nullable', 'string', 'max:255']])['note'] ?? null;

        try {
            $correction = $this->corrections->approve($correction, $request->user(), $reason);
        } catch (InvalidArgumentException $e) {
            /*
             * A correction that cannot be applied — times running backwards, say — is a
             * 422 with an explanation, not a 500. The transaction has already rolled back,
             * so the correction stays pending and the manager can fix it and try again.
             */
            return ApiResponse::error($e->getMessage(), null, 422, 'INVALID_CORRECTION');
        }

        return ApiResponse::success(
            ['correction' => new CorrectionResource($correction->load(['employee', 'outlet', 'requester', 'reviewer']))],
            'Correction approved and applied.',
        );
    }

    public function reject(Request $request, AttendanceCorrection $correction): JsonResponse
    {
        $this->authorize('review', $correction);

        $reason = $request->validate(['note' => ['nullable', 'string', 'max:255']])['note'] ?? null;

        $correction = $this->corrections->reject($correction, $request->user(), $reason);

        return ApiResponse::success(
            ['correction' => new CorrectionResource($correction->load(['employee', 'outlet', 'requester', 'reviewer']))],
            'Correction rejected. The original entry is unchanged.',
        );
    }

    /**
     * The full history for one entry.
     *
     * Deliberately includes corrections that were REJECTED, because "this figure was
     * queried and the change was turned down" is part of the story a dispute needs.
     */
    public function history(Request $request, TimeEntry $entry): JsonResponse
    {
        $outlet = $entry->outlet;

        if (! $request->user()->can('create', [AttendanceCorrection::class, $outlet])) {
            return ApiResponse::notFound('Time entry not found.');
        }

        $corrections = AttendanceCorrection::query()
            ->with(['requester', 'reviewer'])
            ->where('time_entry_id', $entry->id)
            ->orderBy('id')
            ->get();

        return ApiResponse::success([
            'corrections' => CorrectionResource::collection($corrections),
            'entry' => [
                'id' => $entry->id,
                'started_at' => $entry->started_at?->toIso8601String(),
                'ended_at' => $entry->ended_at?->toIso8601String(),
                'status' => $entry->status->value,
                'status_label' => $entry->status->label(),
            ],
        ]);
    }
}
