<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SCOPE-1 / ROLE-6 — make `role_user`'s uniqueness agree with its soft delete.
 *
 * 2026_01_01_000300 gave `role_user` both `softDeletes()` and a plain
 * `unique(role_id, user_id)`. The two contradict each other: a revoked
 * assignment keeps its row, and therefore keeps the unique key, so re-granting a
 * role somebody used to hold — the most ordinary administrative correction there
 * is — raised
 *
 *   SQLSTATE[23000] UNIQUE constraint failed: role_user.role_id, role_user.user_id
 *
 * as an unhandled 500. The workaround an administrator finds for that is a second
 * account, which is exactly the shadow access §5 exists to end.
 *
 * The constraint is still wanted for LIVE rows — one person must not hold the
 * same role twice, or the scope union in User::scopeSetFor() would silently take
 * the widest of two answers. So it becomes a partial index over the live rows
 * only. PostgreSQL (ARCH-1) and SQLite (the test suite) both support that; MySQL
 * does not, so there the constraint is dropped outright and
 * UserAdminService::assignRole()'s withTrashed() lookup is the only guard. That
 * is an acceptable trade: a false duplicate is a data annoyance, a 500 on the
 * revoke-then-regrant path is a control failure.
 */
return new class extends Migration
{
    private const INDEX = 'role_user_role_id_user_id_unique';

    public function up(): void
    {
        Schema::table('role_user', function (Blueprint $table): void {
            $table->dropUnique(self::INDEX);
        });

        if ($this->supportsPartialIndexes()) {
            DB::statement(sprintf(
                'CREATE UNIQUE INDEX %s ON role_user (role_id, user_id) WHERE deleted_at IS NULL',
                self::INDEX,
            ));
        }
    }

    public function down(): void
    {
        if ($this->supportsPartialIndexes()) {
            DB::statement('DROP INDEX '.self::INDEX);
        }

        /*
         * Restoring the plain unique would fail against any database where a
         * revoke-then-regrant has already happened, so the rollback leaves the
         * table without it rather than refusing to run.
         */
    }

    private function supportsPartialIndexes(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['pgsql', 'sqlite'], true);
    }
};
