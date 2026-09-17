<?php

/*
 * Local development helper: list console accounts, for signing in during verification.
 *
 *   php scripts/dev-list-users.php
 *
 * Prints emails only. Passwords are never printed: they are hashed anyway, and putting
 * credentials on a console is a habit worth not forming even in development.
 *
 * Not part of the app.
 */

require __DIR__.'/dev-bootstrap.php';

use App\Models\User;

foreach (User::orderBy('role')->get() as $user) {
    echo sprintf(
        '%-8s %-32s %-24s active=%s outlets=%s',
        $user->role->value,
        $user->email,
        $user->name,
        $user->is_active ? 'yes' : 'no',
        $user->isOwner() ? 'all' : count($user->visibleOutletIds() ?? []),
    ).PHP_EOL;
}
