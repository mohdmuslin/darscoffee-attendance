<?php

use App\Enums\UserRole;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * Console sign-in.
 *
 * Deliberately local: no call to the ordering system happens here, and none ever
 * should. Staff clock in at 07:00 whether or not another application is reachable,
 * and Attendance must be deployable on its own — see docs/sso.md.
 */
beforeEach(function () {
    $this->owner = User::factory()->create([
        'email' => 'owner@test.com',
        'password' => 'secret-password',
    ]);
});

// ---- Signing in -------------------------------------------------------

it('signs in with valid credentials and returns a token', function () {
    $this->postJson('/api/v1/auth/login', [
        'email' => 'owner@test.com',
        'password' => 'secret-password',
    ])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.user.role', 'owner')
        ->assertJsonStructure(['data' => ['token', 'user']]);
});

it('records the last sign-in time', function () {
    expect($this->owner->last_login_at)->toBeNull();

    $this->postJson('/api/v1/auth/login', [
        'email' => 'owner@test.com',
        'password' => 'secret-password',
    ])->assertOk();

    expect($this->owner->fresh()->last_login_at)->not->toBeNull();
});

it('rejects a wrong password', function () {
    $this->postJson('/api/v1/auth/login', [
        'email' => 'owner@test.com',
        'password' => 'not-the-password',
    ])->assertStatus(422)->assertJsonValidationErrors('email');
});

it('gives the same message for an unknown email as for a wrong password', function () {
    /*
     * A different message for "no such user" would let an attacker enumerate which
     * emails have accounts, and the business's email pattern would be revealed.
     */
    $unknown = $this->postJson('/api/v1/auth/login', [
        'email' => 'nobody@test.com',
        'password' => 'whatever',
    ]);

    $wrongPassword = $this->postJson('/api/v1/auth/login', [
        'email' => 'owner@test.com',
        'password' => 'whatever',
    ]);

    expect($unknown->json('errors.email'))
        ->toBe($wrongPassword->json('errors.email'));
});

it('refuses a deactivated account at sign-in', function () {
    $this->owner->update(['is_active' => false]);

    $this->postJson('/api/v1/auth/login', [
        'email' => 'owner@test.com',
        'password' => 'secret-password',
    ])
        ->assertStatus(403)
        ->assertJsonPath('code', 'ACCOUNT_INACTIVE');
});

