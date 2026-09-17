<?php

use App\Enums\AnomalyType;
use App\Enums\CorrectionStatus;
use App\Enums\TimeEntryStatus;
use App\Enums\TimeEntryType;
use App\Enums\UserRole;
use App\Models\Anomaly;
use App\Models\AttendanceCorrection;
use App\Models\Employee;
use App\Models\Outlet;
use App\Models\Setting;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\CorrectionService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Corrections — changing recorded time without destroying the record.
 *
 * The tests below are mostly about what SURVIVES a correction: the original values, who
 * asked, who approved, and the reason. A correction that applies correctly but leaves no
 * trace is worse than no correction feature at all, because it makes the timesheet
 * authoritative and unquestionable at the same time.
 */
beforeEach(function () {
    /*
     * Pinned to a specific instant, not just frozen at "now".
     *
     * The assertions below name absolute times, so freezing at the real current time
     * makes them depend on the day the suite happens to run — which is how a test starts
     * passing locally and failing in CI the next morning.
     */
    $this->travelTo(CarbonImmutable::parse('2026-09-18 09:00:00', 'Asia/Kuala_Lumpur'));

    $this->outlet = Outlet::create(['code' => 'SG-RAMAL', 'name' => 'Sg Ramal', 'token_mode' => 'printed', 'requires_photo' => false]);
    $this->otherOutlet = Outlet::create(['code' => 'SEDAP-SANTAI', 'name' => 'Sedap Santai', 'token_mode' => 'printed', 'requires_photo' => false]);

    $this->owner = User::factory()->create(['role' => UserRole::OWNER, 'email' => 'owner@dars.test']);
    $this->manager = User::factory()->create(['role' => UserRole::MANAGER, 'email' => 'manager@dars.test']);
    $this->manager->outlets()->attach($this->outlet->id);

    $this->employee = Employee::create(['employee_code' => 'RAM-001', 'name' => 'Ali bin Ahmad', 'is_active' => true]);
    $this->employee->outlets()->attach($this->outlet->id);

    $this->service = app(CorrectionService::class);

    // 09:00 in the outlet's timezone, which is 01:00 UTC.
    $started = CarbonImmutable::parse('2026-09-18 09:00:00', $this->outlet->timezone);

    $this->entry = TimeEntry::create([
        'client_uuid' => (string) Str::uuid(),
        'employee_id' => $this->employee->id,
        'outlet_id' => $this->outlet->id,
        'type' => TimeEntryType::WORK,
        'started_at' => $started,
        'ended_at' => $started->addHours(8),
        'business_date' => $started->toDateString(),
        'status' => TimeEntryStatus::CLOSED,
    ]);
});

// ---- The audit record -------------------------------------------------

it('keeps the original values when a correction is applied', function () {
    /*
     * This is what makes the record auditable rather than merely logged. Without the
     * snapshot, applying a correction destroys the evidence needed to answer the dispute
     * the correction was about.
     */
    $originalStart = $this->entry->started_at->toIso8601String();

    $correction = $this->service->request(
        $this->entry,
        ['started_at' => '2026-09-18T15:30:00+08:00'],
        'Employee arrived at 3.30pm, not 9am. Confirmed with the outlet.',
        $this->owner,
    );

    expect($correction->status)->toBe(CorrectionStatus::APPROVED);
    expect($correction->original_values['started_at'])->toBe($originalStart);
    // 15:30 in Kuala Lumpur is 07:30 UTC, and that is the instant stored.
    expect($this->entry->fresh()->started_at->utc()->toIso8601String())->toBe('2026-09-18T07:30:00+00:00');
});

it('stores an offset timestamp as the instant it names', function () {
    /*
     * REGRESSION. The `datetime` cast formats with `$dateFormat` and does not convert to
     * the app timezone, so assigning 09:00+08:00 wrote the literal string "09:00" — which
     * read back as 09:00 UTC, eight hours late. The punch flow masked this because it uses
     * `now()`, already UTC; a correction supplying a local time is the first caller to hit
     * it. TimeEntry normalises on set now.
     */
    $this->service->request(
        $this->entry,
        ['started_at' => '2026-09-18T09:00:00+08:00'],
        'Arrived at 9am in the outlet timezone.',
        $this->owner,
    );

    expect($this->entry->fresh()->started_at->utc()->toIso8601String())->toBe('2026-09-18T01:00:00+00:00');
});

