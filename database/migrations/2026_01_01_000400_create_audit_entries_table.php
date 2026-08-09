<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * §6.9 audit_entries + §12.
 *
 * DM-3 / AUDIT-6 — append-only. No update route, no delete route, no soft
 * delete, and the guarantee is enforced by database triggers so that a future
 * mistake in application code cannot quietly rewrite history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_entries', function (Blueprint $table) {
            $table->id();

            // BR-32 — attribution survives the user being deactivated or
            // soft-deleted, so `actor_label` carries a frozen display name.
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('actor_label');
            $table->string('actor_role_label')->nullable();

            // AUDIT-2 — the captured event vocabulary.
            $table->string('event_type', 32);
            $table->string('module', 48)->nullable();

            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();

            $table->string('summary', 512);

            // AUDIT-3 — before/after grant sets and affected-user counts live here.
            $table->json('detail')->nullable();

            // AUDIT-5 — the reference a blocked user can quote, e.g. DENY-2291.
            $table->string('reference', 32)->nullable()->unique();

            // AUDIT-5 — populated on blocked_access entries.
            $table->string('missing_permission', 80)->nullable();
            $table->string('attempted_route')->nullable();
            $table->string('deny_reason', 16)->nullable();          // permission|scope

            // AUDIT-4
            $table->string('source', 8)->default('web');            // web|api
            $table->boolean('is_test')->default(false);             // TEST-4

            $table->string('ip', 45)->nullable();
            $table->string('request_id', 64)->nullable();           // NFR-9
            $table->timestamp('occurred_at');

            // Convention only. DM-3 forbids ever changing a row, so updated_at
            // is written once and never touched again.
            $table->timestamps();

            // NFR-3 — the two access patterns the audit screen actually uses.
            $table->index(['occurred_at', 'actor_user_id']);
            $table->index(['event_type', 'occurred_at']);
            $table->index(['subject_type', 'subject_id']);
            $table->index('is_test');
        });

        $this->guardAppendOnly();
    }

    /**
     * DM-3 — "Enforce at the database level where the engine allows."
     */
    private function guardAppendOnly(): void
    {
        $driver = DB::connection()->getDriverName();
        $message = 'audit_entries is append-only (DM-3)';

        match ($driver) {
            'sqlite' => collect(['update', 'delete'])->each(fn (string $event) => DB::unprepared(
                "CREATE TRIGGER audit_entries_no_{$event}
                 BEFORE {$event} ON audit_entries
                 BEGIN SELECT RAISE(ABORT, '{$message}'); END;"
            )),

            'mysql', 'mariadb' => collect(['UPDATE', 'DELETE'])->each(fn (string $event) => DB::unprepared(
                'CREATE TRIGGER audit_entries_no_'.strtolower($event)."
                 BEFORE {$event} ON audit_entries FOR EACH ROW
                 SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}';"
            )),

            'pgsql' => $this->guardPostgres($message),

            default => null,
        };
    }

    private function guardPostgres(string $message): void
    {
        DB::unprepared(
            "CREATE OR REPLACE FUNCTION audit_entries_append_only() RETURNS trigger AS \$\$
             BEGIN RAISE EXCEPTION '{$message}'; END;
             \$\$ LANGUAGE plpgsql;"
        );

        DB::unprepared(
            'CREATE TRIGGER audit_entries_no_update BEFORE UPDATE ON audit_entries
             FOR EACH ROW EXECUTE FUNCTION audit_entries_append_only();'
        );

        DB::unprepared(
            'CREATE TRIGGER audit_entries_no_delete BEFORE DELETE ON audit_entries
             FOR EACH ROW EXECUTE FUNCTION audit_entries_append_only();'
        );
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if (in_array($driver, ['sqlite', 'mysql', 'mariadb'], true)) {
            DB::unprepared('DROP TRIGGER IF EXISTS audit_entries_no_update');
            DB::unprepared('DROP TRIGGER IF EXISTS audit_entries_no_delete');
        }

        if ($driver === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS audit_entries_no_update ON audit_entries');
            DB::unprepared('DROP TRIGGER IF EXISTS audit_entries_no_delete ON audit_entries');
            DB::unprepared('DROP FUNCTION IF EXISTS audit_entries_append_only()');
        }

        Schema::dropIfExists('audit_entries');
    }
};
