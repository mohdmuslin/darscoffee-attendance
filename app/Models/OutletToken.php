<?php

namespace App\Models;

use App\Enums\OutletTokenMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A punch code for an outlet.
 *
 * One table covers both modes, because they are the same concept differing only
 * in lifetime:
 *
 *   printed   expires_at NULL  — valid until revoked
 *   rotating  expires_at set   — a short window shown on a device
 *
 * "Latest applicable" is implemented by REVOKING. Generating a new code kills the
 * previous live one for that outlet, which is the only recovery path when a
 * printed sheet is photographed and shared.
 */
#[Fillable([
    'outlet_id', 'token', 'mode', 'expires_at',
    'revoked_at', 'revoked_by', 'created_by', 'generations',
])]
class OutletToken extends Model
{
    protected function casts(): array
    {
        return [
            'mode' => OutletTokenMode::class,
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'generations' => 'integer',
        ];
    }

    /**
     * Generate a code for an outlet.
     *
     * The token is 32 random bytes, base64url-encoded: never sequential, because a
     * guessable code would let anyone fabricate presence at an outlet.
     */
    public static function generateFor(Outlet $outlet, ?User $creator = null): self
    {
        return static::create([
            'outlet_id' => $outlet->id,
            'token' => Str::random(48),
            'mode' => $outlet->token_mode->value,
            'expires_at' => $outlet->token_mode->expires()
                ? now()->addSeconds($outlet->qr_ttl_seconds)
                : null,
            'created_by' => $creator?->id,
        ]);
    }

    /** Usable right now: not revoked, and not past its window. */
    public function isLive(): bool
    {
        if ($this->revoked_at !== null) {
            return false;
        }

        return $this->expires_at === null || $this->expires_at->isFuture();
    }

    /**
     * Whether this code was usable AT A GIVEN MOMENT.
     *
     * Needed for offline punches: a queued punch carries the time it actually
     * happened, so validity must be judged against that instant rather than
     * against "now". Judging only against now would reject every offline punch
     * made on a rotating outlet, because the code will have rotated by the time
     * the phone regains signal.
     */
    public function wasLiveAt(\DateTimeInterface $at): bool
    {
        $at = CarbonImmutable::instance($at);

        if ($this->created_at !== null && $at->lessThan($this->created_at)) {
            return false;
        }

        if ($this->revoked_at !== null && $at->greaterThanOrEqualTo($this->revoked_at)) {
            return false;
        }

        if ($this->expires_at !== null && $at->greaterThan($this->expires_at)) {
            return false;
        }

        return true;
    }

    public function revoke(?User $by = null): void
    {
        if ($this->revoked_at !== null) {
            return;
        }

        $this->forceFill([
            'revoked_at' => now(),
            'revoked_by' => $by?->id,
        ])->save();
    }

    /** @return BelongsTo<Outlet, $this> */
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    public function scopeLive(Builder $query): Builder
    {
        return $query
            ->whereNull('revoked_at')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }
}
