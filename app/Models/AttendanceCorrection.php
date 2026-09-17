<?php

namespace App\Models;

use App\Enums\CorrectionAction;
use App\Enums\CorrectionStatus;
use App\Models\Concerns\ScopesToOutlets;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A change to recorded time, with the original kept.
 *
 * Reports read the resulting entry, not this table, but this is where the explanation
 * lives: after a correction is applied the timesheet no longer matches what the
 * employee punched, and this row is why.
 *
 * `original_values` is what makes it auditable rather than merely logged. Revising a
 * correction snapshots again from the entry as it stands, so a sequence of edits still
 * leads back to the original punch one step at a time.
 */
#[Fillable([
    'time_entry_id', 'employee_id', 'outlet_id', 'action',
    'original_values', 'changes', 'reason', 'status',
    'requested_by', 'reviewed_by', 'reviewed_at', 'review_note',
])]
class AttendanceCorrection extends Model
{
    /*
     * Scoped by outlet, like every other listing in the console. A manager must not be
     * able to read — or quietly amend — a correction raised at another outlet, and
     * `outlet_id` is stored on this table precisely so that filter is a plain indexed
     * column rather than a join through time_entries.
     */
    use ScopesToOutlets;

    protected function casts(): array
    {
        return [
            'action' => CorrectionAction::class,
            'status' => CorrectionStatus::class,
            'original_values' => 'array',
            'changes' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * Fields a correction may alter.
     *
     * An allow-list, not a blocklist: `changes` arrives from a request, and a
     * blocklist would mean any column added later is writable by default — including
     * `employee_id` or `outlet_id`, which would move an entry to another person or
     * another outlet and quietly defeat the scoping that is this application's
     * security boundary.
     *
     * `status` is deliberately NOT here. The correction path sets it to CORRECTED as a
     * consequence of applying a change; letting a request set it would allow marking an
     * entry corrected with no audit row saying why.
     *
     * @return array<int, string>
     */
    public static function correctableFields(): array
    {
        return ['started_at', 'ended_at', 'type', 'note'];
    }

    public function isPending(): bool
    {
        return $this->status === CorrectionStatus::PENDING;
    }

    /**
     * Whether this correction is waiting on the OWNER.
     *
     * A correction raised by an owner is acting on their own authority and needs no
     * second signature, so it is approved as it is created.
     */
    public function needsReview(): bool
    {
        return $this->isPending();
    }

    /** @return BelongsTo<TimeEntry, $this> */
    public function timeEntry(): BelongsTo
    {
        return $this->belongsTo(TimeEntry::class);
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

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** Only corrections still waiting for someone to look at them. */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', CorrectionStatus::PENDING->value);
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', CorrectionStatus::APPROVED->value);
    }
}
