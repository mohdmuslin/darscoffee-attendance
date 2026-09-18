<?php

namespace App\Http\Resources;

use App\Models\Employee;
use App\Services\PhotoService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Employee
 */
class EmployeeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $photos = app(PhotoService::class);

        return [
            'id' => $this->id,
            'employee_code' => $this->employee_code,
            'name' => $this->name,
            'phone' => $this->phone,

            /*
             * MASKED, never the full number. An IC number is sensitive personal
             * data; the full value is only needed on the edit form, which fetches it
             * through a dedicated owner-only endpoint.
             */
            'ic_number_masked' => $this->maskedIcNumber(),

            'pay_basis' => $this->pay_basis?->value,
            'pay_basis_label' => $this->pay_basis?->label(),

            'photo_url' => $photos->temporaryUrl($this->photo_path),

            // Whether a PIN exists, never the PIN or its hash.
            'has_pin' => $this->hasPin(),
            'pin_set_at' => $this->pin_set_at?->toIso8601String(),
            'pin_locked' => $this->isPinLocked(),

            'user_id' => $this->user_id,
            'has_login' => $this->user_id !== null,

            'joined_at' => $this->joined_at?->toDateString(),
            'resigned_at' => $this->resigned_at?->toDateString(),
            'is_active' => $this->is_active,

            'consent_at' => $this->consent_at?->toIso8601String(),
            'consent_method' => $this->consent_method?->value,
            'consent_method_label' => $this->consent_method?->label(),
            'consent_version' => $this->consent_version,
            'consent_note' => $this->consent_note,
            'consent_recorded_by' => $this->whenLoaded(
                'consentRecorder',
                fn () => $this->consentRecorder?->name,
            ),
            'consent_withdrawn_at' => $this->consent_withdrawn_at?->toIso8601String(),
            'consent_withdrawal_note' => $this->consent_withdrawal_note,

            /*
             * The three states the console renders differently, resolved server-side so the
             * client does not re-derive the rules and drift from them. In particular
             * `has_consent` is false after a withdrawal, which a naive check on
             * `consent_at !== null` would get wrong.
             */
            'has_consent' => $this->hasConsent(),
            'consent_withdrawn' => $this->hasWithdrawnConsent(),
            'needs_consent' => $this->needsConsent(),

            'outlets' => $this->whenLoaded('outlets', fn () => $this->outlets->map(fn ($outlet) => [
                'id' => $outlet->id,
                'code' => $outlet->code,
                'name' => $outlet->name,
                'is_primary' => (bool) $outlet->pivot->is_primary,
            ])),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
