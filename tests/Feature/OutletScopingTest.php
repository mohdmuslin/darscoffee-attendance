<?php

use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\Outlet;
use App\Models\User;

/**
 * Outlet scoping — the security boundary of this application.
 *
 * A manager at one outlet must not be able to reach another outlet's staff, their
 * PINs or their photographs, whether by clicking or by crafting a request. The UI
 * is not the guard; these endpoints are.
 *
 * Scoping must also fail CLOSED: a manager with no mapping sees nobody.
 */
beforeEach(function () {
    $this->ramal = Outlet::create(['code' => 'SG-RAMAL', 'name' => 'Sg Ramal']);
    $this->santai = Outlet::create(['code' => 'SEDAP-SANTAI', 'name' => 'Sedap Santai']);

    $this->owner = User::factory()->create(['email' => 'owner@test.com', 'password' => 'secret']);

    $this->ramalManager = User::factory()->forOutlets($this->ramal)->create([
        'email' => 'ramal@test.com', 'password' => 'secret',
    ]);

    // An employee at each outlet, so cross-outlet access is testable.
    $this->ramalStaff = Employee::create([
        'employee_code' => 'RAM-001', 'name' => 'Ramal Worker',
    ]);
    $this->ramalStaff->outlets()->attach($this->ramal->id, ['is_primary' => true]);

    $this->santaiStaff = Employee::create([
        'employee_code' => 'SAN-001', 'name' => 'Santai Worker',
    ]);
    $this->santaiStaff->outlets()->attach($this->santai->id, ['is_primary' => true]);
});

// ---- Listing ---------------------------------------------------------

it('shows an owner every employee', function () {
    $this->getJson('/api/v1/admin/employees', asUser($this->owner))
        ->assertOk()
        ->assertJsonCount(2, 'data.employees');
});

it('shows a manager only their own outlet staff', function () {
    /*
     * The core guarantee. Both employees exist; the manager must see one.
     */
    $response = $this->getJson('/api/v1/admin/employees', asUser($this->ramalManager))
        ->assertOk();

    $names = collect($response->json('data.employees'))->pluck('name');

    expect($names)->toContain('Ramal Worker');
    expect($names)->not->toContain('Santai Worker');
});

it('shows a manager with no mapping nobody at all', function () {
    // Fails closed. Forgetting to map an outlet must lock them out, not open everything.
    $unmapped = User::factory()->manager()->create(['email' => 'none@test.com']);

    $this->getJson('/api/v1/admin/employees', asUser($unmapped))
        ->assertOk()
        ->assertJsonCount(0, 'data.employees');
});

it('shows a manager both outlets they are mapped to', function () {
    // One manager covering two sites is the stated case.
    $both = User::factory()->forOutlets($this->ramal, $this->santai)
        ->create(['email' => 'both@test.com']);

    $this->getJson('/api/v1/admin/employees', asUser($both))
        ->assertOk()
        ->assertJsonCount(2, 'data.employees');
});

it('cannot widen scope by filtering on another outlet', function () {
    /*
     * A manager may legitimately send outlet_id — the UI offers it as a filter. But
     * the scope is applied FIRST, so an out-of-scope id can only narrow to nothing.
     */
    $this->getJson("/api/v1/admin/employees?outlet_id={$this->santai->id}", asUser($this->ramalManager))
        ->assertOk()
        ->assertJsonCount(0, 'data.employees');
});

it('cannot widen scope with a search term', function () {
    // Search must not become a way to reach rows the scope excluded.
    $this->getJson('/api/v1/admin/employees?search=Santai', asUser($this->ramalManager))
        ->assertOk()
        ->assertJsonCount(0, 'data.employees');
});

it('blocks staff from listing employees at all', function () {
    /*
     * Staff sign in to see their own hours. Seeing the staff list is not part of
     * that, and `staff` is deliberately not a manager with no outlets.
     */
    $staff = User::factory()->create(['email' => 'staff@test.com', 'role' => UserRole::STAFF]);

    $this->getJson('/api/v1/admin/employees', asUser($staff))->assertStatus(403);
});

// ---- Single records --------------------------------------------------

it('refuses to show another outlet employee', function () {
    $this->getJson("/api/v1/admin/employees/{$this->santaiStaff->id}", asUser($this->ramalManager))
        ->assertStatus(404);
});

it('allows showing an employee at a shared outlet', function () {
    $this->getJson("/api/v1/admin/employees/{$this->ramalStaff->id}", asUser($this->ramalManager))
        ->assertOk()
        ->assertJsonPath('data.employee.employee_code', 'RAM-001');
});

