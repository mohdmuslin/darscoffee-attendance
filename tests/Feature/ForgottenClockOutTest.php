<?php

use App\Enums\AnomalySeverity;
use App\Enums\AnomalyType;
use App\Enums\TimeEntryStatus;
use App\Enums\TimeEntryType;
use App\Models\Anomaly;
use App\Models\Employee;
use App\Models\Outlet;
use App\Models\Setting;
use App\Models\TimeEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Flagging forgotten clock-outs.
 *
 * This command closes a gap that was invisible because everything around it looked complete: the
 * `MISSING_CLOCKOUT` anomaly type existed, was given HIGH severity, and was raised NOWHERE.
 * `flagOnClose()` raises LONG_SPAN only when a segment CLOSES — which is precisely the event that
 * never happens when somebody forgets to clock out.
 *
 * The cost is not just a wrong total. The open-segment invariant allows one open segment per
 * employee, so a forgotten clock-out means the next morning's clock-in silently does nothing:
 * they scan, enter their PIN, press the button, and the screen carries on as though they had not.
 * Nobody at the counter can see why.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-21 09:00:00', 'Asia/Kuala_Lumpur'));
    $this->freezeTime();

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
});

function openSegmentSince(Employee $employee, Outlet $outlet, DateTimeInterface $startedAt): TimeEntry
{
    $startedAt = CarbonImmutable::instance($startedAt);

    return TimeEntry::create([
        'client_uuid' => (string) Str::uuid(),
        'employee_id' => $employee->id,
        'outlet_id' => $outlet->id,
        'type' => TimeEntryType::WORK,
        'started_at' => $startedAt,
        'business_date' => $startedAt->setTimezone($outlet->timezone)->toDateString(),
        'status' => TimeEntryStatus::OPEN,
    ]);
}

it('flags a segment left open past the threshold', function () {
    $entry = openSegmentSince($this->employee, $this->outlet, now()->subHours(20));

    $this->artisan('attendance:flag-open-segments')->assertSuccessful();

    $anomaly = Anomaly::query()
        ->where('time_entry_id', $entry->id)
        ->where('type', AnomalyType::MISSING_CLOCKOUT->value)
        ->first();

    expect($anomaly)->not->toBeNull()
        ->and($anomaly->severity)->toBe(AnomalySeverity::HIGH);
});

it('leaves a segment that is merely in progress alone', function () {
    // Four hours in: a long but perfectly ordinary shift.
    openSegmentSince($this->employee, $this->outlet, now()->subHours(4));

    $this->artisan('attendance:flag-open-segments')->assertSuccessful();

    expect(Anomaly::count())->toBe(0);
});

it('leaves a closed segment alone', function () {
    $entry = openSegmentSince($this->employee, $this->outlet, now()->subHours(20));
    $entry->close(now()->subHours(4));

    $this->artisan('attendance:flag-open-segments')->assertSuccessful();

    expect(Anomaly::count())->toBe(0);
});

/**
 * Raised ONCE, however many times the command runs.
 *
 * It runs hourly for as long as the mistake stands. Without this, a shift forgotten on Friday
 * would have fifty identical anomalies by Monday — burying every other flag in the review queue,
 * which is the one place a manager looks.
 */
it('does not flag the same segment twice', function () {
    openSegmentSince($this->employee, $this->outlet, now()->subHours(20));

    $this->artisan('attendance:flag-open-segments')->assertSuccessful();
    $this->artisan('attendance:flag-open-segments')->assertSuccessful();
    $this->artisan('attendance:flag-open-segments')->assertSuccessful();

    expect(Anomaly::query()->where('type', AnomalyType::MISSING_CLOCKOUT->value)->count())->toBe(1);
});

it('honours the configured threshold', function () {
    openSegmentSince($this->employee, $this->outlet, now()->subHours(10));

    // Under the 16-hour default this is not yet a problem.
    $this->artisan('attendance:flag-open-segments')->assertSuccessful();
    expect(Anomaly::count())->toBe(0);

    Setting::set(Setting::MISSING_CLOCKOUT_HOURS, '8');

    $this->artisan('attendance:flag-open-segments')->assertSuccessful();
    expect(Anomaly::query()->where('type', AnomalyType::MISSING_CLOCKOUT->value)->count())->toBe(1);
});

it('accepts an hours override without touching the setting', function () {
    openSegmentSince($this->employee, $this->outlet, now()->subHours(10));

    $this->artisan('attendance:flag-open-segments --hours=6')->assertSuccessful();

    expect(Anomaly::count())->toBe(1);
});

it('rejects a nonsensical hours override', function () {
    $this->artisan('attendance:flag-open-segments --hours=0')->assertFailed();
});

it('writes nothing on a dry run', function () {
    openSegmentSince($this->employee, $this->outlet, now()->subHours(20));

    $this->artisan('attendance:flag-open-segments --dry-run')
        ->expectsOutputToContain('Nothing was written')
        ->assertSuccessful();

    expect(Anomaly::count())->toBe(0);
});

it('says the time in the business timezone, not UTC', function () {
    // Started 08:00 Kuala Lumpur yesterday.
    $startedAt = CarbonImmutable::parse('2026-09-20 08:00:00', 'Asia/Kuala_Lumpur')->utc();

    openSegmentSince($this->employee, $this->outlet, $startedAt);

    $this->artisan('attendance:flag-open-segments')->assertSuccessful();

    $detail = Anomaly::query()->where('type', AnomalyType::MISSING_CLOCKOUT->value)->value('detail');

    /*
     * A manager checking "what time did they start" needs the wall clock they would have seen.
     * UTC would read 00:00 and look like a fault in the data rather than a timezone.
     */
    expect($detail)->toContain('08:00')
        ->and($detail)->not->toContain('00:00');
});

it('reports how long the segment has been running', function () {
    openSegmentSince($this->employee, $this->outlet, now()->subHours(20));

    $this->artisan('attendance:flag-open-segments')->assertSuccessful();

    expect(Anomaly::query()->where('type', AnomalyType::MISSING_CLOCKOUT->value)->value('detail'))
        ->toContain('20 hour(s)');
});

it('does nothing at all when there are no open segments', function () {
    $this->artisan('attendance:flag-open-segments')
        ->expectsOutputToContain('Nothing open longer than')
        ->assertSuccessful();

    expect(Anomaly::count())->toBe(0);
});

/**
 * The consequence that makes this worth running hourly rather than daily.
 *
 * With a segment left open, the employee's next clock-in does nothing at all — the open-segment
 * invariant permits only one, so `clockIn()` returns the existing segment instead of creating a
 * new one. From their side the button simply does not work, and no error is shown.
 *
 * This test documents that behaviour rather than asserting the flag fixes it: the flag exists so
 * a MANAGER can, by correcting the entry.
 */
it('documents that a forgotten clock-out blocks the next clock-in', function () {
    $entry = openSegmentSince($this->employee, $this->outlet, now()->subHours(20));

    $this->artisan('attendance:flag-open-segments')->assertSuccessful();

    // The segment is still open — flagging does not close it, deliberately. Closing it would
    // invent an end time, and a fabricated clock-out is worse than a missing one.
    expect($entry->fresh()->ended_at)->toBeNull()
        ->and(TimeEntry::query()->open()->count())->toBe(1);
});
