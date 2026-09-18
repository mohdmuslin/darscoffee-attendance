<?php

use App\Enums\ConsentMethod;
use App\Enums\TimeEntryStatus;
use App\Enums\TimeEntryType;
use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\Outlet;
use App\Models\PunchEvent;
use App\Models\Setting;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\ConsentService;
use App\Services\OutletTokenService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * PDPA consent.
 *
 * The theme: a consent record exists to be DEFENDED. Every one of these tests is about
 * something an employer would actually be asked and would otherwise be unable to answer —
 * who took it, what was agreed to, and what happened when someone changed their mind.
 *
 * Withdrawal gets the most attention, because it is where a system that merely records
 * something diverges from one that does something. Recording a withdrawal and carrying on
 * photographing the person is the failure that looks like compliance from the outside.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-21 09:00:00', 'Asia/Kuala_Lumpur'));

    // Frozen rather than travelled: the punch tests derive durations from real timestamps,
    // which drift by a second between drivers when the machine is busy.
    $this->freezeTime();

    Storage::fake('local');

    $this->outlet = Outlet::create([
        'code' => 'SG-RAMAL',
        'name' => 'Sg Ramal',
        'timezone' => 'Asia/Kuala_Lumpur',
        'token_mode' => 'printed',
        'requires_photo' => true,
    ]);

    $this->owner = User::factory()->create(['role' => UserRole::OWNER]);
    $this->manager = User::factory()->create(['role' => UserRole::MANAGER]);
    $this->manager->outlets()->attach($this->outlet->id);

    $this->employee = Employee::create([
        'employee_code' => 'RAM-001',
        'name' => 'Ali bin Ahmad',
        'is_active' => true,
    ]);
    $this->employee->outlets()->attach($this->outlet->id);

    $this->consent = app(ConsentService::class);
});

/**
 * Open a punch session for an employee, returning its token.
 *
 * Named distinctly rather than `punchIn`/`act`, which already exist in PunchFlowTest: Pest
 * loads every feature file into ONE process, so a redeclared function is a fatal error that
 * takes out the whole suite rather than the one file that caused it.
 *
 * The PIN and outlet token are set up here rather than in `beforeEach`, so the tests that do
 * not use the punch flow are not made to care about them.
 *
 * @return array{token: string, employee: Employee}
 */
function startPunchSession(Outlet $outlet, Employee $employee): array
{
    $employee->setPin('4321');

    $token = app(OutletTokenService::class)->regenerate($outlet);

    $response = test()->postJson('/api/v1/punch/start', [
        'token' => $token->token,
        'pin' => '4321',
    ]);

    expect($response->status())->toBe(200, 'punch/start failed: '.$response->getContent());

    return ['token' => $response->json('data.punch_token'), 'employee' => $employee];
}

it('records consent with who took it, how, and against which notice', function () {
    $employee = $this->consent->record(
        $this->employee,
        ConsentMethod::SIGNED_FORM,
        $this->manager,
        note: 'Form kept in the office file.',
    );

    expect($employee->consent_at)->not->toBeNull()
        ->and($employee->consent_method)->toBe(ConsentMethod::SIGNED_FORM)
        ->and($employee->consent_recorded_by)->toBe($this->manager->id)
        ->and($employee->consent_version)->toBe(ConsentService::NOTICE_VERSION)
        ->and($employee->consent_note)->toBe('Form kept in the office file.')
        ->and($employee->hasConsent())->toBeTrue();
});

it('reports an employee with a photo and no consent record as needing consent', function () {
    $this->employee->update(['photo_path' => 'employees/already-there.jpg']);

    expect($this->employee->fresh()->needsConsent())->toBeTrue()
        ->and($this->employee->fresh()->hasConsent())->toBeFalse();
});

/**
 * A withdrawal is only a withdrawal if it stops the next photograph.
 *
 * This is the test the whole service exists for. A record that says "consent withdrawn"
 * while the punch screen keeps demanding a photograph is worse than no record at all: the
 * system asserts the objection was honoured and visibly is not.
 */
