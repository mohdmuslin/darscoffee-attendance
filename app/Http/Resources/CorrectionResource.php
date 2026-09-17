<?php

namespace App\Http\Resources;

use App\Models\AttendanceCorrection;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A correction, for the audit view.
 *
 * `original_values` and `changes` are both sent, because the value of this record is the
 * COMPARISON between them. Sending only the new values would make it a log of what
 * happened rather than an explanation of what changed.
 *
 * @mixin AttendanceCorrection
 */
class CorrectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action->value,
            'action_label' => $this->action->label(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'reason' => $this->reason,

            'time_entry_id' => $this->time_entry_id,

            'employee' => $this->whenLoaded('employee', fn () => [
                'id' => $this->employee->id,
                'name' => $this->employee->name,
                'employee_code' => $this->employee->employee_code,
            ]),

            'outlet' => $this->whenLoaded('outlet', fn () => [
                'id' => $this->outlet->id,
                'name' => $this->outlet->name,
            ]),

            'requester' => $this->whenLoaded('requester', fn () => [
                'id' => $this->requester->id,
                'name' => $this->requester->name,
            ]),

            'reviewer' => $this->whenLoaded('reviewer', fn () => $this->reviewer === null ? null : [
                'id' => $this->reviewer->id,
                'name' => $this->reviewer->name,
            ]),

            /*
             * Rendered field by field rather than passed through raw, so the console can
             * show "09:03 → 09:15" without re-implementing the timezone handling. Times
             * go out as ISO strings and the client formats them.
             */
            'original_values' => $this->readable($this->original_values),
            'changes' => $this->readable($this->changes),

            'is_pending' => $this->isPending(),

            'requested_at' => $this->created_at?->toIso8601String(),
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'review_note' => $this->review_note,
        ];
    }

    /**
     * Normalise stored values for display.
     *
     * Stored as JSON, so a datetime arrives as a string — it is re-parsed and returned
     * as ISO 8601 so the browser renders it in the viewer's own timezone instead of
     * showing whatever offset happened to be written down.
     *
     * @param  array<string, mixed>|null  $values
     * @return array<string, mixed>|null
     */
    private function readable(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        foreach (['started_at', 'ended_at'] as $field) {
            if (! empty($values[$field])) {
                $values[$field] = CarbonImmutable::parse($values[$field])->toIso8601String();
            }
        }

        return $values;
    }
}
