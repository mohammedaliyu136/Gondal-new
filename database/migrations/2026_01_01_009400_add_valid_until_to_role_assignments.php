<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ROLE-2 / SCOPE-1 — a role assignment may be time-boxed.
 *
 * An external auditor is given real access to real production data for the
 * duration of an engagement. Until now the only way to end that access was for
 * somebody to remember to revoke it, which is the control that fails first: the
 * engagement ends, the team disperses, and the account keeps its grants.
 *
 * `valid_until` is checked wherever permissions and scopes are resolved, so an
 * expired assignment stops granting on its own. It is NOT a soft delete — the
 * row stays, the audit trail still shows who granted what and until when, and an
 * administrator can extend it deliberately rather than by re-granting silently.
 *
 * Null means no expiry, which is every assignment that exists today.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('role_user', function (Blueprint $table) {
            $table->timestamp('valid_until')->nullable()->after('assigned_at');
            $table->index('valid_until');
        });
    }

    public function down(): void
    {
        Schema::table('role_user', function (Blueprint $table) {
            $table->dropIndex(['valid_until']);
            $table->dropColumn('valid_until');
        });
    }
};
