<?php

use App\Enums\AnomalyType;
use App\Models\Anomaly;
use App\Models\Employee;
use App\Models\Outlet;
use App\Models\PunchEvent;
use App\Models\Setting;
use App\Models\TimeEntry;
use App\Services\OfflinePunchService;
use App\Services\OutletTokenService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * The offline punch queue.
 *
 * A kitchen has no signal. Someone clocks out, the phone cannot reach the server, and the
 * punch is queued and sent later. Two things make that safe, and they fail in very different
 * ways:
 *
 *  - IDEMPOTENCY. A queued punch is retried until it succeeds, and the phone cannot tell "the
 *    request never arrived" from "the reply never arrived". A retry that is treated as a new
 *    punch adds a duplicate segment and inflates someone's hours.
 *
 *  - THE CLAIMED TIME. It has to come from the phone, or a 7am punch synced at 2pm is recorded
 *    at 2pm and the feature is pointless. But it is also the only field a crafted request can
 *    lie about, and what it lies about is money. So it is BOUNDED — and out of bounds is a
 *    refusal, never a silent clamp.
 *
 * Most of these tests are about the second one, because a bound that is merely asserted in a
 * comment is not a bound.
 */
beforeEach(function () {
    // Frozen: the bounds are all measured against "now", so a drifting clock would make the
    // boundary tests flaky rather than wrong.
    $this->travelTo(CarbonImmutable::parse('2026-09-21 18:00:00', 'Asia/Kuala_Lumpur'));
    $this->freezeTime();

    Storage::fake('local');

    $this->outlet = Outlet::create([
        'code' => 'SG-RAMAL',
        'name' => 'Sg Ramal',
        'timezone' => 'Asia/Kuala_Lumpur',
        'token_mode' => 'printed',
        'requires_photo' => false,
    ]);

    $this->employee = Employee::create([
        'employee_code' => 'RAM-001',
        'name' => 'Ali bin Ahmad',
        'is_active' => true,
    ]);
    $this->employee->outlets()->attach($this->outlet->id, ['is_primary' => true]);
    $this->employee->setPin('4321');

    $this->token = app(OutletTokenService::class)->regenerate($this->outlet);
    $this->offline = app(OfflinePunchService::class);

    $this->session = $this->postJson('/api/v1/punch/start', [
        'token' => $this->token->token,
        'pin' => '4321',
    ])->json('data.punch_token');
});

/** Perform a punch action, optionally as an offline claim. */
function offlineAct(string $session, string $action, array $extra = []): TestResponse
{
    return test()->postJson('/api/v1/punch/act', ['action' => $action, ...$extra], [
        'X-Punch-Session' => $session,
    ]);
}

// ---- Idempotency -------------------------------------------------------------

it('records a punch once when the same client id is sent twice', function () {
    $uuid = (string) Str::uuid();

    offlineAct($this->session, 'clock_in', ['client_uuid' => $uuid])->assertOk();
    offlineAct($this->session, 'clock_in', ['client_uuid' => $uuid])->assertOk();

    /*
     * The whole point. A retry after a lost reply must not create a second segment — one
     * duplicate clock-in here means an employee is clocked in twice and the open-segment
     * invariant is the only thing standing between that and a corrupted timesheet.
     */
    expect(TimeEntry::count())->toBe(1);
});

it('returns the original entry on a duplicate rather than an error', function () {
    $uuid = (string) Str::uuid();

    $first = offlineAct($this->session, 'clock_in', ['client_uuid' => $uuid])->assertOk();
    $second = offlineAct($this->session, 'clock_in', ['client_uuid' => $uuid])
        ->assertOk()
        ->assertJsonPath('data.duplicate', true);

    /*
     * A 409 would be the instinctive answer and the wrong one: the client is a phone draining a
     * queue, and it can do nothing useful with an error — it would retry for ever. A success
     * carrying the stored result lets the queue advance.
     */
    expect($second->json('data.entry_id'))->toBe($first->json('data.entry_id'));
});

it('records a duplicate in the punch trail', function () {
    $uuid = (string) Str::uuid();

    offlineAct($this->session, 'clock_in', ['client_uuid' => $uuid]);
    offlineAct($this->session, 'clock_in', ['client_uuid' => $uuid]);

    expect(PunchEvent::query()->where('event', 'duplicate_ignored')->count())->toBe(1);
});

/**
 * A replay must not act as a lookup for someone else's punch.
 *
 * The lookup is scoped to the employee, so another person's id simply does not match. They do
 * not get an error that confirms the id exists, and they certainly do not get its entry id and
 * current state back.
 */
