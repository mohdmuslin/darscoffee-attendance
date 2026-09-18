<?php

use App\Enums\AnomalyType;
use App\Enums\CorrectionStatus;
use App\Enums\TimeEntryStatus;
use App\Enums\TimeEntryType;
use App\Models\Anomaly;
use App\Models\AttendanceCorrection;
use App\Models\Employee;
use App\Models\Outlet;
use App\Models\Setting;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\PhotoRetentionService;
use App\Services\PhotoService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * PDPA photo retention.
 *
 * The theme running through all of this: retention is a data-destroying process, and the
 * failures that matter are the quiet ones. A purge that deletes too little is a compliance
 * gap nobody notices; a purge that deletes too much has destroyed the evidence in a
 * dispute, or someone's photograph after they have left, and neither can be undone.
 *
 * So most of these tests assert that something SURVIVED.
 *
 * The other theme is that age is judged per photograph, not per record. A punch has two
 * photos taken up to a shift apart, so using one timestamp for both is wrong by hours.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-18 09:00:00', 'Asia/Kuala_Lumpur'));

    Storage::fake('local');

    $this->outlet = Outlet::create([
        'code' => 'SG-RAMAL',
        'name' => 'Sg Ramal',
        'timezone' => 'Asia/Kuala_Lumpur',
        'token_mode' => 'printed',
        'requires_photo' => true,
    ]);

    $this->employee = Employee::create([
        'employee_code' => 'RAM-001',
        'name' => 'Ali bin Ahmad',
        'is_active' => true,
    ]);
    $this->employee->outlets()->attach($this->outlet->id);

    $this->photos = app(PhotoService::class);
    $this->retention = app(PhotoRetentionService::class);
});

/**
 * Write a file to the private disk with a specific modification time.
 *
 * The modification time is the entire input to the age test, so a helper that cannot
 * control it cannot test anything. `Storage::fake` supports `lastModified` reads against
 * real write times, so the file is written and then its mtime set on the underlying path.
 */
function storeDatedPhoto(string $contents, string $folder, DateTimeInterface $modifiedAt): string
{
    $path = $folder.'/'.uniqid('photo-', true).'.jpg';

    Storage::disk('local')->put($path, $contents);
    touch(Storage::disk('local')->path($path), $modifiedAt->getTimestamp());

    return $path;
}

/**
 * A closed work segment with both photos, at chosen times.
 *
 * The timestamps are accepted as any `DateTimeInterface` because Laravel's own `now()`
 * hands back `Illuminate\Support\Carbon` rather than the immutable class — a distinction
 * that has nothing to do with what these tests are about.
 */
function entryWithPhotos(
    Employee $employee,
    Outlet $outlet,
    DateTimeInterface $startedAt,
    DateTimeInterface $endedAt,
    string $startPhoto,
    ?string $endPhoto = null,
): TimeEntry {
    $startedAt = CarbonImmutable::instance($startedAt);
    $endedAt = CarbonImmutable::instance($endedAt);

    return TimeEntry::create([
        // NOT NULL: every segment carries one so an offline queue can be idempotent.
        'client_uuid' => (string) Str::uuid(),
        'employee_id' => $employee->id,
        'outlet_id' => $outlet->id,
        'type' => TimeEntryType::WORK,
        'started_at' => $startedAt,
        'ended_at' => $endedAt,
        'business_date' => $startedAt->setTimezone($outlet->timezone)->toDateString(),
        'status' => TimeEntryStatus::CLOSED,
        'started_photo_path' => $startPhoto,
        'ended_photo_path' => $endPhoto,
    ]);
}

