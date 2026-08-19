<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;

/**
 * §5.1 — the permission catalogue, verbatim.
 *
 * PERM-1 — "Permissions are seeded rows in a permissions table, not an enum or
 *   config array. New permissions arrive by migration." This seeder is that
 *   arrival mechanism; the catalogue below is the only place the list appears,
 *   and no application code enumerates it.
 * PERM-2 — the resources §5.1 marks **Sensitive** carry is_sensitive, which is
 *   what drives the warning and the "Sensitive Access" counter on role-detail.
 * PERM-3 — "Deleting a permission is forbidden." The 11 permissions retired when
 *   the Project module was disabled are seeded WITH retired_at set, so the
 *   history the audit trail refers to actually exists.
 *
 * Not every resource supports every action; the matrix shows `—` where an action
 * does not apply, which is simply the absence of a row.
 */
class PermissionSeeder extends Seeder
{
    private const CRUD = ['view', 'create', 'edit', 'delete'];

    private const CRUD_APPROVE = ['view', 'create', 'edit', 'delete', 'approve'];

    /**
     * PERM-3 — the operational record types carry no `delete`.
     *
     * Nothing in the milk chain, the shop, HR, logistics, purchasing or the
     * community programme is ever hard-deleted: a sale is voided, a point is
     * suspended, a consignment is adjusted, and DM-3 keeps the audit log
     * append-only. A `delete` permission on those resources named a capability
     * the system deliberately does not have — so it could be granted, would
     * appear on the roles screen as real authority, and enabled nothing.
     *
     * Administration keeps its deletes (`admin.users`, `admin.roles`,
     * `shop.categories`), because those genuinely do remove a row.
     */
    private const RECORD = ['view', 'create', 'edit'];

    private const RECORD_APPROVE = ['view', 'create', 'edit', 'approve'];

