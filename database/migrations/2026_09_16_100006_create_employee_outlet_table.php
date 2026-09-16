<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which outlets an employee may punch at.
 *
 * This is a PUNCH-TIME permission boundary, not just a label: a punch is only
 * accepted at an outlet the employee is mapped to, so someone cannot clock in
 * from a site they do not work at even if they obtain that outlet's code.
 *
 * Many-to-many because staff cover between outlets.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_outlet', function (Blueprint $table) {
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('outlet_id')->constrained()->cascadeOnDelete();

            /** Home outlet, used to pick a default in the console. */
            $table->boolean('is_primary')->default(false);

            $table->primary(['employee_id', 'outlet_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_outlet');
    }
};
