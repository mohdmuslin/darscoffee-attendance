<?php

use App\Models\Employee;
use App\Models\Outlet;
use App\Models\TimeEntry;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

/**
 * The database-level invariants.
 *
 * These are the rules that protect pay accuracy. They are tested against the real
 * schema rather than only through services, because their whole purpose is to hold
 * when application logic is bypassed — a double tap, a retried request, a race.
 */
beforeEach(function () {
    $this->outlet = Outlet::create(['code' => 'TEST', 'name' => 'Test Outlet']);
    $this->employee = Employee::create(['employee_code' => 'T-1', 'name' => 'Tester']);
});

/** Build an entry without going through the model, to test raw constraint behaviour. */
function rawEntry(int $employeeId, int $outletId, ?string $endedAt, ?string $uuid = null): array
{
    return [
        'client_uuid' => $uuid ?? (string) Str::uuid(),
        'employee_id' => $employeeId,
        'outlet_id' => $outletId,
        'type' => 'work',
        'started_at' => now(),
        'ended_at' => $endedAt,
        'business_date' => now()->toDateString(),
        'status' => $endedAt === null ? 'open' : 'closed',
        'created_at' => now(),
        'updated_at' => now(),
    ];
}

// ---- One open segment per employee ------------------------------------

it('allows an employee to have one open segment', function () {
    TimeEntry::create(rawEntry($this->employee->id, $this->outlet->id, null));

    expect(TimeEntry::open()->count())->toBe(1);
});

it('refuses a second open segment for the same employee at the database level', function () {
    /*
     * The important one. An application-level "do they have an open entry?" check
     * cannot survive two simultaneous requests — a double tap on a flaky
     * connection both pass it. The unique index on the generated column is what
     * actually prevents inflated hours.
     */
    TimeEntry::create(rawEntry($this->employee->id, $this->outlet->id, null));

    expect(fn () => TimeEntry::create(rawEntry($this->employee->id, $this->outlet->id, null)))
        ->toThrow(QueryException::class);

    expect(TimeEntry::open()->count())->toBe(1);
});

it('allows many CLOSED segments for the same employee', function () {
    // Only one may be open; any number may be finished.
    foreach (range(1, 5) as $ignored) {
        TimeEntry::create(rawEntry($this->employee->id, $this->outlet->id, now()->toDateTimeString()));
    }

    expect(TimeEntry::count())->toBe(5);
    expect(TimeEntry::open()->count())->toBe(0);
});

it('allows different employees to each have an open segment', function () {
    $other = Employee::create(['employee_code' => 'T-2', 'name' => 'Someone Else']);

    TimeEntry::create(rawEntry($this->employee->id, $this->outlet->id, null));
    TimeEntry::create(rawEntry($other->id, $this->outlet->id, null));

    expect(TimeEntry::open()->count())->toBe(2);
});

it('allows clocking in again after clocking out', function () {
    // The guard must clear when a segment closes, or a second shift is impossible.
    $open = TimeEntry::create(rawEntry($this->employee->id, $this->outlet->id, null));

    $open->update(['ended_at' => now(), 'status' => 'closed']);

    TimeEntry::create(rawEntry($this->employee->id, $this->outlet->id, null));

    expect(TimeEntry::open()->count())->toBe(1);
    expect(TimeEntry::count())->toBe(2);
});

it('treats a break as a segment too, so it cannot overlap work', function () {
    /*
     * Starting a break closes the work segment and opens a break segment, so the
     * invariant means an employee is never simultaneously working and on break.
     */
    $work = TimeEntry::create(rawEntry($this->employee->id, $this->outlet->id, null));

    $work->update(['ended_at' => now(), 'status' => 'closed']);

    TimeEntry::create([
        ...rawEntry($this->employee->id, $this->outlet->id, null),
        'type' => 'break',
    ]);

    expect(TimeEntry::open()->count())->toBe(1);
    expect(TimeEntry::where('type', 'break')->count())->toBe(1);
});

// ---- Idempotency ------------------------------------------------------

it('refuses a duplicate client_uuid', function () {
    /*
     * The offline queue retries a punch when a connection drops. Without this
     * constraint, every retry would create another punch and inflate hours.
     */
    $uuid = (string) Str::uuid();

    TimeEntry::create(rawEntry($this->employee->id, $this->outlet->id, now()->toDateTimeString(), $uuid));

    expect(fn () => TimeEntry::create(
        rawEntry($this->employee->id, $this->outlet->id, now()->toDateTimeString(), $uuid)
    ))->toThrow(QueryException::class);

    expect(TimeEntry::count())->toBe(1);
});

it('requires a client_uuid on every entry', function () {
    // The column is NOT NULL: an entry that cannot be de-duplicated is unusable.
    $entry = rawEntry($this->employee->id, $this->outlet->id, null);
    unset($entry['client_uuid']);

    expect(fn () => TimeEntry::create($entry))->toThrow(QueryException::class);
});
