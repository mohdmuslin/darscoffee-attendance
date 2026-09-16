<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Employees — everyone who clocks in.
 *
 * NOT the same thing as users. Kitchen crew clock in daily and never log into
 * anything; the owner logs in and may never clock in. Modelling them as one table
 * would force a login account onto people who need none, and would leave the
 * attendance records entangled with console permissions.
 *
 * The few who need both (managers) are linked through `user_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();

            // Human-facing, printed on payslips, e.g. DBI-004.
            $table->string('employee_code', 20)->unique();

            $table->string('name', 100);
            $table->string('phone', 20)->nullable();

            /*
             * Sensitive personal data — encrypted at rest and masked in every
             * view that is not the edit form. Only needed for payroll filing.
             */
            $table->text('ic_number')->nullable();

            /*
             * bcrypt of a 4-6 digit PIN. The PIN itself is NEVER stored, and a
             * short numeric secret is only acceptable because it is one factor
             * among three (outlet token, PIN, photo) and is rate-limited.
             */
            $table->string('pin_hash')->nullable();
            $table->timestamp('pin_set_at')->nullable();
            $table->unsignedSmallInteger('pin_failed_attempts')->default(0);
            $table->timestamp('pin_locked_until')->nullable();

            // Profile photo, stored outside the web root.
            $table->string('photo_path', 255)->nullable();

            /* hourly | daily | weekly | monthly. The rate lives in compensation_rules. */
            $table->enum('pay_basis', ['hourly', 'daily', 'weekly', 'monthly'])->nullable();

            /*
             * Nullable link to a console login. Only managers need one, and a
             * manager who also works shifts needs both records.
             */
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->date('joined_at')->nullable();
            $table->date('resigned_at')->nullable();

            $table->boolean('is_active')->default(true);

            // Consent record for PDPA: photographs and time data are personal data.
            $table->timestamp('consent_at')->nullable();
            $table->string('consent_note', 255)->nullable();

            $table->timestamps();

            /*
             * Soft delete only. History must survive a resignation, and
             * time_entries reference this row — removing it would orphan
             * attendance records that may still be under dispute.
             */
            $table->softDeletes();

            $table->index('is_active');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
