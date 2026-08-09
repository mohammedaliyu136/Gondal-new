<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AUTH-8 / BR-31 — remember who moved an account's sign-in address, and when.
 *
 * `users.email` is not a profile field. It is where AUTH-4's reset code and
 * BR-31's activation code are delivered, so whoever controls it controls the
 * account. An administrator holding admin.users.edit could change any user's
 * address and then press "resend activation": the code arrived in their own
 * mailbox, they chose a password through the ordinary flow, and they were that
 * person — including the Executive Director, or Internal Audit, whose whole job
 * is to review what administrators do.
 *
 * The chain needs both halves, so it is broken at the second: UserAdminService
 * refuses to issue an activation code to an address the ACTOR set on an account
 * that outranks them. That refusal needs to know who set it, which is what these
 * two columns are for. They also give the administration screen an honest line —
 * "address changed 2 days ago by X" — where before the change was indistinguish-
 * able from a name or a phone-number edit in the log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('email_changed_at')->nullable()->after('email');
            $table->unsignedBigInteger('email_changed_by_user_id')->nullable()->after('email_changed_at');

            // NFR-3 — every foreign key leads an index.
            $table->index('email_changed_by_user_id');
            $table->foreign('email_changed_by_user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign(['email_changed_by_user_id']);
            $table->dropColumn(['email_changed_at', 'email_changed_by_user_id']);
        });
    }
};
