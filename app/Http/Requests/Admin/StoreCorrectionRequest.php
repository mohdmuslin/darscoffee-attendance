<?php

namespace App\Http\Requests\Admin;

use App\Models\AttendanceCorrection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Raising a correction against an existing entry.
 *
 * Validation lives here rather than in the controller so the rules are one readable
 * list, and so the allow-list of correctable fields has exactly one home —
 * `AttendanceCorrection::correctableFields()` — shared with the service that applies
 * the change.
 */
class StoreCorrectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        /*
         * Authorisation happens in the controller against the resolved entry, because it
         * depends on the entry's outlet and this request has not resolved one yet.
         */
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'changes' => ['required', 'array', 'min:1'],
            /*
             * Restricting the KEYS is what stops a caller moving an entry to another
             * employee or outlet. The service re-checks the same allow-list, so this is
             * the friendly message rather than the only defence.
             */
            'changes.started_at' => ['sometimes', 'date'],
            'changes.ended_at' => ['sometimes', 'nullable', 'date'],
            'changes.type' => ['sometimes', Rule::in(['work', 'break'])],
            'changes.note' => ['sometimes', 'nullable', 'string', 'max:255'],

            /*
             * Required, and deliberately not nullable. An unexplained correction is
             * exactly what makes a timesheet untrustworthy, so refusing it here is the
             * point rather than an inconvenience.
             */
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ];
    }

    /**
     * Reject any key that is not correctable.
     *
     * Declaring rules for the allowed keys is NOT sufficient on its own: Laravel only
     * validates the keys it has rules for, so an unexpected key such as `employee_id`
     * passes validation untouched and is then silently dropped by `validated()` — or, as
     * happened here, arrives at the service as null and produces a 500. An explicit check
     * turns it into a clear 422.
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $changes = $this->input('changes');

                if (! is_array($changes)) {
                    return;
                }

                $unexpected = array_diff(array_keys($changes), AttendanceCorrection::correctableFields());

                foreach ($unexpected as $field) {
                    $validator->errors()->add(
                        'changes.'.$field,
                        "The {$field} field cannot be changed by a correction.",
                    );
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'A correction needs a reason. It is the only explanation on the record.',
            'reason.min' => 'Give a reason someone reading this in six months would understand.',
            'changes.required' => 'Nothing is being changed.',
        ];
    }
}
