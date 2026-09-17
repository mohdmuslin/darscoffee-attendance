<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Short-lived punch sessions.
 *
 * After an employee proves their PIN against an outlet code, they receive a session
 * that authorises their own clock actions for a few minutes. Without it they would
 * retype a PIN for every break and clock-out, which is exactly when people give up
 * and walk away — leaving segments open and hours wrong.
 *
 * Stored HASHED, because a session token is a bearer credential for a short period
 * even though the period is short. In practice a raw token would let a leaked log
 * line clock someone else out.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('punch_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('outlet_id')->constrained()->cascadeOnDelete();

            // Which code was scanned, so a session is traceable to a token.
            $table->foreignId('outlet_token_id')->nullable()->constrained('outlet_tokens')->nullOnDelete();

            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('last_used_at')->nullable();

            // Recorded so a punch can be traced to a device class for anomaly checks.
            $table->string('device', 32)->nullable();
            $table->string('ip_address', 45)->nullable();

            $table->timestamps();

            $table->index(['employee_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('punch_sessions');
    }
};