it('records who asked, who approved and why', function () {
    $correction = $this->service->request(
        $this->entry,
        ['type' => 'work'],
        'Correcting the segment type.',
        $this->owner,
    );

    expect($correction->requested_by)->toBe($this->owner->id);
    expect($correction->reviewed_by)->toBe($this->owner->id);
    expect($correction->reason)->toBe('Correcting the segment type.');
    expect($correction->reviewed_at)->not->toBeNull();
});

it('never overwrites the original with a second correction', function () {
    /*
     * A second correction snapshots the entry AS IT STANDS, not the original punch, so
     * following the chain leads back one step at a time. Snapshotted from the original
     * each time, the second row would claim the first change never happened.
     */
    $first = $this->service->request(
        $this->entry,
        ['started_at' => '2026-09-18T09:15:00+08:00'],
        'First correction.',
        $this->owner,
    );

    $second = $this->service->request(
        $this->entry->fresh(),
        ['started_at' => '2026-09-18T09:30:00+08:00'],
        'Second correction.',
        $this->owner,
    );

    expect($second->original_values['started_at'])->toBe('2026-09-18T01:15:00+00:00');
});

it('marks the entry as corrected so a report can tell the two apart', function () {
    $this->service->request($this->entry, ['started_at' => '2026-09-18T09:30:00+08:00'], 'Late arrival.', $this->owner);

    expect($this->entry->fresh()->status)->toBe(TimeEntryStatus::CORRECTED);
});

it('requires an owner to approve a manager correction by default', function () {
    // A manager rewriting their own staff's hours unattended makes the timesheet
    // self-certifying, so the default is that someone else signs it off.
    $correction = $this->service->request(
        $this->entry,
        ['started_at' => '2026-09-18T09:30:00+08:00'],
        'Manager correcting the arrival.',
        $this->manager,
    );

    expect($correction->status)->toBe(CorrectionStatus::PENDING);
    expect($correction->reviewed_at)->toBeNull();
});

it('applies a manager correction once the owner approves it', function () {
    $correction = $this->service->request(
        $this->entry,
        ['started_at' => '2026-09-18T09:30:00+08:00'],
        'Manager correcting the arrival.',
        $this->manager,
    );

    $this->service->approve($correction, $this->owner, 'Checked against the outlet camera.');

    $correction->refresh();

    expect($correction->status)->toBe(CorrectionStatus::APPROVED);
    expect($correction->reviewed_by)->toBe($this->owner->id);
    expect($this->entry->fresh()->started_at->utc()->toIso8601String())->toBe('2026-09-18T01:30:00+00:00');
});

it('raises an oversight flag when a manager corrects an entry', function () {
    /*
     * The manager acted within their rights, but their change carries no second
     * signature, so it is surfaced to the owner rather than left in a log only the
     * manager would read.
     */
    $correction = $this->service->request(
        $this->entry,
        ['started_at' => '2026-09-18T09:30:00+08:00'],
        'Manager correcting the arrival.',
        $this->manager,
    );

    $this->service->approve($correction, $this->owner);

    expect(Anomaly::where('type', AnomalyType::MANAGER_CORRECTION->value)->exists())->toBeTrue();
});

it('leaves the entry untouched when a correction is rejected', function () {
    $correction = $this->service->request(
        $this->entry,
        ['started_at' => '2026-09-18T09:30:00+08:00'],
        'Manager correcting the arrival.',
        $this->manager,
    );

    $this->service->reject($correction, $this->owner, 'The camera shows 9am.');

    expect($correction->refresh()->status)->toBe(CorrectionStatus::REJECTED);
    expect($this->entry->fresh()->status)->toBe(TimeEntryStatus::CLOSED);
});

it('keeps a rejected correction on the record', function () {
    // "Someone tried to change this and was refused" is exactly the kind of thing worth
    // being able to see later.
    $correction = $this->service->request($this->entry, ['type' => 'break'], 'Trying to reclassify.', $this->manager);
    $this->service->reject($correction, $this->owner);

    expect(AttendanceCorrection::where('status', CorrectionStatus::REJECTED->value)->count())->toBe(1);
});

it('applies an approval only once', function () {
    // A double-click on an approval button must not apply the change twice, and must not
    // overwrite the snapshot with the already-changed value.
    $correction = $this->service->request(
        $this->entry,
        ['started_at' => '2026-09-18T09:30:00+08:00'],
        'Manager correcting the arrival.',
        $this->manager,
    );

    $this->service->approve($correction, $this->owner);
    $this->service->approve($correction->fresh(), $this->owner);

    expect(AttendanceCorrection::count())->toBe(1);
    expect($correction->fresh()->original_values['started_at'])->toBe('2026-09-18T01:00:00+00:00');
    expect($this->entry->fresh()->started_at->utc()->toIso8601String())->toBe('2026-09-18T01:30:00+00:00');
});

