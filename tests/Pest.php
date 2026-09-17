<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Pest configuration
|--------------------------------------------------------------------------
| Without this file Pest has no base TestCase, so the Laravel application is
| never bootstrapped and tests fail with "Target class [config] does not exist".
| That message points at the symptom rather than the cause, so it is worth
| remembering: a missing Pest.php looks like a broken container.
*/

uses(
    TestCase::class,
    RefreshDatabase::class,
)->in('Feature');

/*
 * Unit tests get the application but NOT the database: anything needing a schema
 * belongs in Feature, where RefreshDatabase keeps tests isolated.
 */
uses(TestCase::class)->in('Unit');

/**
 * Simulate a fresh HTTP request within a feature test.
 *
 * WHY THIS IS NEEDED
 *
 * A feature test reuses ONE application instance across every `$this->get()` call.
 * The auth guard resolves the token's user once and then holds it, so a second
 * request in the same test still sees the FIRST request's user — even after the
 * account has been deactivated or the token revoked.
 *
 * That is purely a harness artifact. In production each HTTP request is a fresh
 * process, so nothing is cached and the middleware sees current state. The
 * distinction matters: without this helper, a test asserting "a deactivated account
 * is refused" fails even though production behaviour is correct — and the tempting
 * "fix" is to weaken the check rather than the test.
 *
 * Call it between requests whenever a test changes authentication state that a
 * LATER request must observe.
 */
function freshRequest(): void
{
    /*
     * `app()` resolves the container from the current test case rather than
     * reaching for its protected `$app` property, which is not accessible here.
     */
    app('auth')->forgetGuards();
}

/**
 * Bearer auth headers for a console user.
 *
 * Shared here rather than defined in each test file: Pest loads every feature file into
 * one process, so a helper declared twice is a fatal "cannot redeclare" that takes out
 * the WHOLE suite rather than one file. Keeping it in one place removes the possibility.
 *
 * @return array<string, string>
 */
function asUser(User $user): array
{
    return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
}

/**
 * Run the suite as an owner.
 *
 * Most Phase 3 endpoints are owner-only, so tests that are not about scoping would
 * otherwise repeat the same three lines.
 *
 * @return array<string, string>
 */
function asOwner(): array
{
    return asUser(User::where('role', UserRole::OWNER->value)->firstOrFail());
}
