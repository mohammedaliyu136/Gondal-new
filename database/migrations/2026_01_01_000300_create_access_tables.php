<?php

use App\Authorization\ScopeType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §6.1 identity and access — the heart of the rebuild (§5).
 *
 * PERM-1  permissions are ROWS, never an enum or a config array.
 * PERM-3  permissions are never deleted; `retired_at` hides them.
 * ROLE-1  a role is a name, description, scope type, status and a grant set.
 * ROLE-2  a user may hold several roles; effective permissions are the union.
 * SCOPE-1 every ASSIGNMENT carries its own scope — which is why the scope
 *         columns live on `role_user`, not on `roles`.
 */
return new class extends Migration
{
    public function up(): void
    {
        // PERM-1
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('resource_key', 64);
            $table->string('action', 16);                 // view|create|edit|delete|approve
            $table->string('label');
            $table->text('description')->nullable();

            // PERM-2 / G-6 — granting one of these must warn on the role screen.
            $table->boolean('is_sensitive')->default(false);

            // PERM-3 — retire, never delete.
            $table->timestamp('retired_at')->nullable();
            $table->string('retired_reason')->nullable();

            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['resource_key', 'action']);
            $table->index('retired_at');
            $table->index('is_sensitive');
        });

        // ROLE-1
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->text('description')->nullable();

            // The role's declared default scope type. The assignment on
            // `role_user` is authoritative; this seeds the picker.
            $table->string('scope_type', 16)->default(ScopeType::Network->value);

            $table->string('status', 16)->default('draft');   // active|disabled|draft|retired
            $table->timestamp('retired_at')->nullable();

            // ROLE-3 — the one role every user holds automatically.
            $table->boolean('is_automatic')->default(false);

            // A colour hint so roles.html can render its role dots from data.
            $table->string('accent', 16)->nullable();

            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();                            // ROLE-7 — disable, don't delete

            $table->foreign('created_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->index('status');
        });

        // The grant. ROLE-6 — edits take effect on the next request, so nothing
        // here is cached beyond a single request lifetime.
        Schema::create('permission_role', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained('permissions')->cascadeOnDelete();
            $table->unsignedBigInteger('granted_by_user_id')->nullable();
            $table->timestamps();

            $table->unique(['role_id', 'permission_id']);
            $table->index('permission_id');
            $table->foreign('granted_by_user_id')->references('id')->on('users')->nullOnDelete();
        });

        // SCOPE-1 — the assignment, carrying its own scope.
        Schema::create('role_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->string('scope_type', 16)->default(ScopeType::Network->value);

            // Points at a different table per scope_type (§5.3), so it cannot
            // carry a foreign key. Integrity is enforced in the application.
            $table->unsignedBigInteger('scope_target_id')->nullable();

            $table->unsignedBigInteger('assigned_by_user_id')->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['role_id', 'user_id']);
            $table->index(['user_id', 'role_id']);
            $table->index(['scope_type', 'scope_target_id']);
            $table->foreign('assigned_by_user_id')->references('id')->on('users')->nullOnDelete();
        });

        // SCOPE-1 — the `communities` scope type takes a LIST of targets, which
        // a single scope_target_id cannot express.
        Schema::create('role_user_scope_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_user_id')->constrained('role_user')->cascadeOnDelete();
            $table->unsignedBigInteger('target_id');
            $table->timestamps();

            $table->unique(['role_user_id', 'target_id']);
        });

        // AUTH-5 — "not among the user's last 3 passwords".
        Schema::create('password_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('password_hash');
            $table->timestamp('created_at')->nullable();

            $table->index(['user_id', 'created_at']);
        });

        // AUTH-2 — "trust this device for 30 days", revocable by user and admin.
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('label')->nullable();
            $table->text('user_agent')->nullable();
            $table->string('last_ip', 45)->nullable();

            // NFR-9 — only the hash is stored; the token itself never lands in
            // the database or the logs.
            $table->string('token_hash', 64)->unique();

            $table->timestamp('trusted_until')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->unsignedBigInteger('revoked_by_user_id')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'revoked_at']);
            $table->foreign('revoked_by_user_id')->references('id')->on('users')->nullOnDelete();
        });

        // §6.1 sessions — the auditable session register (BR-32 / BR-33 revoke
        // through this table). The session *driver* uses `http_sessions`.
        Schema::create('sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('device_id')->nullable()->constrained('devices')->nullOnDelete();
            $table->string('http_session_id')->nullable()->index();
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->string('ended_reason', 32)->nullable();   // signout|password_change|deactivated|admin_revoke
            $table->timestamps();

            $table->index(['user_id', 'ended_at']);
        });

        // AUTH-3 / AUTH-4 — 6-digit codes, hashed, single-use, rate limited.
        Schema::create('login_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('purpose', 16);                    // signin|reset
            $table->string('code_hash', 64);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('invalidated_at')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('ip', 45)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'purpose', 'consumed_at']);
            $table->index('expires_at');
        });

        // AUTH-6 — throttling is per IP *and* per account (NFR-8), so failures
        // are recorded rather than only counted in the cache.
        Schema::create('failed_signins', function (Blueprint $table) {
            $table->id();
            $table->string('email');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('reason', 32);                     // bad_password|unknown_email|deactivated|locked|bad_code
            $table->timestamp('occurred_at');

            $table->index(['email', 'occurred_at']);
            $table->index(['ip', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('failed_signins');
        Schema::dropIfExists('login_codes');
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('devices');
        Schema::dropIfExists('password_histories');
        Schema::dropIfExists('role_user_scope_targets');
        Schema::dropIfExists('role_user');
        Schema::dropIfExists('permission_role');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('permissions');
    }
};