it('requires both email and password', function () {
    $this->postJson('/api/v1/auth/login', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email', 'password']);
});

// ---- Deactivation takes effect immediately ---------------------------

it('refuses an existing token once the account is deactivated', function () {
    /*
     * The important one. A Sanctum token outlives the account it was issued for, so
     * without a per-request check, disabling someone would take effect only when
     * their token expired — possibly weeks later.
     *
     * On the ordering system a deactivated admin kept full back-office access until
     * the token lapsed, so this is asserted rather than assumed.
     */
    $token = $this->postJson('/api/v1/auth/login', [
        'email' => 'owner@test.com',
        'password' => 'secret-password',
    ])->json('data.token');

    // Works while active.
    $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();

    $this->owner->update(['is_active' => false]);

    /*
     * A fresh guard, because a feature test reuses one application instance across
     * requests and the guard would otherwise still hold the user resolved by the
     * previous call. In production each request is a new process, so this is a
     * harness concern rather than a behaviour being worked around.
     */
    freshRequest();

    $this->withToken($token)->getJson('/api/v1/auth/me')
        ->assertStatus(403)
        ->assertJsonPath('code', 'ACCOUNT_INACTIVE');
});

it('revokes the token when it refuses a deactivated account', function () {
    // Otherwise a retry could recover the session, and the stale token would sit in
    // the database indefinitely.
    $token = $this->postJson('/api/v1/auth/login', [
        'email' => 'owner@test.com',
        'password' => 'secret-password',
    ])->json('data.token');

    $this->owner->update(['is_active' => false]);

    freshRequest();

    $this->withToken($token)->getJson('/api/v1/auth/me')->assertStatus(403);

    expect($this->owner->tokens()->count())->toBe(0);
});

// ---- Sessions ---------------------------------------------------------

it('signs out, invalidating the token used', function () {
    $token = $this->postJson('/api/v1/auth/login', [
        'email' => 'owner@test.com',
        'password' => 'secret-password',
    ])->json('data.token');

    $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();

    // A fresh guard, or the previous request's resolved user would still satisfy
    // the auth check despite its token having been deleted.
    freshRequest();

    $this->withToken($token)->getJson('/api/v1/auth/me')->assertStatus(401);
});

it('lets a second device sign in without signing the first out', function () {
    /*
     * A manager on a phone and a desktop is normal, and signing in on one must not
     * kick them off the other mid-shift.
     */
    $first = $this->postJson('/api/v1/auth/login', [
        'email' => 'owner@test.com', 'password' => 'secret-password',
    ])->json('data.token');

    $second = $this->postJson('/api/v1/auth/login', [
        'email' => 'owner@test.com', 'password' => 'secret-password',
    ])->json('data.token');

    expect($second)->not->toBe($first);

    $this->withToken($first)->getJson('/api/v1/auth/me')->assertOk();
    $this->withToken($second)->getJson('/api/v1/auth/me')->assertOk();
});

it('rejects a request with no token', function () {
    $this->getJson('/api/v1/auth/me')->assertStatus(401);
});

// ---- What the console is told ---------------------------------------

it('reports an owner as unrestricted, not as having no outlets', function () {
    /*
     * null means "every outlet" and an empty array means "none". The console must
     * be able to tell them apart, or an unrestricted owner could render as having
     * access to nothing.
     */
    $response = $this->withToken(
        $this->postJson('/api/v1/auth/login', [
            'email' => 'owner@test.com', 'password' => 'secret-password',
        ])->json('data.token')
    )->getJson('/api/v1/auth/me')->assertOk();

    expect($response->json('data.user.visible_outlet_ids'))->toBeNull();
    expect($response->json('data.user.can_administer'))->toBeTrue();
    expect($response->json('data.user.is_self_service_only'))->toBeFalse();
});

it('reports a manager with only their mapped outlets', function () {
    $ramal = Outlet::create(['code' => 'SG-RAMAL', 'name' => 'Sg Ramal']);
    $santai = Outlet::create(['code' => 'SEDAP-SANTAI', 'name' => 'Sedap Santai']);

    $manager = User::factory()->forOutlets($ramal)->create([
        'email' => 'manager@test.com',
        'password' => 'manager-password',
    ]);

    $response = $this->withToken(
        $this->postJson('/api/v1/auth/login', [
            'email' => 'manager@test.com', 'password' => 'manager-password',
        ])->json('data.token')
    )->getJson('/api/v1/auth/me')->assertOk();

    expect($response->json('data.user.visible_outlet_ids'))->toBe([$ramal->id]);
    expect($response->json('data.user.can_administer'))->toBeTrue();
});

it('reports a manager with no mapping as having access to nothing', function () {
    /*
     * Fails closed. Forgetting to map an outlet must lock the manager out rather
     * than silently grant every outlet — which is why visibleOutletIds() returns an
     * empty array here and null only for an owner.
     */
    $manager = User::factory()->manager()->create([
        'email' => 'unmapped@test.com',
        'password' => 'manager-password',
    ]);

    $response = $this->withToken(
        $this->postJson('/api/v1/auth/login', [
            'email' => 'unmapped@test.com', 'password' => 'manager-password',
        ])->json('data.token')
    )->getJson('/api/v1/auth/me')->assertOk();

    expect($response->json('data.user.visible_outlet_ids'))->toBe([]);

    // And the model agrees: an empty mapping grants no outlet at all.
    expect($manager->canAccessOutlet(1))->toBeFalse();
    expect($manager->visibleOutletIds())->toBe([]);
});

it('reports staff as self-service only', function () {
    // Staff sign in to see their own hours, and nothing else.
    $staff = User::factory()->create([
        'email' => 'staff@test.com',
        'password' => 'staff-password',
        'role' => UserRole::STAFF,
    ]);

    $response = $this->withToken(
        $this->postJson('/api/v1/auth/login', [
            'email' => 'staff@test.com', 'password' => 'staff-password',
        ])->json('data.token')
    )->getJson('/api/v1/auth/me')->assertOk();

    expect($response->json('data.user.is_self_service_only'))->toBeTrue();
    expect($response->json('data.user.can_administer'))->toBeFalse();
});

it('never exposes the password hash', function () {
    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'owner@test.com', 'password' => 'secret-password',
    ])->assertOk();

    expect($response->getContent())->not->toContain('password');
    expect($response->getContent())->not->toContain(Hash::make('x'));
});