it('stops requiring a photo once consent is withdrawn', function () {
    $this->consent->record($this->employee, ConsentMethod::VERBAL, $this->manager);

    $employee = $this->employee->fresh();
    expect($employee->isPhotographRequired($this->outlet))->toBeTrue();

    $employee = $this->consent->withdraw($employee, 'Prefers not to be photographed.');

    expect($employee->isPhotographRequired($this->outlet))->toBeFalse()
        ->and($employee->hasWithdrawnConsent())->toBeTrue()
        ->and($employee->hasConsent())->toBeFalse();
});

/**
 * Withdrawal must not delete punch photographs.
 *
 * They may be evidence in an open dispute, and they are not solely the objector's — a
 * manager facing a wage claim needs them. Erasing contested evidence is a decision for a
 * human, not a side effect of a tick box.
 */
it('keeps punch photographs when consent is withdrawn', function () {
    $this->consent->record($this->employee, ConsentMethod::VERBAL, $this->manager);

    $path = 'punches/evidence.jpg';
    Storage::disk('local')->put($path, 'photo-bytes');

    $entry = TimeEntry::create([
        'client_uuid' => (string) Str::uuid(),
        'employee_id' => $this->employee->id,
        'outlet_id' => $this->outlet->id,
        'type' => TimeEntryType::WORK,
        'started_at' => now()->subDays(2),
        'ended_at' => now()->subDays(2)->addHours(8),
        'business_date' => now()->subDays(2)->toDateString(),
        'status' => TimeEntryStatus::CLOSED,
        'started_photo_path' => $path,
    ]);

    $this->consent->withdraw($this->employee, 'Objection received.');

    expect(Storage::disk('local')->exists($path))->toBeTrue()
        ->and($entry->fresh()->started_photo_path)->toBe($path);
});

it('deletes the profile photo when consent is withdrawn', function () {
    $this->consent->record($this->employee, ConsentMethod::WRITTEN, $this->manager);

    $path = 'employees/profile.jpg';
    Storage::disk('local')->put($path, 'profile-bytes');
    $this->employee->update(['photo_path' => $path]);

    $employee = $this->consent->withdraw($this->employee->fresh(), 'Asked for it to be removed.');

    // A profile photo is convenience, not evidence, so it goes.
    expect(Storage::disk('local')->exists($path))->toBeFalse()
        ->and($employee->photo_path)->toBeNull();
});

it('keeps the original consent date when consent is withdrawn', function () {
    $this->consent->record($this->employee, ConsentMethod::VERBAL, $this->manager);

    $givenAt = $this->employee->fresh()->consent_at;

    $this->travel(30)->days();
    $employee = $this->consent->withdraw($this->employee->fresh());

    /*
     * Blanking `consent_at` would suggest consent was never given, casting doubt on the
     * lawfulness of every photograph taken while it was — and those were lawful.
     */
    expect($employee->consent_at->equalTo($givenAt))->toBeTrue()
        ->and($employee->consent_withdrawn_at)->not->toBeNull();
});

/**
 * Someone who withdrew and changed their mind has consented.
 *
 * An implementation that leaves `consent_withdrawn_at` set alongside a fresh `consent_at`
 * would report them as unconsented for ever, and the only way to fix it would be a database
 * edit — which is how a compliance report stops being trusted.
 */
it('clears the withdrawal when consent is given again', function () {
    $this->consent->record($this->employee, ConsentMethod::VERBAL, $this->manager);
    $this->consent->withdraw($this->employee->fresh(), 'First objection.');

    $employee = $this->consent->record(
        $this->employee->fresh(),
        ConsentMethod::SIGNED_FORM,
        $this->owner,
    );

    expect($employee->hasConsent())->toBeTrue()
        ->and($employee->consent_withdrawn_at)->toBeNull()
        ->and($employee->consent_withdrawal_note)->toBeNull()
        ->and($employee->consent_method)->toBe(ConsentMethod::SIGNED_FORM)
        ->and($employee->consent_recorded_by)->toBe($this->owner->id);
});

