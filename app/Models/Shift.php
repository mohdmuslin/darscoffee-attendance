<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
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