    /**
     * [resource_key, actions, label, description, is_sensitive]
     *
     * @return array<int, array{0: string, 1: array<int, string>, 2: string, 3: string, 4: bool}>
     */
    private function catalogue(): array
    {
        return [
            // Milk collection
            ['milk.points', self::RECORD, 'Collection points register', 'Collection points and the centers they feed', false],
            ['milk.deliveries', self::RECORD, 'Farmer delivery records', 'Intake recorded at a collection point', false],
            ['milk.consignment.confirm', self::RECORD_APPROVE, 'Confirm a consignment at a center', 'Confirm the litre count a point dispatched', false],
            ['milk.adjustment', self::RECORD, 'Record a litre adjustment', 'Corrections to a delivery or consignment volume', false],
            ['milk.grade', self::RECORD, 'Assign quality grade', 'Sets what a farmer is paid, so it is tightly held', false],
            ['milk.rejection', self::RECORD, 'Record a rejection', 'Reject volume for one of the configured reasons', false],
            ['milk.batch.dispatch', self::RECORD, 'Dispatch a batch to the factory', 'Combine confirmed consignments into a batch', false],
            ['milk.reconciliation', self::RECORD_APPROVE, 'Factory reconciliation', 'Reconcile received against dispatched volume', false],
            ['milk.totals.network', ['view'], 'Network-wide production totals', 'Sensitive — the whole network\'s production figures', true],

            // Logistics
            ['logistics.trips', self::RECORD, 'Trips, riders, vehicles', 'Movement of consignments and batches', false],
            ['logistics.payments', self::RECORD_APPROVE, 'Transport fees and payment runs', 'Sensitive — what riders and drivers are paid', true],

            // Purchases
            ['purchase.requisitions', self::RECORD_APPROVE, 'Requisitions', 'Raise and manage purchase requisitions', false],
            ['purchase.service_providers', self::CRUD, 'Service Providers / Vendors', 'Service provider directory and payment bank details', false],
            ['purchase.approve.depthead', ['approve'], 'Approve at stage 2 (Department Head)', 'One workflow stage, one permission', false],
            ['purchase.approve.audit', ['approve'], 'Approve at stage 3 (Internal Audit)', 'One workflow stage, one permission', false],
            ['purchase.approve.ed', ['approve'], 'Approve at stage 4 (Executive Director)', 'One workflow stage, one permission', false],
            ['purchase.approve.accounts', ['approve'], 'Approve at stage 5 (Accounts)', 'One workflow stage, one permission', false],
            ['purchase.approve.gm', ['approve'], 'Approve at stage 6 (General Manager)', 'One workflow stage, one permission', false],

            // Community engagement
            /*
             * `validate` is a fourth action on the farmer register, not a
             * flavour of `edit`.
             *
             * The two are genuinely different authorities. `edit` is "change
             * this farmer's details because you decided to"; `validate` is "go
             * and check this farmer against reality, and record what you found".
             * A Collection Agent is trusted with the second and deliberately not
             * the first — they meet the farmer every morning, which is exactly
             * what makes them the right person to verify a phone number and the
             * wrong person to be able to silently move one.
             *
             * Folding it into `edit` would have been one fewer row and would
             * have handed every validating agent the run of the register.
             */
            ['community.farmers', ['view', 'create', 'edit', 'validate'], 'Farmer records', 'Farmers are records, not accounts', false],

            /*
             * The revalidation QUEUE, which is a different thing from the farmer
             * register: `create` assigns the work, `approve` accepts what came
             * back. M&E holds both. Holding them is not permission to edit a
             * farmer — see the role's own restrictions.
             */
            ['community.validation', self::RECORD_APPROVE, 'Farmer revalidation queue', 'Who needs re-checking, who is doing it, and what they found', false],
            ['community.cooperatives', self::RECORD, 'Cooperative records', 'Cooperatives and their officials', false],
            ['community.coop.savings', self::RECORD, 'Cooperative fund balances and ledger', 'Sensitive — savings and social fund money', true],
            ['community.extension', self::RECORD, 'Extension agents and field activities', 'The community programme', false],

            // One-Stop Shop
            ['shop.inventory', self::RECORD, 'Stock levels and products', 'Quantities, batches and reorder levels', false],
            ['shop.categories', self::CRUD, 'Product categories', 'Administrator-defined, never hardcoded', false],
            ['shop.sales', self::RECORD, 'Sales transactions', 'Counter sales and receipts', false],
            ['shop.revenue', ['view'], 'Revenue totals, margins, stock values', 'Sensitive — every money aggregate in the shop', true],

            // Human resources
            ['hr.employees', self::RECORD, 'Employee records', 'Staff records and departments', false],
            ['hr.setup', self::CRUD, 'HR setup and schemes', 'Master allowance types, loan schemes, deduction policies, and commission milestones', false],
            ['hr.leave', self::RECORD_APPROVE, 'All leave requests', 'Every employee\'s leave', false],
            ['hr.leave.own', ['view', 'create'], 'Own leave requests', 'Held automatically by every user', false],
            ['hr.payroll', self::RECORD_APPROVE, 'Payroll and salaries', 'Sensitive — salary figures and payment runs', true],
            ['hr.payslip.own', ['view'], 'Own payslips', 'Held automatically by every user', false],

            // Payments & Gateway Disbursements
            ['payments.disbursements', ['view', 'create', 'initialize', 'authorize', 'reconcile'], 'Payment disbursements & runs', 'Sensitive — single & bulk payouts via gateways and bank transfers', true],
            ['payments.gateways', ['view', 'edit'], 'Payment gateway settings', 'Sensitive — API credentials, webhooks, and payment mode toggle', true],

            // Administration
            ['admin.users', self::CRUD, 'User accounts', 'Create and deactivate accounts', false],
            ['admin.roles', self::CRUD, 'Roles and permissions', 'The permission matrix itself', false],
            ['admin.settings', ['view', 'edit'], 'Reference data and workflows', 'Rates, reasons, tariffs, numbering, workflows', false],
            ['admin.audit', ['view'], 'Audit log', 'Append-only record of every change and refusal', false],
        ];
    }

