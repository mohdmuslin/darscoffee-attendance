<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outlets', function (Blueprint $table) {
            $table->id();

            // Short slug used in URLs and QR payloads, e.g. SG-RAMAL.
            $table->string('code', 20)->unique();
            $table->string('name', 100);
            $table->string('address', 255)->nullable();

            /*
             * Per-outlet timezone. Instants are always stored UTC; this decides
             * how they are DISPLAYED and which calendar day a shift belongs to
             * locally. Per-outlet rather than global so a second region works
             * without a schema change.
             */
            $table->string('timezone', 64)->default('Asia/Kuala_Lumpur');

            /* rotating | printed — see OutletTokenMode for the trade-off. */
            $table->enum('token_mode', ['rotating', 'printed'])->default('rotating');

            /*
             * Rotating code lifetime. 90 seconds rather than 60 to absorb clock
             * skew between the display device and the phone, plus the time taken
             * to actually frame a QR code in a camera.
             */
            $table->unsignedInteger('qr_ttl_seconds')->default(90);

            /*
             * Per-outlet, because a shop may negotiate this away. When true, a
             * punch without a photo is refused; when the camera fails, the entry
             * is still recorded but flagged.
             */
            $table->boolean('requires_photo')->default(true);

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outlets');
    }
};
