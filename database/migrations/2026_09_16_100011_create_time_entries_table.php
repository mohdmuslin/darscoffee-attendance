<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * time_entries — the ACTUAL record. The source of truth for pay.
 *
 * Work and breaks are separate rows of different `type`, so worked time is a
 * plain sum with nothing to subtract:
 *
 *     worked = SUM(duration_seconds) WHERE type = 'work'
 *
 * Representing a break as a column instead would mean arithmetic to get right —
 * and arithmetic is what gets a lunch break counted as overtime.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('time_entries', function (Blueprint $table) {
            $table->id();

            /*
             * Generated on the phone, unique in the database. This is the
             * idempotency guarantee for the offline queue: retrying after a flaky
             * connection upserts on this key instead of creating a second punch.
             *
             * It must exist from the first migration, because it is the one thing
             * that cannot be backfilled later.
             */
            $table->uuid('client_uuid');

            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('outlet_id')->constrained()->cascadeOnDelete();

            // Matched shift, when one exists. NULL means adhoc work.
            $table->foreignId('shift_id')->nullable()->constrained()->nullOnDelete();

            $table->enum('type', ['work', 'break']);

            // UTC. Client-supplied when the punch was queued offline, and bounded
            // by the server: a timestamp far in the past or future is rejected.
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();

            /*
             * Cached on close, and always recomputed from the timestamps.
             * Reporting sums these instead of recalculating every row, but the
             * timestamps stay authoritative so the cache can be rebuilt — a
             * cached value that cannot be re-derived is a liability.
             */
            $table->unsignedInteger('duration_seconds')->nullable();

            $table->string('started_photo_path', 255)->nullable();
            $table->string('ended_photo_path', 255)->nullable();

            // Which code was scanned, so a punch can be traced to a token.
            $table->foreignId('started_token_id')->nullable()->constrained('outlet_tokens')->nullOnDelete();
            $table->foreignId('ended_token_id')->nullable()->constrained('outlet_tokens')->nullOnDelete();

            // Coarse device class, used for anomaly detection.
            $table->string('started_device', 32)->nullable();

            /*
             * A business day is the day the segment STARTED, not the calendar day
             * it ended. A 22:00-02:00 shift is one working day; deriving this from
             * the end time would push the tail into tomorrow and misfire overtime
             * thresholds. Stored and indexed so reports group correctly.
             */
            $table->date('business_date');

            $table->enum('status', ['open', 'closed', 'flagged', 'corrected'])->default('open');

            $table->string('note', 255)->nullable();

            // Arrived through the offline queue, so the time is client-reported.
            $table->boolean('is_offline_sync')->default(false);

            $table->timestamps();

            $table->unique('client_uuid');
            $table->index(['employee_id', 'business_date']);
            $table->index(['outlet_id', 'business_date']);
            $table->index('status');
        });

        /*
         * THE ONE-OPEN-SEGMENT INVARIANT, enforced by the database.
         *
         * At most one row per employee may have ended_at IS NULL, so worked time
         * is always a plain sum with no overlapping segments to de-duplicate, and
         * "who is clocked in now" is one indexed query.
         *
         * This is deliberately NOT left to application code: two simultaneous
         * requests (a double tap on a flaky connection) both pass an
         * application-level exists() check. The database catches what the
         * application cannot.
         *
         * The two drivers need different mechanisms for the same rule:
         *
         *   SQLite  a PARTIAL unique index — MySQL has no equivalent syntax.
         *   MySQL   no partial indexes, so a generated column holds the employee
         *           id only while the entry is open, and a unique index on it
         *           makes a second open row impossible.
         *
         * Both were verified to behave identically (reject a second open segment,
         * allow many closed ones, allow one per employee, allow reopen after
         * close), so the test suite on SQLite genuinely proves the production
         * behaviour rather than approximating it.
         */
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            DB::statement('
                CREATE UNIQUE INDEX uniq_open_segment
                ON time_entries (employee_id)
                WHERE ended_at IS NULL
            ');

            return;
        }

        /*
         * VIRTUAL, not STORED. MySQL 8.4 refuses to add a STORED generated column
         * by ALTER on a table that already has foreign keys, failing with
         * "Cannot add foreign key constraint" — a misleading message for what is
         * really a generated-column restriction. A VIRTUAL column can still carry
         * a unique index, so it enforces the same rule while costing no storage.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE time_entries
            ADD COLUMN open_segment_guard BIGINT
                AS (CASE WHEN ended_at IS NULL THEN employee_id ELSE NULL END) VIRTUAL
        SQL);

        DB::statement('
            ALTER TABLE time_entries
            ADD UNIQUE KEY uniq_open_segment (open_segment_guard)
        ');
    }

    public function down(): void
    {
        Schema::dropIfExists('time_entries');
    }
};
