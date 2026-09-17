<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Flags raised automatically on a punch.
 *
 * These are what make buddy punching visible rather than impossible: no software can
 * prove who held the phone, so the goal is that anything odd leaves a trail a manager
 * will see. Kept as rows rather than booleans on the entry so new checks can be added
 * without a migration, and so "everything unreviewed" is one indexed query.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('anomalies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('time_entry_id')->constrained()->cascadeOnDelete();

            $table->string('type', 40);
            $table->string('severity', 10);

            // Plain-language detail for whoever has to judge it.
            $table->string('detail', 255)->nullable();

            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 255)->nullable();

            $table->timestamps();

            $table->index(['type', 'reviewed_at']);
            $table->index('severity');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('anomalies');
    }
};
