<?php

require __DIR__.'/dev-bootstrap.php';

use App\Models\Employee;

$code = $argv[1] ?? null;

if ($code === null) {
    exit('Usage: php scripts/dev-delete-employee.php EMPLOYEE_CODE'.PHP_EOL);
}

$employee = Employee::where('employee_code', $code)->first();

if ($employee === null) {
    exit("No employee {$code}".PHP_EOL);
}

// forceDelete, not delete: these are throwaway records and a soft-deleted one would
// still occupy the employee_code.
$employee->outlets()->detach();
$employee->forceDelete();

echo "Deleted {$code}".PHP_EOL;