it('ignores a client id belonging to another employee', function () {
    $other = Employee::create(['employee_code' => 'RAM-002', 'name' => 'Bala', 'is_active' => true]);
    $other->outlets()->attach($this->outlet->id);
    $other->setPin('9999');

    $uuid = (string) Str::uuid();

    $otherSession = $this->postJson('/api/v1/punch/start', [
        'token' => $this->token->token,
        'pin' => '9999',
    ])->json('data.punch_token');

    offlineAct($otherSession, 'clock_in', ['client_uuid' => $uuid])->assertOk();

    /*
     * A 409 would confirm that the id exists and belongs to somebody — a small leak, but a leak.
     * Scoping the lookup means the id is simply unknown here, and the punch is recorded as this
     * employee's own. Their own clock-in is what they asked for, and it is what they get.
     */
    $response = offlineAct($this->session, 'clock_in', ['client_uuid' => $uuid])->assertOk();

    expect($response->json('data.duplicate'))->toBeNull()
        ->and($response->json('data.entry_id'))->not->toBe(TimeEntry::query()->first()->id)
        ->and(TimeEntry::count())->toBe(2);
});

it('still refuses a double tap with no client id at all', function () {
    offlineAct($this->session, 'clock_in')->assertOk();
    offlineAct($this->session, 'clock_in')->assertOk();

    // The open-segment invariant is the backstop when there is no id to deduplicate on.
    expect(TimeEntry::count())->toBe(1);
});

it('generates an id when the client does not send one', function () {
    offlineAct($this->session, 'clock_in')->assertOk();

    expect(TimeEntry::first()->client_uuid)->not->toBeNull();
});

// ---- The claimed time -------------------------------------------------------

/**
 * The reason the queue exists: a punch made at 07:00 and synced hours later keeps 07:00.
 *
 * Without this the feature would be actively harmful — every offline punch would land at
 * whatever moment the signal returned, so a morning's work would be recorded in the afternoon.
 */
it('records a queued punch at the time the phone claimed', function () {
    // Claimed eight hours ago; the phone was offline all morning.
    $claimed = CarbonImmutable::now()->subHours(8);

    offlineAct($this->session, 'clock_in', [
        'client_uuid' => (string) Str::uuid(),
        'claimed_at' => $claimed->toIso8601String(),
    ])->assertOk();

    $entry = TimeEntry::first();

    expect($entry->started_at->equalTo($claimed))->toBeTrue()
        // And the business day follows the claimed moment, not the sync moment.
        ->and($entry->business_date->toDateString())
        ->toBe($claimed->setTimezone('Asia/Kuala_Lumpur')->toDateString());
});

it('flags a punch whose time the client reported', function () {
    $claimed = CarbonImmutable::now()->subHours(4);

    offlineAct($this->session, 'clock_in', [
        'client_uuid' => (string) Str::uuid(),
        'claimed_at' => $claimed->toIso8601String(),
    ])->assertOk();

    $entry = TimeEntry::first();

    /*
     * Labelled AND flagged, for two different readers: `is_offline_sync` is what the timesheet
     * and pay code filter on, while the anomaly is what a manager sees. A client-reported time
     * must never be indistinguishable from one the server witnessed.
     */
    expect($entry->is_offline_sync)->toBeTrue()
        ->and(Anomaly::query()->where('type', AnomalyType::OFFLINE_SYNC->value)->exists())->toBeTrue();
});

it('does not flag an ordinary online punch as offline', function () {
    offlineAct($this->session, 'clock_in')->assertOk();

    /*
     * Asserted on OFFLINE_SYNC specifically rather than on the anomaly count.
     *
     * This punch happens outside any rostered shift, so it legitimately raises OUTSIDE_SHIFT — an
     * unrelated check that happens to fire here. Counting all anomalies would make this test fail
     * for a reason that has nothing to do with the offline queue, and the tempting fix would be to
     * weaken the assertion until it stopped meaning anything.
     */
    expect(TimeEntry::first()->is_offline_sync)->toBeFalse()
        ->and(Anomaly::query()->where('type', AnomalyType::OFFLINE_SYNC->value)->exists())->toBeFalse();
});

it('records the offline sync in the punch trail', function () {
    offlineAct($this->session, 'clock_in', [
        'client_uuid' => (string) Str::uuid(),
        'claimed_at' => CarbonImmutable::now()->subHours(4)->toIso8601String(),
    ])->assertOk();

    expect(PunchEvent::query()->where('event', 'offline_sync')->exists())->toBeTrue();
});

