<?php

namespace App\Policies;

use App\Models\Employee;
use App\Models\Outlet;
use App\Models\Shift;
use App\Models\User;

/**
 * Who may build and change the roster.
 *
 * Managers manage their own outlet's roster — that is the whole point of the feature, and
 * a manager who had to ask the owner to write next week's shifts would not use it. Like
 * corrections, the control is not a permission but the record: every shift stores who
 * created it, and cancelling keeps the row rather than deleting it.
 *
 * Owners pass every check, stated explicitly rather than relying on the global Gate hook.
 */
class ShiftPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->isOwner() ? true : null;
    }

    /** Listing: any administrator, scoped to their outlets by the query. */
    public function viewAny(User $user): bool
    {
        return $user->role->canAdminister();
    }

    public function view(User $user, Shift $shift): bool
    {
        if (! $user->role->canAdminister()) {
            return false;
        }

        return $shift->isVisibleTo($user);
    }

    /**
     * Creating a shift, checked against the OUTLET it lands in.
     *
     * The employee's own visibility is checked separately by the controller, because both
     * must hold: rostering someone at an outlet the manager cannot see would place that
     * person somewhere the manager then cannot manage.
     */
    public function create(User $user, ?Outlet $outlet = null): bool
    {
        if (! $user->role->canAdminister()) {
            return false;
        }

        if ($outlet === null) {
            return true;
        }

        return $user->canAccessOutlet($outlet->id);
    }

    /**
     * Editing and cancelling: any administrator whose outlet it is.
     *
     * Deliberately not restricted to the shift's creator. Two managers at one outlet
     * cover for each other, and a roster entry that only its author can amend is worse
     * than useless when that author is on leave.
     */
    public function update(User $user, Shift $shift): bool
    {
        if (! $user->role->canAdminister()) {
            return false;
        }

        return $shift->isVisibleTo($user);
    }

    public function cancel(User $user, Shift $shift): bool
    {
        return $this->update($user, $shift);
    }

    /**
     * Deleting is refused outright.
     *
     * A shift that was rostered and then called off is information — it explains a
     * no-show. Cancelling preserves that; deleting destroys it, and would also delete the
     * explanation for a day a manager is being asked about.
     */
    public function delete(User $user, Shift $shift): bool
    {
        return false;
    }

    /** Bulk copying a week into another — the action most likely to be misused. */
    public function copy(User $user, Employee $employee): bool
    {
        if (! $user->role->canAdminister()) {
            return false;
        }

        return $employee->isVisibleTo($user);
    }
}