// ---- Guard rails ------------------------------------------------------

it('refuses to move an entry to another employee', function () {
    /*
     * An allow-list, not a blocklist. If employee_id were writable, a manager could move
     * a punch to someone else — quietly defeating the outlet scoping that is this
     * application's security boundary.
     */
    expect(fn () => $this->service->request(
        $this->entry,
        ['employee_id' => 999],
        'Moving this to another person.',
        $this->owner,
    ))->toThrow(InvalidArgumentException::class);
});

it('refuses to move an entry to another outlet', function () {
    expect(fn () => $this->service->request(
        $this->entry,
        ['outlet_id' => $this->otherOutlet->id],
        'Moving this to another outlet.',
        $this->owner,
    ))->toThrow(InvalidArgumentException::class);
});

it('refuses a correction that changes nothing', function () {
    expect(fn () => $this->service->request($this->entry, [], 'Nothing.', $this->owner))
        ->toThrow(InvalidArgumentException::class);
});

it('refuses to let a change set the status by hand', function () {
    // status is set by the correction path itself; letting a request set it would allow
    // marking an entry CORRECTED without any audit row existing.
    expect(fn () => $this->service->request(
        $this->entry,
        ['status' => 'closed'],
        'Trying to set the status directly.',
        $this->owner,
    ))->toThrow(InvalidArgumentException::class);
});

it('recomputes the cached duration rather than leaving it stale', function () {
    $this->service->request(
        $this->entry,
        ['ended_at' => '2026-09-18T20:00:00+08:00'],
        'Employee worked until 8pm.',
        $this->owner,
    );

    $entry = $this->entry->fresh();

    expect($entry->durationSeconds())->toBe(11 * 3600);
    expect($entry->duration_seconds)->toBe(11 * 3600);
});

it('re-derives the business date when a correction moves the clock-in', function () {
    /*
     * The business date decides which day the hours land on. A correction that moved a
     * clock-in across midnight without moving the date would put the hours on the wrong
     * day with nothing visible to show for it — so the date is recomputed, never accepted
     * from the request.
     *
     * The end moves too: a start after midnight with the original 17:00 end would be a
     * segment running backwards, which the service now refuses — correctly.
     */
    $this->service->request(
        $this->entry,
        [
            'started_at' => '2026-09-19T00:30:00+08:00',
            'ended_at' => '2026-09-19T04:30:00+08:00',
        ],
        'Employee actually worked after midnight, not the previous morning.',
        $this->owner,
    );

    $entry = $this->entry->fresh();

    // 00:30 on the 19th in Kuala Lumpur is the 19th, not the 18th.
    expect($entry->business_date->toDateString())->toBe('2026-09-19');
});

it('refuses a correction that would end the segment before it starts', function () {
    /*
     * REGRESSION. A manager mistyping a date, or picking yesterday from the date picker,
     * produced an end before the start — a negative duration. MySQL stores that in an
     * UNSIGNED column as a hard error, so the request died with a 500 that told the manager
     * nothing; the same change on SQLite would have been accepted and quietly recorded a
     * negative day.
     *
     * The check is applied AFTER the changes are merged, so it catches combinations rather
     * than only the individual fields.
     */
    expect(fn () => $this->service->request(
        $this->entry,
        ['ended_at' => '2026-09-18T08:00:00+08:00'],
        'Trying to end this before it started.',
        $this->owner,
    ))->toThrow(InvalidArgumentException::class);

    // And nothing was written.
    expect($this->entry->fresh()->ended_at->utc()->toIso8601String())->toBe('2026-09-18T09:00:00+00:00');
    expect($this->entry->fresh()->status)->toBe(TimeEntryStatus::CLOSED);
});

it('refuses a correction that would make a segment zero length', function () {
    expect(fn () => $this->service->request(
        $this->entry,
        ['ended_at' => '2026-09-18T09:00:00+08:00'],
        'Ending it at the same moment it started.',
        $this->owner,
    ))->toThrow(InvalidArgumentException::class);
});

