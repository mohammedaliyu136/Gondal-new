<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A DELIBERATE RELAXATION OF AUTH-1, ON THE COOPERATIVE'S INSTRUCTION.
 *
 * AUTH-1 says "sign-in is email plus password, then a 6-digit code emailed to
 * the user". That second step is now OFF BY DEFAULT for accounts created from
 * here. This is a security posture change, not a bug fix, and it is recorded in
 * a migration rather than done by hand so that the decision has a date, an
 * author and a `down()`.
 *
 * WHY. The people this system is built for work in Gengle, Gurin and Waltandi,
 * where an emailed code is not a second factor — it is a locked door. AUTH-2's
 * device trust was the intended answer and it works, but it still requires
 * completing one emailed code per handset, which for a field agent with no
 * inbox means an office trip before they can record a single delivery.
 *
 * WHAT IS NOT CHANGED, AND MATTERS:
 *
 *   The FEATURE is untouched. `two_factor_enabled` is still a per-user column,
 *   still settable by an administrator, and still enforced by SigninService and
 *   MobileSigninService when it is on. Nothing here removes the second factor —
 *   it stops being the default, which is a different thing and a reversible one.
 *
 *   The AUDIT is untouched. UserAdminService::announceTwoFactorRemoval still
 *   fires when an administrator turns it off on an account that had it, and
 *   AUTH-1 still forbids a user switching off their own.
 *
 *   Existing accounts are NOT rewritten. A column default only applies to rows
 *   inserted without a value; anyone who has it on today keeps it until somebody
 *   decides otherwise on the admin screen.
 *
 * TURNING IT BACK ON is `down()`, plus flipping the two application-level
 * defaults this migration's sibling commit changed (UserAdminService::create
 * and Admin\UserController::store) — the column default alone does not decide
 * it, because both of those pass the value explicitly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('two_factor_enabled')->default(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('two_factor_enabled')->default(true)->change();
        });
    }
};
