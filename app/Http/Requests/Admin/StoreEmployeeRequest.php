<?php

namespace App\Http\Requests\Admin;

use App\Enums\PayBasis;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class StoreEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Enforced by the controller's policy check; this keeps validation separate.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /*
             * Employee codes are what appear on a payslip, so they must be unique and
             * stable. Not auto-generated: the shop already has its own scheme.
             */
            'employee_code' => ['required', 'string', 'max:20', 'unique:employees,employee_code'],

            'name' => ['required', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:20', 'regex:/^[0-9+\-\s()]*$/'],

            /*
             * Optional. Sent as plain text over HTTPS and encrypted before storage;
             * the request is not where encryption belongs, because this object may
             * be logged.
             *
             * Format-checked because it is an identity document: a typo here is found
             * years later, when someone tries to file for an employee who does not
             * exist under that number. Accepts 12 digits with optional dashes, which
             * is the Malaysian IC format.
             */
            'ic_number' => ['nullable', 'string', 'regex:/^\d{6}-?\d{2}-?\d{4}$/'],

            'pay_basis' => ['nullable', new Enum(PayBasis::class)],

            // Which outlets this person works at. Drives whether a punch is accepted.
            'outlet_ids' => ['required', 'array', 'min:1'],
            'outlet_ids.*' => ['integer', Rule::exists('outlets', 'id')],
            'primary_outlet_id' => ['nullable', 'integer', Rule::exists('outlets', 'id')],

            'joined_at' => ['nullable', 'date'],
            'is_active' => ['sometimes', 'boolean'],

            /*
             * Optional at creation: a manager may add someone before they have been
             * given a PIN, and setting it later is a normal "forgot my PIN" action.
             */
            'pin' => ['nullable', 'string', 'digits_between:4,6'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'outlet_ids.required' => 'Choose at least one outlet — a punch is refused at an outlet the employee is not mapped to.',
            'outlet_ids.min' => 'Choose at least one outlet — a punch is refused at an outlet the employee is not mapped to.',
            'pin.digits_between' => 'The PIN must be 4 to 6 digits.',
            'ic_number.regex' => 'The IC number should be 12 digits, e.g. 900101-14-5566.',
        ];
    }
}
