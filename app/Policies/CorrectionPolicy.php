<?php

namespace App\Policies;

use App\Models\AttendanceCorrection;
use App\Models\Employee;
use App\Models\Outlet;
use App\Models\User;

/**
 * Who may change recorded time.
 *
 * A manager may correct entries for their own outlet — that was decided deliberately,
 * because waiting on an owner for every forgotten clock-out would mean the hours simply
 * never get fixed. What keeps that safe is not a permission but the audit record, which
 * is why every correction names its requester, its reviewer and its reason.
 *
 * Only an OWNER may review. A manager approving their own correction would make the
 * timesheet self-certifying, which defeats the point of having an approval step at all.
 */
class CorrectionPolicy
{
    /** Owners pass everything, stated explicitly rather than relying on a global hook. */
    public function before(User $user, string $ability): ?bool
    {
        return $user->isOwner() ? true : null;
    }

    /** Listing: any administrator, scoped separately by outlet. */
    public function viewAny(User $user): bool
    {
        return $user->role->canAdminister();
    }

    public function view(User $user, AttendanceCorrection $correction): bool
    {
        if (! $user->role->canAdminister()) {
            return false;
        }

        return $correction->isVisibleTo($user);
    }

    /**
     * Raising a correction, checked against the OUTLET it affects.
     *
     * Takes an outlet rather than a model because it is evaluated from two call sites —
     * against an existing entry, and against an outlet plus employee when recording a
     * punch that never happened.
     *
     * Uses `canAccessOutlet`, the same rule the outlet listing filters by, so scoping
     * cannot drift between "which outlets can I see" and "which may I change".
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

    public function correct(User $user, Employee $employee): bool
    {
        if (! $user->role->canAdminister()) {
            return false;
        }

        return $employee->isVisibleTo($user);
    }

    /**
     * Reviewing is owner-only by design.
     *
     * The `before` hook already returns true for an owner, so reaching here means the
     * user is a manager — and a manager must not approve their own correction.
     */
    public function review(User $user, AttendanceCorrection $correction): bool
    {
        return false;
    }
}
