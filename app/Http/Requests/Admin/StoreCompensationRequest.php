<?php

namespace App\Http\Requests\Admin;

use App\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Setting an employee's pay rate.
 *
 * Rows are INSERTED, never updated, so this is always "a new rate from this date" rather than
 * an edit. That is what keeps "what was he paid in March?" answerable after a raise — and it is
 * why the form asks for an effective date rather than assuming today.
 */
class StoreCompensationRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authorisation happens in the controller, against the resolved employee.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'basis' => ['required', 'in:hourly,daily,weekly,monthly'],

            /*
             * A DECIMAL string, not a float. Money in a binary float accumulates representation
             * error, and `numeric` plus an explicit decimal check keeps the value exact through
             * validation as well as storage.
             */
            'rate' => ['required', 'numeric', 'min:0', 'max:99999999', 'decimal:0,2'],
            'overtime_rate' => ['nullable', 'numeric', 'min:0', 'max:99999999', 'decimal:0,2'],

            'currency' => ['sometimes', 'string', 'size:3'],

            /*
             * A date, not a datetime: a rate applies to whole business days, and accepting a
             * time would invite an argument about which part of a day was priced at which rate.
             */
            'effective_from' => ['required', 'date_format:Y-m-d'],

            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'rate.decimal' => 'A rate is money: at most two decimal places.',
            'effective_from.required' => 'A rate needs the date it starts from.',
        ];
    }
}
