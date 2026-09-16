<?php

namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * A console login: the owner, or a manager.
 *
 * NOT an employee. Most people who clock in have no account here; the few who
 * need both (a manager who also works shifts) are linked to an Employee row
 * through `employees.user_id`.
 */
#[Fillable(['name', 'email', 'password', 'role', 'is_active', 'last_login_at'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    public function isOwner(): bool
    {
        return $this->role === UserRole::OWNER;
    }

    public function isManager(): bool
    {
        return $this->role === UserRole::MANAGER;
    }

    /** @return BelongsToMany<Outlet, $this> */
    public function outlets(): BelongsToMany
    {
        return $this->belongsToMany(Outlet::class, 'outlet_user');
    }

    /** The employee record for a manager who also works shifts, if any. */
    /** @return HasOne<Employee, $this> */
    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class);
    }

    /**
     * The outlet ids this user may act on.
     *
     * Returns NULL for an owner, meaning "every outlet". Callers must treat null as
     * unrestricted rather than as "none" — the distinction matters enough to make
     * it explicit here instead of returning an empty array that a careless
     * `in_array` would read as full access.
     *
     * A manager with no rows in outlet_user gets an empty array, so the check
     * FAILS CLOSED: forgetting to grant access locks someone out rather than
     * exposing every outlet's staff and photos.
     *
     * @return array<int>|null
     */
    public function visibleOutletIds(): ?array
    {
        if ($this->role->seesAllOutlets()) {
            return null;
        }

        return $this->outlets()->pluck('outlets.id')->all();
    }

    /** Whether this user may see the given outlet at all. */
    public function canAccessOutlet(int $outletId): bool
    {
        $visible = $this->visibleOutletIds();

        return $visible === null || in_array($outletId, $visible, true);
    }
}
