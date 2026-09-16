<?php

namespace App\Models;

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
