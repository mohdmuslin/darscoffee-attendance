<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A one-time SSO assertion issued for another app to redeem.
 *
 * Stored HASHED — the plaintext exists only in the redirect that carries it. See
 * `App\Services\SsoTokenService` for issuing and redeeming, and `docs/sso.md` for
 * why Attendance issues rather than consumes these.
 */
#[Fillable(['user_id', 'token_hash', 'audience', 'claims', 'expires_at', 'consumed_at', 'consumed_by_ip'])]
class SsoToken extends Model
{
    protected function casts(): array
    {
        return [
            'claims' => 'array',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    public function isConsumed(): bool
    {
        return $this->consumed_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
