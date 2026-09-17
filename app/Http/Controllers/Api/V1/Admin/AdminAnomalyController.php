<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\AnomalySeverity;
use App\Http\Controllers\Controller;
use App\Http\Resources\PunchEventResource;
use App\Models\Anomaly;
use App\Models\Outlet;
use App\Models\PunchEvent;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The anomaly review queue, and the punch audit trail behind it.
 *
 * The design accepts that no software can prove who held the phone. The whole
 * compensating control is that anything odd is VISIBLE and gets looked at, so this
 * screen is not a debugging aid — it is the control that makes the rest of the system
 * worth trusting.
 *
 * Reviewing a flag changes no hours. It records that someone looked, which is a
 * different act from correcting an entry and deliberately does not need approval.
 */
class AdminAnomalyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Anomaly::class);

        $validated = $request->validate([
            'severity' => ['nullable', 'in:info,warn,high'],
            'type' => ['nullable', 'string', 'max:40'],
            'outlet_id' => ['nullable', 'integer'],
            'employee_id' => ['nullable', 'integer'],
            'unreviewed_only' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $anomalies = Anomaly::query()
            ->with(['timeEntry.employee', 'timeEntry.outlet'])
            /*
             * Scoped through the entry's outlet, because an anomaly has no outlet column
             * of its own. Written as a whereHas so the filter happens in the database —
             * loading every flag and discarding most of them would be both slower and one
             * careless line away from leaking another outlet's data.
             */
            ->whereHas('timeEntry', fn ($q) => $q->whereIn(
                'outlet_id',
                $this->visibleOutletIds($request)
            ))
            ->when($validated['severity'] ?? null, fn ($q, $s) => $q->where('severity', $s))
            ->when($validated['type'] ?? null, fn ($q, $t) => $q->where('type', $t))
            ->when(
                isset($validated['outlet_id']),
                fn ($q) => $q->whereHas('timeEntry', fn ($w) => $w->where('outlet_id', $validated['outlet_id']))
            )
            ->when(
                isset($validated['employee_id']),
                fn ($q) => $q->whereHas('timeEntry', fn ($w) => $w->where('employee_id', $validated['employee_id']))
            )
            ->when($request->boolean('unreviewed_only', false), fn ($q) => $q->whereNull('reviewed_at'))
            // Highest severity first, then newest: the queue should lead with what
            // matters rather than with whatever happened most recently.
            ->orderByRaw("FIELD(severity, 'high', 'warn', 'info')")
            ->orderByDesc('id')
            ->paginate($validated['per_page'] ?? 25);

        return ApiResponse::success([
            'anomalies' => collect($anomalies->items())->map(fn (Anomaly $anomaly) => $this->payload($anomaly)),
            'counts' => $this->counts($request),
            'meta' => [
                'current_page' => $anomalies->currentPage(),
                'last_page' => $anomalies->lastPage(),
                'total' => $anomalies->total(),
            ],
        ]);
    }

    /**
     * Mark a flag as looked at.
     *
     * Idempotent: reviewing something twice simply updates the note, so a double-click
     * or a stale screen cannot produce an error the manager has to interpret.
     */
    public function review(Request $request, Anomaly $anomaly): JsonResponse
    {
        $this->authorize('review', $anomaly);

        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $anomaly->forceFill([
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'review_note' => $validated['note'] ?? null,
        ])->save();

        return ApiResponse::success(
            ['anomaly' => $this->payload($anomaly->fresh(['timeEntry.employee', 'timeEntry.outlet']))],
            'Flag reviewed.',
        );
    }

    /**
     * The punch audit trail.
     *
     * Answers "I clocked in and it says I didn't" by showing the FAILED attempts too,
     * which is the only way that question can be settled — a successful punch leaves an
     * entry, so a complaint about absence is always about something that did not work.
     */
    public function events(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Anomaly::class);

        $validated = $request->validate([
            'employee_id' => ['nullable', 'integer'],
            'outlet_id' => ['nullable', 'integer'],
            'failures_only' => ['nullable', 'boolean'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $events = PunchEvent::query()
            ->with(['employee', 'outlet'])
            ->whereIn('outlet_id', $this->visibleOutletIds($request))
            ->when($validated['employee_id'] ?? null, fn ($q, $id) => $q->where('employee_id', $id))
            ->when($validated['outlet_id'] ?? null, fn ($q, $id) => $q->where('outlet_id', $id))
            ->when($request->boolean('failures_only', false), fn ($q) => $q->failures())
            ->when($validated['from'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
            ->when($validated['to'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '<=', $d))
            ->orderByDesc('id')
            ->paginate($validated['per_page'] ?? 50);

        return ApiResponse::success([
            'events' => PunchEventResource::collection($events->items()),
            'meta' => [
                'current_page' => $events->currentPage(),
                'last_page' => $events->lastPage(),
                'total' => $events->total(),
            ],
        ]);
    }

    // ---- Internals ---------------------------------------------------

    /**
     * The badge counts.
     *
     * @return array<string, mixed>
     */
    private function counts(Request $request): array
    {
        $base = fn () => Anomaly::query()
            ->whereHas('timeEntry', fn ($q) => $q->whereIn('outlet_id', $this->visibleOutletIds($request)));

        return [
            'unreviewed' => $base()->whereNull('reviewed_at')->count(),
            'high' => $base()->whereNull('reviewed_at')->where('severity', AnomalySeverity::HIGH->value)->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Anomaly $anomaly): array
    {
        $entry = $anomaly->timeEntry;

        return [
            'id' => $anomaly->id,
            'type' => $anomaly->type->value,
            'type_label' => $anomaly->type->label(),
            'severity' => $anomaly->severity->value,
            'severity_label' => $anomaly->severity->label(),
            'detail' => $anomaly->detail,
            // Whether this row exists for the owner's oversight rather than as a fault,
            // so the console can group the manager-action records separately.
            'is_oversight_record' => $anomaly->type->isOversightRecord(),
            'is_reviewed' => $anomaly->isReviewed(),
            'review_note' => $anomaly->review_note,
            'reviewed_at' => $anomaly->reviewed_at?->toIso8601String(),

            'time_entry_id' => $anomaly->time_entry_id,
            'employee' => $entry === null ? null : [
                'id' => $entry->employee?->id,
                'name' => $entry->employee?->name,
                'employee_code' => $entry->employee?->employee_code,
            ],
            'outlet' => $entry === null ? null : [
                'id' => $entry->outlet?->id,
                'name' => $entry->outlet?->name,
            ],
            'started_at' => $entry?->started_at?->toIso8601String(),
            'ended_at' => $entry?->ended_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<int>
     */
    private function visibleOutletIds(Request $request): array
    {
        $ids = $request->user()->visibleOutletIds();

        // null means an owner: every outlet. Resolved to a list because the filter needs
        // a whereIn, and an empty list must mean "nothing" rather than "everything".
        return $ids ?? Outlet::query()->pluck('id')->all();
    }
}
