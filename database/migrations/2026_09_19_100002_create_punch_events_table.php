<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only audit trail of every punch attempt.
 *
 * This is what answers "I clocked in but the system says I didn't" — including the
 * attempts that FAILED, which are exactly the ones people ask about. Without it, a
 * wrong PIN and a dead battery look identical from the outside.
 *
 * Never updated or deleted by the application. Rows are written and left alone.
 *
 * The foreign keys are nullable because the most interesting rows are the ones where
 * identity is unknown: a scanned code with a PIN that matched nobody has no employee,
 * and that pattern — one code, many failed PINs — is the signature of someone trying
 * codes rather than a genuine mistake.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('punch_events', function (Blueprint $table) {
            $table->id();

            /* Null when the PIN matched no one, which is itself the signal. */
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();

            $table->foreignId('outlet_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('outlet_token_id')->nullable()->constrained()->nullOnDelete();

            /*
             * The entry an event produced, when it produced one. Lets a manager go
             * from "the punch is missing" to "here is the attempt that failed".
             */
            $table->foreignId('time_entry_id')->nullable()->constrained()->nullOnDelete();

            $table->enum('event', [
                'scan',          // a code was presented and accepted
                'pin_failed',    // a code was valid but no PIN matched
                'pin_locked',    // the PIN was correct but the account was locked out
                'clock_in',
                'break_start',
                'break_end',
                'clock_out',
                'rejected',      // refused for a reason that is neither of the above
            ]);

            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->json('meta')->nullable();

            /*
             * Only created_at: a trail that records when it was modified is an audit
             * log with an audit problem of its own.
             */
            $table->timestamp('created_at')->nullable();

            /* "What happened to this employee, newest first". */
            $table->index(['employee_id', 'created_at']);

            /* Repeated failures against one code are the thing worth spotting. */
            $table->index(['outlet_token_id', 'event']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('punch_events');
    }
};
