<?php

namespace App\Models;

use App\Enums\RoundingPolicy;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Overtime, rounding and grace policy for an outlet, versioned by date.
 *
 * Versioned for the same reason pay rates are: changing the overtime threshold today
 * must not silently rewrite last month's total. Reports look up the rule that was in
 * force on the day, which is what makes an old figure reproducible.
 *
 * A missing rule is not an error. The defaults below are the business's agreed rules,
 * applied when no row exists, so a fresh install reports sensibly and an outlet created
 * without a rules row still behaves correctly rather than crashing.
 */
#[Fillable([
    'outlet_id', 'ot_after_seconds', 'ot_basis', 'rounding_policy',
    'grace_seconds', 'break_paid', 'effective_from', 'effective_to', 'created_by',
])]
class LabourRule extends Model
{
    /** Overtime begins after 8 hours worked. */
    public const DEFAULT_OT_AFTER_SECONDS = 28800;

    /** 5 minutes, as decided: it moves the late FLAG only, never pay. */
    public const DEFAULT_GRACE_SECONDS = 300;

    protected function casts(): array
    {
        return [
            'rounding_policy' => RoundingPolicy::class,
            'ot_after_seconds' => 'integer',
            'grace_seconds' => 'integer',
            'break_paid' => 'boolean',
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    /**
     * The rule in force at an outlet on a given date.
     *
     * Falls back to a default instance rather than null so callers never have to
     * special-case a missing policy — the alternative is a `?->` at every use, and one
     * of them would eventually be missed and silently produce a zero threshold.
     */
    public static function forDate(int $outletId, string $date): self
    {
        $rule = static::query()
            ->where('outlet_id', $outletId)
            ->whereDate('effective_from', '<=', $date)
            ->where(fn (Builder $q) => $q
                ->whereNull('effective_to')
                ->orWhereDate('effective_to', '>=', $date))
            ->orderByDesc('effective_from')
            ->first();

        return $rule ?? static::defaultsFor($outletId);
    }

    /** An unsaved instance carrying the business's agreed defaults. */
    public static function defaultsFor(int $outletId): self
    {
        return new static([
            'outlet_id' => $outletId,
            'ot_after_seconds' => self::DEFAULT_OT_AFTER_SECONDS,
            'ot_basis' => 'worked',
            'rounding_policy' => RoundingPolicy::EXACT,
            'grace_seconds' => self::DEFAULT_GRACE_SECONDS,
            'break_paid' => false,
        ]);
    }

    /** @return BelongsTo<Outlet, $this> */
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }
}
