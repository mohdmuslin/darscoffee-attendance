<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * An adhoc rate adjustment.
 *
 * The reason is required and cannot be waived. A manager may set rates for their own staff with
 * no second signature (blueprint §10.2) — which makes this record the only control there is, so
 * it has to be complete rather than convenient.
 */
class StoreRateAdjustmentRequest extends FormRequest
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

            'applies_to_date' => ['required', 'date_format:Y-m-d'],
            'applies_to_period' => ['required', 'in:day,week,month'],

            /*
             * Which figure is being overridden. Required rather than defaulted: an employee can
             * have both an ordinary and an overtime rate, and "changed the rate" would be
             * ambiguous the moment they do.
             */
            'applies_to' => ['required', 'in:overtime,ordinary'],

            'hours' => ['nullable', 'numeric', 'min:0', 'max:9999', 'decimal:0,2'],
            'rate' => ['required', 'numeric', 'min:0', 'max:99999999', 'decimal:0,2'],

            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'A rate change needs a reason. It is the only explanation on the record.',
            'reason.min' => 'Give a reason someone reading this in six months would understand.',
            'applies_to.required' => 'Say whether this changes the ordinary or the overtime rate.',
        ];
    }
}
