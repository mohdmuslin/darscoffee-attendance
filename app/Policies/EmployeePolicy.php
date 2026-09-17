<?php

namespace App\Policies;

use App\Models\Employee;
use App\Models\User;

/**
 * Who may see and edit an employee.
 *
 * This is the security boundary that matters most in this app: it is what stops a
 * manager at one outlet from reading another outlet's staff, their hours and their
 * photographs. Everything is checked here rather than hidden in the UI, because a
 * crafted request bypasses the UI entirely.
 *
 * Scoping fails CLOSED. A manager mapped to no outlet sees nobody.
 */
class EmployeePolicy
{
    /**
     * Owners pass every check. Registered here explicitly so this policy behaves
     * correctly even if the global Gate::before is ever removed.
     */
    public function before(User $user, string $ability): ?bool
    {
        return $user->isOwner() ? true : null;
    }

    /** Listing: any administrator may list, and the query is scoped separately. */
    public function viewAny(User $user): bool
    {
        return $user->role->canAdminister();
    }

    public function view(User $user, Employee $employee): bool
    {
        if (! $user->role->canAdminister()) {
            return false;
        }

        return $employee->isVisibleTo($user);
    }

    public function create(User $user): bool
    {
        return $user->role->canAdminister();
    }

    public function update(User $user, Employee $employee): bool
    {
        if (! $user->role->canAdminister()) {
            return false;
        }

        return $employee->isVisibleTo($user);
    }

    /**
     * Deleting an employee is owner-only.
     *
     * Even soft-deleting removes someone from the roster, and a manager deleting a
     * member of their own staff ahead of a dispute is not something to permit.
     * Managers deactivate instead, which keeps the record.
     */
    public function delete(User $user, Employee $employee): bool
    {
        return false;
    }

    public function deactivate(User $user, Employee $employee): bool
    {
        if (! $user->role->canAdminister()) {
            return false;
        }

        return $employee->isVisibleTo($user);
    }

    /**
     * Setting a PIN.
     *
     * A manager may set a PIN for their own staff — that is a routine
     * "I forgot my PIN" fix at the counter. Note it is also how a manager could
     * clock someone in as themselves, which is precisely the buddy-punching risk
     * the punch photo and anomaly queue exist for. These PIN changes are recorded
     * so the trail exists.
     */
    public function setPin(User $user, Employee $employee): bool
    {
        if (! $user->role->canAdminister()) {
            return false;
        }

        return $employee->isVisibleTo($user);
    }

    /**
     * Linking an employee record to a console login, and unlinking it.
     *
     * Owner-only, because it is a privilege grant: whoever is linked gains that
     * account's access to this app.
     */
    public function linkUser(User $user, Employee $employee): bool
    {
        return false;
    }

    /**
     * Viewing the punch photograph.
     *
     * Separate from `view` so photo access can be tightened independently later
     * without touching the general permission.
     */
    public function viewPhoto(User $user, Employee $employee): bool
    {
        if (! $user->role->canAdminister()) {
            return false;
        }

        return $employee->isVisibleTo($user);
    }
}
