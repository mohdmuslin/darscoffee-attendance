<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\AnomalyType;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCompensationRequest;
use App\Http\Requests\Admin\StoreRateAdjustmentRequest;
use App\Models\Anomaly;
use App\Models\CompensationRule;
use App\Models\Employee;
use App\Models\RateAdjustment;
use App\Models\Setting;
use App\Services\PayService;
use App\Support\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Pay rates and adhoc adjustments.
 *
 * Managers may set rates for their own staff, with no owner approval — that was decided
 * deliberately (blueprint §10.2), because waiting on the owner for every rate would mean rates
 * are wrong for weeks. What keeps that safe is not a permission but the record, so every change
 * stores who made it, when, why, and what it was before.
 *
 * Nothing here computes pay. That lives in PayService, so there is exactly one definition of
 * what an hour is worth.
 */
class AdminRateController extends Controller
{
    public function __construct(private readonly PayService $pay) {}

    /**
     * The current rates for everyone the user can see.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Employee::class);

        $today = CarbonImmutable::now(config('attendance.business_timezone'))->toDateString();

        $employees = Employee::query()
            ->with('outlets')
            ->visibleTo($request->user())
            ->when($request->boolean('active_only', true), fn ($q) => $q->where('is_active', true))
            ->orderBy('name')
            ->get();

        return ApiResponse::success([
            'as_of' => $today,
            'requires_approval' => Setting::bool(Setting::REQUIRE_RATE_APPROVAL, false),
            'employees' => $employees->map(function (Employee $employee) use ($today) {
                $rate = CompensationRule::forDate($employee->id, $today);

                return [
                    'id' => $employee->id,
                    'name' => $employee->name,
                    'employee_code' => $employee->employee_code,
                    'pay_basis' => $employee->pay_basis?->value,
                    // Null means no rate has been set. Reported as its own state rather than a
                    // zero, because "owes nothing" and "not configured" are different facts.
                    'has_rate' => $rate !== null,
                    'rate' => $rate !== null ? (float) $rate->rate : null,
                    'overtime_rate' => $rate?->overtime_rate !== null ? (float) $rate->overtime_rate : null,
                    'basis' => $rate?->basis->value,
                    'currency' => $rate?->currency ?? 'MYR',
                    'effective_from' => $rate?->effective_from->toDateString(),
                    'adjustment_count' => RateAdjustment::query()
                        ->where('employee_id', $employee->id)
                        ->whereDate('applies_to_date', '>=', $today)
                        ->count(),
                ];
            }),
        ]);
    }

    /**
     * One employee's rate history.
     *
     * The whole history, not just the current row: "what was he paid in March" is the question
     * this table exists to answer, and it cannot be answered from the present row alone.
     */
    public function history(Request $request, Employee $employee): JsonResponse
    {
        if (! $request->user()->can('view', $employee)) {
            // 404 rather than 403: a manager probing ids must not learn which employees exist
            // at another outlet, and a rate is personal data.
            return ApiResponse::notFound('Employee not found.');
        }

        $rates = CompensationRule::query()
            ->with('creator')
            ->where('employee_id', $employee->id)
            ->orderByDesc('effective_from')
            ->get()
            ->map(fn (CompensationRule $rule) => [
                'id' => $rule->id,
                'basis' => $rule->basis->value,
                'basis_label' => $rule->basis->label(),
                'rate' => (float) $rule->rate,
                'overtime_rate' => $rule->overtime_rate !== null ? (float) $rule->overtime_rate : null,
                'currency' => $rule->currency,
                'effective_from' => $rule->effective_from->toDateString(),
                'effective_to' => $rule->effective_to?->toDateString(),
                'is_current' => $rule->isOpenEnded(),
                'note' => $rule->note,
                'created_by' => $rule->creator?->name,
            ]);

        $adjustments = RateAdjustment::query()
            ->with(['creator', 'approver'])
            ->where('employee_id', $employee->id)
            ->orderByDesc('applies_to_date')
            ->limit(50)
            ->get()
            ->map(fn (RateAdjustment $adjustment) => [
                'id' => $adjustment->id,
                'applies_to_date' => $adjustment->applies_to_date->toDateString(),
                'applies_to_period' => $adjustment->applies_to_period,
                'applies_to' => $adjustment->applies_to,
                'hours' => $adjustment->hours !== null ? (float) $adjustment->hours : null,
                'rate' => (float) $adjustment->rate,
                'reason' => $adjustment->reason,
                'awaiting_approval' => $adjustment->awaitingApproval(),
                'created_by' => $adjustment->creator?->name,
                'approved_by' => $adjustment->approver?->name,
                'approved_at' => $adjustment->approved_at?->toIso8601String(),
            ]);

        return ApiResponse::success([
            'employee' => [
                'id' => $employee->id,
                'name' => $employee->name,
                'employee_code' => $employee->employee_code,
            ],
            'rates' => $rates,
            'adjustments' => $adjustments,
        ]);
    }