it('deletes a punch photo past the window and clears the pointer to it', function () {
    $old = storeDatedPhoto('old-start', PhotoRetentionService::PUNCH_FOLDER, now()->subDays(120));

    $entry = entryWithPhotos(
        $this->employee,
        $this->outlet,
        now()->subDays(100),
        now()->subDays(100)->addHours(8),
        $old,
    );

    $report = $this->retention->run();

    expect($report['photos']['purged'])->toBe(1)
        ->and(Storage::disk('local')->exists($old))->toBeFalse()
        /*
         * The pointer must go too. If it did not, the console would keep rendering a
         * broken image for a photo the database insists exists.
         */
        ->and($entry->fresh()->started_photo_path)->toBeNull();
});

it('keeps a photo taken inside the retention window', function () {
    $recent = storeDatedPhoto('recent', PhotoRetentionService::PUNCH_FOLDER, now()->subDays(10));

    $entry = entryWithPhotos(
        $this->employee,
        $this->outlet,
        now()->subDays(9),
        now()->subDays(9)->addHours(8),
        $recent,
    );

    $report = $this->retention->run();

    expect($report['photos']['purged'])->toBe(0)
        ->and(Storage::disk('local')->exists($recent))->toBeTrue()
        ->and($entry->fresh()->started_photo_path)->toBe($recent);
});

it('respects the configured retention window rather than a hardcoded one', function () {
    Setting::set(Setting::PHOTO_RETENTION_DAYS, '30');

    $path = storeDatedPhoto('sixty-days', PhotoRetentionService::PUNCH_FOLDER, now()->subDays(60));

    entryWithPhotos(
        $this->employee,
        $this->outlet,
        now()->subDays(55),
        now()->subDays(55)->addHours(8),
        $path,
    );

    // 60 days old and the window is 30, so it goes. Under the 90-day default it would stay.
    expect($this->retention->run()['photos']['purged'])->toBe(1);

    Setting::set(Setting::PHOTO_RETENTION_DAYS, '365');
    $path2 = storeDatedPhoto('sixty-days-b', PhotoRetentionService::PUNCH_FOLDER, now()->subDays(60));

    entryWithPhotos(
        $this->employee,
        $this->outlet,
        now()->subDays(55),
        now()->subDays(55)->addHours(8),
        $path2,
    );

    // Now the window is a year, so the same age survives.
    expect($this->retention->run()['photos']['purged'])->toBe(0)
        ->and(Storage::disk('local')->exists($path2))->toBeTrue();
});

/**
 * The bug this test exists for: judging both photos of a punch by the segment's START.
 *
 * An overnight shift begins before the cutoff and ends after it. Its clock-out photo is
 * therefore inside the window even though the entry is not. Aging both by `started_at`
 * would delete the clock-out photo early — and on an overnight shift that is most of a
 * day of the window lost.
 */
it('ages the clock-out photo by its own time, not the segment start', function () {
    /*
     * `CarbonImmutable::now()` rather than `now()` — and the difference is the test.
     *
     * Laravel's `now()` returns a MUTABLE Carbon, so `$cutoff->subDay()` moves the
     * variable itself as well as returning it. Building the two timestamps that way
     * made them depend on evaluation order rather than on the intent written beside
     * them, and produced two identical instants where a day apart was meant.
     */
    $cutoff = CarbonImmutable::now()->subDays(90);

    // Starts a day before the cutoff, ends a day after it.
    $startPhoto = storeDatedPhoto('night-start', PhotoRetentionService::PUNCH_FOLDER, $cutoff->subDay());
    $endPhoto = storeDatedPhoto('night-end', PhotoRetentionService::PUNCH_FOLDER, $cutoff->addDay());

    $entry = entryWithPhotos(
        $this->employee,
        $this->outlet,
        $cutoff->subDay(),
        $cutoff->addDay(),
        $startPhoto,
        $endPhoto,
    );

    $this->retention->run();

    $fresh = $entry->fresh();

    expect(Storage::disk('local')->exists($startPhoto))->toBeFalse('the clock-in photo is past the window')
        ->and($fresh->started_photo_path)->toBeNull()
        ->and(Storage::disk('local')->exists($endPhoto))->toBeTrue('the clock-out photo is still inside the window')
        ->and($fresh->ended_photo_path)->toBe($endPhoto);
});

