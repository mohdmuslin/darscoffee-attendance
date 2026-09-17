<?php

namespace App\Models;

use App\Models\Concerns\ScopesToOutlets;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A one-off change to how an employee's hours are priced.
 *
 * The overtime rate is a flat figure a manager may adjust adhoc, and because that decision
 * carries no second signature the record is the control. Hence: a required reason, a stored
 * previous state, and an optional approval that is off by default.
 *
 * `applies_to` names WHICH figure is overridden. It is explicit because an employee can have
 * both an ordinary and an overtime rate, and "changed the rate" would be ambiguous the moment
 * they do.
 */
#[Fillable([
    'employee_id', 'applies_to_date', 'applies_to_period', 'applies_to',
    'hours', 'rate', 'reason', 'created_by', 'approved_by', 'approved_at',
])]
class RateAdjustment extends Model
{
    /*
     * Scoped by the outlets the employee works at, so a manager cannot read or create an
     * adjustment for someone at another outlet. Reached through the employee relation, since
     * this table has no outlet of its own — the rate belongs to the person, not the site.
     */
    use ScopesToOutlets;

    protected function casts(): array
    {
        return [
            'applies_to_date' => 'date',
            'hours' => 'decimal:2',
            'rate' => 'decimal:2',
            'approved_at' => 'datetime',
        ];
    }

    /**
     * The outlets this adjustment's employee works at.
     *
     * Overridden because the scope has to travel through `employees`, and the trait's default
     * would look for a column this table does not have.
     *
     * @param  Builder<static>  $query
     * @param  array<int>  $outletIds
     * @return Builder<static>
     */
    protected function applyOutletScope(Builder $query, array $outletIds): Builder
    {
        return $query->whereHas(
            'employee.outlets',
            fn (Builder $q) => $q->whereIn('outlets.id', $outletIds),
        );
    }

    /**
     * Whether this adjustment is still waiting for approval.
     *
     * Approval is off by default, so an adjustment created without one is not "pending" — it
     * is simply not required. The distinction is made by the setting, not by nullness, so an
     * owner switching approval on later does not retroactively make old rows pending.
     */
    public function awaitingApproval(): bool
    {
        return Setting::bool(Setting::REQUIRE_RATE_APPROVAL, false)
            && $this->approved_at === null;
    }

    /** The last date this adjustment covers, inclusive. */
    public function lastCoveredDate(): string
    {
        $start = $this->applies_to_date->toDateString();

        return match ($this->applies_to_period) {
            'day' => $start,
            'week' => $this->applies_to_date->copy()->addDays(6)->toDateString(),
            'month' => $this->applies_to_date->copy()->endOfMonth()->toDateString(),
            /*
             * A default that treats an unknown period as a single day. The alternative — letting
             * `match` throw — would turn an unexpected value into a 500 on a PAYSLIP screen,
             * which is the worst place for one. Under-applying an adjustment is the safe
             * direction, and it is visible rather than silent.
             */
            default => $start,
        };
    }

    /** Whether a local date falls inside the span this adjustment covers. */
    public function coversDate(\DateTimeInterface $date): bool
    {
        $target = CarbonImmutable::instance($date)->toDateString();

        return $target >= $this->applies_to_date->toDateString()
            && $target <= $this->lastCoveredDate();
    }

    /**
     * Whether this adjustment's span meets a pay period at all.
     *
     * Overlap, not containment: an adjustment for a single busy Saturday must still price that
     * Saturday when the pay period starts before it.
     */
    public function overlapsRange(string $from, string $to): bool
    {
        return $this->applies_to_date->toDateString() <= $to
            && $this->lastCoveredDate() >= $from;
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->whereNotNull('approved_at');
    }
}
