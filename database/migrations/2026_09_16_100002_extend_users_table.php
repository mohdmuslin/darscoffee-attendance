<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extend the default users table for console logins.
 *
 * Only owner and manager have accounts. Employees — who make up almost everyone
 * who uses this system — are a separate entity and never log in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // owner | manager. Employees are NOT users, so there is no 'staff' case.
            $table->enum('role', ['owner', 'manager'])->default('manager')->after('password');

            /*
             * Checked on every request, not only at sign-in.
             *
             * The ordering system learned this the hard way: a deactivated admin
             * could keep using an already-issued token until it expired.
             */
            $table->boolean('is_active')->default(true)->after('role');

            $table->timestamp('last_login_at')->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role', 'is_active', 'last_login_at']);
        });
    }
};
