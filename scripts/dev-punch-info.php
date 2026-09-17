<?php

/*
 * Local development helper: print a live outlet code and the employee roster.
 *
 * Not part of the app. It exists because exercising the punch flow by hand needs a
 * code that is live right now, and the rotating codes expire in 90 seconds.
 *
 *   php scripts/dev-punch-info.php [OUTLET_CODE]
 */

require __DIR__.'/dev-bootstrap.php';

use App\Models\Employee;
use App\Models\Outlet;
use App\Services\OutletTokenService;

$code = $argv[1] ?? 'DARS-COFFEE';

$outlet = Outlet::where('code', $code)->first();

if ($outlet === null) {
    exit("No outlet with code {$code}".PHP_EOL);
}

$token = app(OutletTokenService::class)->currentOrFreshForDisplay($outlet);

echo 'OUTLET='.$outlet->name.PHP_EOL;
echo 'TOKEN='.$token->token.PHP_EOL;
echo 'PUNCH_URL=http://127.0.0.1:8100/punch?t='.$token->token.PHP_EOL;
echo 'REQUIRES_PHOTO='.($outlet->requires_photo ? 'yes' : 'no').PHP_EOL;
echo '---'.PHP_EOL;

foreach (Employee::with('outlets')->orderBy('employee_code')->get() as $employee) {
    echo sprintf(
        '%s | %s | outlets=%s | pin=%s'.PHP_EOL,
        $employee->employee_code,
        $employee->name,
        $employee->outlets->pluck('code')->implode(',') ?: '-',
        $employee->hasPin() ? 'set' : 'none',
    );
}