it('lists the employees whose photograph has no consent behind it', function () {
    $withPhoto = Employee::create(['employee_code' => 'RAM-002', 'name' => 'Bala', 'is_active' => true]);
    $withPhoto->outlets()->attach($this->outlet->id);
    $withPhoto->update(['photo_path' => 'employees/bala.jpg']);

    $consented = Employee::create(['employee_code' => 'RAM-003', 'name' => 'Chen', 'is_active' => true]);
    $consented->outlets()->attach($this->outlet->id);
    $consented->update(['photo_path' => 'employees/chen.jpg']);
    $this->consent->record($consented->fresh(), ConsentMethod::WRITTEN, $this->manager);

    $noPhoto = Employee::create(['employee_code' => 'RAM-004', 'name' => 'Devi', 'is_active' => true]);
    $noPhoto->outlets()->attach($this->outlet->id);

    $backlog = $this->consent->withoutConsent();

    // Only the one holding a photograph without consent. No photo is not a breach.
    expect($backlog->pluck('name')->all())->toBe(['Bala']);
});

it('includes a withdrawn employee in the backlog', function () {
    $this->consent->record($this->employee, ConsentMethod::VERBAL, $this->manager);
    $this->employee->update(['photo_path' => 'employees/ali.jpg']);

    expect($this->consent->withoutConsent())->toHaveCount(0);

    $this->consent->withdraw($this->employee->fresh());

    // Withdrawal deletes the profile photo, so re-create the situation the backlog is for:
    // a photograph still on file with no live consent.
    /*
     * A query-builder update, not `$this->employee->update(...)`.
     *
     * Withdrawal clears `photo_path` through `toBase()`, which bypasses Eloquent and leaves
     * any in-memory model stale. The instance in this test still believed the path was set,
     * so `update()` found nothing dirty and issued NO SQL — and the assertion then read the
     * database, which still held NULL. Writing through the query builder sidesteps a cached
     * model that no longer matches its row.
     */
    Employee::query()->whereKey($this->employee->id)->update(['photo_path' => 'employees/ali.jpg']);

    expect($this->consent->withoutConsent()->pluck('name')->all())->toBe(['Ali bin Ahmad']);
});

it('scopes the backlog to the outlets the viewer can see', function () {
    $otherOutlet = Outlet::create([
        'code' => 'SEDAP-SANTAI',
        'name' => 'Sedap Santai',
        'timezone' => 'Asia/Kuala_Lumpur',
        'token_mode' => 'printed',
        'requires_photo' => true,
    ]);

    $theirs = Employee::create(['employee_code' => 'SS-001', 'name' => 'Farid', 'is_active' => true]);
    $theirs->outlets()->attach($otherOutlet->id);
    $theirs->update(['photo_path' => 'employees/farid.jpg']);

    expect($this->consent->withoutConsent($this->manager))->toHaveCount(0)
        ->and($this->consent->withoutConsent($this->owner))->toHaveCount(1);
});

// ---- Photograph requirement -------------------------------------------------

/**
 * A missing consent record is NOT disqualifying by default, and that default is deliberate.
 *
 * Every existing employee has no consent row, so enforcing this by default would stop
 * photographs being captured altogether on the first morning — silently disabling the
 * anti-buddy-punching control the system exists for.
 */
it('still photographs an employee with no consent record by default', function () {
    expect(Setting::bool(Setting::REQUIRE_CONSENT_FOR_PHOTOS, false))->toBeFalse()
        ->and($this->employee->isPhotographRequired($this->outlet))->toBeTrue();
});

it('refuses to photograph an employee with no consent record when the owner enforces it', function () {
    Setting::set(Setting::REQUIRE_CONSENT_FOR_PHOTOS, '1');

    expect($this->employee->isPhotographRequired($this->outlet))->toBeFalse();
});

/**
 * Enforcement is overridden by a withdrawal, but never the other way round.
 *
 * The two rules point in opposite directions on purpose. A default protects the business's
 * existing control; an explicit withdrawal protects the person. Where they conflict the
 * person wins, because a withdrawal is an instruction and a default is not.
 */
