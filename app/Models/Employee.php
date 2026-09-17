<?php

namespace App\Models;

use App\Enums\PayBasis;
use App\Models\Concerns\ScopesToOutlets;
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
 * Having a `users` row means you can SIGN IN; having this row means you WORK SHIFTS
 * and are paid. A person is often both, and `user_id` links them, but they are
 * different facts and live in different tables.
 */
#[Fillable([
    'employee_code', 'name', 'phone', 'ic_number', 'pay_basis',
    'user_id', 'joined_at', 'resigned_at', 'is_active',
    'photo_path', 'consent_at', 'consent_note',
])]
#[Hidden(['pin_hash', 'ic_number'])]
class Employee extends Model
{
    use ScopesToOutlets, SoftDeletes;

    /**
     * An employee has no `outlet_id` column — they are mapped to outlets through
     * the `employee_outlet` pivot, because staff cover between outlets.
     *
     * So the scope is a relation filter rather than a column comparison. Getting
     * this wrong would either leak every employee or hide all of them, which is why
     * the trait exposes an override for exactly this case.
     *
     * @param  Builder<static>  $query
     * @param  array<int>  $outletIds
     * @return Builder<static>
     */
    protected function applyOutletScope(Builder $query, array $outletIds): Builder
    {
        return $query->whereHas(
            'outlets',
            fn (Builder $q) => $q->whereIn('outlets.id', $outletIds)
        );
    }

    /**
     * The outlet used for a single-record scope check.
     *
     * An employee may belong to several outlets; any of them grants visibility, so
     * this returns the primary if set and otherwise the first. `isVisibleTo()` uses
     * this, and the query scope above is what actually filters lists.
     */
    protected function outletScopeOutletId(): ?int
    {
        return $this->outlets
            ->sortByDesc(fn ($outlet) => $outlet->pivot->is_primary)
            ->first()?->id;
    }

    /** Whether the given user may see this employee, given any shared outlet. */
    public function isVisibleTo(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        $outletIds = $user->visibleOutletIds();

        // null means an owner: unrestricted.
        if ($outletIds === null) {
            return true;
        }

        if ($outletIds === []) {
            return false;
        }

        return $this->outlets->pluck('id')->intersect($outletIds)->isNotEmpty();
    }

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
     * time-based rather than permanent so a genuine fat-finger does not need an
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

    /**
     * IC number is sensitive personal data, so it is written explicitly rather than
     * by mass assignment through the form. Kept separate so the encryption point is
     * obvious and greppable.
     */
    public function setIcNumber(?string $ic): void
    {
        $this->forceFill(['ic_number' => $ic === null ? null : encrypt($ic)])->save();
    }

    public function icNumber(): ?string
    {
        if (blank($this->ic_number)) {
            return null;
        }

        try {
            return decrypt($this->ic_number);
        } catch (\Throwable) {
            // A value encrypted with a rotated key cannot be read; surface nothing
            // rather than throwing into a listing screen.
            return null;
        }
    }

    /** Masked for display, e.g. 900101-**-5566. */
    public function maskedIcNumber(): ?string
    {
        $ic = $this->icNumber();

        if ($ic === null) {
            return null;
        }

        return strlen($ic) > 4
            ? str_repeat('*', max(0, strlen($ic) - 4)).substr($ic, -4)
            : str_repeat('*', strlen($ic));
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

    /**
     * Changes to this employee's recorded time.
     *
     * On the employee rather than reached through time_entries, because a correction for
     * an ADDED punch has no entry when it is raised — and the audit view has to show it
     * either way.
     *
     * @return HasMany<AttendanceCorrection, $this>
     */
    public function corrections(): HasMany
    {
        return $this->hasMany(AttendanceCorrection::class);
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
