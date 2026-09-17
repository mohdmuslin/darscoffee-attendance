<?php

namespace App\Models;

use App\Enums\PayBasis;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An employee's pay rate, in force over a date range.
 *
 * Rows are inserted, never updated. A raise closes the current row and inserts a new one,
 * which is the only way "what was he paid in March?" stays answerable afterwards.
 *
 * The rate's MEANING depends on the basis: 2,000 monthly is not 2,000 hourly, and the unit is
 * always carried alongside the figure rather than assumed by whoever reads it next.
 */
#[Fillable([
    'employee_id', 'basis', 'rate', 'overtime_rate', 'currency',
    'effective_from', 'effective_to', 'note', 'created_by',
])]
class CompensationRule extends Model
{
    protected function casts(): array
    {
        return [
            'basis' => PayBasis::class,
            // Kept as a string, not a float. Money in a binary float accumulates
            // representation error, and a payslip a sen out is one somebody has to explain.
            'rate' => 'decimal:2',
            'overtime_rate' => 'decimal:2',
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    /**
     * The rate in force for an employee on a given date.
     *
     * Returns null when no row covers the date — a new employee who has not been given a rate
     * yet, or a period before their first one. Deliberately null rather than a zero rate:
     * silently paying nothing is worse than the console showing "no rate set", and a zero
     * would flow into a total that looks like a real figure.
     */
    public static function forDate(int $employeeId, string $date): ?self
    {
        return static::query()
            ->where('employee_id', $employeeId)
            ->whereDate('effective_from', '<=', $date)
            ->where(fn (Builder $q) => $q
                ->whereNull('effective_to')
                ->orWhereDate('effective_to', '>=', $date))
            // Newest wins when two rows overlap, which is what makes a same-day re-rate take
            // effect rather than being ambiguous.
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();
    }

    /** The rate currently in force, or null. */
    public static function currentFor(int $employeeId): ?self
    {
        return static::forDate($employeeId, now(config('attendance.business_timezone'))->toDateString());
    }

    public function isOpenEnded(): bool
    {
        return $this->effective_to === null;
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

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereNull('effective_to');
    }
}
