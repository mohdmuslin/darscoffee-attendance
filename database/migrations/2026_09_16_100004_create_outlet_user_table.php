<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which outlets a manager may see.
 *
 * This table is a PERMISSION BOUNDARY. A manager with no rows here sees nothing
 * — it fails closed, not open, so forgetting to grant access locks someone out
 * rather than exposing every outlet's staff and photos.
 *
 * Many-to-many because one manager may cover two outlets, which is already the
 * case: there is one manager today.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outlet_user', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('outlet_id')->constrained()->cascadeOnDelete();

            $table->primary(['user_id', 'outlet_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outlet_user');
    }
};