it('refuses to update another outlet employee', function () {
    $this->patchJson(
        "/api/v1/admin/employees/{$this->santaiStaff->id}",
        ['name' => 'Renamed'],
        asUser($this->ramalManager),
    )->assertStatus(404);

    // And the record is genuinely untouched, not merely reported as refused.
    expect($this->santaiStaff->fresh()->name)->toBe('Santai Worker');
});

it('refuses to set a PIN for another outlet employee', function () {
    /*
     * Setting a PIN is how a manager could clock someone in as themselves, so
     * cross-outlet PIN setting would be a direct route to fabricated hours.
     */
    $this->putJson(
        "/api/v1/admin/employees/{$this->santaiStaff->id}/pin",
        ['pin' => '1234'],
        asUser($this->ramalManager),
    )->assertStatus(404);

    expect($this->santaiStaff->fresh()->hasPin())->toBeFalse();
});

it('refuses to deactivate another outlet employee', function () {
    $this->postJson(
        "/api/v1/admin/employees/{$this->santaiStaff->id}/deactivate",
        [],
        asUser($this->ramalManager),
    )->assertStatus(404);

    expect($this->santaiStaff->fresh()->is_active)->toBeTrue();
});

// ---- Creating --------------------------------------------------------

it('refuses to place a new employee at an outlet the manager cannot see', function () {
    /*
     * Otherwise a manager could create an employee somewhere they cannot manage and
     * immediately lose sight of them — or worse, seed an outlet they do not own.
     */
    $this->postJson('/api/v1/admin/employees', [
        'employee_code' => 'SAN-999',
        'name' => 'Smuggled',
        'outlet_ids' => [$this->santai->id],
    ], asUser($this->ramalManager))->assertStatus(404);

    expect(Employee::where('employee_code', 'SAN-999')->exists())->toBeFalse();
});

it('allows a manager to add staff to their own outlet', function () {
    $this->postJson('/api/v1/admin/employees', [
        'employee_code' => 'RAM-002',
        'name' => 'New Starter',
        'outlet_ids' => [$this->ramal->id],
    ], asUser($this->ramalManager))->assertStatus(201);

    expect(Employee::where('employee_code', 'RAM-002')->exists())->toBeTrue();
});

it('refuses to move an employee to an outlet the manager cannot see', function () {
    $this->patchJson(
        "/api/v1/admin/employees/{$this->ramalStaff->id}",
        ['outlet_ids' => [$this->santai->id]],
        asUser($this->ramalManager),
    )->assertStatus(404);

    // Still at the original outlet.
    expect($this->ramalStaff->fresh()->outlets->pluck('id')->all())->toBe([$this->ramal->id]);
});

// ---- Outlets ---------------------------------------------------------

it('shows a manager only their own outlets', function () {
    $response = $this->getJson('/api/v1/admin/outlets', asUser($this->ramalManager))->assertOk();

    $codes = collect($response->json('data.outlets'))->pluck('code');

    expect($codes)->toContain('SG-RAMAL');
    expect($codes)->not->toContain('SEDAP-SANTAI');
});

it('does not reveal whether another outlet exists', function () {
    // 404 rather than 403: a probe should learn nothing.
    $this->getJson("/api/v1/admin/outlets/{$this->santai->id}/token", asUser($this->ramalManager))
        ->assertStatus(404);
});

it('lets a manager reprint their own outlet code', function () {
    /*
     * Necessary, not optional: reprinting is the REVOKE mechanism for a printed code,
     * so if a manager cannot reprint, a leaked sheet stays live.
     */
    $this->ramal->update(['token_mode' => 'printed']);

    $this->postJson("/api/v1/admin/outlets/{$this->ramal->id}/token", [], asUser($this->ramalManager))
        ->assertOk()
        ->assertJsonPath('data.token.token', fn ($t) => filled($t));
});

it('refuses a manager reprinting another outlet code', function () {
    $this->postJson("/api/v1/admin/outlets/{$this->santai->id}/token", [], asUser($this->ramalManager))
        ->assertStatus(404);
});

it('refuses a manager adding an outlet', function () {
    // Adding a location is an owner decision.
    $this->postJson('/api/v1/admin/outlets', [
        'code' => 'NEW-SITE', 'name' => 'New Site',
    ], asUser($this->ramalManager))->assertStatus(403);

    expect(Outlet::where('code', 'NEW-SITE')->exists())->toBeFalse();
});

// ---- Accounts --------------------------------------------------------

