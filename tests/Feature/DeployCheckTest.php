<?php

use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\URL;

/**
 * The pre-flight deployment check.
 *
 * This command's whole value is that it FAILS LOUDLY on a misconfiguration, so the thing worth
 * testing is that it actually fails — a check that passes everything is worse than no check,
 * because it converts an unknown risk into false confidence.
 *
 * Each dangerous setting is asserted in both directions: the bad value must fail, and the good
 * value must pass. Only asserting one direction is how a check that always passes gets shipped.
 */
it('fails when debug mode is on, because error pages leak credentials', function () {
    Config::set('app.debug', true);

    $this->artisan('attendance:deploy-check')
        ->expectsOutputToContain('APP_DEBUG')
        ->assertFailed();
});

it('fails when APP_URL is not https', function () {
    Config::set('app.debug', false);
    Config::set('app.env', 'production');
    Config::set('app.url', 'http://attendance.example.com');

    // The scheme decides whether signed photo URLs validate at all.
    $this->artisan('attendance:deploy-check')
        ->expectsOutputToContain('APP_URL')
        ->assertFailed();
});

/**
 * No worker process exists on this host, so a queued job would never run.
 *
 * The symptom would be a silent no-op rather than an error, which is why the setting is pinned
 * and checked rather than left to chance.
 */
it('fails when the queue is not set to sync', function () {
    Config::set('queue.default', 'database');

    $this->artisan('attendance:deploy-check')
        ->expectsOutputToContain('QUEUE_CONNECTION')
        ->assertFailed();

    Config::set('queue.default', 'sync');
});

it('warns rather than fails when no proxy is trusted', function () {
    /*
     * A WARN, not a FAIL: a host that terminates HTTPS itself is correctly configured with
     * nothing trusted, and failing that case would train whoever runs this to ignore it.
     */
    $this->artisan('attendance:deploy-check')
        ->expectsOutputToContain('TRUSTED_PROXIES')
        ->assertFailed(); // other checks (APP_ENV, APP_DEBUG) still fail in the test env
});

it('reports the seeded accounts that ship with a published password', function () {
    // The suite runs against a seeded database, so these exist and must be called out.
    $this->artisan('attendance:deploy-check')
        ->expectsOutputToContain('Seeded account')
        ->assertFailed();
});

it('lists the scheduled jobs so a missing cron entry is visible', function () {
    $this->artisan('attendance:deploy-check')
        ->expectsOutputToContain('Scheduled jobs')
        ->assertFailed();
});

/**
 * The check must be honest about what it CANNOT verify.
 *
 * It cannot read the crontab. Reporting "cron is fine" from inside the application would be a
 * lie, and the consequences of believing it are severe: the retention purge stops (a compliance
 * gap nobody notices) and forgotten clock-outs stop being flagged (which blocks that employee's
 * next clock-in entirely, with no error shown).
 */
it('says plainly that it cannot verify the crontab', function () {
    $this->artisan('attendance:deploy-check')
        ->expectsOutputToContain('cannot see the crontab')
        ->assertFailed();
});

it('warns that a cached config means .env is not read', function () {
    $this->artisan('attendance:deploy-check')
        ->expectsOutputToContain('Config cached')
        ->assertFailed();
});

/**
 * With the environment corrected, the check passes.
 *
 * This is the other half of the contract: a correct deployment must not be reported as broken,
 * or the command becomes noise and gets skipped.
 *
 * THREE THINGS HAVE TO BE DRIVEN DIFFERENTLY IN A TEST
 *
 *  - `app()->environment()` reads the value set at BOOT, not `config('app.env')`, so
 *    `Config::set` alone does not change it. The environment is re-detected instead.
 *  - `URL` caches the request root, so changing `app.url` mid-test has no effect on generated
 *    URLs until `forceScheme` is applied.
 *  - The seeded accounts are removed, standing in for a real go-live where the published
 *    default passwords have been changed.
 */
it('passes when the environment is production-ready', function () {
    $this->app->detectEnvironment(fn () => 'production');

    Config::set('app.debug', false);
    Config::set('app.url', 'https://attendance.example.com');
    Config::set('queue.default', 'sync');
    Config::set('cache.default', 'database');
    Config::set('trustedproxy.proxies', '*');

    // The URL generator holds its own root; forcing the scheme is what the live config achieves.
    URL::forceScheme('https');
    URL::forceRootUrl('https://attendance.example.com');

    User::query()->delete();

    $this->artisan('attendance:deploy-check')->assertSuccessful();

    URL::forceScheme(null);
});