it('honours a withdrawal even when consent enforcement is switched on', function () {
    Setting::set(Setting::REQUIRE_CONSENT_FOR_PHOTOS, '1');
    $this->consent->record($this->employee, ConsentMethod::VERBAL, $this->manager);

    expect($this->employee->fresh()->isPhotographRequired($this->outlet))->toBeTrue();

    $this->consent->withdraw($this->employee->fresh());

    expect($this->employee->fresh()->isPhotographRequired($this->outlet))->toBeFalse();
});

it('never requires a photo at an outlet that does not ask for one', function () {
    $this->outlet->update(['requires_photo' => false]);
    $this->consent->record($this->employee, ConsentMethod::VERBAL, $this->manager);

    // Consent can only narrow what the outlet asks for, never widen it.
    expect($this->employee->fresh()->isPhotographRequired($this->outlet->fresh()))->toBeFalse();
});

/**
 * "Not required" is not the same as "not permitted".
 *
 * This is a bug that shipped in the first version of this code, and the test exists because
 * it is invisible until someone notices their photograph was never saved. At an outlet that
 * does not require photos, an employee may still choose to supply one; the code asked
 * `isPhotographRequired()` — a question about the OUTLET — to decide whether a photograph
 * was permitted at all, and threw away every volunteered one.
 *
 * The two questions are separate and both are needed:
 *   required  = the outlet wants one AND the person may be photographed
 *   permitted = the person may be photographed, regardless of the outlet
 */
it('permits a volunteered photo at an outlet that does not require one', function () {
    $this->outlet->update(['requires_photo' => false]);

    expect($this->employee->fresh()->mayBePhotographed())->toBeTrue()
        ->and($this->employee->fresh()->isPhotographRequired($this->outlet->fresh()))->toBeFalse();
});

it('forbids a photo of someone who withdrew consent, required or not', function () {
    $this->consent->record($this->employee, ConsentMethod::VERBAL, $this->manager);
    $employee = $this->consent->withdraw($this->employee->fresh());

    // Refused even where no photo is demanded, because the objection is about the person.
    expect($employee->mayBePhotographed())->toBeFalse()
        ->and($employee->isPhotographRequired($this->outlet))->toBeFalse();

    $this->outlet->update(['requires_photo' => false]);

    expect($employee->fresh()->mayBePhotographed())->toBeFalse();
});

it('exposes consent state on the employee resource', function () {
    $this->consent->record($this->employee, ConsentMethod::WRITTEN, $this->manager);

    $response = $this->getJson(
        "/api/v1/admin/employees/{$this->employee->id}",
        asUser($this->owner),
    )->assertOk();

    $employee = $response->json('data.employee');

    expect($employee['has_consent'])->toBeTrue()
        ->and($employee['consent_withdrawn'])->toBeFalse()
        ->and($employee['needs_consent'])->toBeFalse()
        ->and($employee['consent_method'])->toBe('written')
        ->and($employee['consent_version'])->toBe(ConsentService::NOTICE_VERSION);
});

/**
 * The recorder's NAME must reach the client, not just the id.
 *
 * Found in the browser: the field silently vanished, because `consentRecorder` is a relation and
 * `whenLoaded` omits anything not eager-loaded. The page still worked and simply showed no
 * recorder — so the gap was invisible except as a missing name nobody knew to look for.
 *
 * A consent record whose recorder cannot be seen answers none of the questions it exists for.
 */
it('names the manager who recorded the consent', function () {
    $this->consent->record($this->employee, ConsentMethod::SIGNED_FORM, $this->manager);

    $response = $this->getJson(
        "/api/v1/admin/employees/{$this->employee->id}",
        asUser($this->owner),
    )->assertOk();

    expect($response->json('data.employee.consent_recorded_by'))
        ->toBe($this->manager->name);
});