it('refuses a manager managing console accounts', function () {
    // Creating an account grants access; a manager able to create managers could
    // widen their own scope.
    $this->getJson('/api/v1/admin/users', asUser($this->ramalManager))->assertStatus(403);

    $this->postJson('/api/v1/admin/users', [
        'name' => 'Sneaky', 'email' => 'sneaky@test.com',
        'password' => 'password123', 'role' => 'owner',
    ], asUser($this->ramalManager))->assertStatus(403);

    expect(User::where('email', 'sneaky@test.com')->exists())->toBeFalse();
});

it('lets an owner create a manager mapped to an outlet', function () {
    $this->postJson('/api/v1/admin/users', [
        'name' => 'New Manager',
        'email' => 'newmanager@test.com',
        'password' => 'password123',
        'role' => 'manager',
        'outlet_ids' => [$this->santai->id],
    ], asUser($this->owner))->assertStatus(201);

    $created = User::where('email', 'newmanager@test.com')->first();

    expect($created->visibleOutletIds())->toBe([$this->santai->id]);
});

it('requires a manager to be given at least one outlet', function () {
    /*
     * A manager with no mapping sees nothing, so creating one without outlets
     * produces an account that appears broken. Refused at creation rather than left
     * to be discovered later.
     */
    $this->postJson('/api/v1/admin/users', [
        'name' => 'Useless Manager',
        'email' => 'useless@test.com',
        'password' => 'password123',
        'role' => 'manager',
    ], asUser($this->owner))->assertStatus(422)->assertJsonValidationErrors('outlet_ids');
});

it('refuses to remove the last owner', function () {
    /*
     * Demoting or deactivating the only owner would lock everyone out of every
     * screen, with no way back in.
     */
    $this->postJson(
        "/api/v1/admin/users/{$this->owner->id}/deactivate",
        [],
        asUser($this->owner),
    )->assertStatus(409)->assertJsonPath('code', 'LAST_OWNER');

    expect($this->owner->fresh()->is_active)->toBeTrue();
});

it('allows deactivating an owner when another active owner remains', function () {
    $second = User::factory()->create(['email' => 'owner2@test.com']);

    $this->postJson(
        "/api/v1/admin/users/{$this->owner->id}/deactivate",
        [],
        asUser($second),
    )->assertOk();

    expect($this->owner->fresh()->is_active)->toBeFalse();
});

it('signs a deactivated account out everywhere immediately', function () {
    // Belt and braces: deactivation revokes tokens rather than waiting for expiry.
    $staff = User::factory()->manager()->create(['email' => 'temp@test.com']);
    $staff->outlets()->attach($this->ramal->id);

    $token = $staff->createToken('test')->plainTextToken;

    $this->getJson('/api/v1/admin/employees', ['Authorization' => "Bearer {$token}"])->assertOk();

    /*
     * A fresh guard before switching identity. A feature test reuses one application
     * instance, so without this the next request would still authenticate as the
     * MANAGER — and the owner-only deactivate call would be refused as forbidden.
     */
    freshRequest();

    $this->postJson(
        "/api/v1/admin/users/{$staff->id}/deactivate",
        [],
        asUser($this->owner),
    )->assertOk();

    expect($staff->fresh()->tokens()->count())->toBe(0);

    // And the revoked token must be refused rather than merely absent.
    freshRequest();

    $this->getJson('/api/v1/admin/employees', ['Authorization' => "Bearer {$token}"])
        ->assertStatus(401);
});

// ---- Employee payload safety ----------------------------------------

it('never exposes a PIN hash or an unmasked IC number', function () {
    $this->ramalStaff->setPin('4321');
    $this->ramalStaff->setIcNumber('900101-14-5566');

    $response = $this->getJson(
        "/api/v1/admin/employees/{$this->ramalStaff->id}",
        asUser($this->owner),
    )->assertOk();

    $content = $response->getContent();

    expect($content)->not->toContain('pin_hash');
    expect($content)->not->toContain('900101-14-5566');
    // The masked form is what may appear.
    expect($response->json('data.employee.ic_number_masked'))->toContain('5566');
    expect($response->json('data.employee.ic_number_masked'))->toContain('*');
});

it('reports whether a PIN exists without revealing it', function () {
    $before = $this->getJson("/api/v1/admin/employees/{$this->ramalStaff->id}", asUser($this->owner));
    expect($before->json('data.employee.has_pin'))->toBeFalse();

    $this->putJson(
        "/api/v1/admin/employees/{$this->ramalStaff->id}/pin",
        ['pin' => '9876'],
        asUser($this->owner),
    )->assertOk()->assertJsonPath('data.employee.has_pin', true);

    // And the stored value is a hash, not the PIN.
    expect($this->ramalStaff->fresh()->pin_hash)->not->toBe('9876');
    expect($this->ramalStaff->fresh()->verifyPin('9876'))->toBeTrue();
});
