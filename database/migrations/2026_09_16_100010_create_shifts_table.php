<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shifts — the PLAN.
 *
 * A shift never contributes to worked hours. Those come only from `time_entries`.
 * Keeping the plan and the actual in separate tables is what makes "we also have
 * adhoc tasks" work without a special case: an adhoc attendance is simply a punch
 * with no matching shift, and the interesting report becomes the VARIANCE between
 * the two rather than a flag on a row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('outlet_id')->constrained()->cascadeOnDelete();

            // Stored UTC. The outlet's timezone decides how these are displayed.
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');

            // "Kitchen", "Front", or an adhoc description.
            $table->string('position', 50)->nullable();
            $table->string('note', 255)->nullable();

            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();

            /*
             * Cancelling keeps the row. A shift that was planned and then called
             * off is information; deleting it would erase the fact that someone
             * was rostered and did not come in.
             */
            $table->timestamp('cancelled_at')->nullable();

            $table->timestamps();

            $table->index(['employee_id', 'starts_at']);
            $table->index(['outlet_id', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shifts');
    }
};