it('names the recorder in the employee listing too', function () {
    $this->consent->record($this->employee, ConsentMethod::SIGNED_FORM, $this->manager);

    $response = $this->getJson('/api/v1/admin/employees', asUser($this->owner))->assertOk();

    expect($response->json('data.employees.0.consent_recorded_by'))
        ->toBe($this->manager->name);
});

it('reports has_consent as false after a withdrawal', function () {
    $this->consent->record($this->employee, ConsentMethod::WRITTEN, $this->manager);
    $this->consent->withdraw($this->employee->fresh());

    $response = $this->getJson(
        "/api/v1/admin/employees/{$this->employee->id}",
        asUser($this->owner),
    )->assertOk();

    /*
     * The distinction a naive client gets wrong: `consent_at` is still set, so a check on
     * that alone would report consent where there is none.
     */
    expect($response->json('data.employee.has_consent'))->toBeFalse()
        ->and($response->json('data.employee.consent_withdrawn'))->toBeTrue()
        ->and($response->json('data.employee.consent_at'))->not->toBeNull();
});

// ---- API --------------------------------------------------------------------

it('records consent through the API', function () {
    $this->postJson(
        "/api/v1/admin/employees/{$this->employee->id}/consent",
        [
            'method' => 'signed_form',
            'acknowledged' => true,
            'note' => 'Signed in front of me.',
        ],
        asUser($this->manager),
    )->assertOk();

    $employee = $this->employee->fresh();

    expect($employee->hasConsent())->toBeTrue()
        ->and($employee->consent_method)->toBe(ConsentMethod::SIGNED_FORM)
        // Taken from the token, never from the request body.
        ->and($employee->consent_recorded_by)->toBe($this->manager->id);
});

it('refuses consent without the acknowledgement', function () {
    $this->postJson(
        "/api/v1/admin/employees/{$this->employee->id}/consent",
        ['method' => 'verbal'],
        asUser($this->manager),
    )->assertStatus(422)->assertJsonValidationErrors('acknowledged');

    expect($this->employee->fresh()->hasConsent())->toBeFalse();
});

it('ignores a client-supplied recorder', function () {
    $someoneElse = User::factory()->create(['role' => UserRole::OWNER]);

    $this->postJson(
        "/api/v1/admin/employees/{$this->employee->id}/consent",
        [
            'method' => 'verbal',
            'acknowledged' => true,
            'consent_recorded_by' => $someoneElse->id,
        ],
        asUser($this->manager),
    )->assertOk();

    // Attribution is the part of the record most likely to be relied on, so it cannot be
    // supplied by the caller.
    expect($this->employee->fresh()->consent_recorded_by)->toBe($this->manager->id);
});

it('rejects an unknown consent method', function () {
    $this->postJson(
        "/api/v1/admin/employees/{$this->employee->id}/consent",
        ['method' => 'shook_hands', 'acknowledged' => true],
        asUser($this->manager),
    )->assertStatus(422)->assertJsonValidationErrors('method');
});

it('withdraws consent through the API', function () {
    $this->consent->record($this->employee, ConsentMethod::VERBAL, $this->manager);

    $this->deleteJson(
        "/api/v1/admin/employees/{$this->employee->id}/consent",
        ['note' => 'Asked to be removed.'],
        asUser($this->manager),
    )->assertOk();

    $employee = $this->employee->fresh();

    expect($employee->hasWithdrawnConsent())->toBeTrue()
        ->and($employee->consent_withdrawal_note)->toBe('Asked to be removed.');
});

it('lists the consent backlog through the API', function () {
    $this->employee->update(['photo_path' => 'employees/ali.jpg']);

    $response = $this->getJson('/api/v1/admin/employees/consent/backlog', asUser($this->owner))
        ->assertOk();

    expect($response->json('data.employees'))->toHaveCount(1)
        ->and($response->json('data.enforced'))->toBeFalse()
        ->and($response->json('data.notice_version'))->toBe(ConsentService::NOTICE_VERSION);
});

it('offers the consent methods with their labels', function () {
    $response = $this->getJson('/api/v1/admin/employees/consent/methods', asUser($this->manager))
        ->assertOk();

    expect($response->json('data.methods'))->toHaveCount(3)
        ->and($response->json('data.methods.0.value'))->toBe('verbal');
});

