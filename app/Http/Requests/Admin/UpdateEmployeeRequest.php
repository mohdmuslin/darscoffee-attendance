<?php

namespace App\Http\Requests\Admin;

use App\Enums\PayBasis;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class UpdateEmployeeRequest extends FormRequest
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
        $employee = $this->route('employee');

        return [
            // `ignore` so an employee can be saved without changing their own code.
            'employee_code' => [
                'sometimes', 'string', 'max:20',
                Rule::unique('employees', 'employee_code')->ignore($employee?->id),
            ],

            'name' => ['sometimes', 'string', 'max:100'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:20', 'regex:/^[0-9+\-\s()]*$/'],
            // Same format check as on create: it is an identity document.
            'ic_number' => ['sometimes', 'nullable', 'string', 'regex:/^\d{6}-?\d{2}-?\d{4}$/'],
            'pay_basis' => ['sometimes', 'nullable', new Enum(PayBasis::class)],

            'outlet_ids' => ['sometimes', 'array', 'min:1'],
            'outlet_ids.*' => ['integer', Rule::exists('outlets', 'id')],
            'primary_outlet_id' => ['sometimes', 'nullable', 'integer', Rule::exists('outlets', 'id')],

            'joined_at' => ['sometimes', 'nullable', 'date'],
            'resigned_at' => ['sometimes', 'nullable', 'date', 'after_or_equal:joined_at'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
