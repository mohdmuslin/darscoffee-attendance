<?php

namespace App\Http\Resources;

use App\Models\Shift;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A roster entry, in the outlet's own local time.
 *
 * Times go out BOTH as ISO instants and as local wall-clock strings, because the two
 * consumers want different things and neither should be re-deriving the other:
 *
 *  - `starts_at` (ISO) is what the console sorts, compares and renders in the viewer's
 *    timezone.
 *  - `starts_local` is what a `datetime-local` input needs, and what a printed roster
 *    shows. Reconstructing it in the browser means re-implementing the outlet timezone,
 *    which is exactly where an eight-hour error creeps in.
 *
 * @mixin Shift
 */
class ShiftResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $timezone = $this->outlet?->timezone ?? config('attendance.business_timezone');

        return [
            'id' => $this->id,

            'employee' => $this->whenLoaded('employee', fn () => [
                'id' => $this->employee->id,
                'name' => $this->employee->name,
                'employee_code' => $this->employee->employee_code,
            ]),

            'outlet' => $this->whenLoaded('outlet', fn () => [
                'id' => $this->outlet->id,
                'name' => $this->outlet->name,
                'code' => $this->outlet->code,
                'timezone' => $this->outlet->timezone,
            ]),

            'outlet_id' => $this->outlet_id,
            'employee_id' => $this->employee_id,

            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),

            'starts_local' => $this->starts_at?->setTimezone($timezone)->format('Y-m-d\TH:i'),
            'ends_local' => $this->ends_at?->setTimezone($timezone)->format('Y-m-d\TH:i'),

            // The local calendar date, which is what groups a roster into days. Deriving it
            // from the ISO string in the browser would use the VIEWER's timezone and put an
            // evening shift under tomorrow for anyone travelling.
            'local_date' => $this->starts_at?->setTimezone($timezone)->toDateString(),

            'duration_seconds' => $this->durationSeconds(),
            'position' => $this->position,
            'note' => $this->note,

            'is_cancelled' => $this->isCancelled(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),

            'created_by' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