it('holds a photo whose anomaly nobody has reviewed', function () {
    $path = storeDatedPhoto('disputed', PhotoRetentionService::PUNCH_FOLDER, now()->subDays(120));

    $entry = entryWithPhotos(
        $this->employee,
        $this->outlet,
        now()->subDays(100),
        now()->subDays(100)->addHours(8),
        $path,
    );

    Anomaly::raise($entry, AnomalyType::DOUBLE_OUTLET);

    $report = $this->retention->run();

    expect($report['photos']['purged'])->toBe(0)
        ->and($report['photos']['held'])->toBe(1)
        ->and($report['holds']['unreviewed_anomaly'])->toBe(1)
        ->and(Storage::disk('local')->exists($path))->toBeTrue()
        ->and($entry->fresh()->started_photo_path)->toBe($path);
});

it('releases the hold once the anomaly has been reviewed', function () {
    $path = storeDatedPhoto('settled', PhotoRetentionService::PUNCH_FOLDER, now()->subDays(120));

    $entry = entryWithPhotos(
        $this->employee,
        $this->outlet,
        now()->subDays(100),
        now()->subDays(100)->addHours(8),
        $path,
    );

    $anomaly = Anomaly::raise($entry, AnomalyType::DOUBLE_OUTLET);

    // A manager looked at it and closed it. The photo has served its purpose.
    $anomaly->update(['reviewed_at' => now(), 'reviewed_by' => User::factory()->create()->id]);

    expect($this->retention->run()['photos']['purged'])->toBe(1)
        ->and(Storage::disk('local')->exists($path))->toBeFalse();
});

it('holds a photo attached to a correction still awaiting a decision', function () {
    $path = storeDatedPhoto('pending', PhotoRetentionService::PUNCH_FOLDER, now()->subDays(120));

    $entry = entryWithPhotos(
        $this->employee,
        $this->outlet,
        now()->subDays(100),
        now()->subDays(100)->addHours(8),
        $path,
    );

    AttendanceCorrection::create([
        'time_entry_id' => $entry->id,
        'employee_id' => $this->employee->id,
        'outlet_id' => $this->outlet->id,
        'action' => 'update',
        'changes' => ['ended_at' => now()->subDays(100)->addHours(10)->toIso8601String()],
        'reason' => 'Forgot to clock out on time',
        'status' => CorrectionStatus::PENDING,
        'requested_by' => User::factory()->create()->id,
    ]);

    $report = $this->retention->run();

    expect($report['photos']['purged'])->toBe(0)
        ->and($report['holds']['pending_correction'])->toBe(1)
        ->and(Storage::disk('local')->exists($path))->toBeTrue();
});

it('releases the hold once the correction has been decided', function () {
    $path = storeDatedPhoto('decided', PhotoRetentionService::PUNCH_FOLDER, now()->subDays(120));

    $entry = entryWithPhotos(
        $this->employee,
        $this->outlet,
        now()->subDays(100),
        now()->subDays(100)->addHours(8),
        $path,
    );

    $correction = AttendanceCorrection::create([
        'time_entry_id' => $entry->id,
        'employee_id' => $this->employee->id,
        'outlet_id' => $this->outlet->id,
        'action' => 'update',
        'changes' => ['ended_at' => now()->subDays(100)->addHours(10)->toIso8601String()],
        'reason' => 'Forgot to clock out on time',
        'status' => CorrectionStatus::PENDING,
        'requested_by' => User::factory()->create()->id,
    ]);

    $correction->update(['status' => CorrectionStatus::REJECTED]);

    expect($this->retention->run()['photos']['purged'])->toBe(1);
});

