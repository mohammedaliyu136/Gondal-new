<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BR-31 / AUTH-4 — an administrator can reset ANY user's password, and the
 * account has to be able to say so.
 *
 * The screen only ever offered "resend activation", and only while
 * `password_changed_at` was null — that is, only to a new hire who had never
 * signed in. For everybody else an administrator had no lever at all: a
 * collection agent who has forgotten their password and cannot reach their
 * mailbox, or an account whose credential is suspected of being in somebody
 * else's hands, left exactly two options, both wrong. Deactivate-then-reactivate
 * revokes every trusted device and sends a welcome email; "sign out everywhere"
 * ends the sessions and leaves the password itself working, so the holder — or
 * whoever else knows it — simply signs back in.
 *
 * BR-31 is not relaxed to fix that. The administrator still never sees or sets a
 * password: the reset replaces the current hash with a random one NOBODY knows
 * and emails the user a code they redeem to choose their own.
 *
 * Which is why these columns exist rather than reusing `password_changed_at`.
 * That column already carries three other meanings — AUTH-5's 90-day age, the
 * "pending activation" badge, and (in UserAdminService::guardActivationIsNot-
 * ATakeover) "this account has never been used, so there is nothing to take
 * over". Nulling it to mark a forced reset would have reopened the AUTH-8
 * takeover it closes: change a colleague's e-mail, reset their password to clear
 * the flag, then send an activation code to the address you just chose. So the
 * forced reset is recorded separately and `password_changed_at` keeps meaning
 * only what it already meant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // Set when an administrator clears the password; cleared again by
            // PasswordPolicy::apply() the moment the user chooses a new one, so
            // a non-null value means "waiting on the user".
            $table->timestamp('password_reset_at')->nullable()->after('password_changed_at');
            $table->unsignedBigInteger('password_reset_by_user_id')->nullable()->after('password_reset_at');
            $table->string('password_reset_reason')->nullable()->after('password_reset_by_user_id');

            // NFR-3 — every foreign key leads an index.
            $table->index('password_reset_by_user_id');
            $table->foreign('password_reset_by_user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign(['password_reset_by_user_id']);
            $table->dropColumn(['password_reset_at', 'password_reset_by_user_id', 'password_reset_reason']);
        });
    }
};
