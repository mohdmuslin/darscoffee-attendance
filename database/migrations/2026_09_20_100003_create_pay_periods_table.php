<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pay periods, and the lock.
 *
 * A period is a named date range that can be LOCKED. Locking is what turns a computed figure
 * into a committed one: without it, "last month's total" changes every time someone fixes a
 * forgotten clock-out, and a payslip already handed out stops matching the system.
 *
 * Three things are stored when a period is locked, and all three are necessary:
 *
 *  - `locked_at` and `locked_by`: who committed it, and when.
 *  - `snapshot`: the computed figures AT THE MOMENT OF LOCKING. This is the answer to
 *    success criterion 6 — reproducing last month's number does not depend on re-running the
 *    calculation against data that has since changed. It is the number that was approved.
 *  - `snapshot_hash`: a digest of the inputs at lock time, so a later run can say whether the
 *    figures would STILL come out the same and, if not, that somebody changed something.
 *
 * A locked period is not frozen by a database constraint — corrections remain possible,
 * because refusing a legitimate correction because a period was locked would be worse than
 * the problem. Instead a change after locking is DETECTED: the recomputed figure stops
 * matching the stored one, and the console says so.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pay_periods', function (Blueprint $table) {
            $table->id();

            $table->string('name', 100);

            /*
             * Inclusive local dates. Stored as dates rather than instants because a pay period
             * is a calendar concept the business agreed on, not a moment in time — and the
             * outlet timezone decides which punches fall inside it.
             */
            $table->date('starts_on');
            $table->date('ends_on');

            /* NULL while open. */
            $table->timestamp('locked_at')->nullable();
            $table->foreignId('locked_by')->nullable()->constrained('users')->nullOnDelete();

            /*
             * JSON, so the shape can grow without a migration. Holds per-employee figures plus
             * the totals that were approved.
             */
            $table->json('snapshot')->nullable();

            /* sha256 of the inputs, so drift after locking is detectable rather than silent. */
            $table->char('snapshot_hash', 64)->nullable();

            $table->string('note', 255)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            /*
             * Two periods must not overlap, or a punch could belong to two of them and the
             * "which figure is authoritative" question would have no answer.
             *
             * Enforced in the service rather than as a database constraint: MySQL has no
             * exclusion constraint, and a generated-column trick would be far harder to read
             * than the rule it replaces. The service checks, and the check is tested.
             */
            $table->index(['starts_on', 'ends_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pay_periods');
    }
};
