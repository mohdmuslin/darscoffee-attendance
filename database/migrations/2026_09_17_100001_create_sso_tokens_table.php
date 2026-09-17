<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One-time SSO assertions issued by this app for the ordering system to redeem.
 *
 * This is the outbound half of single sign-on. Attendance is the identity source;
 * the ordering system consumes tokens issued here. The direction matters: it means
 * Attendance never depends on the ordering system being available, which is what
 * allows Attendance to be deployed first.
 *
 * The token is a short-lived, single-use assertion — not a session id, and never a
 * credential the browser could reuse.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sso_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            /*
             * Stored HASHED. A leaked database row must not be redeemable, and the
             * plaintext only ever exists in the redirect that carries it.
             */
            $table->string('token_hash', 64)->unique();

            /*
             * Which app the token is for. Lets one issuer serve more than one
             * consumer without a token for one being usable at another.
             */
            $table->string('audience', 40);

            /*
             * Claims snapshot at issue time, so the consuming app receives what was
             * true when the user actually signed in rather than whatever is true
             * when the token is finally redeemed. The window is 60 seconds, but the
             * principle is worth encoding.
             */
            $table->json('claims');

            $table->timestamp('expires_at');

            /*
             * Set on redemption. Single use is enforced by a conditional UPDATE, so
             * two simultaneous redemptions cannot both succeed.
             */
            $table->timestamp('consumed_at')->nullable();

            // Recorded for diagnosis: which app redeemed it, and from where.
            $table->string('consumed_by_ip', 45)->nullable();

            $table->timestamps();

            $table->index(['user_id', 'expires_at']);
            $table->index('consumed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sso_tokens');
    }
};
