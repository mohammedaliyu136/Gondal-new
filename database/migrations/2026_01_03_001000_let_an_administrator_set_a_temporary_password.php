<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BR-31, qualified — an administrator may now TYPE a user's password, and the
 * account has to record that the password in force is one they did not choose.
 *
 * This is a deliberate, owner-approved narrowing of BR-31 ("Administrators never
 * see or set a user's password"). The rule's original answer to "my agent cannot
 * get in" was an emailed code, and that answer assumes the agent can reach their
 * mailbox — which, on a phone with no data at a collection centre at 05:30, is
 * exactly what they cannot do. So the code path stays (UserAdminService::reset-
 * Password) and this is the second option: the administrator sets a password,
 * says it down the phone, and the user changes it the moment they are in.
 *
 * What the flag buys is that the exception ENDS. `password_is_temporary` makes
 * User::passwordHasExpired() true, which EnsureAccountIsUsable already turns into
 * a redirect to the change-password screen before the user may reach anything
 * else, and PasswordPolicy::apply() clears it. So an administrator knows a
 * working credential for one sign-in, not indefinitely.
 *
 * It is a column rather than `password_changed_at = null` because that null
 * already means three other things — AUTH-5's 90-day age, the "pending
 * activation" badge, and (in guardCredentialIsNotATakeover) "this account has
 * never been used, so there is nothing to take over". Borrowing it would have
 * told the AUTH-8 guard to stand down on an account that is very much in use.
 *
 * What no column can buy back: an administrator who sets a colleague's password
 * can sign in as that colleague before they ever hear about it. AUTH-8's guard
 * cannot reach this path — it protects the mailbox a code is sent to, and no code
 * is sent here. The compensating controls are that the user is emailed the moment
 * it happens (never the password itself), that Internal Audit and the General
 * Manager are notified, and that the audit entry says SECURITY and names the
 * administrator. Detection, not prevention. That trade was made knowingly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('password_is_temporary')->default(false)->after('password_reset_reason');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('password_is_temporary');
        });
    }
};
