<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A short-lived authorisation to punch.
 *
 * Issued once a PIN has been checked against an outlet code, so break and clock-out
 * do not require retyping it. Scope is deliberately narrow: this session works only
 * for ONE employee, and only for their own clock actions.
 */
#[Fillable([
    'employee_id', 'outlet_id', 'outlet_token_id', 'token_hash',
    'expires_at', 'last_used_at', 'device', 'ip_address',
])]
class PunchSession extends Model
{
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
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

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /** Hash a session token for storage and lookup. */
    public static function hashToken(string $plain): string
    {
        return hash('sha256', $plain);
    }

    public static function newToken(): string
    {
        return Str::random(64);
    }
}