/**
 * The anomaly must read sensibly, because a manager reads it.
 *
 * Found by looking at a real record rather than at an assertion: the message rendered as
 * "received -180 minute(s) later". Carbon's `diffInMinutes` is SIGNED, and the first version
 * passed that straight through. A negative figure does not merely read oddly — it reads as a
 * number computed wrongly rather than a sentence phrased wrongly, which sends whoever sees it
 * hunting for a fault in the arithmetic that is not there.
 */
it('describes the sync delay as a positive, human figure', function () {
    offlineAct($this->session, 'clock_in', [
        'client_uuid' => (string) Str::uuid(),
        'claimed_at' => CarbonImmutable::now()->subHours(3)->toIso8601String(),
    ])->assertOk();

    $detail = Anomaly::query()
        ->where('type', AnomalyType::OFFLINE_SYNC->value)
        ->value('detail');

    expect($detail)->toContain('3 hour(s) later')
        // No negative figure anywhere in the sentence.
        ->and($detail)->not->toMatch('/-\d/');
});

/**
 * A clock-out closes the segment at the claimed moment, not at the moment it arrived.
 *
 * This is the most expensive way to get the queue wrong: a 15:00 clock-out synced at 20:00
 * would add five hours to the shift, and the employee is paid for time they were not there.
 */
it('closes a segment at the claimed clock-out time', function () {
    $clockIn = CarbonImmutable::now()->subHours(12);

    offlineAct($this->session, 'clock_in', [
        'client_uuid' => (string) Str::uuid(),
        'claimed_at' => $clockIn->toIso8601String(),
    ])->assertOk();

    $clockOut = CarbonImmutable::now()->subHours(4);

    offlineAct($this->session, 'clock_out', [
        'client_uuid' => (string) Str::uuid(),
        'claimed_at' => $clockOut->toIso8601String(),
    ])->assertOk();

    $entry = TimeEntry::first()->fresh();

    expect($entry->ended_at->equalTo($clockOut))->toBeTrue()
        // Eight hours, not twelve — the four hours between punching out and syncing are not work.
        ->and($entry->durationSeconds())->toBe(8 * 3600);
});

// ---- The bounds ------------------------------------------------------------

it('refuses a punch claiming to be from the future', function () {
    offlineAct($this->session, 'clock_in', [
        'client_uuid' => (string) Str::uuid(),
        'claimed_at' => CarbonImmutable::now()->addHours(4)->toIso8601String(),
    ])->assertStatus(422)->assertJsonPath('code', 'CLAIMED_AT_OUT_OF_BOUNDS');

    expect(TimeEntry::count())->toBe(0);
});

it('allows a small clock skew so a fast phone is not refused', function () {
    // Two minutes fast. Refusing this reads to the employee as the app being broken.
    offlineAct($this->session, 'clock_in', [
        'client_uuid' => (string) Str::uuid(),
        'claimed_at' => CarbonImmutable::now()->addMinutes(2)->toIso8601String(),
    ])->assertOk();

    expect(TimeEntry::count())->toBe(1);
});

it('refuses a punch older than the backdating window', function () {
    Setting::set(Setting::OFFLINE_MAX_BACKDATE_HOURS, '24');

    offlineAct($this->session, 'clock_in', [
        'client_uuid' => (string) Str::uuid(),
        'claimed_at' => CarbonImmutable::now()->subHours(30)->toIso8601String(),
    ])->assertStatus(422)->assertJsonPath('code', 'CLAIMED_AT_OUT_OF_BOUNDS');

    expect(TimeEntry::count())->toBe(0);
});

/**
 * Out of bounds is REFUSED, never clamped.
 *
 * Clamping would record a different time from the one claimed and tell nobody: the employee
 * would not know their punch had been altered, and a manager would have nothing to review. A
 * refusal is visible, and the correction path exists for exactly this.
 */
it('never silently substitutes server time for an out-of-bounds claim', function () {
    offlineAct($this->session, 'clock_in', [
        'client_uuid' => (string) Str::uuid(),
        'claimed_at' => CarbonImmutable::now()->subDays(30)->toIso8601String(),
    ])->assertStatus(422);

    expect(TimeEntry::count())->toBe(0);
});

it('honours a raised backdating window', function () {
    Setting::set(Setting::OFFLINE_MAX_BACKDATE_HOURS, '72');

    offlineAct($this->session, 'clock_in', [
        'client_uuid' => (string) Str::uuid(),
        'claimed_at' => CarbonImmutable::now()->subHours(48)->toIso8601String(),
    ])->assertOk();

    expect(TimeEntry::count())->toBe(1);
});

