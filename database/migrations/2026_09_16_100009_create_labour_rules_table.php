<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Overtime and rounding policy, per outlet and versioned by date.
 *
 * Per outlet because sites differ, and versioned because a policy change must not
 * silently rewrite history — the same reason pay rates are versioned. Changing the
 * overtime threshold today must not alter last month's total.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('labour_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outlet_id')->constrained()->cascadeOnDelete();

            /* Overtime begins after this many WORKED seconds. Default 8 hours. */
            $table->unsignedInteger('ot_after_seconds')->default(28800);

            /*
             * worked | span
             *
             * 'worked' excludes breaks, and is the default deliberately: measuring
             * overtime on the elapsed span would pay an hour of overtime for every
             * hour of lunch, systematically overpaying long shifts.
             */
            $table->enum('ot_basis', ['worked', 'span'])->default('worked');

            /*
             * exact | nearest_15 | down_15
             *
             * Applied in REPORTING only. Stored seconds are always exact, so a
             * policy change can be re-applied to history without re-entering data
             * and the record never misstates when someone actually arrived.
             */
            $table->enum('rounding_policy', ['exact', 'nearest_15', 'down_15'])->default('exact');

            /*
             * Affects the LATE FLAG only, never pay. Arriving inside the grace
             * window is on time; pay still starts at the real minute.
             */
            $table->unsignedInteger('grace_seconds')->default(300);

            $table->boolean('break_paid')->default(false);

            $table->date('effective_from');
            $table->date('effective_to')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['outlet_id', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('labour_rules');
    }
};
