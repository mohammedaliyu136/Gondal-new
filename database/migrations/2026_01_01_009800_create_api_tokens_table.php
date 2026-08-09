<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ARCH-2 — "when [mobile applications] arrive a token guard is added to
 * config/auth.php and [the API routes gain it] — nothing below has to change."
 *
 * This is that table. A field phone cannot hold a session cookie across days of
 * intermittent signal, so it carries a bearer token instead. Three properties
 * are deliberate:
 *
 *   NO ABILITIES COLUMN. ARCH-4 puts authorisation in roles and scopes, and a
 *   second, parallel grant system on the token would be exactly the implicit
 *   widening §5.2 warns against. A token says WHO is calling; the roles still
 *   say what they may do, and ROLE-6 keeps working — a role edit reaches the
 *   phone on its next request, with no re-issue and no re-login.
 *
 *   HASHED, like every other secret here (NFR-9). The plaintext exists in the
 *   phone's keystore and nowhere else.
 *
 *   EXPIRES. A lost phone in a field programme is not an exceptional event, so
 *   the token dies on its own; `device_id` ties it to the AUTH-2 trust record so
 *   revoking the device revokes the token with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // What the user sees in the device list on their profile.
            $table->string('name', 120);

            // NFR-9 — sha256 of the plaintext. Unique so a collision is a hard
            // error rather than two accounts sharing one credential.
            $table->string('token_hash', 64)->unique();

            // AUTH-2 — the trust record this token was issued alongside, so an
            // administrator revoking the device also kills the token.
            $table->foreignId('device_id')->nullable()->constrained('devices')->nullOnDelete();

            $table->string('platform', 32)->nullable();      // android|ios
            $table->string('app_version', 32)->nullable();

            $table->timestamp('last_used_at')->nullable();
            $table->string('last_ip', 45)->nullable();

            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_reason', 64)->nullable();

            $table->timestamps();

            $table->index(['user_id', 'revoked_at']);
            // NFR-3 — every foreign key leads an index.
            $table->index('device_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_tokens');
    }
};
