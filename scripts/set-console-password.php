<?php

/*
 * ONE-OFF UTILITY — sets new passwords for the seeded console accounts.
 *
 * WHY THIS EXISTS
 * The console has no "change password" screen: `AccountsView.vue` can only SET a password when
 * creating an account, and the API's owner-only PATCH endpoint has no UI in front of it. The
 * two accounts created by `attendance:install --seed` therefore keep the password `password`,
 * which is published in a PUBLIC repository. Anyone who has seen the repo can sign in as owner
 * and read every staff member's hours and photographs.
 *
 * The normal fix is a screen for it, but the FTP deploy is currently failing (530), so no new
 * code can reach the server. This file is the way to close that hole TODAY using only cPanel's
 * File Manager and the cron job that is already known to work.
 *
 * HOW TO USE
 *   1. Upload this file to the APPLICATION ROOT (the folder containing `artisan`).
 *   2. cPanel -> Cron Jobs -> add a ONE-OFF job:
 *
 *        /usr/local/bin/php /home/mwstayco/attendance.darscoffee.com/attendance/set-console-password.php \
 *          >> /home/mwstayco/password-change.log 2>&1
 *
 *      (a single `*` in the minute field is fine — it is a manual one-shot)
 *   3. Wait a minute, then open `/home/mwstayco/password-change.log` in File Manager and COPY
 *      THE GENERATED PASSWORDS.
 *   4. Delete the cron job, this file, and the log.
 *
 * SAFETY
 *   - This file is NOT web-reachable: the document root is `<app root>/public`, so a script in
 *     the app root cannot be fetched over HTTP. Delete it anyway once used.
 *   - It DELETES ITSELF at the end, so a forgotten copy cannot be re-run later to reset the
 *     passwords again. It prints whether that deletion succeeded — if it says it failed, delete
 *     the file by hand.
 *   - Passwords are RANDOM and printed ONCE. Nothing is stored in this file.
 *   - Editing the array below lets you pin specific passwords instead, if you prefer.
 */

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;

/*
 * The accounts to reset. Set a value in place of null to choose your own password; leaving it
 * null generates a strong random one and prints it below.
 */
$targets = [
    'owner@darscoffee.com' => null,
    'fatimahbokhare@gmail.com' => null,
];

echo "Setting console passwords\n";
echo str_repeat('=', 46)."\n\n";

$changed = 0;

foreach ($targets as $email => $chosen) {
    $user = User::where('email', $email)->first();

    if ($user === null) {
        echo "SKIPPED  {$email} — no such account\n\n";

        continue;
    }

    // 24 chars from a cryptographic source: long enough that guessing is not a concern, and it
    // never needs to be typed by hand more than once.
    $password = $chosen ?? rtrim(strtr(base64_encode(random_bytes(24)), '+/', 'AB'), '=');

    /*
     * ASSIGNED IN PLAIN TEXT, DELIBERATELY.
     *
     * `App\Models\User` declares `'password' => 'hashed'` in its casts, so Eloquent hashes the
     * value on assignment and there is no need to call Hash::make() here.
     *
     * For the record, because it is a natural thing to assume: Hash::make() would NOT break
     * this. Laravel's `hashed` cast is idempotent — it inspects the value with
     * password_get_info() and skips re-hashing anything that is already a bcrypt hash — so both
     * forms work. Verified against the local database rather than reasoned about, since it is
     * easy to get the opposite way round.
     *
     * Plain text is used because it is the simpler of two working options: one hashing step,
     * described in one place.
     */
    $user->password = $password;
    $user->save();

    echo "UPDATED  {$email}\n";
    echo "   name     : {$user->name}\n";
    echo "   role     : {$user->role->value}\n";
    echo "   password : {$password}\n\n";

    $changed++;
}

echo str_repeat('=', 46)."\n";
echo "{$changed} account(s) updated.\n\n";
echo "NEXT:\n";
echo "  1. Copy the passwords above — they are shown ONCE and cannot be recovered.\n";
echo "  2. Sign in at /console to confirm, then CHANGE them to something you will remember\n";
echo "     (once a change-password screen exists).\n";
echo "  3. Delete the cron job, this file, and the log file.\n\n";

/*
 * Remove this script so it cannot be run a second time later — a forgotten copy that resets
 * the owner password on a whim is a bigger risk than the password it was fixing. Reported
 * rather than assumed, because a failed unlink leaves a live file behind.
 */
$self = __FILE__;

if (@unlink($self)) {
    echo "This script deleted itself.\n";
} else {
    echo "COULD NOT self-delete — DELETE THIS FILE BY HAND: {$self}\n";
}