it('refuses consent for an employee at another outlet', function () {
    $otherOutlet = Outlet::create([
        'code' => 'DARS-COFFEE',
        'name' => 'Dars Coffee',
        'timezone' => 'Asia/Kuala_Lumpur',
        'token_mode' => 'printed',
        'requires_photo' => true,
    ]);

    $stranger = Employee::create(['employee_code' => 'DC-001', 'name' => 'Guna', 'is_active' => true]);
    $stranger->outlets()->attach($otherOutlet->id);

    // 404 rather than 403, so probing ids cannot confirm who exists.
    $this->postJson(
        "/api/v1/admin/employees/{$stranger->id}/consent",
        ['method' => 'verbal', 'acknowledged' => true],
        asUser($this->manager),
    )->assertNotFound();

    expect($stranger->fresh()->hasConsent())->toBeFalse();
});

it('refuses consent for a staff member with no console login', function () {
    $staff = User::factory()->create(['role' => UserRole::STAFF]);

    /*
     * 404, not 403: every record-level refusal in this controller answers 404 so that probing
     * sequential ids cannot distinguish "not yours" from "does not exist". A 403 here would
     * confirm the employee exists to someone with no business knowing that.
     */
    $this->postJson(
        "/api/v1/admin/employees/{$this->employee->id}/consent",
        ['method' => 'verbal', 'acknowledged' => true],
        asUser($staff),
    )->assertNotFound();

    expect($this->employee->fresh()->hasConsent())->toBeFalse();
});

// ---- The punch flow honours it ----------------------------------------------

/**
 * The end-to-end proof that a withdrawal changes what happens at the counter.
 *
 * The checks above prove the MODEL answers correctly. This proves the punch endpoint actually
 * asks it — the difference between a rule and a rule that is applied.
 */
it('does not demand a photo from an employee who withdrew consent', function () {
    $this->consent->record($this->employee, ConsentMethod::VERBAL, $this->manager);
    $this->consent->withdraw($this->employee->fresh(), 'Objection received.');

    $session = startPunchSession($this->outlet, $this->employee);

    // No photo attached, and the outlet requires one. Before the withdrawal this was a 422.
    $this->postJson('/api/v1/punch/act', [
        'action' => 'clock_in',
    ], ['X-Punch-Session' => $session['token']])->assertOk();

    expect(TimeEntry::first()->started_photo_path)->toBeNull();
});

it('discards a photo supplied after consent was withdrawn', function () {
    $this->consent->record($this->employee, ConsentMethod::VERBAL, $this->manager);

    /*
     * The session begins while consent still stands, as it would if the screen were already
     * open when the withdrawal was recorded — which is the realistic way this happens.
     */
    $session = startPunchSession($this->outlet, $this->employee);

    $this->consent->withdraw($this->employee->fresh(), 'Changed their mind mid-session.');

    $png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    $this->postJson('/api/v1/punch/act', [
        'action' => 'clock_in',
        'photo' => $png,
    ], ['X-Punch-Session' => $session['token']])->assertOk();

    /*
     * The punch succeeds — they did work — but the photograph is not kept. The photo being
     * taken at all is the event the consent existed to prevent, so it is recorded as a
     * rejection rather than silently dropped.
     */
    expect(TimeEntry::first()->started_photo_path)->toBeNull()
        ->and(PunchEvent::query()
            ->where('meta->reason', 'consent_withdrawn_photo_discarded')
            ->exists())->toBeTrue();
});

it('still stores a photo for an employee who has consented', function () {
    $this->consent->record($this->employee, ConsentMethod::SIGNED_FORM, $this->manager);

    $session = startPunchSession($this->outlet, $this->employee);

    $png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    $this->postJson('/api/v1/punch/act', [
        'action' => 'clock_in',
        'photo' => $png,
    ], ['X-Punch-Session' => $session['token']])->assertOk();

    expect(TimeEntry::first()->started_photo_path)->not->toBeNull();
});
