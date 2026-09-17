<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Outlet;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Console accounts: who can sign in, and what they may see.
 *
 * Owner-only. Creating an account is granting access, and a manager who could create
 * a manager could widen their own scope.
 *
 * NOTE: these accounts are local to this app. Single sign-on will make Attendance the
 * identity SOURCE for the ordering system, but nothing here calls the other app — see
 * docs/sso.md. Attendance must keep working if the ordering system is unreachable.
 */
class AdminUserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeOwner($request);

        $users = User::with(['outlets', 'employee'])
            ->orderBy('name')
            ->get();

        return ApiResponse::success([
            'users' => $users->map(fn (User $user) => $this->userPayload($user)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeOwner($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:150', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', new Enum(UserRole::class)],

            /*
             * Required for a manager, ignored for an owner (who sees everything) and
             * for staff (who see only themselves). A manager with no outlet mapping
             * would see NOTHING, so this is enforced rather than left to chance.
             */
            'outlet_ids' => ['required_if:role,manager', 'array'],
            'outlet_ids.*' => ['integer', Rule::exists('outlets', 'id')],

            /*
             * Optional link to an employee record. Needed when a manager also works
             * shifts, so their punches and their login are the same person.
             */
            'employee_id' => ['nullable', 'integer', Rule::exists('employees', 'id')],

            'is_active' => ['sometimes', 'boolean'],
        ]);

        $user = DB::transaction(function () use ($validated) {
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'role' => $validated['role'],
                'is_active' => $validated['is_active'] ?? true,
                'email_verified_at' => now(),
            ]);

            if ($validated['role'] === UserRole::MANAGER->value) {
                $user->outlets()->sync($validated['outlet_ids'] ?? []);
            }

            if (filled($validated['employee_id'] ?? null)) {
                $this->linkEmployee($user, (int) $validated['employee_id']);
            }

            return $user;
        });

        return ApiResponse::created(
            $this->userPayload($user->fresh()->load(['outlets', 'employee'])),
            'Account created.',
        );
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $this->authorizeOwner($request);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:100'],
            'email' => ['sometimes', 'email', 'max:150', Rule::unique('users', 'email')->ignore($user->id)],
            'role' => ['sometimes', new Enum(UserRole::class)],
            'password' => ['sometimes', 'string', 'min:8'],
            'outlet_ids' => ['sometimes', 'array'],
            'outlet_ids.*' => ['integer', Rule::exists('outlets', 'id')],
            'employee_id' => ['sometimes', 'nullable', 'integer', Rule::exists('employees', 'id')],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        /*
         * Refuse to leave the system with no owner.
         *
         * If this is the last active owner, demoting or deactivating them would lock
         * everyone out of every screen with no way back in — the same class of
         * failure the ordering system guards against on its staff accounts.
         */
        if ($this->wouldRemoveLastOwner($user, $validated)) {
            return ApiResponse::error(
                'This is the only active owner. Promote someone else first, or the system '
                .'would be left with nobody able to administer it.',
                null,
                409,
                'LAST_OWNER',
            );
        }

        DB::transaction(function () use ($validated, $user) {
            $user->fill(collect($validated)->only(['name', 'email', 'is_active'])->all());

            if (filled($validated['password'] ?? null)) {
                $user->password = Hash::make($validated['password']);
            }

            $user->save();

            if (array_key_exists('role', $validated)) {
                $user->update(['role' => $validated['role']]);

                /*
                 * A newly-promoted manager needs a mapping or sees nothing; no longer
                 * being a manager makes any mapping meaningless, so it is cleared to
                 * avoid a stale grant reappearing if they are promoted again.
                 */
                if ($validated['role'] === UserRole::MANAGER->value) {
                    if (array_key_exists('outlet_ids', $validated)) {
                        $user->outlets()->sync($validated['outlet_ids']);
                    }
                } else {
                    $user->outlets()->sync([]);
                }
            } elseif (array_key_exists('outlet_ids', $validated)) {
                $user->outlets()->sync($validated['outlet_ids']);
            }

            if (array_key_exists('employee_id', $validated)) {
                if ($validated['employee_id'] === null) {
                    Employee::where('user_id', $user->id)->update(['user_id' => null]);
                } else {
                    $this->linkEmployee($user, (int) $validated['employee_id']);
                }
            }
        });

        return ApiResponse::success(
            $this->userPayload($user->fresh()->load(['outlets', 'employee'])),
            'Account updated.',
        );
    }

    /**
     * Deactivate an account.
     *
     * Preferred over deleting: the account's history stays attributable, and taking
     * effect is immediate because every request re-checks `is_active`.
     */
    public function deactivate(Request $request, User $user): JsonResponse
    {
        $this->authorizeOwner($request);

        if ($this->wouldRemoveLastOwner($user, ['is_active' => false])) {
            return ApiResponse::error(
                'This is the only active owner, so it cannot be deactivated.',
                null,
                409,
                'LAST_OWNER',
            );
        }

        $user->update(['is_active' => false]);

        // Revoke tokens immediately rather than waiting for them to expire.
        $user->tokens()->delete();

        return ApiResponse::success(
            $this->userPayload($user->fresh()->load(['outlets', 'employee'])),
            'Account deactivated and signed out everywhere.',
        );
    }

    public function activate(Request $request, User $user): JsonResponse
    {
        $this->authorizeOwner($request);

        $user->update(['is_active' => true]);

        return ApiResponse::success(
            $this->userPayload($user->fresh()->load(['outlets', 'employee'])),
            'Account reactivated.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role->value,
            'role_label' => $user->role->label(),
            'is_active' => $user->is_active,
            'last_login_at' => $user->last_login_at?->toIso8601String(),
            'outlets' => $user->outlets->map(fn (Outlet $outlet) => [
                'id' => $outlet->id,
                'code' => $outlet->code,
                'name' => $outlet->name,
            ]),
            'employee_id' => $user->employee?->id,
            'employee_name' => $user->employee?->name,
            'has_password' => filled($user->password),
        ];
    }

    /**
     * Link an employee record to a login, moving the link if it was elsewhere.
     *
     * One employee maps to at most one login, so an existing link is cleared first —
     * otherwise two accounts could point at the same person and the punch-to-account
     * relationship would be ambiguous.
     */
    private function linkEmployee(User $user, int $employeeId): void
    {
        Employee::where('user_id', $user->id)->update(['user_id' => null]);
        Employee::where('id', $employeeId)->update(['user_id' => $user->id]);
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function wouldRemoveLastOwner(User $user, array $changes): bool
    {
        if (! $user->isOwner()) {
            return false;
        }

        $losingOwner = ($changes['role'] ?? null) !== null && $changes['role'] !== UserRole::OWNER->value;
        $beingDeactivated = ($changes['is_active'] ?? null) === false;

        if (! $losingOwner && ! $beingDeactivated) {
            return false;
        }

        return User::where('role', UserRole::OWNER->value)
            ->where('is_active', true)
            ->where('id', '!=', $user->id)
            ->doesntExist();
    }

    private function authorizeOwner(Request $request): void
    {
        if (! $request->user()->isOwner()) {
            abort(403, 'Only the owner can manage accounts.');
        }
    }
}
