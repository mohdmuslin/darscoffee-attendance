<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Corrections to recorded time.
 *
 * The record is never edited in place: a correction keeps a snapshot of what was
 * there before, so after it is applied you can still say exactly what the original
 * punch said. Success criterion 6 — a month's figures must be re-derivable — depends
 * on this, because a correction that overwrote the original would destroy the evidence
 * needed to answer a dispute.
 *
 * Supersedes the sketch in docs/database-design.md in two ways, both deliberate:
 *
 *  1. `action` distinguishes an UPDATE from a CREATE. A missing clock-out is an update
 *     to an open segment, but a shift nobody ever punched needs a segment invented —
 *     and the blueprint's success criterion says "a missed punch can be corrected",
 *     which includes that case.
 *  2. `employee_id` and `outlet_id` are stored on the correction itself, not only
 *     reachable through the entry. Outlet scoping is the security boundary here, and it
 *     has to be a plain indexed column so it can be filtered before anything is loaded —
 *     which a CREATE (with no entry yet) cannot offer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_corrections', function (Blueprint $table) {
            $table->id();

            /*
             * Null when action = 'create': there is no segment to point at yet.
             * nullOnDelete rather than cascade: deleting a punch must not silently
             * erase the record of who changed it.
             */
            $table->foreignId('time_entry_id')->nullable()->constrained('time_entries')->nullOnDelete();

            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('outlet_id')->constrained()->cascadeOnDelete();

            $table->enum('action', ['update', 'create']);

            /* Snapshot of the affected columns BEFORE the change. Null when creating. */
            $table->json('original_values')->nullable();

            /* Only the fields being altered, so the intent is readable at a glance. */
            $table->json('changes');

            /*
             * Required. An unexplained correction is exactly what makes a timesheet
             * untrustworthy, so this is enforced at the schema level as well as in the
             * request — a NOT NULL column cannot be forgotten by a future caller.
             */
            $table->string('reason', 255);

            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');

            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();

            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 255)->nullable();

            $table->timestamps();

            /* "This employee's corrections, newest first" — the audit view. */
            $table->index(['employee_id', 'created_at']);

            /* "What is waiting for me" and "what has been changed at this outlet". */
            $table->index(['outlet_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_corrections');
    }
};
