<?php

/*
 * Local development helper: print each outlet's punch-relevant settings.
 *
 *   php scripts/dev-outlet-settings.php
 *
 * Exists because the dev helpers mutate outlet settings (the smoke test turns
 * requires_photo off, since there is no camera), and a setting left altered makes the
 * browser behave differently from production for no visible reason.
 *
 * Not part of the app.
 */

require __DIR__.'/dev-bootstrap.php';

use App\Models\Outlet;

foreach (Outlet::orderBy('code')->get() as $outlet) {
    echo sprintf(
        '%-14s requires_photo=%-5s token_mode=%-8s ttl=%-4s active=%s',
        $outlet->code,
        $outlet->requires_photo ? 'yes' : 'no',
        $outlet->token_mode->value,
        $outlet->qr_ttl_seconds,
        $outlet->is_active ? 'yes' : 'no',
    ).PHP_EOL;
}