it('reports an unappliable correction as a 422 rather than a server error', function () {
    // The request has to fail in a way the console can explain, not with a 500.
    $correction = $this->service->request(
        $this->entry,
        ['type' => 'work'],
        'A change that is applied immediately.',
        $this->owner,
    );

    // Raised directly so the times can be made invalid without validation catching it
    // first — the point is what happens when it reaches the service.
    $broken = AttendanceCorrection::create([
        'time_entry_id' => $this->entry->id,
        'employee_id' => $this->employee->id,
        'outlet_id' => $this->outlet->id,
        'action' => 'update',
        'original_values' => $correction->original_values,
        'changes' => ['ended_at' => '2026-09-18T08:00:00+08:00'],
        'reason' => 'Times that cannot be applied.',
        'status' => CorrectionStatus::PENDING,
        'requested_by' => $this->manager->id,
    ]);

    $this->withHeaders(asUser($this->owner))
        ->postJson('/api/v1/admin/corrections/'.$broken->id.'/approve')
        ->assertStatus(422)
        ->assertJsonPath('code', 'INVALID_CORRECTION');

    // The correction stays pending so it can be fixed rather than disappearing.
    expect($broken->fresh()->status)->toBe(CorrectionStatus::PENDING);
});

// ---- Missing punches --------------------------------------------------
it('creates a segment for a punch that never happened', function () {
    // An update cannot fix a shift nobody punched, because there is nothing to amend.
    $correction = $this->service->requestMissing(
        $this->employee,
        $this->outlet->id,
        ['started_at' => '2026-09-17T09:00:00+08:00', 'ended_at' => '2026-09-17T17:00:00+08:00'],
        'Employee worked but the phone was broken.',
        $this->owner,
    );

    expect($correction->status)->toBe(CorrectionStatus::APPROVED);

    // whereDate, not where: business_date is date-cast, so an equality comparison never
    // matches under SQLite — see TimeEntry::scopeForBusinessDate.
    $entry = TimeEntry::whereDate('business_date', '2026-09-17')->first();

    expect($entry)->not->toBeNull();
    expect($entry->durationSeconds())->toBe(8 * 3600);
    // CORRECTED, not CLOSED: this did not come from a punch, and a report has to be able
    // to say so.
    expect($entry->status)->toBe(TimeEntryStatus::CORRECTED);
});

it('refuses a missing punch that ends before it starts', function () {
    expect(fn () => $this->service->requestMissing(
        $this->employee,
        $this->outlet->id,
        ['started_at' => '2026-09-17T17:00:00+08:00', 'ended_at' => '2026-09-17T09:00:00+08:00'],
        'Backwards.',
        $this->owner,
    ))->toThrow(InvalidArgumentException::class);
});

it('refuses a missing punch without an end', function () {
    // A segment with no end is an open one, and creating one by hand would collide with
    // the one-open-segment invariant for no good reason.
    expect(fn () => $this->service->requestMissing(
        $this->employee,
        $this->outlet->id,
        ['started_at' => '2026-09-17T09:00:00+08:00'],
        'No end given.',
        $this->owner,
    ))->toThrow(InvalidArgumentException::class);
});

it('does not create the segment until a manager correction is approved', function () {
    $correction = $this->service->requestMissing(
        $this->employee,
        $this->outlet->id,
        ['started_at' => '2026-09-17T09:00:00+08:00', 'ended_at' => '2026-09-17T17:00:00+08:00'],
        'Forgot to punch.',
        $this->manager,
    );

    expect(TimeEntry::whereDate('business_date', '2026-09-17')->exists())->toBeFalse();

    $this->service->approve($correction, $this->owner);

    expect(TimeEntry::whereDate('business_date', '2026-09-17')->exists())->toBeTrue();
});

// ---- Approval policy as a setting -------------------------------------

it('applies a manager correction immediately when approval is switched off', function () {
    // An owner may reasonably decide to tighten or loosen this without a deploy.
    Setting::set(Setting::REQUIRE_CORRECTION_APPROVAL, '0');

    $correction = $this->service->request(
        $this->entry,
        ['started_at' => '2026-09-18T09:30:00+08:00'],
        'Manager correcting the arrival.',
        $this->manager,
    );

    expect($correction->status)->toBe(CorrectionStatus::APPROVED);
});

it('still applies an owner correction immediately when approval is on', function () {
    // The owner is the final authority, so requiring their approval adds a step and no
    // control.
    Setting::set(Setting::REQUIRE_CORRECTION_APPROVAL, '1');

    $correction = $this->service->request(
        $this->entry,
        ['started_at' => '2026-09-18T09:30:00+08:00'],
        'Owner correcting the arrival.',
        $this->owner,
    );

    expect($correction->status)->toBe(CorrectionStatus::APPROVED);
});

// ---- Through the API --------------------------------------------------

