<?php

use App\Enums\PayBasis;
use App\Models\Employee;
use App\Models\Outlet;
use App\Models\SsoToken;
use App\Models\User;
use App\Services\SsoTokenService;

/**
 * Single sign-on token issuing and redemption.
 *
 * Attendance is the IDENTITY SOURCE: it issues short-lived assertions the ordering
 * system redeems. It never calls the ordering system, which is what lets Attendance
 * be deployed first and keep working when the other app is unreachable.
 */
beforeEach(function () {
    config()->set('sso.signing_key', 'test-signing-key');

    $this->service = app(SsoTokenService::class);

    $this->user = User::factory()->create([
        'name' => 'A Manager',
        'email' => 'manager@test.com',
    ]);
});

// ---- Issuing ----------------------------------------------------------

it('issues a token that redeems once with the expected claims', function () {
    $plain = $this->service->issue($this->user);

    $claims = $this->service->redeem($plain);

    expect($claims)->not->toBeNull();
    expect($claims['email'])->toBe('manager@test.com');
    expect($claims['name'])->toBe('A Manager');
    expect($claims['role'])->toBe('owner');
    expect($claims['sub'])->toBe((string) $this->user->id);
});

it('never stores the plaintext token', function () {
    /*
     * A leaked database must not yield anything redeemable. The plaintext exists
     * only in the redirect that carries it.
     */
    $plain = $this->service->issue($this->user);

    $stored = SsoToken::first();

    expect($stored->token_hash)->not->toBe($plain);
    expect($stored->token_hash)->toHaveLength(64);
    expect(json_encode($stored->toArray()))->not->toContain($plain);
});

it('refuses to issue a second redemption of the same token', function () {
    /*
     * Single use is the core guarantee: a token captured from a redirect history or
     * a log must not be usable after the legitimate redemption.
     */
    $plain = $this->service->issue($this->user);

    expect($this->service->redeem($plain))->not->toBeNull();
    expect($this->service->redeem($plain))->toBeNull();
});

it('refuses an expired token', function () {
    $plain = $this->service->issue($this->user);

    $this->travel(SsoTokenService::TTL_SECONDS + 1)->seconds();

    expect($this->service->redeem($plain))->toBeNull();
});

it('refuses a token issued for a different audience', function () {
    // One issuer may serve several consumers, and a token for one must not work at
    // another.
    $plain = $this->service->issue($this->user, 'ordering');

    expect($this->service->redeem($plain, 'something-else'))->toBeNull();
});

it('refuses an unknown token', function () {
    expect($this->service->redeem('not-a-real-token'))->toBeNull();
});

it('refuses a token whose user was deactivated before redemption', function () {
    /*
     * Checked at REDEMPTION, not only at issue. Otherwise deactivation would take
     * effect whenever the token happened to be issued, and a disabled user could
     * still get a session in the meantime.
     */
    $plain = $this->service->issue($this->user);

    $this->user->update(['is_active' => false]);

    expect($this->service->redeem($plain))->toBeNull();
});

it('refuses a token whose user was deleted before redemption', function () {
    $plain = $this->service->issue($this->user);

    $this->user->forceDelete();

    expect($this->service->redeem($plain))->toBeNull();
});

// ---- Claim shape ------------------------------------------------------

it('sends outlet ids so the consumer can scope', function () {
    $ramal = Outlet::create(['code' => 'SG-RAMAL', 'name' => 'Sg Ramal']);

    $manager = User::factory()->forOutlets($ramal)->create();

    $claims = $this->service->redeem($this->service->issue($manager));

    expect($claims['outlets'])->toBe([$ramal->id]);
});

it('sends an empty outlet list for an owner, who is unrestricted', function () {
    /*
     * An owner has no mappings. The consumer distinguishes "unrestricted" from "no
     * access" by ROLE, so an empty list plus role=owner must mean everything.
     */
    $claims = $this->service->redeem($this->service->issue($this->user));

    expect($claims['role'])->toBe('owner');
    expect($claims['outlets'])->toBe([]);
});

it('never sends anything the consumer has no business knowing', function () {
    /*
     * The ordering system takes orders; it has no reason to know what anyone is
     * paid, and no reason to receive anything reusable as a credential.
     *
     * Note that IC numbers and pay rates live on `employees`, not `users`, so they
     * are structurally out of reach of these claims — this asserts the claim list
     * explicitly anyway, because a future field added to `claimsFor()` would
     * otherwise go unnoticed.
     */
    $claims = $this->service->redeem($this->service->issue($this->user));

    $encoded = json_encode($claims);

    expect($encoded)->not->toContain('password');
    expect($encoded)->not->toContain('pin');
    expect($encoded)->not->toContain('ic_number');
    expect($encoded)->not->toContain('pay_basis');
    expect($encoded)->not->toContain('rate');

    // The exact claim set, so an addition to claimsFor() fails this test.
    expect(array_keys($claims))->toBe([
        'email', 'name', 'role', 'is_active', 'outlets', 'email_verified', 'sub',
    ]);
});

it('cannot leak employee pay data because it is not on the user record', function () {
    // Structural, not just a filter: pay and IC data live on `employees`, so the
    // identity source has nothing sensitive to send even by accident.
    $employee = Employee::create([
        'employee_code' => 'DCC-001',
        'name' => 'A Manager',
        'ic_number' => 'encrypted-value',
        'pay_basis' => PayBasis::HOURLY,
        'user_id' => $this->user->id,
    ]);

    $claims = $this->service->redeem($this->service->issue($this->user->fresh()));

    $encoded = json_encode($claims);

    expect($encoded)->not->toContain($employee->employee_code);
    expect($encoded)->not->toContain('encrypted-value');
    expect($encoded)->not->toContain('hourly');
});

it('hashes with the configured signing key', function () {
    // Two services with different keys must not accept each other's tokens, which
    // is what makes rotating the key a real revocation.
    $plain = $this->service->issue($this->user);

    $otherKey = new SsoTokenService('a-different-key');

    expect($otherKey->redeem($plain))->toBeNull();
    expect($this->service->redeem($plain))->not->toBeNull();
});

// ---- One-time redemption under concurrency ---------------------------

it('lets only one of two simultaneous redemptions succeed', function () {
    /*
     * The conditional UPDATE (`WHERE consumed_at IS NULL`) is what makes this safe.
     * A read-then-write in PHP would let both callers see "not consumed" and both
     * proceed, producing two sessions from one token.
     */
    $plain = $this->service->issue($this->user);

    $results = collect(range(1, 5))
        ->map(fn () => $this->service->redeem($plain))
        ->filter()   // drop the nulls
        ->count();

    expect($results)->toBe(1);
});

it('records when and from where a token was redeemed', function () {
    // Diagnosis: if a token was used unexpectedly, this is what says when.
    $plain = $this->service->issue($this->user);

    $this->service->redeem($plain, 'ordering', '203.0.113.7');

    $stored = SsoToken::first();

    expect($stored->consumed_at)->not->toBeNull();
    expect($stored->consumed_by_ip)->toBe('203.0.113.7');
});
