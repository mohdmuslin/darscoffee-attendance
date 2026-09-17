<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A named pay period, and its lock.
 *
 * Locking is what turns a computed figure into a committed one. Without it, "last month's
 * total" changes every time somebody fixes a forgotten clock-out, and a payslip already handed
 * out stops matching the system.
 *
 * The lock is a DETECTABLE freeze, not a hard one:
 *
 *  - Corrections remain possible after locking, because refusing a legitimate correction
 *    because a period was locked would be worse than the problem it solves.
 *  - The figures approved at lock time are stored in `snapshot`, so reproducing them does not
 *    depend on re-running the calculation against data that has since changed. That is success
 *    criterion 6.
 *  - `snapshot_hash` digests the inputs at lock time, so a later run can say whether the
 *    numbers would STILL come out the same. A mismatch means somebody changed something, and
 *    the console says so rather than quietly showing a new figure.
 */
#[Fillable([
    'name', 'starts_on', 'ends_on', 'locked_at', 'locked_by',
    'snapshot', 'snapshot_hash', 'note', 'created_by',
])]
class PayPeriod extends Model
{
    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'locked_at' => 'datetime',
            'snapshot' => 'array',
        ];
    }

    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }

    /** Whether a local date falls inside this period, inclusive of both ends. */
    public function containsDate(\DateTimeInterface $date): bool
    {
        $target = Carbon::instance($date)->toDateString();

        return $target >= $this->starts_on->toDateString()
            && $target <= $this->ends_on->toDateString();
    }

    /** Whether two periods share any date at all. */
    public function overlaps(\DateTimeInterface $startsOn, \DateTimeInterface $endsOn): bool
    {
        return $this->starts_on->toDateString() <= Carbon::instance($endsOn)->toDateString()
            && $this->ends_on->toDateString() >= Carbon::instance($startsOn)->toDateString();
    }

    public function dayCount(): int
    {
        return (int) $this->starts_on->diffInDays($this->ends_on) + 1;
    }

    /** @return BelongsTo<User, $this> */
    public function locker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeLocked(Builder $query): Builder
    {
        return $query->whereNotNull('locked_at');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('locked_at');
    }

    /** Periods that cover a date, locked or not. */
    public function scopeCovering(Builder $query, string $date): Builder
    {
        return $query->whereDate('starts_on', '<=', $date)->whereDate('ends_on', '>=', $date);
    }
}