it('sweeps a file that no row refers to', function () {
    $orphan = storeDatedPhoto('orphan', PhotoRetentionService::PUNCH_FOLDER, now()->subDays(200));

    $report = $this->retention->run();

    expect($report['photos']['orphans'])->toBe(1)
        ->and(Storage::disk('local')->exists($orphan))->toBeFalse();
});

/**
 * The orphan sweep must not eat recent photos.
 *
 * A file is only an orphan when NOTHING refers to it. Deriving the referent set from the
 * eligible rows instead of all rows would make every in-window photo look unreferenced,
 * and the sweep would delete exactly the photographs that were supposed to survive.
 */
it('leaves a recent referred photo alone even though the sweep runs over the folder', function () {
    $recent = storeDatedPhoto('recent', PhotoRetentionService::PUNCH_FOLDER, now()->subDays(2));

    entryWithPhotos(
        $this->employee,
        $this->outlet,
        now()->subDay(),
        now()->subDay()->addHours(8),
        $recent,
    );

    expect($this->retention->run()['photos']['orphans'])->toBe(0)
        ->and(Storage::disk('local')->exists($recent))->toBeTrue();
});

it('clears a row whose file is already gone', function () {
    // Named by a row but never written — an upload that failed after the row committed.
    $entry = entryWithPhotos(
        $this->employee,
        $this->outlet,
        now()->subDays(100),
        now()->subDays(100)->addHours(8),
        'punches/never-written.jpg',
    );

    $report = $this->retention->run();

    expect($report['photos']['missing'])->toBe(1)
        ->and($entry->fresh()->started_photo_path)->toBeNull();
});

it('is idempotent', function () {
    $path = storeDatedPhoto('once', PhotoRetentionService::PUNCH_FOLDER, now()->subDays(120));

    entryWithPhotos(
        $this->employee,
        $this->outlet,
        now()->subDays(100),
        now()->subDays(100)->addHours(8),
        $path,
    );

    $first = $this->retention->run();
    $second = $this->retention->run();

    expect($first['photos']['purged'])->toBe(1)
        ->and($second['photos']['purged'])->toBe(0)
        ->and($second['photos']['orphans'])->toBe(0);
});

it('deletes nothing on a dry run but reports what it would delete', function () {
    $path = storeDatedPhoto('preview', PhotoRetentionService::PUNCH_FOLDER, now()->subDays(120));

    $entry = entryWithPhotos(
        $this->employee,
        $this->outlet,
        now()->subDays(100),
        now()->subDays(100)->addHours(8),
        $path,
    );

    $report = $this->retention->run(null, dryRun: true);

    expect($report['dry_run'])->toBeTrue()
        ->and($report['photos']['purged'])->toBe(1)
        ->and(Storage::disk('local')->exists($path))->toBeTrue()
        ->and($entry->fresh()->started_photo_path)->toBe($path);
});

it('keeps a departed employee photo until the window has passed', function () {
    $path = storeDatedPhoto('profile', PhotoRetentionService::EMPLOYEE_FOLDER, now()->subDays(120));

    $this->employee->update([
        'photo_path' => $path,
        'resigned_at' => now()->subDays(10)->toDateString(),
    ]);

    $report = $this->retention->run();

    expect($report['profiles']['purged'])->toBe(0)
        ->and(Storage::disk('local')->exists($path))->toBeTrue();
});

it('deletes a departed employee photo once the window has passed', function () {
    $path = storeDatedPhoto('profile-old', PhotoRetentionService::EMPLOYEE_FOLDER, now()->subDays(200));

    $this->employee->update([
        'photo_path' => $path,
        'resigned_at' => now()->subDays(150)->toDateString(),
        'is_active' => false,
    ]);

    $report = $this->retention->run();

    expect($report['profiles']['purged'])->toBe(1)
        ->and(Storage::disk('local')->exists($path))->toBeFalse()
        ->and($this->employee->fresh()->photo_path)->toBeNull();
});

