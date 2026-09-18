<?php

/*
 * Local development helper: one stable outlet code, for verifying the punch flow in a browser.
 *
 * Not part of the app. The default outlet codes ROTATE every 90 seconds, which makes a manual
 * walkthrough of the punch screen almost impossible — the code expires between reading it and
 * typing it. This switches one outlet to `printed` mode, where the code is fixed, so the flow can
 * be driven by hand.
 *
 *   php scripts/dev-stable-token.php [OUTLET_CODE]
 *
 * Re-run with no argument to switch the outlet back to rotating codes.
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

/*
 * Printed mode means the token does not rotate: one code, valid until revoked. That is also how a
 * real outlet runs when it prints a sheet and sticks it by the till.
 */
$outlet->update(['token_mode' => 'printed', 'requires_photo' => false]);

$token = app(OutletTokenService::class)->regenerate($outlet);

echo 'OUTLET='.$outlet->name.PHP_EOL;
echo 'MODE='.$outlet->fresh()->token_mode->value.PHP_EOL;
echo 'REQUIRES_PHOTO='.($outlet->fresh()->requires_photo ? 'yes' : 'no').PHP_EOL;
echo 'TOKEN='.$token->token.PHP_EOL;
echo 'PUNCH_URL=http://127.0.0.1:8100/punch?t='.$token->token.PHP_EOL;
echo '---'.PHP_EOL;

foreach (Employee::with('outlets')->orderBy('employee_code')->get() as $employee) {
    echo sprintf(
        '%s | %s | pin=%s'.PHP_EOL,
        $employee->employee_code,
        $employee->name,
        $employee->hasPin() ? 'set (dev default 1234)' : 'none',
    );
}