it('lists only corrections for outlets the manager can see', function () {
    /*
     * The scoping test that matters most: a manager must not be able to read another
     * outlet's corrections, because a correction names a reason and a person.
     */
    $otherEmployee = Employee::create(['employee_code' => 'SS-001', 'name' => 'Someone Else', 'is_active' => true]);
    $otherEmployee->outlets()->attach($this->otherOutlet->id);

    AttendanceCorrection::create([
        'employee_id' => $otherEmployee->id,
        'outlet_id' => $this->otherOutlet->id,
        'action' => 'update',
        'changes' => ['type' => 'work'],
        'reason' => 'Other outlet correction.',
        'status' => CorrectionStatus::APPROVED,
        'requested_by' => $this->owner->id,
    ]);

    $this->service->request($this->entry, ['type' => 'work'], 'Our outlet correction.', $this->owner);

    $response = $this->withHeaders(asUser($this->manager))->getJson('/api/v1/admin/corrections');

    $response->assertOk();

    expect($response->json('data.corrections'))->toHaveCount(1);
    expect($response->json('data.corrections.0.reason'))->toBe('Our outlet correction.');
});

it('refuses a correction on an entry at another outlet with a 404', function () {
    // 404 rather than 403, so probing cannot reveal which entry ids exist.
    $otherEmployee = Employee::create(['employee_code' => 'SS-001', 'name' => 'Someone Else', 'is_active' => true]);

    $otherEntry = TimeEntry::create([
        'client_uuid' => (string) Str::uuid(),
        'employee_id' => $otherEmployee->id,
        'outlet_id' => $this->otherOutlet->id,
        'type' => TimeEntryType::WORK,
        'started_at' => now()->setTime(9, 0),
        'ended_at' => now()->setTime(17, 0),
        'business_date' => now()->toDateString(),
        'status' => TimeEntryStatus::CLOSED,
    ]);

    $this->withHeaders(asUser($this->manager))
        ->postJson('/api/v1/admin/corrections/entries/'.$otherEntry->id, [
            'changes' => ['type' => 'break'],
            'reason' => 'Trying to reach another outlet.',
        ])
        ->assertStatus(404);
});

it('refuses to let a manager approve their own correction', function () {
    // The whole point of the approval step. A manager approving themselves would make the
    // timesheet self-certifying.
    $correction = $this->service->request(
        $this->entry,
        ['started_at' => '2026-09-18T09:30:00+08:00'],
        'Manager correcting the arrival.',
        $this->manager,
    );

    $this->withHeaders(asUser($this->manager))
        ->postJson('/api/v1/admin/corrections/'.$correction->id.'/approve')
        ->assertStatus(403);

    // And nothing was applied.
    expect($this->entry->fresh()->started_at->toIso8601String())->toBe('2026-09-18T01:00:00+00:00');
});

it('lets the owner approve a manager correction', function () {
    $correction = $this->service->request(
        $this->entry,
        ['started_at' => '2026-09-18T09:30:00+08:00'],
        'Manager correcting the arrival.',
        $this->manager,
    );

    $this->withHeaders(asUser($this->owner))
        ->postJson('/api/v1/admin/corrections/'.$correction->id.'/approve', ['note' => 'Checked.'])
        ->assertOk();

    expect($this->entry->fresh()->started_at->toIso8601String())->toBe('2026-09-18T01:30:00+00:00');
});

it('requires a reason through the API', function () {
    // The API must not be a way around the rule that a correction explains itself.
    $this->withHeaders(asUser($this->owner))
        ->postJson('/api/v1/admin/corrections/entries/'.$this->entry->id, [
            'changes' => ['type' => 'break'],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('reason');
});

it('refuses an empty changes object through the API', function () {
    $this->withHeaders(asUser($this->owner))
        ->postJson('/api/v1/admin/corrections/entries/'.$this->entry->id, [
            'changes' => [],
            'reason' => 'Changing absolutely nothing.',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('changes');
});

it('rejects a change to a field that is not correctable through the API', function () {
    $this->withHeaders(asUser($this->owner))
        ->postJson('/api/v1/admin/corrections/entries/'.$this->entry->id, [
            'changes' => ['employee_id' => 999],
            'reason' => 'Trying to reassign this punch.',
        ])
        ->assertStatus(422);
});

it('returns the correction history for one entry', function () {
    $this->service->request($this->entry, ['type' => 'work'], 'First note.', $this->owner);
    $this->service->request($this->entry->fresh(), ['note' => 'Second note.'], 'Second change.', $this->owner);

    $response = $this->withHeaders(asUser($this->owner))
        ->getJson('/api/v1/admin/corrections/entries/'.$this->entry->id.'/history')
        ->assertOk();

    expect($response->json('data.corrections'))->toHaveCount(2);
});
