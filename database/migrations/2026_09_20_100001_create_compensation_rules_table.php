<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pay rates, versioned by date.
 *
 * Rows are INSERTED, never updated. A raise closes the current row (`effective_to`) and
 * inserts a new one. This is the only way "what was he paid in March?" stays answerable
 * after the rate changes — and it is success criterion 6, the one to protect hardest.
 *
 * `overtime_rate` is a FLAT per-hour figure rather than a multiplier, matching the business's
 * decided rule ("flat rate, adjustable adhoc"). It is nullable so an employee can be paid for
 * overtime at a rate agreed separately from their ordinary one; adhoc changes live in
 * `rate_adjustments` rather than by editing this row, so the standing rate is never
 * overwritten by a one-off.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compensation_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();

            $table->enum('basis', ['hourly', 'daily', 'weekly', 'monthly']);

            /*
             * DECIMAL, never a float.
             *
             * Money in a binary float accumulates representation error, and a payslip that is
             * one sen out is a payslip someone has to explain. 12,2 holds up to ten billion
             * with two decimals — far beyond any rate here, and exact.
             */
            $table->decimal('rate', 12, 2);

            /* Flat per-hour overtime rate. NULL falls back to the outlet's multiple. */
            $table->decimal('overtime_rate', 12, 2)->nullable();

            $table->char('currency', 3)->default('MYR');

            $table->date('effective_from');
            /* NULL means this is the current rate. */
            $table->date('effective_to')->nullable();

            /* Why it changed. A raise with no explanation is unauditable. */
            $table->string('note', 255)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['employee_id', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compensation_rules');
    }
};
