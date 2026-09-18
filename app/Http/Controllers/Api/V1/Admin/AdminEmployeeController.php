<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\ConsentMethod;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreEmployeeRequest;
use App\Http\Requests\Admin\UpdateEmployeeRequest;
use App\Http\Resources\EmployeeResource;
use App\Models\Employee;
use App\Models\Setting;
use App\Services\ConsentService;
use App\Services\PhotoService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Employee management.
 *
 * Every method is authorised through EmployeePolicy, and every listing is scoped by
 * outlet. That scoping is the security boundary of this application: a manager at one
 * outlet must not read another outlet's staff, hours or photographs, whether by
 * clicking or by crafting a request.
 */
class AdminEmployeeController extends Controller
{
    public function __construct(private readonly PhotoService $photos) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Employee::class);

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:64'],
            'outlet_id' => ['nullable', 'integer'],
            'include_inactive' => ['nullable', 'boolean'],
        ]);

        $employees = Employee::query()
            ->with('outlets', 'consentRecorder')
            // The scope, applied first so nothing below can widen it again.
            ->visibleTo($request->user())
            ->when(
                $request->filled('search'),
                fn ($q) => $q->where(fn ($w) => $w
                    ->where('name', 'like', '%'.$validated['search'].'%')
                    ->orWhere('employee_code', 'like', '%'.$validated['search'].'%'))
            )
            ->when(
                $request->filled('outlet_id'),
                /*
                 * A manager may ask for any outlet_id, including one they cannot
                 * see. The visibleTo() scope above already excluded those rows, so
                 * this filter can only narrow further — an unauthorised id simply
                 * returns nothing rather than exposing the outlet's staff.
                 */
                fn ($q) => $q->whereHas(
                    'outlets',
                    fn ($o) => $o->where('outlets.id', $validated['outlet_id'])
                )
            )
            ->when(
                ! $request->boolean('include_inactive'),
                fn ($q) => $q->where('is_active', true)
            )
            ->orderBy('name')
            ->get();

        return ApiResponse::success([
            'employees' => EmployeeResource::collection($employees),
        ]);
    }

    public function store(StoreEmployeeRequest $request): JsonResponse
    {
        $this->authorize('create', Employee::class);

        $validated = $request->validated();

        /*
         * A manager may only add staff to an outlet they can already see, or they
         * could place an employee somewhere they cannot manage and then no longer
         * see them.
         */
        $this->assertOutletsAreVisible($request, $validated['outlet_ids']);

        $employee = DB::transaction(function () use ($validated) {
            $employee = Employee::create([
                'employee_code' => $validated['employee_code'],
                'name' => $validated['name'],
                'phone' => $validated['phone'] ?? null,
                'pay_basis' => $validated['pay_basis'] ?? null,
                'joined_at' => $validated['joined_at'] ?? now()->toDateString(),
                'is_active' => $validated['is_active'] ?? true,
            ]);

            if (filled($validated['ic_number'] ?? null)) {
                $employee->setIcNumber($validated['ic_number']);
            }

            $this->syncOutlets($employee, $validated['outlet_ids'], $validated['primary_outlet_id'] ?? null);

            if (filled($validated['pin'] ?? null)) {
                $employee->setPin($validated['pin']);
            }

            return $employee;
        });

        return ApiResponse::created(
            ['employee' => new EmployeeResource($employee->load('outlets', 'consentRecorder'))],
            'Employee added.',
        );
    }

    public function show(Request $request, Employee $employee): JsonResponse
    {
        $this->authorizeOnRecord('view', $employee);

        return ApiResponse::success([
            'employee' => new EmployeeResource($employee->load('outlets', 'consentRecorder')),
        ]);
    }

    public function update(UpdateEmployeeRequest $request, Employee $employee): JsonResponse
    {
        $this->authorizeOnRecord('update', $employee);

        $validated = $request->validated();

        if (array_key_exists('outlet_ids', $validated)) {
            $this->assertOutletsAreVisible($request, $validated['outlet_ids']);
        }

        DB::transaction(function () use ($validated, $employee) {
            $employee->fill(collect($validated)->only([
                'employee_code', 'name', 'phone', 'pay_basis',
                'joined_at', 'resigned_at', 'is_active',
            ])->all())->save();

            if (array_key_exists('ic_number', $validated)) {
                $employee->setIcNumber($validated['ic_number']);
            }

            if (array_key_exists('outlet_ids', $validated)) {
                $this->syncOutlets(
                    $employee,
                    $validated['outlet_ids'],
                    $validated['primary_outlet_id'] ?? null,
                );
            }
        });

        return ApiResponse::success(
            ['employee' => new EmployeeResource($employee->fresh()->load('outlets', 'consentRecorder'))],
            'Employee updated.',
        );
    }

    /**
     * Deactivate rather than delete.
     *
     * History must survive someone leaving: their time entries may still be under
     * dispute, and a hard delete would orphan them. There is no destroy endpoint for
     * the same reason — and because a manager deleting their own staff ahead of a
     * dispute is not a power worth granting.
     */
    public function deactivate(Request $request, Employee $employee): JsonResponse
    {
        $this->authorizeOnRecord('deactivate', $employee);

        $employee->update([
            'is_active' => false,
            'resigned_at' => $employee->resigned_at ?? now()->toDateString(),
        ]);

        return ApiResponse::success(
            ['employee' => new EmployeeResource($employee->fresh()->load('outlets', 'consentRecorder'))],
            'Employee deactivated. Their history is kept.',
        );
    }

    public function activate(Request $request, Employee $employee): JsonResponse
    {
        $this->authorizeOnRecord('deactivate', $employee);

        $employee->update(['is_active' => true, 'resigned_at' => null]);

        return ApiResponse::success(
            ['employee' => new EmployeeResource($employee->fresh()->load('outlets', 'consentRecorder'))],
            'Employee reactivated.',
        );
    }

    /**
     * Set or reset a PIN.
     *
     * A manager may do this for their own staff — it is a routine fix at the
     * counter. It is also how a manager could clock someone in as themselves, which
     * is exactly the buddy-punching risk the punch photo and anomaly queue exist for,
     * so these changes are recorded in the audit trail.
     */
    public function setPin(Request $request, Employee $employee): JsonResponse
    {
        $this->authorizeOnRecord('setPin', $employee);

        $validated = $request->validate([
            'pin' => ['required', 'string', 'digits_between:4,6'],
        ]);

        $employee->setPin($validated['pin']);

        return ApiResponse::success(
            ['employee' => new EmployeeResource($employee->fresh()->load('outlets', 'consentRecorder'))],
            'PIN set. The employee can clock in with it immediately.',
        );
    }

    /** Clear a PIN without setting a new one, e.g. a lost phone. */
    public function clearPin(Request $request, Employee $employee): JsonResponse
    {
        $this->authorizeOnRecord('setPin', $employee);

        $employee->forceFill([
            'pin_hash' => null,
            'pin_set_at' => null,
            'pin_failed_attempts' => 0,
            'pin_locked_until' => null,
        ])->save();

        return ApiResponse::success(
            ['employee' => new EmployeeResource($employee->fresh()->load('outlets', 'consentRecorder'))],
            'PIN cleared. The employee cannot clock in until a new one is set.',
        );
    }

    /** Upload or replace the profile photo. */
    public function uploadPhoto(Request $request, Employee $employee): JsonResponse
    {
        $this->authorizeOnRecord('update', $employee);

        $request->validate([
            'photo' => ['required', 'image', 'mimes:jpeg,png,webp', 'max:4096'],
        ]);

        $previous = $employee->photo_path;

        $path = $this->photos->store($request->file('photo'), 'employees');

        $employee->update(['photo_path' => $path]);

        // Removed only after the new path is committed, so a failure cannot leave
        // the employee with no photo at all.
        if ($previous !== null) {
            $this->photos->delete($previous);
        }

        return ApiResponse::success(
            ['employee' => new EmployeeResource($employee->fresh()->load('outlets', 'consentRecorder'))],
            'Photo updated.',
        );
    }

    /**
     * Record PDPA consent to hold this person's photograph.
     *
     * The recorder is taken from the authenticated user rather than the request body. A
     * client-supplied "recorded_by" would let a manager attribute a consent to somebody
     * else, and the attribution is the part of a consent record most likely to be relied on
     * when it is disputed.
     *
     * Managers may do this for their own staff: taking consent is ordinary counter work, and
     * the alternative — only the owner can record it — means it does not get recorded.
     */
    public function recordConsent(Request $request, Employee $employee, ConsentService $consent): JsonResponse
    {
        $this->authorizeOnRecord('update', $employee);

        $validated = $request->validate([
            'method' => ['required', 'string', Rule::enum(ConsentMethod::class)],
            'version' => ['nullable', 'string', 'max:20'],
            'note' => ['nullable', 'string', 'max:255'],
            /*
             * Acknowledgement is required, and required HERE rather than in the UI. A consent
             * recorded without the person being told what they were agreeing to is not a
             * consent, and a form that can be submitted without the acknowledgement is one
             * that will be.
             */
            'acknowledged' => ['required', 'accepted'],
        ]);

        $employee = $consent->record(
            $employee,
            ConsentMethod::from($validated['method']),
            $request->user(),
            $validated['version'] ?? null,
            $validated['note'] ?? null,
        );

        return ApiResponse::success(
            ['employee' => new EmployeeResource($employee->load('outlets', 'consentRecorder'))],
            'Consent recorded.',
        );
    }

    /**
     * Withdraw consent.
     *
     * Withdrawal stops future photographs and deletes the profile photograph, but does NOT
     * delete punch photographs — those may be evidence in an open dispute, and a person's
     * objection does not by itself decide someone else's wage claim. Erasing them is an owner
     * decision taken on a request, which is what `--note` records.
     */
    public function withdrawConsent(Request $request, Employee $employee, ConsentService $consent): JsonResponse
    {
        $this->authorizeOnRecord('update', $employee);

        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $employee = $consent->withdraw($employee, $validated['note'] ?? null);

        return ApiResponse::success(
            ['employee' => new EmployeeResource($employee->load('outlets', 'consentRecorder'))],
            'Consent withdrawn. Photographs will no longer be taken at the punch screen, and their profile photo has been deleted.',
        );
    }

    /**
     * Who has a photograph on file with no consent to hold it.
     *
     * The compliance backlog, and the reason consent is a setting rather than always
     * enforced: this list has to be worked through before enforcement can be turned on
     * without stopping photographs altogether.
     */
    public function consentBacklog(Request $request, ConsentService $consent): JsonResponse
    {
        $this->authorize('viewAny', Employee::class);

        return ApiResponse::success([
            'employees' => EmployeeResource::collection(
                $consent->withoutConsent($request->user())
            ),
            'enforced' => Setting::bool(Setting::REQUIRE_CONSENT_FOR_PHOTOS, false),
            'notice_version' => ConsentService::NOTICE_VERSION,
        ]);
    }

    /** The methods the console may offer, so the labels live in one place. */
    public function consentMethods(): JsonResponse
    {
        $this->authorize('viewAny', Employee::class);

        return ApiResponse::success([
            'methods' => ConsentMethod::options(),
            'notice_version' => ConsentService::NOTICE_VERSION,
        ]);
    }

    /**
     * Authorise against a single record, answering 404 when refused.
     *
     * WHY 404 AND NOT 403
     *
     * A manager asking for employee 42 must not be able to tell the difference
     * between "that employee is at another outlet" and "no such employee". A 403
     * would confirm the record EXISTS, so probing sequential ids would reveal how
     * many staff another outlet has — and their existence at all.
     *
     * 404 for both makes the two indistinguishable, matching the ordering system's
     * choice for another table's order.
     *
     * Collection-level actions (listing, creating) still use `authorize()`, because
     * there the caller learns nothing about individual records and a clear 403 is
     * more useful than a misleading 404.
     */
    private function authorizeOnRecord(string $ability, Employee $employee): void
    {
        if (! request()->user()->can($ability, $employee)) {
            abort(404);
        }
    }

    /**
     * @param  array<int>  $outletIds
     */
    private function assertOutletsAreVisible(Request $request, array $outletIds): void
    {
        foreach ($outletIds as $outletId) {
            if (! $request->user()->canAccessOutlet((int) $outletId)) {
                abort(404);
            }
        }
    }

    /**
     * @param  array<int>  $outletIds
     */
    private function syncOutlets(Employee $employee, array $outletIds, ?int $primaryOutletId): void
    {
        // Exactly one primary, and it must be one of the selected outlets.
        $primary = $primaryOutletId !== null && in_array($primaryOutletId, $outletIds, true)
            ? $primaryOutletId
            : $outletIds[0];

        $employee->outlets()->sync(
            collect($outletIds)->mapWithKeys(fn ($id) => [
                (int) $id => ['is_primary' => (int) $id === (int) $primary],
            ])->all()
        );
    }
}
