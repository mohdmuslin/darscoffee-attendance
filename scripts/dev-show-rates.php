<?php

/*
 * Local development helper: print an employee's pay rate history.
 *
 *   php scripts/dev-show-rates.php [EMPLOYEE_CODE]
 *
 * Shows the effective-dated chain, which is what makes "what was he paid in March?" answerable.
 * A gap or an overlap in the dates is visible here at a glance, and both would make the figure
 * for some day ambiguous.
 *
 * Not part of the app.
 */

require __DIR__.'/dev-bootstrap.php';

use App\Models\CompensationRule;
use App\Models\Employee;
use App\Models\RateAdjustment;

$code = $argv[1] ?? 'DEV-47D7';

$employee = Employee::where('employee_code', $code)->first();

if ($employee === null) {
    exit("No employee {$code}".PHP_EOL);
}

echo "Rate history for {$employee->name} ({$code})".PHP_EOL;
echo str_pad('from', 12).str_pad('to', 12).str_pad('basis', 9).str_pad('rate', 10).'overtime'.PHP_EOL;

$rules = CompensationRule::where('employee_id', $employee->id)->orderBy('effective_from')->get();

foreach ($rules as $rule) {
    echo str_pad($rule->effective_from->toDateString(), 12)
        .str_pad($rule->effective_to?->toDateString() ?? 'open', 12)
        .str_pad($rule->basis->value, 9)
        .str_pad((string) $rule->rate, 10)
        .($rule->overtime_rate ?? '-')
        .PHP_EOL;
}

// Gaps and overlaps both make the price for a day ambiguous, so they are called out.
$previousTo = null;

foreach ($rules as $rule) {
    if ($previousTo !== null && $rule->effective_from->toDateString() <= $previousTo) {
        echo '  WARNING: overlaps the previous row (from '.$rule->effective_from->toDateString()
            .' but the previous ran to '.$previousTo.')'.PHP_EOL;
    }

    $previousTo = $rule->effective_to?->toDateString();
}

echo PHP_EOL.'Adjustments'.PHP_EOL;

foreach (RateAdjustment::where('employee_id', $employee->id)->orderBy('applies_to_date')->get() as $adjustment) {
    echo '  '.str_pad($adjustment->applies_to_date->toDateString(), 12)
        .str_pad($adjustment->applies_to_period, 7)
        .str_pad($adjustment->applies_to, 9)
        .str_pad((string) $adjustment->rate, 8)
        .($adjustment->approved_at === null ? 'PENDING' : 'approved')
        .'  '.$adjustment->reason
        .PHP_EOL;
}
