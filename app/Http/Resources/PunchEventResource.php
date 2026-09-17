<?php

namespace App\Http\Resources;

use App\Models\PunchEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One line of the punch audit trail.
 *
 * @mixin PunchEvent
 */
class PunchEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event' => $this->event->value,
            'event_label' => $this->event->label(),
            'is_failure' => $this->event->isFailure(),

            'employee' => $this->whenLoaded('employee', fn () => $this->employee === null ? null : [
                'id' => $this->employee->id,
                'name' => $this->employee->name,
                'employee_code' => $this->employee->employee_code,
            ]),

            'outlet' => $this->whenLoaded('outlet', fn () => $this->outlet === null ? null : [
                'id' => $this->outlet->id,
                'name' => $this->outlet->name,
            ]),

            'time_entry_id' => $this->time_entry_id,
            'ip_address' => $this->ip_address,
            'meta' => $this->meta,
            'occurred_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
