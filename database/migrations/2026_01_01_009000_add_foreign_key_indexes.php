<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NFR-3 — "Index every foreign key, plus deliveries(delivered_at,
 * collection_point_id), consignments(status, collection_center_id),
 * audit_entries(occurred_at, actor_user_id)."
 *
 * The named composite indexes are declared alongside their tables. The single-
 * column foreign-key indexes are collected here instead, for one reason: a
 * foreign key does NOT imply an index. MySQL creates one silently, PostgreSQL —
 * which ARCH-1 names as the production database — does not, and neither does the
 * SQLite used for local work. Every `->constrained()` in the earlier migrations
 * would therefore have left a sequential scan behind on delete-checks and on the
 * "who created this?" joins the audit and admin screens make constantly.
 *
 * Keeping them in one migration also makes the rule auditable: the test for NFR-3
 * walks the live schema and asserts that no foreign key anywhere leads no index,
 * so a new table added later fails that test until it is indexed — the list below
 * is the answer to the rule as of today, not a permanent one.
 */
return new class extends Migration
{
    /**
     * table => foreign-key columns that had no index of their own.
     *
     * @var array<string, array<int, string>>
     */
    private const INDEXES = [
        'activity_types' => ['created_by_user_id'],
        'adjustment_reasons' => ['created_by_user_id'],
        'adjustments' => ['created_by_user_id', 'adjustment_reason_id'],
        'agent_community' => ['community_id'],
        'attachments' => ['uploaded_by_user_id'],
        'batches' => ['created_by_user_id', 'released_by_user_id', 'rejection_reason_id', 'discrepancy_cause_id', 'reconciled_by_user_id', 'trip_id', 'dispatched_by_user_id', 'collection_center_id'],
        'collection_centers' => ['created_by_user_id', 'logistics_user_id', 'officer_user_id', 'lga_id'],
        'collection_points' => ['created_by_user_id'],
        'comments' => ['created_by_user_id'],
        'consignments' => ['created_by_user_id', 'rejection_reason_id', 'grade_rate_id', 'grade_id', 'confirmed_by_user_id', 'trip_id', 'dispatched_by_user_id', 'collection_center_id'],
        'cooperative_entries' => ['created_by_user_id'],
        'cooperatives' => ['created_by_user_id', 'collection_point_id', 'lga_id'],
        'delegations' => ['created_by_user_id', 'role_id'],
        'deliveries' => ['created_by_user_id', 'cutoff_override_by_user_id', 'recorded_by_user_id', 'collection_point_id'],
        'departments' => ['created_by_user_id'],
        'devices' => ['revoked_by_user_id'],
        'discrepancy_causes' => ['created_by_user_id'],
        'drivers' => ['created_by_user_id'],
        'employees' => ['created_by_user_id', 'line_manager_id'],
        'extension_agents' => ['created_by_user_id', 'reports_to_user_id'],
        'failed_signins' => ['user_id'],
        'farmers' => ['created_by_user_id', 'enrolled_by_user_id', 'lga_id'],
        'field_activities' => ['created_by_user_id', 'closes_followup_id', 'activity_type_id'],
        'grade_rates' => ['created_by_user_id'],
        'grades' => ['created_by_user_id'],
        'idempotency_keys' => ['user_id'],
        'leave_requests' => ['created_by_user_id', 'workflow_instance_id', 'leave_type_id'],
        'payroll_runs' => ['run_by_user_id', 'workflow_instance_id'],
        'pending_farmer_deductions' => ['created_by_user_id', 'sale_id'],
        'permission_role' => ['granted_by_user_id'],
        'permission_test_runs' => ['run_by_user_id', 'test_user_id'],
        'positions' => ['created_by_user_id', 'department_id'],
        'product_batches' => ['created_by_user_id', 'requisition_id'],
        'product_categories' => ['created_by_user_id'],
        'products' => ['created_by_user_id'],
        'quality_followups' => ['closed_by_activity_id', 'rejection_reason_id', 'closed_by_user_id'],
        'quality_test_definitions' => ['created_by_user_id'],
        'quality_tests' => ['recorded_by_user_id', 'quality_test_definition_id'],
        'rejection_reasons' => ['created_by_user_id'],
        'requisitions' => ['created_by_user_id', 'revises_requisition_id', 'workflow_instance_id', 'department_id'],
        'role_user' => ['assigned_by_user_id'],
        'roles' => ['last_passing_test_run_id', 'created_by_user_id'],
        'routes' => ['created_by_user_id'],
        'sale_items' => ['product_batch_id'],
        'sales' => ['created_by_user_id', 'sales_officer_user_id', 'cooperative_id'],
        'sequences' => ['updated_by_user_id'],
        'sessions' => ['device_id'],
        'settings' => ['updated_by_user_id'],
        'stock_movements' => ['created_by_user_id', 'workflow_instance_id', 'sale_id', 'reason_id', 'product_batch_id'],
        'trips' => ['created_by_user_id', 'logged_by_user_id', 'driver_id', 'vehicle_id', 'route_id'],
        'users' => ['employee_id', 'created_by_user_id'],
        'vehicles' => ['created_by_user_id'],
        'workflow_actions' => ['delegation_id', 'workflow_stage_id', 'on_behalf_of_user_id'],
        'workflow_band_stage' => ['workflow_stage_id'],
        'workflow_instances' => ['requester_user_id', 'current_stage_id', 'workflow_band_id', 'workflow_id'],
        'workflows' => ['created_by_user_id'],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table, $columns): void {
                foreach ($columns as $column) {
                    if (Schema::hasColumn($table, $column)) {
                        $blueprint->index($column);
                    }
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table, $columns): void {
                foreach ($columns as $column) {
                    $blueprint->dropIndex($table.'_'.$column.'_index');
                }
            });
        }
    }
};
