<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Copying a roster week into another week or employee.
 *
 * "Copy last week" is the single action a manager will use most, and the one most likely
 * to go wrong in bulk — so the shape is narrow on purpose: a source range, a target range,
 * and an explicit list of what may happen to anything already in the way.
 */
class CopyShiftsRequest extends FormRequest
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
            // Only one of these: either shifting a week forward, or giving one person
            // someone else's roster. Allowing both at once would make "which is it?" a
            // question the audit record could not answer.
            'source_from' => ['required', 'date_format:Y-m-d'],
            'source_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:source_from'],

            'target_from' => ['required', 'date_format:Y-m-d'],

            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],

            /*
             * What to do about shifts that already exist in the target range.
             * 'skip' is the default because the safe failure is to leave something alone;
             * 'replace' has to be asked for by name.
             */
            'on_conflict' => ['sometimes', 'in:skip,replace'],

            'outlet_id' => ['nullable', 'integer', 'exists:outlets,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'source_to.after_or_equal' => 'The source range ends before it starts.',
        ];
    }
}
