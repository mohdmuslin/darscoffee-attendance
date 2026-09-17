<?php

/*
 * Local development helper: create a throwaway employee with a known PIN.
 *
 *   php scripts/dev-make-employee.php "Ahmad Bin Ali" 1234
 *
 * The employee is mapped to DARS-COFFEE. Not part of the app.
 */

require __DIR__.'/dev-bootstrap.php';

use App\Models\Employee;
use App\Models\Outlet;

$name = $argv[1] ?? 'Test Worker';
$pin = $argv[2] ?? '1234';

$outlet = Outlet::where('code', 'DARS-COFFEE')->firstOrFail();

$employee = Employee::firstOrNew(['employee_code' => 'DEV-'.strtoupper(substr(md5($name), 0, 4))]);
$employee->name = $name;
$employee->pay_basis = 'hourly';
$employee->is_active = true;
$employee->save();
$employee->outlets()->syncWithoutDetaching([$outlet->id]);
$employee->setPin($pin);
$employee->save();

echo $employee->employee_code.' | '.$employee->name.' | PIN '.$pin.PHP_EOL;
