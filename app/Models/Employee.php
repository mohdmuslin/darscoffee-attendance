<?php

namespace App\Models;

use App\Enums\ConsentMethod;
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
    'consent_recorded_by', 'consent_version', 'consent_method',
    'consent_withdrawn_at', 'consent_withdrawal_note',
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
            'consent_method' => ConsentMethod::class,
            'consent_withdrawn_at' => 'datetime',
        ];
    }

    /** The manager who took the consent, so a claim of consent can be attributed. */
    public function consentRecorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'consent_recorded_by');
    }

    /**
     * Whether there is valid consent to hold this person's photographs right now.
     *
     * Consent that was withdrawn is NOT consent, and the check has to be this explicit
     * rather than a truthiness test on `consent_at` — a withdrawn consent still has a
     * `consent_at`, and treating that as a yes would mean a withdrawal did nothing.
     */
    public function hasConsent(): bool
    {
        return $this->consent_at !== null && $this->consent_withdrawn_at === null;
    }

    /** Whether consent was given and later taken back. */
    public function hasWithdrawnConsent(): bool
    {
        return $this->consent_at !== null && $this->consent_withdrawn_at !== null;
    }

    /** True when this person's photograph is held with no consent on record. */
    public function needsConsent(): bool
    {
        return $this->photo_path !== null && ! $this->hasConsent();
    }

    /**
     * Whether a photograph MAY be taken of this person, at any outlet.
     *
     * This is the consent question on its own, separate from whether the outlet wants a
     * photograph. The two are different and conflating them is a real bug:
     *
     *   `isPhotographRequired()` — must a photo accompany the punch? Only at an outlet that
     *                             asks for one, and only for someone who may be photographed.
     *
     *   `mayBePhotographed()`   — is a photo of this person allowed at all?
     *
     * The distinction matters at an outlet that does NOT require photos: an employee may
     * still choose to supply one, and it should be stored. Treating "not required" as "not
     * permitted" would silently throw away a photograph the person volunteered, which helps
     * nobody and looks like the camera is broken.
     *
     * TWO RULES, and the difference between them is the point:
     *
     *  - A WITHDRAWAL is always honoured. Someone who has actively taken their consent back
     *    is never photographed again, whatever the settings say. Their withdrawal was an
     *    explicit instruction, and no default can override it.
     *
     *  - A MISSING RECORD is refused only when the owner has turned that on. It cannot be
     *    the default, because every existing employee has no consent row — enabling it by
     *    default would stop photographs being taken at all, silently disabling the
     *    anti-buddy-punching control the day it shipped. See
     *    `Setting::REQUIRE_CONSENT_FOR_PHOTOS`.
     */
    public function mayBePhotographed(): bool
    {
        if ($this->hasWithdrawnConsent()) {
            return false;
        }

        if ($this->hasConsent()) {
            return true;
        }

        // No record either way: the owner decides whether that is disqualifying.
        return ! Setting::bool(Setting::REQUIRE_CONSENT_FOR_PHOTOS, false);
    }

    /**
     * Whether a photograph is MANDATORY at this outlet for this person.
     *
     * Consent can only ever narrow what the outlet asks for, never widen it: a photo is never
     * demanded at an outlet that does not want one, and never demanded of someone who may not
     * be photographed.
     */
    public function isPhotographRequired(Outlet $outlet): bool
    {
        return $outlet->requires_photo && $this->mayBePhotographed();
    }

    /**
     * Record consent, or record it again after a withdrawal.
     *
     * Re-recording after withdrawal CLEARS the withdrawal rather than leaving both
     * timestamps set. Someone who withdrew and then changed their mind has consented, and
     * an implementation that kept `consent_withdrawn_at` would report them as unconsented
     * for ever.
     */
    public function recordConsent(
        ConsentMethod $method,
        ?User $recordedBy = null,
        ?string $version = null,
        ?string $note = null,
    ): void {
        $this->forceFill([
            'consent_at' => now(),
            'consent_method' => $method,
            'consent_recorded_by' => $recordedBy?->id ?? $this->consent_recorded_by,
            'consent_version' => $version ?? $this->consent_version,
            'consent_note' => $note ?? $this->consent_note,
            // A fresh consent replaces any previous withdrawal.
            'consent_withdrawn_at' => null,
            'consent_withdrawal_note' => null,
        ])->save();
    }

    /**
     * Withdraw consent.
     *
     * The original `consent_at` is left alone. Blanking it would imply consent was never
     * given, which would call into question the lawfulness of every photograph taken while
     * it was — and those were taken lawfully.
     *
     * Withdrawal stops FUTURE photographs. It does not by itself delete past ones: their
     * retention is a separate question, handled by the retention policy and, where the
     * person asks, by a deletion request the owner can act on.
     */
    public function withdrawConsent(?string $note = null): void
    {
        $this->forceFill([
            'consent_withdrawn_at' => now(),
            'consent_withdrawal_note' => $note,
        ])->save();
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
