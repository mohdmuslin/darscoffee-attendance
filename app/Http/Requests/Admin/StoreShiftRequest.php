<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Creating or amending a roster entry.
 *
 * Times arrive as local wall-clock strings (`2026-09-21T09:00`) rather than with an offset,
 * because that is what a `datetime-local` input produces and what a manager means: "nine in
 * the morning at this outlet". The controller attaches the outlet's timezone; asking the
 * browser to do it would mean trusting the phone's clock and its timezone setting.
 */
class StoreShiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authorisation happens in the controller, which needs the outlet resolved first.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'outlet_id' => ['required', 'integer', 'exists:outlets,id'],

            /*
             * `date_format` rather than `date`, because `date` would accept an offset or a
             * full ISO string and silently reinterpret it in the app timezone (UTC) — which
             * is exactly the eight-hour shift that made every lateness figure wrong.
             * Requiring the bare local form means the controller alone decides the zone.
             */
            'starts_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'ends_at' => ['required', 'date_format:Y-m-d\TH:i', 'after:starts_at'],

            'position' => ['nullable', 'string', 'max:50'],
            'note' => ['nullable', 'string', 'max:255'],

            /*
             * Whether to accept a shift that collides with one this person already has.
             * Off by default so an accidental double-booking is refused, but available
             * because two shifts in a day is legitimate when someone covers a split.
             */
            'allow_overlap' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ends_at.after' => 'A shift has to end after it starts.',
            'starts_at.date_format' => 'Give the shift a start date and time.',
            'ends_at.date_format' => 'Give the shift an end date and time.',
        ];
    }
}
