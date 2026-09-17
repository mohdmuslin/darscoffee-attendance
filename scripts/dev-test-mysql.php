<?php

/*
 * Local development helper: run the test suite against MySQL instead of SQLite.
 *
 *   php scripts/dev-test-mysql.php [pest args...]
 *
 * The suite normally runs on SQLite for speed. That hid a real bug: a date-cast column
 * binds as 'Y-m-d H:i:s', MySQL truncates that to the day so the query works, and
 * SQLite stored the full string so the query matched nothing. Every business-day query
 * returned empty on SQLite while looking fine on MySQL.
 *
 * This copies .env, points the COPY at a scratch MySQL database, runs Pest against it,
 * and restores .env afterwards. CI runs both drivers for the same reason.
 *
 * Not part of the app.
 */

$root = __DIR__.'/..';
$env = $root.'/.env';
$backup = $root.'/.env.pest-mysql-backup';
$database = 'dars_attendance_test';

if (! is_file($env)) {
    exit('No .env at '.$env.PHP_EOL);
}

if (! copy($env, $backup)) {
    exit('Could not back up .env'.PHP_EOL);
}

$exitCode = 0;

try {
    $contents = file_get_contents($env);

    $swaps = [
        // Matches the line whether or not it is commented out, since .env.example and
        // the local .env differ in that respect.
        '/^#?\s*DB_CONNECTION=.*$/m' => 'DB_CONNECTION=mysql',
        '/^#?\s*DB_HOST=.*$/m' => 'DB_HOST=127.0.0.1',
        '/^#?\s*DB_PORT=.*$/m' => 'DB_PORT=3306',
        '/^#?\s*DB_DATABASE=.*$/m' => 'DB_DATABASE='.$database,
        '/^#?\s*DB_USERNAME=.*$/m' => 'DB_USERNAME=root',
        '/^#?\s*DB_PASSWORD=.*$/m' => 'DB_PASSWORD=',
    ];

    foreach ($swaps as $pattern => $replacement) {
        $updated = preg_replace($pattern, $replacement, $contents, 1, $count);

        if ($count === 0) {
            exit('Could not find a line matching '.$pattern.' in .env'.PHP_EOL);
        }

        $contents = $updated;
    }

    // A scratch database, never the real one: the suite uses RefreshDatabase and would
    // otherwise wipe development data.
    file_put_contents($env, $contents);

    echo "Running Pest against MySQL database '{$database}'...".PHP_EOL;

    $args = array_slice($argv, 1);
    $escaped = implode(' ', array_map('escapeshellarg', $args));

    passthru(PHP_BINARY.' '.escapeshellarg(__DIR__.'/../vendor/bin/pest').' '.$escaped, $code);

    /*
     * The exit code is captured and returned at the END rather than calling exit() here.
     *
     * exit() unwinds immediately and SKIPS the finally block below, so an earlier version
     * of this script left .env pointing at the scratch database — and the next command run
     * against the project silently targeted the wrong database.
     */
    $exitCode = (int) $code;
} finally {
    /*
     * Restore the original .env before reporting anything, and never let a failure here
     * be swallowed: a half-restored environment is worse than a failed test run.
     */
    if (is_file($backup)) {
        if (! rename($backup, $env)) {
            fwrite(STDERR, 'WARNING: could not restore .env from '.$backup.PHP_EOL);
        } else {
            echo '.env restored.'.PHP_EOL;
        }
    }
}

exit($exitCode);
