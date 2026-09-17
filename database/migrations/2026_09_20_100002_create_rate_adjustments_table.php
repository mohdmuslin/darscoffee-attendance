<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adhoc rate overrides, audited.
 *
 * The overtime rate is a flat figure a manager or the owner can adjust for a particular day,
 * week or month. Because that decision carries no second signature (blueprint §10.2), the
 * record IS the control — so the reason is required at the schema level and the previous
 * state is never touched.
 *
 * An adjustment overrides only what it names. A `day` adjustment applies to one date; a
 * `week` or `month` one applies from `applies_to_date` across that period.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rate_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();

            $table->date('applies_to_date');

            /*
             * The span the override covers, starting at `applies_to_date`. Stored as its own
             * column rather than derived from the period because an adjustment is often agreed
             * before the period it belongs to is locked.
             */
            $table->enum('applies_to_period', ['day', 'week', 'month'])->default('day');

            /*
             * Which figure is being overridden. Deliberately explicit: an adjustment that
             * changed "the rate" without saying which one would be ambiguous the moment an
             * employee has both an ordinary and an overtime rate.
             */
            $table->enum('applies_to', ['overtime', 'ordinary'])->default('overtime');

            /* Hours at this rate. NULL means the whole period, which is the common case. */
            $table->decimal('hours', 6, 2)->nullable();

            /* The flat per-hour figure applied. */
            $table->decimal('rate', 12, 2);

            /* REQUIRED. An unexplained rate change is unauditable. */
            $table->string('reason', 255);

            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();

            /*
             * Approval is supported but OFF by default (blueprint §10.2): a manager may set
             * rates for their own staff directly. Kept in the schema so an owner can switch it
             * on later without a migration.
             */
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            $table->timestamps();

            $table->index(['employee_id', 'applies_to_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rate_adjustments');
    }
};
