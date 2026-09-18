<?php

/*
 * Safety gate for the local development helper scripts.
 *
 * Several helpers in `scripts/` DELETE data or REWRITE `.env`, and they are committed to the
 * repository — so they are present on the server after a deploy. Running one there would damage
 * the attendance record silently, because they print a friendly line and exit zero.
 *
 * TWO REASONS THIS IS ITS OWN FILE
 *
 * 1. It must run BEFORE the application boots. A helper that has already connected to the live
 *    database cannot be made safe by a check that runs afterwards.
 * 2. Three helpers do not use `dev-bootstrap.php` at all — `dev-test-mysql.php` rewrites `.env`,
 *    and `make-icons.php` and `pest-report.php` have no database at all. A guard living inside
 *    the bootstrap would silently not cover the most dangerous of them.
 *
 * WHY IT READS THE `.env` FILE ITSELF
 *
 * `APP_ENV` normally lives only in `.env`, which nothing has loaded yet at this point. The first
 * version of this gate used `getenv()` alone, which returned nothing locally, defaulted to
 * "production", and refused every local run as well as every live one — a gate so strict it was
 * simply removed. The parse below is deliberately minimal rather than a real dotenv reader,
 * because loading the framework's parser before the framework boots is the dependency this
 * check exists to avoid.
 *
 * Not part of the app.
 */

if (! function_exists('dev_require_local')) {
    /**
     * Refuse to continue unless this is a local or testing environment.
     *
     * Fails CLOSED: an unreadable or absent `.env` is treated as production, because guessing
     * "local" there would defeat the gate in exactly the situation it exists for — a
     * misconfigured server.
     */
    function dev_require_local(): void
    {
        $environment = $_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: null;

        if ($environment === null) {
            $envFile = dirname(__DIR__).'/.env';

            if (is_readable($envFile)) {
                foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                    if (str_starts_with(trim($line), 'APP_ENV=')) {
                        $environment = trim(explode('=', trim($line), 2)[1], " \t\"'");

                        break;
                    }
                }
            }
        }

        $environment = $environment ?? 'production';

        if (in_array($environment, ['local', 'testing'], true)) {
            return;
        }

        fwrite(STDERR, PHP_EOL);
        fwrite(STDERR, 'REFUSED: this is a development script and APP_ENV is "'.$environment.'".'.PHP_EOL);
        fwrite(STDERR, PHP_EOL);
        fwrite(STDERR, 'These helpers delete attendance data or rewrite .env, so they are blocked'.PHP_EOL);
        fwrite(STDERR, 'outside a local environment. To run one deliberately against a staging copy,'.PHP_EOL);
        fwrite(STDERR, 'set APP_ENV=local for that command only — never on the live database.'.PHP_EOL);
        fwrite(STDERR, PHP_EOL);

        exit(1);
    }
}
