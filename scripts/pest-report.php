<?php

/*
 * Local development helper: summarise Pest's compact JSON output.
 *
 *   php vendor/bin/pest --compact | php scripts/pest-report.php
 *
 * The compact reporter is one long JSON line. Piping it through PowerShell mangles the
 * quoting, and reading the raw line costs a lot of context for very little signal. This
 * prints the counts plus one line per failure, which is what is actually needed while
 * iterating.
 *
 * Not part of the app.
 */

$path = $argv[1] ?? null;

if ($path !== null) {
    /*
     * Read from a FILE rather than STDIN when a path is given.
     *
     * PowerShell 5.1 does not support the `<` input redirect, so piping is the only
     * option there — and piping through `Select-Object` truncates the JSON, which turns a
     * test failure into a confusing parse error. Reading a file avoids both problems.
     */
    $raw = is_file($path) ? file_get_contents($path) : '';
} else {
    $raw = stream_get_contents(STDIN);
}

$json = json_decode((string) $raw, true);

if (! is_array($json)) {
    // Not JSON: print whatever came through, so a fatal error is still visible rather
    // than being swallowed by the parser.
    exit((string) $raw);
}

$failures = array_merge($json['failures'] ?? [], $json['error_details'] ?? []);

echo sprintf(
    '%s: %d tests, %d passed, %d failed',
    $json['result'] ?? 'unknown',
    $json['tests'] ?? 0,
    $json['passed'] ?? 0,
    count($failures),
).PHP_EOL;

if (($json['assertions'] ?? null) !== null) {
    echo sprintf('assertions: %d, duration: %dms', $json['assertions'], $json['duration_ms'] ?? 0).PHP_EOL;
}

foreach ($failures as $failure) {
    $name = str_replace('P\\Tests\\', '', (string) ($failure['test'] ?? '?'));

    echo PHP_EOL.'FAIL '.$name.PHP_EOL;

    $message = str_replace(["\r", "\n"], ' ', (string) ($failure['message'] ?? ''));

    echo '  '.substr(preg_replace('/\s+/', ' ', $message), 0, 400).PHP_EOL;

    if (isset($failure['file'], $failure['line'])) {
        echo '  at '.$failure['file'].':'.$failure['line'].PHP_EOL;
    }
}
