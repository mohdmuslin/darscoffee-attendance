<?php

namespace App\Models;

use App\Enums\OutletTokenMode;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An outlet (a shop). Three today; the owner can add more.
 */
#[Fillable([
    'code', 'name', 'address', 'timezone', 'token_mode',
    'qr_ttl_seconds', 'requires_photo', 'is_active',
])]
class Outlet extends Model
{
    protected function casts(): array
    {
        return [
            /*
             * Cast so `token_mode` is the enum everywhere, not a bare string.
             * Without this, `$outlet->token_mode->expires()` fails at runtime —
             * and a seeder that stores the enum's value silently produces a string
             * that looks correct in the database.
             */
            'token_mode' => OutletTokenMode::class,
            'qr_ttl_seconds' => 'integer',
            'requires_photo' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<Employee, $this> */
    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    /** Console users who may see this outlet. */
    /** @return BelongsToMany<User, $this> */
    public function managers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'outlet_user');
    }

    /** @return HasMany<OutletToken, $this> */
    public function tokens(): HasMany
    {
        return $this->hasMany(OutletToken::class);
    }
}
