<?php

namespace App\Models;

use App\Enums\PunchEventType;
use App\Models\Concerns\ScopesToOutlets;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of the punch audit trail.
 *
 * Append-only, and deliberately without `updated_at`: a trail that records when it was
 * modified is an audit log with an audit problem of its own. Nothing in the application
 * updates or deletes these rows.
 *
 * Written for FAILED attempts as well as successful ones. A refusal that left no trace
 * would make "I clocked in and it says I didn't" unanswerable, which is the single most
 * common attendance complaint.
 */
#[Fillable([
    'employee_id', 'outlet_id', 'outlet_token_id', 'time_entry_id',
    'event', 'ip_address', 'user_agent', 'meta', 'created_at',
])]
class PunchEvent extends Model
{
    use ScopesToOutlets;

    /**
     * No `updated_at`: rows are written once and never touched.
     *
     * Declared so Eloquent does not try to set a column that does not exist.
     */
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'event' => PunchEventType::class,
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Write a trail row.
     *
     * A static like `Anomaly::raise`, so recording is a single call at the point the
     * thing happens and no caller has to remember which columns to fill in.
     *
     * @param  array<string, mixed>  $meta
     */
    public static function record(
        PunchEventType $event,
        ?int $employeeId = null,
        ?int $outletId = null,
        ?int $tokenId = null,
        ?int $timeEntryId = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
        array $meta = [],
    ): self {
        return static::create([
            'employee_id' => $employeeId,
            'outlet_id' => $outletId,
            'outlet_token_id' => $tokenId,
            'time_entry_id' => $timeEntryId,
            'event' => $event,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent !== null ? mb_substr($userAgent, 0, 255) : null,
            'meta' => $meta === [] ? null : $meta,
            'created_at' => now(),
        ]);
    }

    public function isFailure(): bool
    {
        return $this->event->isFailure();
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

    /** @return BelongsTo<OutletToken, $this> */
    public function outletToken(): BelongsTo
    {
        return $this->belongsTo(OutletToken::class);
    }

    /** @return BelongsTo<TimeEntry, $this> */
    public function timeEntry(): BelongsTo
    {
        return $this->belongsTo(TimeEntry::class);
    }

    public function scopeFailures(Builder $query): Builder
    {
        return $query->whereIn('event', [
            PunchEventType::PIN_FAILED->value,
            PunchEventType::PIN_LOCKED->value,
            PunchEventType::REJECTED->value,
        ]);
    }
}
