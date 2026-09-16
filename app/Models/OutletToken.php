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

    /**
     * Usable right now: not revoked, not past its window, not before it existed.
     *
     * STRICT, with no clock-skew tolerance, because the server's own clock is
     * authoritative here — there is no remote clock to disagree with. A revoked
     * code must stop working the instant it is revoked; the whole point of
     * reprinting after a leak is that the old sheet dies immediately.
     */
    public function isLive(): bool
    {
        if ($this->revoked_at !== null) {
            return false;
        }

        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return false;
        }

        return true;
    }

    /**
     * Whether this code was usable AT A GIVEN MOMENT.
     *
     * Needed for offline punches: a queued punch carries the time it actually
     * happened, so validity must be judged against that instant rather than
     * against "now". Judging only against now would reject every offline punch
     * made on a rotating outlet, because the code will have rotated by the time
     * the phone regains signal.
     *
     * CLOCK SKEW TOLERANCE. The timestamp here comes from the employee's phone,
     * and phone clocks are not reliably in step with the server. Without slack, a
     * phone running even slightly behind would have its legitimate punches refused
     * as "before this code existed" — and the employee would be standing at the
     * counter unable to clock in, with nothing they could do about it.
     *
     * The tolerance is deliberately small (a minute). It absorbs clock drift while
     * still refusing a punch back-dated to before the code was issued, which is
     * the attack that matters: obtaining today's code and claiming to have worked
     * yesterday.
     */
    private const SKEW_TOLERANCE_SECONDS = 60;

    public function wasLiveAt(\DateTimeInterface $at): bool
    {
        $at = CarbonImmutable::instance($at);
        $tolerance = self::SKEW_TOLERANCE_SECONDS;

        // Punched before the code existed, beyond the skew allowance.
        if ($this->created_at !== null && $at->lessThan($this->created_at->subSeconds($tolerance))) {
            return false;
        }

        // Punched after revocation, beyond the skew allowance.
        if ($this->revoked_at !== null && $at->greaterThan($this->revoked_at->addSeconds($tolerance))) {
            return false;
        }

        if ($this->expires_at !== null && $at->greaterThan($this->expires_at->addSeconds($tolerance))) {
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
