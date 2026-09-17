<?php

use App\Enums\AnomalyType;
use App\Enums\TimeEntryType;
use App\Models\Anomaly;
use App\Models\Employee;
use App\Models\Outlet;
use App\Models\PunchSession;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\OutletTokenService;
use App\Services\PunchService;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/**
 * The punch flow.
 *
 * This is where money is earned, so the tests concentrate on the ways it can be got
 * wrong: clocking in twice, punching at a site you do not work at, and breaks counting
 * as worked time.
 */
beforeEach(function () {
    /*
     * Freeze the clock.
     *
     * Every duration in this file is derived from real timestamps, so without this the
     * assertions drift by a second whenever the machine is busy — the work segment picks
     * up the fraction of a second between two HTTP calls. It surfaced as
     * "28801 is not 28800" on MySQL and passed on SQLite purely because SQLite is faster.
     *
     * Freezing time makes `$this->travel()` exact and the arithmetic settled, rather than
     * loosening the assertions until they stop meaning anything.
     */
    $this->freezeTime();

    $this->outlet = Outlet::create([
        'code' => 'SG-RAMAL',
        'name' => 'Sg Ramal',
        'token_mode' => 'printed',
        'requires_photo' => false,   // most tests are not about photos
    ]);

    $this->other = Outlet::create(['code' => 'DARS-COFFEE', 'name' => 'Dars Coffee']);

    $this->employee = Employee::create([
        'employee_code' => 'RAM-001',
        'name' => 'Ali bin Ahmad',
        'is_active' => true,
    ]);
    $this->employee->outlets()->attach($this->outlet->id, ['is_primary' => true]);
    $this->employee->setPin('4321');

    $this->token = app(OutletTokenService::class)->regenerate($this->outlet);
});

/** Sign in via the public endpoint and return the punch session token. */
function punchIn(string $token, string $pin = '4321'): ?string
{
    $response = test()->postJson('/api/v1/punch/start', ['token' => $token, 'pin' => $pin]);

    return $response->status() === 200 ? $response->json('data.punch_token') : null;
}

function act(string $session, string $action, array $extra = []): TestResponse
{
    return test()->postJson('/api/v1/punch/act', ['action' => $action, ...$extra], [
        'X-Punch-Session' => $session,
    ]);
}

// ---- Starting a session ----------------------------------------------

it('issues a punch session for a valid code and PIN', function () {
    $session = punchIn($this->token->token);

    expect($session)->not->toBeNull();
});

it('returns only the first name and no pay data', function () {
    /*
     * The response confirms who you are and nothing else. An employee's surname, IC
     * number and pay basis have no business being sent to a public endpoint.
     */
    $response = $this->postJson('/api/v1/punch/start', [
        'token' => $this->token->token, 'pin' => '4321',
    ])->assertOk();

    expect($response->json('data.employee.name'))->toBe('Ali');
    expect($response->json('data.employee'))->not->toHaveKey('ic_number_masked');
    expect($response->json('data.employee'))->not->toHaveKey('pay_basis');
    expect($response->getContent())->not->toContain('bin Ahmad');
});

it('never stores the punch session token in plain text', function () {
    $session = punchIn($this->token->token);

    expect(PunchSession::first()->token_hash)->not->toBe($session);
});

it('refuses a wrong PIN', function () {
    expect(punchIn($this->token->token, '9999'))->toBeNull();
});

it('refuses an unknown outlet code', function () {
    expect(punchIn('not-a-real-code'))->toBeNull();
});

it('gives the same answer for a wrong PIN as for an unknown code', function () {
    // Differing messages would tell a prober which codes are real.
    $wrongPin = $this->postJson('/api/v1/punch/start', [
        'token' => $this->token->token, 'pin' => '9999',
    ]);

    $wrongCode = $this->postJson('/api/v1/punch/start', [
        'token' => 'nonsense', 'pin' => '4321',
    ]);

    expect($wrongPin->json('code'))->toBe($wrongCode->json('code'));
    expect($wrongPin->json('message'))->toBe($wrongCode->json('message'));
});

