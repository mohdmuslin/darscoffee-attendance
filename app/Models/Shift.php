<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A planned shift — the roster.
 *
 * A shift never contributes to worked hours; those come only from TimeEntry.
 * Keeping the plan and the actual apart is what makes adhoc work work without a
 * special case: an adhoc attendance is a punch with no matching shift.
 *
 * Cancelling sets a timestamp rather than deleting, because a shift that was
 * rostered and then called off is information — it explains a no-show.
 */
#[Fillable([
    'employee_id', 'outlet_id', 'starts_at', 'ends_at',
    'position', 'note', 'created_by', 'cancelled_at',
])]
class Shift extends Model
{
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * Normalise the shift times to UTC on the way in.
     *
     * Same hazard as TimeEntry, and it bit hardest here: a manager enters a roster in the
     * outlet's LOCAL time, and the `datetime` cast does not convert — so 09:00+08:00 was
     * written as "09:00" and read back as 09:00 UTC, eight hours late. Every lateness and
     * early-out figure was then computed against a shift that had moved, which showed up as
     * arrivals that were never late and departures reported as eight hours early.
     *
     * @return Attribute<CarbonImmutable, never>
     */
    protected function startsAt(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => $value === null ? null : CarbonImmutable::parse($value)->utc(),
        );
    }

    /** @return Attribute<CarbonImmutable, never> */
    protected function endsAt(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => $value === null ? null : CarbonImmutable::parse($value)->utc(),
        );
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    public function durationSeconds(): int
    {
        return (int) $this->starts_at->diffInSeconds($this->ends_at);
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<Outlet, $this> */
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('cancelled_at');
    }
}
