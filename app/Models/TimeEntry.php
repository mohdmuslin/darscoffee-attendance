<?php

namespace App\Models;

use App\Enums\TimeEntryStatus;
use App\Enums\TimeEntryType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attendance segment — a period of WORK or of BREAK.
 *
 * Storing work and breaks as separate rows rather than a break column means worked
 * time is a plain sum with nothing to subtract, so there is no arithmetic to get
 * wrong. A one-hour lunch can never become an hour of overtime.
 */
#[Fillable([
    'client_uuid', 'employee_id', 'outlet_id', 'shift_id', 'type',
    'started_at', 'ended_at', 'duration_seconds',
    'started_photo_path', 'ended_photo_path',
    'started_token_id', 'ended_token_id', 'started_device',
    'business_date', 'status', 'note', 'is_offline_sync',
])]
class TimeEntry extends Model
{
    protected function casts(): array
    {
        return [
            'type' => TimeEntryType::class,
            'status' => TimeEntryStatus::class,
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'business_date' => 'date',
            'duration_seconds' => 'integer',
            'is_offline_sync' => 'boolean',
        ];
    }

    /**
     * Duration in seconds.
     *
     * Derived from the timestamps rather than trusted from the cached column, so a
     * stale cache can never inflate hours. The cached value exists only to make
     * reporting cheaper.
     */
    public function durationSeconds(): int
    {
        if ($this->ended_at === null) {
            return (int) $this->started_at->diffInSeconds(now());
        }

        return (int) $this->started_at->diffInSeconds($this->ended_at);
    }

    /**
     * Close the segment now (or at a given moment), caching the duration.
     *
     * An open segment has no meaningful duration — a forgotten clock-out would
     * otherwise accumulate hours indefinitely — so this is the only path that
     * makes an entry countable.
     */
    public function close(?\DateTimeInterface $at = null, ?string $photoPath = null, ?int $tokenId = null): void
    {
        $endedAt = $at ? CarbonImmutable::instance($at) : now();

        $this->forceFill([
            'ended_at' => $endedAt,
            'duration_seconds' => (int) $this->started_at->diffInSeconds($endedAt),
            'ended_photo_path' => $photoPath ?? $this->ended_photo_path,
            'ended_token_id' => $tokenId ?? $this->ended_token_id,
            'status' => TimeEntryStatus::CLOSED,
        ])->save();
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

    /** @return BelongsTo<Shift, $this> */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    /** Segments that have not been closed. At most one per employee. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('ended_at');
    }

    public function scopeClosed(Builder $query): Builder
    {
        return $query->whereNotNull('ended_at');
    }

    /**
     * Worked segments only.
     *
     * This is the scope every hours calculation must go through: breaks are
     * deliberately excluded from pay.
     */
    public function scopeWork(Builder $query): Builder
    {
        return $query->where('type', TimeEntryType::WORK->value);
    }

    public function scopeBreak(Builder $query): Builder
    {
        return $query->where('type', TimeEntryType::BREAK->value);
    }

    public function scopeForBusinessDate(Builder $query, string $date): Builder
    {
        /*
         * whereDate, NOT where.
         *
         * The `date` cast makes `business_date` a Carbon, which is convenient for
         * grouping, but Laravel formats a Carbon using the model's $dateFormat
         * ('Y-m-d H:i:s') when binding it. MySQL's DATE column silently truncates that
         * to the day, so `where('business_date', '2026-09-18')` appears to work —
         * while SQLite stores the full datetime string and never matches. Every
         * business-day query would therefore return nothing under SQLite and pass on
         * MySQL, which is the worst possible split: the tests would be lying.
         */
        return $query->whereDate('business_date', $date);
    }
}