/**
 * A current employee keeps their photograph however old the file is.
 *
 * The file's age says nothing about whether the photo is still in use, and a profile
 * photo is what the console shows on the roster and beside every punch. Deleting it
 * because the file is old would leave a nameless placeholder for someone still working.
 */
it('never deletes the photo of an employee who still works here', function () {
    $path = storeDatedPhoto('ancient-but-current', PhotoRetentionService::EMPLOYEE_FOLDER, now()->subDays(900));

    $this->employee->update(['photo_path' => $path]);

    expect($this->retention->run()['profiles']['purged'])->toBe(0)
        ->and(Storage::disk('local')->exists($path))->toBeTrue();
});

it('clears the profile photo of a soft-deleted employee past the window', function () {
    $path = storeDatedPhoto('soft-deleted', PhotoRetentionService::EMPLOYEE_FOLDER, now()->subDays(200));

    $this->employee->update(['photo_path' => $path]);
    $this->employee->delete();

    /*
     * The employee was removed and then the retention window elapsed. Travel forwards
     * rather than back-dating `deleted_at`, because the point of the case is that the
     * soft-delete timestamp is what ages a row with no resignation date — and that
     * timestamp is set by `delete()` itself.
     */
    $this->travel(150)->days();

    $report = $this->retention->run();

    expect($report['profiles']['purged'])->toBe(1)
        ->and(Storage::disk('local')->exists($path))->toBeFalse()
        // Read with the trashed row included, since the row itself must survive.
        ->and(Employee::withTrashed()->find($this->employee->id)->photo_path)->toBeNull()
        ->and(Employee::withTrashed()->find($this->employee->id)->trashed())->toBeTrue();
});

it('does not bump updated_at when clearing a photo pointer', function () {
    $path = storeDatedPhoto('stamp', PhotoRetentionService::PUNCH_FOLDER, now()->subDays(120));

    $entry = entryWithPhotos(
        $this->employee,
        $this->outlet,
        now()->subDays(100),
        now()->subDays(100)->addHours(8),
        $path,
    );

    $before = $entry->fresh()->updated_at;

    // Travel far enough that a bumped timestamp is unmistakable.
    $this->travel(3)->days();

    $this->retention->run();

    /*
     * Clearing a photo path is not an edit to the recorded hours, so it must not look
     * like one. An `updated_at` that moves on a pay period's entries would suggest the
     * times were touched after approval.
     */
    expect($entry->fresh()->updated_at->equalTo($before))->toBeTrue();
});

it('reports a folder that does not exist as zero rather than failing', function () {
    // A fresh install with no photographs yet.
    expect($this->retention->run()['photos']['purged'])->toBe(0);
});

it('runs from the artisan command', function () {
    $path = storeDatedPhoto('command', PhotoRetentionService::PUNCH_FOLDER, now()->subDays(120));

    entryWithPhotos(
        $this->employee,
        $this->outlet,
        now()->subDays(100),
        now()->subDays(100)->addHours(8),
        $path,
    );

    $this->artisan('attendance:purge-photos')
        ->expectsOutputToContain('keeping photographs for 90 days')
        ->assertSuccessful();

    expect(Storage::disk('local')->exists($path))->toBeFalse();
});

it('leaves everything alone when the command runs with --dry-run', function () {
    $path = storeDatedPhoto('command-preview', PhotoRetentionService::PUNCH_FOLDER, now()->subDays(120));

    entryWithPhotos(
        $this->employee,
        $this->outlet,
        now()->subDays(100),
        now()->subDays(100)->addHours(8),
        $path,
    );

    $this->artisan('attendance:purge-photos --dry-run')
        ->expectsOutputToContain('Nothing was deleted')
        ->assertSuccessful();

    expect(Storage::disk('local')->exists($path))->toBeTrue();
});

it('rejects a nonsensical --days override', function () {
    $this->artisan('attendance:purge-photos --days=0')->assertFailed();
});
