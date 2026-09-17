<?php

namespace App\Models;

use App\Enums\AnomalySeverity;
use App\Enums\AnomalyType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A flag raised on a punch, for a manager to review.
 *
 * See AnomalyType for why these exist: no software can prove who held the phone, so
 * the design goal is that anything odd is visible rather than prevented.
 */
#[Fillable([
    'time_entry_id', 'type', 'severity', 'detail',
    'reviewed_by', 'reviewed_at', 'review_note',
])]
class Anomaly extends Model
{
    protected function casts(): array
    {
        return [
            'type' => AnomalyType::class,
            'severity' => AnomalySeverity::class,
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * Raise a flag, storing the type's own severity.
     *
     * Severity lives on the enum rather than at each call site, so every check gets
     * the same loudness and no caller can quietly downgrade one.
     */
    public static function raise(TimeEntry $entry, AnomalyType $type, ?string $detail = null): self
    {
        return static::create([
            'time_entry_id' => $entry->id,
            'type' => $type,
            'severity' => $type->severity(),
            'detail' => $detail,
        ]);
    }

    public function isReviewed(): bool
    {
        return $this->reviewed_at !== null;
    }

    /** @return BelongsTo<TimeEntry, $this> */
    public function timeEntry(): BelongsTo
    {
        return $this->belongsTo(TimeEntry::class);
    }
}