it('records a refused claim in the punch trail', function () {
    offlineAct($this->session, 'clock_in', [
        'client_uuid' => (string) Str::uuid(),
        'claimed_at' => CarbonImmutable::now()->subDays(30)->toIso8601String(),
    ])->assertStatus(422);

    // A run of these against one device is worth seeing; without the trail it is invisible.
    expect(PunchEvent::query()->where('event', 'rejected')->exists())->toBeTrue();
});

it('rejects a claimed_at that is not a date', function () {
    offlineAct($this->session, 'clock_in', [
        'client_uuid' => (string) Str::uuid(),
        'claimed_at' => 'yesterday-ish',
    ])->assertStatus(422)->assertJsonValidationErrors('claimed_at');
});

// ---- Out of order ----------------------------------------------------------

/**
 * A queue can present actions in the wrong order.
 *
 * A clock-out that overtakes the clock-in it followed would close nothing and create a segment
 * ending before it started — a negative duration, the corruption `durationSeconds()` clamps
 * against. Refused, because an ignored clock-out leaves the employee believing they are off the
 * clock while a segment is still open and accruing hours.
 */
it('refuses a clock-out with nothing open', function () {
    offlineAct($this->session, 'clock_out', [
        'client_uuid' => (string) Str::uuid(),
    ])->assertStatus(422)->assertJsonPath('code', 'OUT_OF_ORDER_PUNCH');

    expect(TimeEntry::count())->toBe(0);
});

it('refuses a break action with nothing open', function () {
    offlineAct($this->session, 'start_break', [
        'client_uuid' => (string) Str::uuid(),
    ])->assertStatus(422)->assertJsonPath('code', 'OUT_OF_ORDER_PUNCH');
});

it('still allows an ordinary clock-out to be idempotent', function () {
    $uuid = (string) Str::uuid();

    offlineAct($this->session, 'clock_in', ['client_uuid' => (string) Str::uuid()])->assertOk();

    offlineAct($this->session, 'clock_out', ['client_uuid' => $uuid])->assertOk();
    offlineAct($this->session, 'clock_out', ['client_uuid' => $uuid])
        ->assertOk()
        ->assertJsonPath('data.duplicate', true);

    // The retry must not close the same segment twice or open a new one.
    expect(TimeEntry::count())->toBe(1);
});

// ---- The service, directly --------------------------------------------------

it('treats a claim within the skew as an ordinary punch', function () {
    // Recently claimed: the phone and the server agree closely, so there is nothing to review.
    // Flagging every one would bury the cases that matter.
    expect($this->offline->isOfflineReport(CarbonImmutable::now()->subMinutes(2)->toIso8601String()))
        ->toBeFalse();

    expect($this->offline->isOfflineReport(CarbonImmutable::now()->subHours(3)->toIso8601String()))
        ->toBeTrue();
});

it('returns server time when nothing is claimed', function () {
    $resolved = $this->offline->resolveTimestamp(null);

    expect($resolved->equalTo(CarbonImmutable::now()))->toBeTrue();
});

it('rejects an unparseable claim rather than guessing', function () {
    expect($this->offline->resolveTimestamp('not-a-time'))->toBeNull();
});

it('treats a replay from another outlet as the same punch', function () {
    $otherOutlet = Outlet::create([
        'code' => 'DARS-COFFEE',
        'name' => 'Dars Coffee',
        'timezone' => 'Asia/Kuala_Lumpur',
        'token_mode' => 'printed',
        'requires_photo' => false,
    ]);

    $this->employee->outlets()->attach($otherOutlet->id);

    $uuid = (string) Str::uuid();

    offlineAct($this->session, 'clock_in', ['client_uuid' => $uuid])->assertOk();

    /*
     * The same person, a different outlet's code, the same id. Still one punch.
     *
     * Deduplication is keyed on the employee rather than the outlet because the employee is
     * what the queue belongs to: their phone drained one queue, and the outlet in a retry is
     * whichever code happened to be scanned, not a distinct act of work.
     */
    $otherToken = app(OutletTokenService::class)->regenerate($otherOutlet);

    $otherSession = $this->postJson('/api/v1/punch/start', [
        'token' => $otherToken->token,
        'pin' => '4321',
    ])->json('data.punch_token');

    offlineAct($otherSession, 'clock_in', ['client_uuid' => $uuid])
        ->assertOk()
        ->assertJsonPath('data.duplicate', true);

    expect(TimeEntry::count())->toBe(1);
});