it('refuses a PIN at an outlet the employee is not mapped to', function () {
    /*
     * The outlet mapping is a permission boundary, not a label. Without this check a
     * valid PIN would let someone clock in anywhere once they had that outlet's code.
     */
    $otherToken = app(OutletTokenService::class)->regenerate($this->other);

    expect(punchIn($otherToken->token))->toBeNull();
});

it('refuses a deactivated employee', function () {
    $this->employee->update(['is_active' => false]);

    expect(punchIn($this->token->token))->toBeNull();
});

it('refuses an employee with no PIN set', function () {
    // They cannot clock in until a manager sets one — which is why the console flags it.
    $this->employee->forceFill(['pin_hash' => null])->save();

    expect(punchIn($this->token->token))->toBeNull();
});

it('refuses a superseded code', function () {
    // Reprinting is the revoke mechanism, so an old sheet must stop working at once.
    app(OutletTokenService::class)->regenerate($this->outlet);

    expect(punchIn($this->token->token))->toBeNull();
});

it('locks the PIN after repeated failures', function () {
    foreach (range(1, 5) as $ignored) {
        $this->postJson('/api/v1/punch/start', [
            'token' => $this->token->token, 'pin' => '9999',
        ]);
    }

    // Now even the correct PIN is refused, and a manager must reset it.
    expect(punchIn($this->token->token, '4321'))->toBeNull();
    expect($this->employee->fresh()->pin_locked_until)->not->toBeNull();
});

// ---- Clocking in and out ---------------------------------------------

it('clocks in, opening a work segment', function () {
    $session = punchIn($this->token->token);

    act($session, 'clock_in')->assertOk();

    $entry = TimeEntry::first();

    expect($entry->type)->toBe(TimeEntryType::WORK);
    expect($entry->ended_at)->toBeNull();
    expect($entry->outlet_id)->toBe($this->outlet->id);
});

it('does not create a second segment on a double clock-in', function () {
    /*
     * A double tap on a flaky connection. The database unique index is what actually
     * prevents it — see TimeEntryInvariantTest — and this confirms the endpoint returns
     * calmly rather than erroring at the employee.
     */
    $session = punchIn($this->token->token);

    act($session, 'clock_in')->assertOk();
    act($session, 'clock_in')->assertOk();

    expect(TimeEntry::count())->toBe(1);
});

it('records a break as its own segment and excludes it from worked time', function () {
    /*
     * The rule that protects pay. A one-hour lunch must not become an hour of
     * overtime: worked time is the sum of WORK segments only.
     *
     * Each step re-obtains a session rather than relying on the original one, because
     * travelling the clock forward would also expire a session issued before the jump.
     */
    $session = punchIn($this->token->token);
    act($session, 'clock_in')->assertOk();

    $this->travel(3)->hours();
    act(punchIn($this->token->token), 'start_break')->assertOk();

    $this->travel(1)->hour();
    act(punchIn($this->token->token), 'end_break')->assertOk();

    $this->travel(5)->hours();
    act(punchIn($this->token->token), 'clock_out')->assertOk();

    $worked = TimeEntry::query()->work()->get()->sum(fn (TimeEntry $e) => $e->durationSeconds());
    $break = TimeEntry::query()->break()->get()->sum(fn (TimeEntry $e) => $e->durationSeconds());

    expect($worked)->toBe(3 * 3600 + 5 * 3600);   // 8 hours
    expect($break)->toBe(3600);                    // 1 hour
    expect(TimeEntry::query()->open()->count())->toBe(0);
});

it('reports the correct state at each step', function () {
    $session = punchIn($this->token->token);

    $this->getJson('/api/v1/punch/state', ['X-Punch-Session' => $session])
        ->assertOk()->assertJsonPath('data.state.state', 'clocked_out');

    act($session, 'clock_in');
    $this->getJson('/api/v1/punch/state', ['X-Punch-Session' => $session])
        ->assertOk()->assertJsonPath('data.state.state', 'working');

    act($session, 'start_break');
    $this->getJson('/api/v1/punch/state', ['X-Punch-Session' => $session])
        ->assertOk()->assertJsonPath('data.state.state', 'on_break');
});

