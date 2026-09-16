<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keys for the wall-mounted display devices that show a rotating code.
 *
 * Separate from `users` because a tablet on a wall is a device, not a person. It
 * authenticates the DISPLAY only — it can read the current code for its one
 * outlet and nothing else, so stealing a tablet does not expose the console.
 *
 * Stored hashed and shown once at creation, like an API token.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outlet_id')->constrained()->cascadeOnDelete();

            $table->string('key_hash', 255);

            // "Front counter tablet", so a dead device is identifiable.
            $table->string('label', 64)->nullable();

            // Lets the console notice a display that has stopped reporting in.
            $table->timestamp('last_seen_at')->nullable();

            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index('outlet_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_keys');
    }
};