    /**
     * PERM-3 — "11 permissions were retired when the Project module was
     * disabled." Seeded retired so the roles screen, the audit trail and the
     * settings screen's "11 permissions retired" line all refer to real rows.
     *
     * @return array<int, array{0: string, 1: array<int, string>, 2: string}>
     */
    private function retiredCatalogue(): array
    {
        return [
            ['project.projects', self::CRUD_APPROVE, 'Projects'],                 // 5
            ['project.tasks', self::CRUD, 'Project tasks'],                        // 4
            ['project.budget', ['view', 'edit'], 'Project budget'],                // 2
        ];
    }

    /**
     * PERM-3 — retired, never deleted.
     *
     * The `delete` action on every operational record type. They were granted to
     * live roles and checked by nothing, which is the worst of both: an
     * administrator reading the roles screen saw authority that did not exist,
     * and a reviewer counting sensitive grants counted them.
     *
     * The rows stay so that a historical grant, and the audit entry recording it,
     * still resolve to a real permission.
     *
     * @return array<int, array{0: string, 1: array<int, string>, 2: string}>
     */
    private function retiredDeletes(): array
    {
        $resources = [
            'milk.points', 'milk.deliveries', 'milk.consignment.confirm', 'milk.adjustment',
            'milk.grade', 'milk.rejection', 'milk.batch.dispatch', 'milk.reconciliation',
            'logistics.trips', 'logistics.payments', 'purchase.requisitions',
            'community.farmers', 'community.cooperatives', 'community.coop.savings',
            'community.extension', 'shop.inventory', 'shop.sales',
            'hr.employees', 'hr.leave', 'hr.payroll',
        ];

        return array_map(
            static fn (string $resource) => [$resource, ['delete'], 'Delete (withdrawn)'],
            $resources,
        );
    }

    public function run(): void
    {
        $position = 0;

        foreach ($this->catalogue() as [$resourceKey, $actions, $label, $description, $isSensitive]) {
            foreach ($actions as $action) {
                Permission::query()->updateOrCreate(
                    ['resource_key' => $resourceKey, 'action' => $action],
                    [
                        'label' => $label,
                        'description' => $description,
                        'is_sensitive' => $isSensitive,
                        'position' => $position++,
                        'retired_at' => null,
                        'retired_reason' => null,
                    ],
                );
            }
        }

        /*
         * PERM-3 — the withdrawn deletes. Written after the live catalogue so
         * that re-running the seeder over an existing database retires a row
         * that used to be live, rather than leaving it granted.
         */
        foreach ($this->retiredDeletes() as [$resourceKey, $actions, $label]) {
            foreach ($actions as $action) {
                Permission::query()->updateOrCreate(
                    ['resource_key' => $resourceKey, 'action' => $action],
                    [
                        'label' => $label,
                        'description' => 'This record type is never hard-deleted — it is voided, suspended or superseded.',
                        'is_sensitive' => false,
                        'position' => $position++,
                        'retired_at' => '2026-08-01 09:00:00',
                        'retired_reason' => 'No delete path exists: the record is voided or deactivated instead',
                    ],
                );
            }
        }

        // PERM-3 — retired, never deleted.
        foreach ($this->retiredCatalogue() as [$resourceKey, $actions, $label]) {
            foreach ($actions as $action) {
                Permission::query()->updateOrCreate(
                    ['resource_key' => $resourceKey, 'action' => $action],
                    [
                        'label' => $label,
                        'description' => 'Retired when the Project Management module was disabled.',
                        'is_sensitive' => false,
                        'position' => $position++,
                        'retired_at' => '2026-07-12 08:45:00',
                        'retired_reason' => 'Project Management module disabled',
                    ],
                );
            }
        }
    }
}
