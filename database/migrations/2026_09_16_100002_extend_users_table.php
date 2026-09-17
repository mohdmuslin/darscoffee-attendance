<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extend the default users table for console logins.
 *
 * Originally owner and manager only, on the reasoning that staff clock in without
 * ever signing in. Single sign-on with the ordering system changed that: shared
 * accounts mean every staff member needs one, so `staff` is included here rather
 * than added by a later ALTER.
 *
 * INCLUDED IN THE ORIGINAL DEFINITION ON PURPOSE. Nothing is deployed yet, and a
 * later `MODIFY COLUMN role ENUM(...)` is MySQL-specific — it would break the
 * SQLite test suite, so the tests would stop covering the real column. Editing the
 * definition keeps both drivers in step.
 *
 * Note what a `users` row does NOT mean: it means you can sign in. Whether you work
 * shifts and are paid is a separate fact in `employees`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            /*
             * owner | manager | staff.
             *
             * Defaults to `staff` deliberately: the least privileged role is the
             * safe default, so a row created without an explicit role cannot
             * accidentally administer the system.
             */
            $table->enum('role', ['owner', 'manager', 'staff'])->default('staff')->after('password');

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
