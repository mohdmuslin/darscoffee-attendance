<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Recording a punch that never happened.
 *
 * Separate from StoreCorrectionRequest because the requirements differ: this one has no
 * entry to amend, so both timestamps are mandatory and the outlet must be named.
 */
class StoreMissingPunchRequest extends FormRequest
{
    public function authorize(): bool
    {
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

            // Both ends are required: a segment with no end is an open one, and creating
            // one by hand would collide with the one-open-segment invariant.
            'started_at' => ['required', 'date'],
            'ended_at' => ['required', 'date', 'after:started_at'],

            'type' => ['sometimes', Rule::in(['work', 'break'])],
            'note' => ['sometimes', 'nullable', 'string', 'max:255'],

            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ended_at.after' => 'The shift has to end after it starts.',
            'reason.required' => 'Say why this punch is being added.',
        ];
    }
}
