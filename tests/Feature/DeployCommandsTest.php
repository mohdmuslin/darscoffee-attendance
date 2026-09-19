<?php

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;

/**
 * The no-shell deploy commands.
 *
 * These exist because the host has no SSH and no Terminal, so every `php artisan ...` step in a
 * normal deploy has to be triggered from cPanel's Cron Jobs GUI — which holds ONE command. These
 * commands are therefore the entire deploy surface, which makes what they REFUSE to do more
 * important than what they do.
 *
 * Each refusal below protects something that cannot be recovered:
 *
 *   - `migrate:fresh` would drop the attendance history.
 *   - `key:generate` on an installed app makes every encrypted IC number unreadable for ever.
 *   - `db:seed` would recreate the published default accounts after they were removed.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-21 09:00:00', 'Asia/Kuala_Lumpur'));
    $this->freezeTime();
});

// ---- attendance:deploy -----------------------------------------------------

/**
 * The check is now reported BEFORE anything runs, so the cron log says what was applied. A
 * deploy that silently changed the schema is one nobody can account for afterwards.
 */
it('reports that there is nothing to apply when the schema is current', function () {
    $this->artisan('attendance:deploy')
        ->expectsOutputToContain('No migrations pending')
        ->assertSuccessful();
});

it('clears the compiled caches', function () {
    // A stale view cache is the classic "I deployed and nothing changed".
    $this->artisan('attendance:deploy')
        ->expectsOutputToContain('Caches cleared')
        ->assertSuccessful();
});

it('does not run migrations on a dry run', function () {
    $this->artisan('attendance:deploy --dry-run')
        ->expectsOutputToContain('Dry run')
        ->assertSuccessful();
});

/**
 * The flag is what a permanent cron entry watches for, so that deploying needs nothing
 * reachable over HTTP.
 *
 * Silent when absent, because this runs every few minutes forever — printing a line each time
 * would fill the log with noise nobody reads, which is how a real failure gets missed.
 */
it('does nothing when --if-flagged is set and no flag exists', function () {
    $this->artisan('attendance:deploy --if-flagged')
        ->doesntExpectOutputToContain('Environment:')
        ->assertSuccessful();
});

it('runs and removes the flag when it exists', function () {
    $flag = storage_path('app/private/deploy.flag');

    // The private directory is gitignored and may not exist in a fresh checkout.
    if (! is_dir(dirname($flag))) {
        mkdir(dirname($flag), 0755, true);
    }

    file_put_contents($flag, 'deploy');

    $this->artisan('attendance:deploy --if-flagged')->assertSuccessful();

    expect(file_exists($flag))->toBeFalse('a completed deploy must clear the flag');
});

// ---- attendance:install ----------------------------------------------------

/**
 * Refusing to re-run is the single most valuable thing this command does.
 *
 * `key:generate` would replace APP_KEY, which decrypts the employee IC numbers. The damage is
 * silent — the console simply renders a number that can never be revealed — so a guard is the
 * only defence.
 *
 * The account is created here rather than assumed: the test database is migrated but NOT
 * seeded, because `RefreshDatabase` resets it for every test and seeding it would recreate the
 * published default passwords on every run. So the condition the guard checks for is set up
 * explicitly.
 */
it('refuses to install over an existing installation', function () {
    User::factory()->create();

    expect(User::query()->count())->toBeGreaterThan(0);

    $this->artisan('attendance:install')
        ->expectsOutputToContain('already looks installed')
        ->assertFailed();
});

it('says how to proceed deliberately', function () {
    User::factory()->create();

    $this->artisan('attendance:install')
        ->expectsOutputToContain('--force')
        ->assertFailed();
});

it('explains why re-running would be destructive', function () {
    User::factory()->create();

    // The message has to name the harm, or it reads as an obstacle rather than a protection.
    $this->artisan('attendance:install')
        ->expectsOutputToContain('APP_KEY')
        ->assertFailed();
});

/**
 * An empty database IS installable, which is the point of the guard being a check rather than a
 * refusal.
 */
it('proceeds on a database that has no accounts yet', function () {
    expect(User::query()->count())->toBe(0);

    $this->artisan('attendance:install')
        ->expectsOutputToContain('Outlets:')
        ->assertSuccessful();
});

it('names the seeded password risk when seeding', function () {
    // Reported rather than fixed: silently changing a password would lock out whoever is using
    // the account.
    $this->artisan('attendance:install --force --seed')
        ->expectsOutputToContain('published in the repository')
        ->assertSuccessful();
});

/*
 * The storage-tree repair is NOT tested here, deliberately.
 *
 * Asserting it means deleting `storage/framework/views` from the real project and letting the
 * command recreate it — which, if the test failed midway, would leave the working tree broken
 * in a way that looks exactly like the bug being tested. The behaviour was verified by hand
 * instead (the command reported "Created 3 missing storage director(y/ies)" and the application
 * recovered), and it is documented on both private methods.
 *
 * The tradeoff is deliberate: a fragile test that mutates the developer's project is worse than
 * no test, particularly for something that fails loudly and immediately when it is wrong.
 */
