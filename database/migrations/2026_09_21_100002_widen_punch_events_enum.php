<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Widen `punch_events.event` for the offline queue.
 *
 * MySQL implements `enum()` as a real CHECK constraint, and SQLite compiles it to a column
 * `varchar check ("event" in (...))`. Either way a new event type inserted without this
 * migration fails AT THE DATABASE, not in PHP — which is the right way round for data integrity
 * and an easy thing to forget: the enum case, the model and the writer all look correct, and
 * only the insert objects.
 *
 * Widened rather than converted to a plain string. The constraint is what stops a typo in a
 * `PunchEvent::record()` call from quietly writing an unrecognised type that nothing will ever
 * query.
 *
 * Written with the schema builder rather than a raw `ALTER ... MODIFY`, because the two drivers
 * need different things: MySQL alters the column in place, while SQLite has no way to change a
 * CHECK constraint and requires the table to be rebuilt. The builder does the right thing on
 * each, and a hand-written pair of branches is exactly where one of them would be wrong.
 */
return new class extends Migration
{
    /** @var array<int, string> */
    private const VALUES = [
        'scan',
        'pin_failed',
        'pin_locked',
        'clock_in',
        'break_start',
        'break_end',
        'clock_out',
        'rejected',
        // A punch made on the phone while offline and sent later.
        'offline_sync',
        // A retry that matched an existing client_uuid, so nothing was changed.
        'duplicate_ignored',
    ];

    public function up(): void
    {
        Schema::table('punch_events', function (Blueprint $table) {
            $table->enum('event', self::VALUES)->change();
        });
    }

    public function down(): void
    {
        /*
         * Rows using the new types are deleted first.
         *
         * Narrowing the enum with those rows still present fails on MySQL with a truncated-value
         * error — and on a permissive configuration would instead rewrite them to the empty
         * string, silently losing the record that a punch was client-reported. That record is the
         * only thing distinguishing a time the server witnessed from one the phone claimed, so
         * losing it quietly is the worst outcome available.
         */
        DB::table('punch_events')
            ->whereIn('event', ['offline_sync', 'duplicate_ignored'])
            ->delete();

        Schema::table('punch_events', function (Blueprint $table) {
            $table->enum('event', array_slice(self::VALUES, 0, 8))->change();
        });
    }
};
