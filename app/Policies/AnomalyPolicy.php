<?php

namespace App\Policies;

use App\Models\Anomaly;
use App\Models\User;

/**
 * Reviewing the anomaly queue.
 *
 * Managers review their own outlet's flags; owners review everything. Reviewing is a
 * single act — mark it looked at, with a note — so unlike corrections there is no
 * second-signature problem: dismissing a flag changes no hours.
 */
class AnomalyPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->isOwner() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->role->canAdminister();
    }

    public function view(User $user, Anomaly $anomaly): bool
    {
        if (! $user->role->canAdminister()) {
            return false;
        }

        /*
         * Scoped through the entry's OUTLET, since an anomaly has no outlet column of
         * its own. Reading it here rather than trusting the caller to have filtered is
         * the point: the console loads a single anomaly by id when a manager opens one.
         */
        return $this->entryIsAccessible($user, $anomaly);
    }

    public function review(User $user, Anomaly $anomaly): bool
    {
        if (! $user->role->canAdminister()) {
            return false;
        }

        return $this->entryIsAccessible($user, $anomaly);
    }

    /**
     * Whether the user may reach the entry this flag hangs off.
     *
     * An anomaly whose entry has been deleted is unreachable by a manager rather than
     * visible to everyone — the safe direction for a row that can no longer be scoped.
     */
    private function entryIsAccessible(User $user, Anomaly $anomaly): bool
    {
        $outletId = $anomaly->timeEntry?->outlet_id;

        return $outletId !== null && $user->canAccessOutlet($outletId);
    }
}