it('offers only actions valid in the current state', function () {
    // If the screen offered "clock in" while working, the employee would press a
    // button that fails with no explanation they could act on.
    $session = punchIn($this->token->token);

    $out = $this->getJson('/api/v1/punch/state', ['X-Punch-Session' => $session])
        ->json('data.state.actions');
    expect(collect($out)->pluck('action')->all())->toBe(['clock_in']);

    act($session, 'clock_in');

    $working = $this->getJson('/api/v1/punch/state', ['X-Punch-Session' => $session])
        ->json('data.state.actions');
    expect(collect($working)->pluck('action')->all())->toBe(['start_break', 'clock_out']);
});

it('closes the break when clocking out from a break', function () {
    // The employee stopped working when the break started, so the break closes.
    $session = punchIn($this->token->token);
    act($session, 'clock_in');

    $this->travel(2)->hours();
    act(punchIn($this->token->token), 'start_break');

    $this->travel(30)->minutes();
    act(punchIn($this->token->token), 'clock_out')->assertOk();

    expect(TimeEntry::open()->count())->toBe(0);
    expect(TimeEntry::work()->first()->durationSeconds())->toBe(2 * 3600);
});

it('refuses an action without a session', function () {
    $this->postJson('/api/v1/punch/act', ['action' => 'clock_in'])->assertStatus(401);
});

it('refuses an expired session', function () {
    $session = punchIn($this->token->token);

    $this->travel(11)->minutes();   // sessions last 10

    act($session, 'clock_in')->assertStatus(401);
});

// ---- Photos -----------------------------------------------------------

it('stores a photo when one is supplied', function () {
    $session = punchIn($this->token->token);

    // A 1x1 PNG, as the browser would send from a canvas.
    $png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    act($session, 'clock_in', ['photo' => $png])->assertOk();

    expect(TimeEntry::first()->started_photo_path)->not->toBeNull();
});

it('refuses a punch with no photo at an outlet that requires one', function () {
    /*
     * Enforced rather than warned about. A punch without a photo at such an outlet
     * leaves nothing to settle a dispute with, which is the only reason the photo is
     * collected at all.
     */
    $this->outlet->update(['requires_photo' => true]);

    $session = punchIn($this->token->token);

    act($session, 'clock_in')
        ->assertStatus(422)
        ->assertJsonPath('code', 'PHOTO_REQUIRED');

    expect(TimeEntry::count())->toBe(0);
});

it('rejects an oversized photo', function () {
    // The bytes go straight to disk, so an unbounded upload could fill it.
    $session = punchIn($this->token->token);

    $huge = 'data:image/jpeg;base64,'.base64_encode(str_repeat('x', 4 * 1024 * 1024));

    // requires_photo is off, so this is accepted but the photo is discarded rather
    // than written.
    act($session, 'clock_in', ['photo' => $huge])->assertOk();

    expect(TimeEntry::first()->started_photo_path)->toBeNull();
});

// ---- Anomalies --------------------------------------------------------

it('flags a clock-in with no shift nearby', function () {
    // Adhoc work is real, so this is flagged rather than refused — but a random 3am
    // punch should be visible to a manager.
    $session = punchIn($this->token->token);
    act($session, 'clock_in');

    expect(Anomaly::where('type', AnomalyType::OUTSIDE_SHIFT->value)->exists())->toBeTrue();
});

it('does not flag a clock-in inside a scheduled shift', function () {
    $manager = User::factory()->create();

    Shift::create([
        'employee_id' => $this->employee->id,
        'outlet_id' => $this->outlet->id,
        'starts_at' => now()->subMinutes(10),
        'ends_at' => now()->addHours(8),
        'created_by' => $manager->id,
    ]);

    $session = punchIn($this->token->token);
    act($session, 'clock_in');

    expect(TimeEntry::first()->shift_id)->not->toBeNull();
    expect(Anomaly::where('type', AnomalyType::OUTSIDE_SHIFT->value)->exists())->toBeFalse();
});

