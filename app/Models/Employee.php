<?php

namespace App\Models;

use App\Enums\PayBasis;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Hash;

/**
 * An employee — someone who clocks in.
 *
 * Not a login user. Kitchen crew clock in every day and never sign in to
 * anything, so forcing a user account on them would be wrong; the few who do
 * (managers) are linked through `user_id`.
 */
#[Fillable([
    'employee_code', 'name', 'phone', 'ic_number', 'pay_basis',
    'user_id', 'joined_at', 'resigned_at', 'is_active',
    'photo_path', 'consent_at', 'consent_note',
])]
#[Hidden(['pin_hash', 'ic_number'])]
class Employee extends Model
{
    use SoftDeletes;

    /**
     * PINs are hashed with bcrypt, like passwords.
     *
     * A 4-6 digit secret is weak on its own; it is acceptable here only because it
     * is one of three factors (outlet code, PIN, photo) and is rate-limited with
     * lockout. Storing it in plain text would make a database leak immediately
     * exploitable for impersonation.
     */
    public function setPin(string $pin): void
    {
        $this->forceFill([
            'pin_hash' => Hash::make($pin),
            'pin_set_at' => now(),
            'pin_failed_attempts' => 0,
            'pin_locked_until' => null,
        ])->save();
    }

    /** Timing-safe PIN check. bcrypt's own comparison is constant-time. */
    public function verifyPin(string $pin): bool
    {
        if (blank($this->pin_hash)) {
            return false;
        }

        return Hash::check($pin, $this->pin_hash);
    }

    public function hasPin(): bool
    {
        return filled($this->pin_hash);
    }

    /** Whether the PIN is currently locked out after repeated failures. */
    public function isPinLocked(): bool
    {
        return $this->pin_locked_until !== null && $this->pin_locked_until->isFuture();
    }

    /**
     * Record a failed attempt, locking the PIN after too many.
     *
     * Without this, a 4-digit PIN falls in seconds to a script. The lockout is
     * time-based rather than permanent so a genuine fat-finger does not require an
     * owner to intervene.
     */
    public function recordPinFailure(int $maxAttempts, int $lockoutMinutes): void
    {
        $attempts = $this->pin_failed_attempts + 1;

        $this->forceFill([
            'pin_failed_attempts' => $attempts,
            'pin_locked_until' => $attempts >= $maxAttempts
                ? now()->addMinutes($lockoutMinutes)
                : $this->pin_locked_until,
        ])->save();
    }

    public function clearPinFailures(): void
    {
        if ($this->pin_failed_attempts !== 0 || $this->pin_locked_until !== null) {
            $this->forceFill([
                'pin_failed_attempts' => 0,
                'pin_locked_until' => null,
            ])->save();
        }
    }

    protected function casts(): array
    {
        return [
            'pay_basis' => PayBasis::class,
            'joined_at' => 'date',
            'resigned_at' => 'date',
            'is_active' => 'boolean',
            'pin_set_at' => 'datetime',
            'pin_locked_until' => 'datetime',
            'consent_at' => 'datetime',
        ];
    }

    /** @return BelongsToMany<Outlet, $this> */
    public function outlets(): BelongsToMany
    {
        return $this->belongsToMany(Outlet::class, 'employee_outlet')
            ->withPivot('is_primary');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<TimeEntry, $this> */
    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }

    /** @return HasMany<Shift, $this> */
    public function shifts(): HasMany
    {
        return $this->hasMany(Shift::class);
    }

    /** Only active employees may clock in. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Employees permitted to punch at this outlet. */
    public function scopeForOutlet(Builder $query, int $outletId): Builder
    {
        return $query->whereHas('outlets', fn ($q) => $q->where('outlets.id', $outletId));
    }
}
