<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §16 — the persona reference, promoted from prototype HTML to data.
 *
 * `personas.html` is described as authoritative ("what they do in it, and what
 * they must never see"), but it is a static screen: nothing can serve it. A
 * field client has to be told what the signed-in user's job IS, not only which
 * permission keys they hold — a list of `milk.deliveries.create` does not tell an
 * agent that their job is to meet farmers at 05:30 and run the lactometer check.
 *
 * So the three persona columns the screen renders live here, next to the role
 * they describe:
 *
 *   responsibilities  the "Their day" list — what this role is for
 *   restrictions      the "Cannot see" list — the boundary, in words, which the
 *                     grant set expresses in keys but never explains
 *   mobile_home       the landing screen of a client that has this role, so the
 *                     app does not hard-code a route per role name
 *
 * ROLE-1 still holds: none of this grants anything. A role is still a name, a
 * description, a scope type, a status and a grant set — these columns are the
 * documentation of that grant set, carried with it so it cannot drift.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            // JSON rather than a child table: these are an ordered list of
            // sentences read as a block, never queried, filtered or joined.
            $table->json('responsibilities')->nullable()->after('description');
            $table->json('restrictions')->nullable()->after('responsibilities');

            // Null means "this role has no mobile surface" — the honest answer
            // for Accounts or Internal Audit, and the client shows the role's
            // responsibilities without pretending there are actions to take.
            $table->string('mobile_home', 32)->nullable()->after('accent');
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn(['responsibilities', 'restrictions', 'mobile_home']);
        });
    }
};