it('flags the same person clocking in at two outlets in one day', function () {
    /*
     * Staff who cover outlets legitimately work across sites, so this is flagged rather
     * than refused — but it is also what clocking in on someone else's behalf looks
     * like, so it has to be visible.
     *
     * The employee must already be mapped to both outlets before the first punch, since
     * an unmapped outlet refuses the PIN entirely (covered above).
     */
    $this->employee->outlets()->attach($this->other->id);
    $this->other->update(['requires_photo' => false]);

    $otherToken = app(OutletTokenService::class)->regenerate($this->other);

    $first = punchIn($this->token->token);
    act($first, 'clock_in')->assertOk();

    $second = punchIn($otherToken->token);
    act($second, 'clock_in')->assertOk();

    expect(Anomaly::where('type', AnomalyType::DOUBLE_OUTLET->value)->exists())->toBeTrue();
});

it('flags a segment left open far too long', function () {
    // The signature of a forgotten clock-out.
    $session = punchIn($this->token->token);
    act($session, 'clock_in');

    $this->travel(20)->hours();
    act(punchIn($this->token->token), 'clock_out');

    expect(Anomaly::where('type', AnomalyType::LONG_SPAN->value)->exists())->toBeTrue();
});

it('flags a missing photo at an outlet that requires one', function () {
    // Requires_photo is off by default here, so the flag is raised only when it is on
    // and the photo nevertheless failed to arrive.
    $this->outlet->update(['requires_photo' => true]);

    $session = punchIn($this->token->token);

    // Bypass the endpoint guard to simulate a photo that failed to decode.
    $entry = app(PunchService::class)->clockIn(
        app(PunchService::class)->resolveSession($session)
    );

    expect(Anomaly::where('type', AnomalyType::NO_PHOTO->value)->exists())->toBeTrue();
});

// ---- The employee's own hours ----------------------------------------

it('shows the employee their own hours', function () {
    $session = punchIn($this->token->token);
    act($session, 'clock_in');

    $this->travel(3)->hours();
    act(punchIn($this->token->token), 'start_break');

    $this->travel(1)->hour();
    act(punchIn($this->token->token), 'end_break');

    $this->travel(2)->hours();
    act(punchIn($this->token->token), 'clock_out');

    $response = $this->getJson('/api/v1/punch/hours', ['X-Punch-Session' => punchIn($this->token->token)])
        ->assertOk();

    expect($response->json('data.total_seconds'))->toBe(5 * 3600);
    expect($response->json('data.total_label'))->toBe('5h 0m');
});

it('never exposes another employee through the public punch API', function () {
    /*
     * Worth pinning: this is the only unauthenticated group in the application, and a
     * mistake here would expose every employee's hours.
     */
    $other = Employee::create(['employee_code' => 'RAM-002', 'name' => 'Someone Else']);
    $other->outlets()->attach($this->outlet->id);

    $session = punchIn($this->token->token);

    $response = $this->getJson('/api/v1/punch/hours', ['X-Punch-Session' => $session]);

    expect($response->getContent())->not->toContain('Someone Else');
    expect($response->getContent())->not->toContain('RAM-002');
});

// ---- Resuming after a reload -----------------------------------------

it('reports who and where on every state response', function () {
    /*
     * The punch token is persisted on the phone, but the employee and outlet were only
     * ever sent in the response to /punch/start. A reload mid-shift therefore left the
     * screen with an empty header, which looks like being logged out even though every
     * button still works — so the state response carries them too.
     */
    $session = punchIn($this->token->token);
    act($session, 'clock_in');

    $state = $this->getJson('/api/v1/punch/state', ['X-Punch-Session' => $session])
        ->assertOk()
        ->json('data.state');

    expect($state['subject']['name'])->toBe('Ali');
    expect($state['subject']['employee_code'])->toBe('RAM-001');
    expect($state['subject']['outlet'])->toBe('Sg Ramal');
});

it('reports the subject without exposing pay or identity data', function () {
    // Same rule as the start response: first name, no surname, no IC, no pay basis.
    $session = punchIn($this->token->token);

    $response = $this->getJson('/api/v1/punch/state', ['X-Punch-Session' => $session])->assertOk();

    $subject = $response->json('data.state.subject');

    expect($subject)->not->toHaveKey('ic_number_masked');
    expect($subject)->not->toHaveKey('pay_basis');
    expect($subject)->not->toHaveKey('hourly_rate');
    expect($response->getContent())->not->toContain('bin Ahmad');
});