    /**
     * Set a new rate.
     *
     * Closes the current open row and inserts a new one, in a transaction. Never an update:
     * overwriting the old figure is exactly what makes "what was he paid in March?"
     * unanswerable, and that is success criterion 6.
     */
    public function store(StoreCompensationRequest $request, Employee $employee): JsonResponse
    {
        if (! $request->user()->can('update', $employee)) {
            return ApiResponse::notFound('Employee not found.');
        }

        $validated = $request->validated();

        $rule = DB::transaction(function () use ($employee, $validated, $request) {
            /*
             * Close any row still open. The day BEFORE the new rate starts, so the two do not
             * overlap — an overlap would make `forDate` order-dependent and the answer
             * arbitrary.
             */
            $newStart = CarbonImmutable::parse($validated['effective_from'], config('attendance.business_timezone'));

            CompensationRule::query()
                ->where('employee_id', $employee->id)
                ->whereNull('effective_to')
                ->whereDate('effective_from', '<', $newStart->toDateString())
                ->update(['effective_to' => $newStart->subDay()->toDateString()]);

            /*
             * A row starting on the SAME day is replaced rather than closed, because closing it
             * would give it an effective_to before its effective_from — a row that can never
             * apply, and one that would confuse every future reader.
             */
            CompensationRule::query()
                ->where('employee_id', $employee->id)
                ->whereDate('effective_from', $newStart->toDateString())
                ->delete();

            return CompensationRule::create([
                'employee_id' => $employee->id,
                'basis' => $validated['basis'],
                'rate' => $validated['rate'],
                'overtime_rate' => $validated['overtime_rate'] ?? null,
                'currency' => $validated['currency'] ?? 'MYR',
                'effective_from' => $validated['effective_from'],
                'note' => $validated['note'] ?? null,
                'created_by' => $request->user()->id,
            ]);
        });

        // The employee's pay basis is kept in step, since the rate and the basis describe the
        // same arrangement and letting them disagree would be a trap.
        $employee->forceFill(['pay_basis' => $validated['basis']])->save();

        return ApiResponse::created(
            ['rate' => ['id' => $rule->id, 'rate' => (float) $rule->rate, 'effective_from' => $rule->effective_from->toDateString()]],
            'Pay rate saved.',
        );
    }

    /**
     * An adhoc rate adjustment.
     */
    public function storeAdjustment(StoreRateAdjustmentRequest $request): JsonResponse
    {
        $employee = Employee::find($request->integer('employee_id'));

        if ($employee === null || ! $request->user()->can('update', $employee)) {
            return ApiResponse::notFound('Employee not found.');
        }

        $validated = $request->validated();

        $requiresApproval = Setting::bool(Setting::REQUIRE_RATE_APPROVAL, false);

        $adjustment = RateAdjustment::create([
            'employee_id' => $employee->id,
            'applies_to_date' => $validated['applies_to_date'],
            'applies_to_period' => $validated['applies_to_period'],
            'applies_to' => $validated['applies_to'],
            'hours' => $validated['hours'] ?? null,
            'rate' => $validated['rate'],
            'reason' => $validated['reason'],
            'created_by' => $request->user()->id,
            /*
             * Approval is OFF by default, so the adjustment applies at once and is self-approved
             * rather than sitting in a queue nobody asked for. The audit record is the control.
             */
            'approved_by' => $requiresApproval ? null : $request->user()->id,
            'approved_at' => $requiresApproval ? null : now(),
        ]);

        /*
         * A manager setting a rate for their own staff carries no second signature, so it is
         * surfaced to the owner rather than left in a log only the manager would read. Attached
         * to the employee's most recent entry so it appears where the hours are, since a rate
         * adjustment is not itself a punch.
         */
        if ($request->user()->role === UserRole::MANAGER) {
            $entry = $employee->timeEntries()->latest('id')->first();

            if ($entry !== null) {
                Anomaly::raise(
                    $entry,
                    AnomalyType::MANAGER_RATE_CHANGE,
                    'Rate set to '.number_format((float) $validated['rate'], 2).' for '
                        .$validated['applies_to_period'].' from '.$validated['applies_to_date']
                        .' by '.$request->user()->name.': '.$validated['reason'],
                );
            }
        }

        return ApiResponse::created(
            ['adjustment' => ['id' => $adjustment->id, 'rate' => (float) $adjustment->rate]],
            $requiresApproval
                ? 'Adjustment recorded and waiting for the owner to approve.'
                : 'Adjustment applied.',
        );
    }

    /**
     * Approve a pending adjustment.
     *
     * Owner-only in effect: the policy check is that rate approval is off for a manager, so a
     * manager never has anything of their own to approve.
     */
    public function approveAdjustment(Request $request, RateAdjustment $adjustment): JsonResponse
    {
        if (! $request->user()->isOwner()) {
            return ApiResponse::forbidden('Only the owner can approve a rate change.');
        }

        if ($adjustment->approved_at !== null) {
            // Idempotent: a double-click must not error, and must not move the timestamp.
            return ApiResponse::success(['adjustment' => ['id' => $adjustment->id]], 'Already approved.');
        }

        $adjustment->forceFill([
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
        ])->save();

        return ApiResponse::success(['adjustment' => ['id' => $adjustment->id]], 'Rate change approved.');
    }
}
