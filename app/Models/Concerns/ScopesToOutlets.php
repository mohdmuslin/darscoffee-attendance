<?php

namespace App\Models\Concerns;

use App\Models\Outlet;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Restricts a query to the outlets a user may see.
 *
 * This is a SECURITY boundary, not a convenience filter. A manager at Sg Ramal
 * must not be able to reach Sedap Santai's staff, hours or photos, whether by
 * clicking or by crafting a request — so every listing goes through here rather
 * than trusting the UI to hide things.
 *
 * An owner resolves to "no restriction". A manager with no mappings resolves to
 * "restriction to nothing", which FAILS CLOSED: forgetting to grant access locks
 * someone out rather than exposing every outlet.
 *
 * Models may implement `outletScopeColumn()` to name the column, or
 * `scopeForUser()` for anything more involved (a relation, say).
 */
trait ScopesToOutlets
{
    /**
     * Apply the user's outlet scope.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if ($user === null) {
            return $query->whereRaw('1 = 0');
        }

        $outletIds = $user->visibleOutletIds();

        // null means every outlet (an owner).
        if ($outletIds === null) {
            return $query;
        }

        // An empty array means no access at all — fail closed.
        if ($outletIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $this->applyOutletScope($query, $outletIds);
    }

    /**
     * Narrow the query to the given outlet ids.
     *
     * Overridden by models where the outlet is reached through a relation rather
     * than a direct column.
     *
     * @param  Builder<static>  $query
     * @param  array<int>  $outletIds
     * @return Builder<static>
     */
    protected function applyOutletScope(Builder $query, array $outletIds): Builder
    {
        $column = $this->outletScopeColumn();

        return $query->whereIn($this->qualifyColumn($column), $outletIds);
    }

    /**
     * The column holding the outlet id.
     *
     * Defaults to `outlet_id`, which covers most models.
     */
    protected function outletScopeColumn(): string
    {
        return 'outlet_id';
    }

    /**
     * Whether a single record is within a user's scope.
     *
     * Used for show/update routes, where a query filter is not enough.
     *
     * Models whose outlet comes from a relation rather than a column — Employee,
     * whose outlets are a pivot — MUST override this, because the default compares
     * a single `outlet_id` that such a model does not have. An override that forgets
     * to would deny everything rather than leak, which is the safer direction but
     * still wrong.
     */
    public function isVisibleTo(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        $outletIds = $user->visibleOutletIds();

        // null means an owner: unrestricted.
        if ($outletIds === null) {
            return true;
        }

        if ($outletIds === []) {
            return false;
        }

        return in_array($this->outletScopeOutletId(), $outletIds, true);
    }

    /** The outlet id of this record, for a single-record scope check. */
    protected function outletScopeOutletId(): ?int
    {
        return $this->outlet_id;
    }

    /**
     * @param  array<int>  $outletIds
     */
    public static function assertVisibleOutlet(?User $user, int $outletId): void
    {
        $outletIds = $user?->visibleOutletIds();

        if ($outletIds !== null && ! in_array($outletId, $outletIds, true)) {
            abort(404);
        }
    }

    /**
     * The outlets a user may choose from, for building filter dropdowns.
     *
     * @return Collection<int, Outlet>
     */
    public static function outletsFor(?User $user)
    {
        $query = Outlet::query()->orderBy('name');

        $outletIds = $user?->visibleOutletIds();

        if ($outletIds !== null) {
            $query->whereIn('id', $outletIds);
        }

        return $query->get();
    }
}