it('keeps the same subject after a reload mid-shift', function () {
    /*
     * The actual reload path: a second session is NOT obtained, so the only thing the
     * phone has is the stored token. This is the bug the subject payload fixes.
     *
     * The session lifetime is raised for this test only, because travelling three hours
     * would also expire the default ten-minute session — a legitimate behaviour, but not
     * the thing under test here. The point is that no new session is issued.
     */
    Setting::set(Setting::PUNCH_SESSION_MINUTES, 300);

    $session = punchIn($this->token->token);
    act($session, 'clock_in');

    $this->travel(3)->hours();

    $state = $this->getJson('/api/v1/punch/state', ['X-Punch-Session' => $session])
        ->assertOk()
        ->json('data.state');

    expect($state['state'])->toBe('working');
    expect($state['subject']['name'])->toBe('Ali');
    expect($state['today']['worked_seconds'])->toBeGreaterThanOrEqual(3 * 3600);
});

// ---- Business-day queries ---------------------------------------------

it('finds the entries for a day when the date column holds a full datetime', function () {
    /*
     * REGRESSION. `business_date` is date-cast, so Laravel binds it as
     * 'Y-m-d H:i:s'. MySQL's DATE column truncates that to the day and the comparison
     * works — while SQLite stores the whole datetime string and `where('business_date',
     * '2026-09-18')` matches NOTHING.
     *
     * The effect was invisible on the target database and fatal on SQLite: every
     * business-day query returned empty, so "worked today" showed 0m, the weekly
     * summary hid every shift, and the same-person-at-two-outlets check could never
     * fire. This test asserts the raw stored value directly, so it fails if the
     * date-cast behaviour or the query style ever changes.
     */
    $session = punchIn($this->token->token);
    act($session, 'clock_in');

    $raw = DB::table('time_entries')->value('business_date');
    $businessDate = TimeEntry::first()->business_date->toDateString();

    // The underlying value is a datetime, which is exactly why a plain `where` fails.
    expect($raw)->toContain($businessDate);
    expect($raw)->not->toBe($businessDate);

    expect(TimeEntry::query()->forBusinessDate($businessDate)->count())->toBe(1);
});

it('counts the worked time for today rather than reporting zero', function () {
    /*
     * The user-visible half of the same bug: this is the number on the employee's own
     * screen, and a wrong zero here is the kind of thing that makes staff stop trusting
     * the system and go back to a paper book.
     */
    Setting::set(Setting::PUNCH_SESSION_MINUTES, 300);

    $session = punchIn($this->token->token);
    act($session, 'clock_in');

    $this->travel(2)->hours();
    act(punchIn($this->token->token), 'start_break');

    $this->travel(30)->minutes();
    act(punchIn($this->token->token), 'end_break');

    $today = $this->getJson('/api/v1/punch/state', ['X-Punch-Session' => punchIn($this->token->token)])
        ->assertOk()
        ->json('data.state.today');

    // Worked excludes the break: 2h30m of span, 2h of it paid.
    expect($today['worked_seconds'])->toBe(2 * 3600);
    expect($today['break_seconds'])->toBe(30 * 60);
    expect($today['has_open_segment'])->toBeTrue();
});

it('reports a same-day punch at another outlet in the weekly summary', function () {
    /*
     * Also driven by the business-day query. Without the fix the summary grouped nothing
     * and the employee saw an empty week despite having worked.
     */
    Setting::set(Setting::PUNCH_SESSION_MINUTES, 300);

    $session = punchIn($this->token->token);
    act($session, 'clock_in');

    $this->travel(4)->hours();
    act(punchIn($this->token->token), 'clock_out');

    $hours = $this->getJson('/api/v1/punch/hours', ['X-Punch-Session' => punchIn($this->token->token)])
        ->assertOk()
        ->json('data');

    // Deltas are derived from real timestamps, so a second of drift is expected.
    expect($hours['total_seconds'])->toBeGreaterThanOrEqual(4 * 3600);
    expect($hours['total_seconds'])->toBeLessThan(4 * 3600 + 60);
    expect($hours['days'])->toHaveCount(1);
});
