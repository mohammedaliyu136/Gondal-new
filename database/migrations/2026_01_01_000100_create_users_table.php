<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §6.1 users — plus the framework's own session-driver table.
 *
 * USER-1: farmers, cooperative officials, riders, drivers and vendors are
 * deliberately NOT here. They are records elsewhere and have no credentials.
 * AUTH-8: there is no self-registration; `created_by_user_id` is always an
 * administrator (nullable only for the very first seeded account).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password_hash');
            $table->string('phone', 32)->nullable();

            // FK added in the departments migration — departments.head_user_id
            // points back here, so the two cannot both be constrained at create.
            $table->unsignedBigInteger('department_id')->nullable();
            $table->string('position')->nullable();
            $table->unsignedBigInteger('employee_id')->nullable();   // FK added with `employees`

            $table->string('status', 16)->default('active');         // active|deactivated

            // TEST-1 — excluded from every report, aggregate and payroll.
            $table->boolean('is_test')->default(false);

            // AUTH-1 — the emailed-code step. Off only for accounts an
            // administrator has explicitly exempted; the default is on.
            $table->boolean('two_factor_enabled')->default(true);

            // AUTH-5 — maximum password age of 90 days is measured from here.
            $table->timestamp('password_changed_at')->nullable();

            // AUTH-6 — lockout bookkeeping.
            $table->timestamp('locked_until')->nullable();

            $table->timestamp('last_signed_in_at')->nullable();

            // BR-32 — deactivation blocks sign-in but preserves attribution.
            $table->timestamp('deactivated_at')->nullable();
            $table->string('deactivated_reason')->nullable();

            $table->unsignedBigInteger('created_by_user_id')->nullable();

            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();                                   // ARCH-8

            $table->index('status');
            $table->index('is_test');
            $table->index('department_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreign('created_by_user_id')->references('id')->on('users')->nullOnDelete();
        });

        // The session driver's own storage. The PRD's semantic `sessions`
        // register is created in the access-tables migration.
        Schema::create('http_sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('http_sessions');
        Schema::dropIfExists('users');
    }
};
