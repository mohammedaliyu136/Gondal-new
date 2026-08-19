<?php

namespace Database\Seeders;

use App\Authorization\ScopeType;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * §5.2 / ROLE-4 — "The 19 seeded roles are listed in roles.html with their
 * permission counts. Two roles (Administrator, HR / IT Admin) are seeded as
 * retired to preserve the audit trail of the clean-up."
 *
 * The grant sets below come from §16 / personas.html, which explains WHY each
 * boundary exists — the permission matrix alone does not. Each role's
 * `restriction` comment is the "Key Restriction" column of that screen, and is
 * the thing a reviewer should check the grants against.
 *
 * ROLE-3 — "Staff (self-service)" is flagged is_automatic; every user holds it.
 * ROLE-5 — "Farm Manager is seeded with zero permissions and status draft" (§15.2).
 *
 * The permission COUNTS shown in the prototype are demo figures (§17). The
 * catalogue in §5.1 is normative, so counts are computed from these grants.
 */
class RoleSeeder extends Seeder
{
    /**
     * Grants are written as resource keys with action lists, or `'*'` for every
     * action the resource supports.
     *
     * @return array<int, array<string, mixed>>
     */
    private function roles(): array
    {
        return [
            [
                'name' => 'System Administrator',
                'description' => 'All modules, roles and settings',
                'scope_type' => ScopeType::Network,
                'status' => Role::STATUS_ACTIVE,
                'accent' => 'accent',
                'responsibilities' => [
                    'Creates users, edits roles and maintains settings',
                    'Tests every role change against a test user before it reaches live staff',
                    'Reviews the audit log for permission changes and blocked access attempts',
                ],
                'restrictions' => [
                    'Never sees or sets a user password — users choose one from an activation e-mail (BR-31)',
                    'Holds no bypass in code: this role passes checks only because it holds the grants (ROLE-2)',
                ],
                // Everything. Note there is still no bypass in code (ROLE-2):
                // this role passes checks only because it holds the grants.
                'grants' => ['*'],
                // "Never sees user passwords" — BR-31, enforced by there being no
                // password field anywhere in the admin UI, not by a permission.
            ],
            [
                'name' => 'Delivery Lead',
                'description' => 'Extension oversight, farmer programmes',
                'scope_type' => ScopeType::Communities,
                'status' => Role::STATUS_ACTIVE,
                'accent' => 'accent',
                'mobile_home' => 'extension_team',
                'responsibilities' => [
                    'Heads delivery of the community engagement programme across all communities',
                    'Owns the farmer and cooperative registers and the extension calendar',
                    'Watches point and center rejection patterns for the communities they cover',
                ],
                'restrictions' => [
                    'No payroll',
                    'Cannot grade or reject milk',
                ],
                // Restriction: no payroll.
                'grants' => [
                    // `*` now includes the new `validate` action — correct: the
                    // lead of the programme may verify a farmer themselves.
                    'community.farmers' => '*',
                    'community.cooperatives' => '*',
                    'community.coop.savings' => ['view'],
                    'community.extension' => '*',
                    // Sees what M&E has asked of their team, and may reassign
                    // within it; accepting the result stays with M&E.
                    'community.validation' => ['view', 'create', 'edit'],
                    'milk.points' => ['view'],
                    'milk.deliveries' => ['view'],
                    'milk.rejection' => ['view'],
                    'purchase.requisitions' => ['view', 'create', 'edit'],
                    'shop.inventory' => ['view'],
                    'shop.sales' => ['view'],
                ],
            ],
            [
                'name' => 'Milk Collection Supervisor',
                'description' => 'Factory reconciliation, discrepancies',
                'scope_type' => ScopeType::Network,
                'status' => Role::STATUS_ACTIVE,
                'accent' => 'accent',
                'mobile_home' => 'center',
                'responsibilities' => [
                    'Reviews every point and center, and chases anything flagged',
                    'Reconciles each batch at the factory — records discrepancies from container changes and any final rejection',
                    'Releases reconciled volume to production and payment',
                    'Holds the re-grade break alongside the Quality Officer',
                ],
                'restrictions' => [
                    'No payroll or employee salary records',
                    'No shop revenue totals',
                ],
                // Restriction: no payroll or shop revenue.
                'grants' => [
                    'milk.points' => '*',
                    /*
                     * BR-3 — "accepted with an explicit SUPERVISOR override that
                     * is logged". `cutoff_override` is a fourth action rather
                     * than part of `edit`, because "record what arrived" and
                     * "overrule the rule about when it may arrive" are different
                     * authorities and the agent holds only the first.
                     *
                     * It is listed HERE, and not only in the migration that
                     * created it, because this catalogue rewrites
                     * permission_role on every seed. The migration granted it
                     * and this file did not, so every reseed took it straight
                     * back off — leaving a sensitive permission that existed
                     * with nobody holding it, and a supervisor asked to
                     * authorise late milk being refused. DeliveryService::
                     * guardCutoff() carries a comment predicting exactly that.
                     */
                    'milk.deliveries' => ['view', 'edit', 'cutoff_override'],
                    'milk.consignment.confirm' => '*',
                    'milk.adjustment' => '*',
                    'milk.grade' => '*',
                    'milk.rejection' => '*',
                    'milk.batch.dispatch' => '*',
                    'milk.reconciliation' => '*',
                    'milk.totals.network' => ['view'],
                    'logistics.trips' => ['view', 'edit'],
                    'community.farmers' => ['view'],
                    'purchase.requisitions' => ['view', 'create', 'edit'],
                ],
            ],
            [
                'name' => 'Milk Collection Officer',
                'description' => 'Confirmations, adjustments, grades, batches',
                'scope_type' => ScopeType::Center,
                'status' => Role::STATUS_ACTIVE,
                'accent' => 'info',
                'mobile_home' => 'center',
                'responsibilities' => [
                    'Confirms the litre count every agent reported, and records adjustments with a reason',
                    'Runs quality tests and assigns Grade A or Grade B — this sets what farmers are paid',
                    'Combines the day’s consignments into one batch and hands it to logistics',
                ],
                'restrictions' => [
                    'Cannot change a grade already assigned — re-grading is held by Quality Officer and supervisor',
                    'No other center’s consignments, and no network production total',
                    'No payroll, transport payments or shop figures',
                ],
                // Restriction: no other center, no payroll, NO network total.
                'grants' => [
                    /*
                     * Phase 7 — farmer payment. Stated HERE as well as in
                     * migration 001600 because this catalogue rewrites
                     * permission_role on every seed: a grant that lives only
                     * in a migration is taken straight back off at the next
                     * db:seed. That is exactly how milk.deliveries.cutoff_override
                     * ended up sensitive, real, and held by nobody.
                     */
                    'finance.farmer_payments' => ['view', 'disburse'],
                    // The officer who hands cash to a farmer at a centre hands
                    // it to the rider who carried the milk there too.
                    'logistics.payments' => ['view', 'disburse'],
                    // Sees their own float and what the system thinks they have
                    // handed over. Cannot sign it back in — see migration 002100.
                    'finance.cash' => ['view'],
                    'milk.points' => ['view'],
                    // BR-3 — the centre officer is the other role §5.1 puts
                    // above the point, and the migration that introduced this
                    // permission named both. See the note on the supervisor.
                    'milk.deliveries' => ['view', 'cutoff_override'],
                    'milk.consignment.confirm' => ['view', 'create', 'edit'],
                    'milk.adjustment' => ['view', 'create'],
                    'milk.grade' => ['view', 'create'],
                    'milk.rejection' => ['view', 'create'],
                    'milk.batch.dispatch' => ['view', 'create', 'edit'],
                    // Confirms the litre counts of farmers they never meet, so
                    // verifying a register entry against the person is squarely
                    // their business — but the register itself is not theirs to
                    // edit any more than a grade is Accounts'.
                    'community.farmers' => ['view', 'validate'],
                    'community.validation' => ['view'],
                    'purchase.requisitions' => ['view', 'create'],
                ],
            ],
            [
                'name' => 'Collection Agent',
                'description' => 'Point intake and the three rejection reasons',
                'scope_type' => ScopeType::Point,
                'status' => Role::STATUS_ACTIVE,
                'accent' => 'info',
                'mobile_home' => 'milk_collection',
                'responsibilities' => [
                    'Meets farmers at the point from 05:30 and records each delivery in litres',
                    'Runs the lactometer check and rejects milk using one of three reasons only',
                    'Dispatches the accepted volume to the center as one consignment',
                    'Enrols new farmers at the point',
                ],
                'restrictions' => [
                    'No other point’s deliveries and no network total',
                    'No farmer payment amounts, payroll or shop revenue',
                    'Cannot grade milk or adjust a confirmed litre count',
                ],
                // Restriction: no network totals, no payments.
                'grants' => [
                    'milk.points' => ['view'],
                    'milk.deliveries' => ['view', 'create', 'edit'],
                    'milk.rejection' => ['view', 'create'],
                    'milk.consignment.confirm' => ['view', 'create'],
                    // They see the farmer and may enrol one, but may not edit
                    // the register — `validate` is the narrow authority to
                    // confirm details against the person standing in front of
                    // them, which is the one check they are best placed to make.
                    'community.farmers' => ['view', 'create', 'validate'],
                    'community.validation' => ['view'],
                ],
            ],
            [
                'name' => 'Logistics Officer',
                'description' => 'Trips, route fees, transport payments',
                'scope_type' => ScopeType::Center,
                'status' => Role::STATUS_ACTIVE,
                'accent' => 'info',
                'mobile_home' => 'logistics',
                'responsibilities' => [
                    'Logs which rider or vehicle moved each consignment and batch',
                    'Records the route fee so the transport payment run can pay riders',
                    'Raises requisitions for diesel, servicing and spares',
                ],
                'restrictions' => [
                    'Cannot grade or reject milk — not their call',
                    'Cannot approve their own requisition at any stage',
                ],
                // Restriction: cannot grade or reject milk — not their call.
                'grants' => [
                    'logistics.trips' => '*',
                    'logistics.payments' => ['view', 'create'],
                    'milk.points' => ['view'],
                    'milk.consignment.confirm' => ['view'],
                    'milk.batch.dispatch' => ['view'],
                    'purchase.requisitions' => ['view', 'create', 'edit'],
                ],
            ],
            [
                'name' => 'Community Engagement Officer',
                'description' => 'Agents, activities, farmer enrolment',
                'scope_type' => ScopeType::Communities,
                'status' => Role::STATUS_ACTIVE,
                'accent' => 'info',
                'mobile_home' => 'extension_team',
                'responsibilities' => [
                    'Assigns communities to agents and tracks visit and enrolment targets',
                    'Maintains farmer and cooperative records, including savings and social fund entries',
                    'Escalates agents who fall behind, and plans the training calendar',
                ],
                'restrictions' => [
                    'No approval authority at any requisition stage',
                    'No milk grading decisions at the centers',
                    'No payroll',
                ],
                // Restriction: no approval authority, no milk grading.
                'grants' => [
                    'community.farmers' => '*',
                    'community.cooperatives' => '*',
                    'community.coop.savings' => ['view', 'create', 'edit'],
                    'community.extension' => '*',
                    'community.validation' => ['view', 'create', 'edit'],
                    'milk.deliveries' => ['view'],
                    'milk.rejection' => ['view'],
                    'purchase.requisitions' => ['view', 'create'],
                ],
            ],
            [
                'name' => 'Extension Agent',
                'description' => 'Visits, enrolment, training, follow-ups',
                'scope_type' => ScopeType::Communities,
                'status' => Role::STATUS_ACTIVE,
                'accent' => 'info',
                'mobile_home' => 'field_visits',
                'responsibilities' => [
                    'Visits households, enrols new farmers and records herd details',
                    'Runs training sessions on clean milk production and feed',
                    'Closes quality follow-ups raised automatically by repeat rejections (BR-5)',
                ],
                'restrictions' => [
                    'No milk volumes or payment amounts for the farmers they visit',
                    'No other agent’s communities',
                    'No cooperative savings or any financial screen',
                ],
                // Restriction: no volumes or payment figures for the farmers
                // they visit, and no other agent's communities.
                'grants' => [
                    'community.farmers' => ['view', 'create', 'edit', 'validate'],
                    'community.extension' => ['view', 'create', 'edit'],
                    'community.validation' => ['view'],
                ],
            ],
            [
                'name' => 'One-Stop Shop Manager',
                'description' => 'Categories, inventory, sales and revenue',
                'scope_type' => ScopeType::Network,
                'status' => Role::STATUS_ACTIVE,
                'accent' => 'info',
                'mobile_home' => 'inventory',
                'responsibilities' => [
                    'Creates and retires product categories — drugs, feed, manure and the rest — without a system change',
                    'Watches stock levels and raises restock requisitions',
                    'Reviews daily sales, credit exposure per cooperative, and margins',
                ],
                'restrictions' => [
                    'No milk production volumes or farmer payments',
                    'No payroll or employee records',
                ],
                // Restriction: no milk or payroll data.
                'grants' => [
                    'shop.inventory' => '*',
                    'shop.categories' => '*',
                    'shop.sales' => '*',
                    'shop.revenue' => ['view'],
                    'community.farmers' => ['view'],
                    'community.cooperatives' => ['view'],
                    'purchase.requisitions' => ['view', 'create', 'edit'],
                ],
            ],
            [
                'name' => 'Sales Officer',
                'description' => 'Sales only — no revenue totals',
                'scope_type' => ScopeType::Own,
                'status' => Role::STATUS_ACTIVE,
                'accent' => 'info',
                'mobile_home' => 'sales',
                'responsibilities' => [
                    'Records each sale, takes payment or books it to a cooperative’s credit',
                    'Looks up farmers and cooperatives at the counter',
                ],
                'restrictions' => [
                    'No daily or monthly revenue totals — only their own transactions (BR-29)',
                    'No milk production litres',
                ],
                // BR-29 — deliberately NOT granted shop.revenue.
                'grants' => [
                    'shop.sales' => ['view', 'create'],
                    'shop.inventory' => ['view'],
                    'community.farmers' => ['view'],
                    'community.cooperatives' => ['view'],
                ],
            ],
            [
                'name' => 'Inventory Officer',
                'description' => 'Quantities only — no financial values',
                'scope_type' => ScopeType::Network,
                'status' => Role::STATUS_ACTIVE,
                'accent' => 'info',
                'mobile_home' => 'inventory',
                'responsibilities' => [
                    'Counts stock, receives deliveries and flags low items',
                ],
                'restrictions' => [
                    'Stock quantities only — never values at cost or margin',
                    'No sales or revenue figures',
                ],
                // Restriction: quantities only. No shop.revenue, no shop.sales.
                'grants' => [
                    'shop.inventory' => ['view', 'create', 'edit'],
                    'shop.categories' => ['view'],
                ],
            ],
            [
                'name' => 'Department Head',
                'description' => 'Requisition approval stage 2',
                'scope_type' => ScopeType::Department,
                'status' => Role::STATUS_ACTIVE,
                'accent' => 'primary',
                'mobile_home' => 'approvals',
                'responsibilities' => [
                    'First approval for their own department’s requisitions',
                    'Approves their team’s leave requests',
                ],
                'restrictions' => [
                    'Only their own department’s requests',
                    'No payroll figures',
                ],
                // Restriction: only their own department's requests.
                'grants' => [
                    'purchase.requisitions' => ['view', 'create', 'edit', 'approve'],
                    'purchase.approve.depthead' => ['approve'],
                    'hr.employees' => ['view'],
                    'hr.leave' => ['view', 'approve'],
                ],
            ],
            [
                'name' => 'Internal Audit',
                'description' => 'Requisition approval stage 3',
                'scope_type' => ScopeType::Network,
                'status' => Role::STATUS_ACTIVE,
                'accent' => 'primary',
                'mobile_home' => 'approvals',
                'responsibilities' => [
                    'Works an approval queue: verifies quotations, budget lines and that quantities match usage',
                    'Approves, reduces the amount, asks for more information, or rejects with a reason',
                    'Reviews the audit log for permission changes and blocked access attempts',
                ],
                'restrictions' => [
                    'Reads operational records, never edits them',
                    'Cannot approve at any stage other than Internal Audit',
                ],
                // Restriction: reads data, does not edit it. Every grant below
                // is `view` or `approve` — that is the restriction, in data.
                'grants' => [
                    /*
                     * Phase 7 — farmer payment. Stated HERE as well as in
                     * migration 001600 because this catalogue rewrites
                     * permission_role on every seed: a grant that lives only
                     * in a migration is taken straight back off at the next
                     * db:seed. That is exactly how milk.deliveries.cutoff_override
                     * ended up sensitive, real, and held by nobody.
                     */
                    'finance.farmer_payments' => ['view'],
                    'purchase.requisitions' => ['view', 'approve'],
                    'purchase.approve.audit' => ['approve'],
                    'admin.audit' => ['view'],
                    'milk.points' => ['view'],
                    'milk.deliveries' => ['view'],
                    'milk.consignment.confirm' => ['view'],
                    'milk.batch.dispatch' => ['view'],
                    'milk.reconciliation' => ['view'],
                    'milk.totals.network' => ['view'],
                    'logistics.trips' => ['view'],
                    'logistics.payments' => ['view'],
                    'community.farmers' => ['view'],
                    'community.cooperatives' => ['view'],
                    'community.coop.savings' => ['view'],
                    'community.extension' => ['view'],
                    'shop.inventory' => ['view'],
                    'shop.sales' => ['view'],
                    'shop.revenue' => ['view'],
                    'hr.employees' => ['view'],
                    'admin.settings' => ['view'],
                ],
            ],
            [
                'name' => 'Executive Director',
                'description' => 'Requisition approval stage 4',
                'scope_type' => ScopeType::Network,
                'status' => Role::STATUS_ACTIVE,
                'accent' => 'primary',
                'mobile_home' => 'approvals',
                'responsibilities' => [
                    'Authorises major spend — anything above ₦500,000 routes through the ED and the GM',
                    'Reviews network production, quality and rejection trends',
                    'Reads every module, but operates none of them day to day',
                ],
                'restrictions' => [
                    'Cannot raise and approve the same request (BR-18)',
                ],
                // Restriction: BR-18 — cannot raise and approve the same request.
                'grants' => [
                    /*
                     * Phase 7 — farmer payment. Stated HERE as well as in
                     * migration 001600 because this catalogue rewrites
                     * permission_role on every seed: a grant that lives only
                     * in a migration is taken straight back off at the next
                     * db:seed. That is exactly how milk.deliveries.cutoff_override
                     * ended up sensitive, real, and held by nobody.
                     */
                    'finance.farmer_payments' => ['view'],
                    'purchase.requisitions' => ['view', 'create', 'approve'],
                    'purchase.approve.ed' => ['approve'],
                    'milk.points' => ['view'],
                    'milk.deliveries' => ['view'],
                    'milk.consignment.confirm' => ['view'],
                    'milk.batch.dispatch' => ['view'],
                    'milk.reconciliation' => ['view'],
                    'milk.totals.network' => ['view'],
                    'logistics.trips' => ['view'],
                    'logistics.payments' => ['view'],
                    'community.farmers' => ['view'],
                    'community.cooperatives' => ['view'],
                    'community.coop.savings' => ['view'],
                    'community.extension' => ['view'],
                    'shop.inventory' => ['view'],
                    'shop.sales' => ['view'],
                    'shop.revenue' => ['view'],
                    'hr.employees' => ['view'],
                    'hr.payroll' => ['view'],
                    'payments.disbursements' => ['view', 'authorize', 'reconcile'],
                    'admin.audit' => ['view'],
                    'admin.settings' => ['view'],
                ],
            ],
            [
                'name' => 'Accounts',
                'description' => 'Requisition approval stage 5',
                'scope_type' => ScopeType::Network,
                'status' => Role::STATUS_ACTIVE,
                'accent' => 'primary',
                'mobile_home' => 'approvals',
                'responsibilities' => [
                    'Confirms funds and raises payment once a requisition clears the ED',
                    'Runs payroll, and settles transport and farmer payments',
                    'Reconciles cooperative credit against milk payment deductions',
                ],
                'restrictions' => [
                    'Cannot change a quality grade or a confirmed litre count',
                    'Cannot create users or edit roles',
                ],
                // Restriction: cannot change a grade or a confirmed litre count,
                // and cannot create users or edit roles.
                'grants' => [
                    /*
                     * Phase 7 — farmer payment. Stated HERE as well as in
                     * migration 001600 because this catalogue rewrites
                     * permission_role on every seed: a grant that lives only
                     * in a migration is taken straight back off at the next
                     * db:seed. That is exactly how milk.deliveries.cutoff_override
                     * ended up sensitive, real, and held by nobody.
                     */
                    'finance.farmer_payments' => ['view', 'create', 'approve', 'reverse'],
                    // `spend` recorded here as well as in migration 002400: this
                    // catalogue rewrites permission_role on every seed, so a grant
                    // that lives only in a migration is taken straight back off.
                    'purchase.requisitions' => ['view', 'approve', 'spend'],
                    'purchase.service_providers' => '*',
                    'purchase.approve.accounts' => ['approve'],
                    'hr.payroll' => '*',
                    'hr.employees' => ['view'],
                    'payments.disbursements' => ['view', 'create', 'initialize', 'reconcile'],
                    'payments.gateways' => ['view'],
                    'logistics.payments' => '*',
                    'finance.cash' => '*',
                    /*
                     * Without this the cost-per-litre report answered ₦0.00 to
                     * the only role with a reason to run it: SCOPE-4 sends
                     * every aggregate through the model's global scope, and a
                     * role holding no `milk.deliveries` scope at all resolves
                     * to an empty set rather than an error. View only — the
                     * cut-off override and recording a delivery stay at the
                     * point.
                     */
                    'milk.deliveries' => ['view'],
                    'logistics.trips' => ['view'],
                    'community.coop.savings' => ['view', 'create', 'edit'],
                    'community.cooperatives' => ['view'],
                    'community.farmers' => ['view'],
                    'shop.revenue' => ['view'],
                    'shop.sales' => ['view'],
                    'milk.consignment.confirm' => ['view'],
                    'milk.batch.dispatch' => ['view'],
                    'milk.reconciliation' => ['view'],
                    'milk.totals.network' => ['view'],
                ],
            ],
            [
                'name' => 'General Manager',
                'description' => 'Requisition approval stage 6',
                'scope_type' => ScopeType::Network,
                'status' => Role::STATUS_ACTIVE,
                'accent' => 'primary',
                'mobile_home' => 'approvals',
                'responsibilities' => [
                    'Final authorisation on major spend',
                    'Owns the process definitions and the approval workflow',
                    'Reviews network production, quality and rejection trends',
                ],
                'restrictions' => [
                    'No delegate configured — approvals stall while the single holder is away',
                    'Cannot raise and approve the same request (BR-18)',
                ],
                // Restriction: no delegate configured — a single point of
                // failure the workflow screen warns about.
                'grants' => [
                    /*
                     * Phase 7 — farmer payment. Stated HERE as well as in
                     * migration 001600 because this catalogue rewrites
                     * permission_role on every seed: a grant that lives only
                     * in a migration is taken straight back off at the next
                     * db:seed. That is exactly how milk.deliveries.cutoff_override
                     * ended up sensitive, real, and held by nobody.
                     */
                    'finance.farmer_payments' => ['view', 'approve', 'reverse'],
                    'purchase.requisitions' => ['view', 'create', 'approve'],
                    'purchase.approve.gm' => ['approve'],
                    'milk.points' => ['view'],
                    'milk.deliveries' => ['view'],
                    'milk.consignment.confirm' => ['view'],
                    'milk.batch.dispatch' => ['view'],
                    'milk.reconciliation' => ['view'],
                    'milk.totals.network' => ['view'],
                    'logistics.trips' => ['view'],
                    'logistics.payments' => ['view', 'approve', 'reverse'],
                    'finance.cash' => ['view', 'issue', 'reconcile'],
                    'community.farmers' => ['view'],
                    'community.cooperatives' => ['view'],
                    'community.coop.savings' => ['view'],
                    'community.extension' => ['view'],
                    'shop.inventory' => ['view'],
                    'shop.categories' => ['view'],
                    'shop.sales' => ['view'],
                    'shop.revenue' => ['view'],
                    'hr.employees' => ['view'],
                    'hr.setup' => ['view'],
                    'hr.payroll' => ['view', 'approve'],
                    'payments.disbursements' => ['view', 'authorize', 'reconcile'],
                    'payments.gateways' => ['view'],
                    'admin.audit' => ['view'],
                    'admin.settings' => ['view'],
                ],
            ],
            [
                'name' => 'HR Manager',
                'description' => 'Full HR module',
                'scope_type' => ScopeType::Network,
                'status' => Role::STATUS_ACTIVE,
                'accent' => 'info',
                'responsibilities' => [
                    'Maintains employee records, leave, positions and hiring',
                    'Prepares payroll runs and payslips',
                ],
                'restrictions' => [
                    'No operational milk data',
                ],
                // Restriction: no operational milk data.
                'grants' => [
                    'hr.employees' => '*',
                    'hr.setup' => '*',
                    'hr.leave' => '*',
                    'hr.payroll' => '*',
                    'purchase.requisitions' => ['view', 'create'],
                ],
            ],
            [
                'name' => 'Farm Manager',
                'description' => 'Raised at review — awaiting definition',
                'scope_type' => ScopeType::Network,
                // ROLE-5 / §15.2 — zero permissions, status draft. Do not invent
                // a scope for this role; the business has not agreed one.
                'status' => Role::STATUS_DRAFT,
                'accent' => 'warning',
                'grants' => [],
            ],
            /*
             * Three roles added in the catalogue reshape.
             *
             * QUALITY OFFICER holds the re-grade break. A clerk keeps
             * `milk.grade.create` — blocking the morning is worse than the fraud
             * it would prevent — but CHANGING a grade already assigned moves
             * money after the fact, so it is held here and at supervisor level
             * only, and every use lands on the exceptions list.
             *
             * BOARD and EXTERNAL AUDIT are separate read-only roles rather than
             * one "observer", because they must not see the same things. The
             * board governs and does not need names and salaries; the auditor
             * needs the audit log and a defined end date.
             */
            [
                'name' => 'Quality Officer',
                'description' => 'Quality testing, grading and re-grading',
                'scope_type' => ScopeType::Center,
                'status' => Role::STATUS_ACTIVE,
                'accent' => 'primary',
                'mobile_home' => 'center',
                'responsibilities' => [
                    'Runs quality tests and assigns the grade at their center',
                    'Holds the re-grade break: changing a grade already assigned, with every use on the exceptions list',
                    'Records rejections and reviews adjustment reasons',
                ],
                'restrictions' => [
                    'Quality only — no dispatch, no money, no staff records',
                ],
                // Restriction: quality only — no dispatch, no money, no staff.
                'grants' => [
                    'milk.grade' => ['view', 'create', 'edit'],
                    'milk.rejection' => ['view', 'create', 'edit'],
                    'milk.adjustment' => ['view'],
                    'milk.consignment.confirm' => ['view'],
                    'milk.deliveries' => ['view'],
                    'milk.points' => ['view'],
                    'milk.reconciliation' => ['view'],
                    'admin.settings' => ['view'],
                ],
            ],
            /*
             * MONITORING & EVALUATION was not in §5.1 and did not come up at the
             * 30 Jul review; it was raised afterwards as a post the organisation
             * actually holds. It is seeded active, but its boundary is narrower
             * than an M&E officer will eventually want, on purpose — see
             * docs/OPEN-DECISIONS.md §5 for the four grants deliberately left
             * out. Widening a role once the business has asked for it is a
             * normal edit; discovering that a role quietly saw farmer payment
             * figures is the failure ROLE-4 retired two roles over.
             */
            [
                'name' => 'Monitoring & Evaluation',
                'description' => 'Programme indicators, targets and outcome trends',
                'scope_type' => ScopeType::Network,
                'status' => Role::STATUS_ACTIVE,
                'accent' => 'primary',
                'mobile_home' => 'validation_queue',
                'responsibilities' => [
                    'Decides which farmers need revalidation, and who in the field will do it',
                    'Sets the due date, and whether what comes back needs their review or stands on its own',
                    'Tracks agent visit and enrolment targets against actuals across every community',
                    'Reports on enrolment, training reach and quality follow-up closure',
                    'Watches rejection and grade trends by point, centre and community for the programme story',
                ],
                'restrictions' => [
                    'Schedules the check and accepts the result — never records the result itself',
                    'Cannot edit a farmer record, a grade, a litre count or a follow-up',
                    'No payroll and no employee register',
                    'No farmer payment amounts and no cooperative savings',
                ],
                /*
                 * M&E writes the SCHEDULE and nothing else.
                 *
                 * The earlier version of this role held `view` and nothing more,
                 * and that was the right shape until revalidation gave it work
                 * to direct. Assigning a check is a write, so the blanket
                 * read-only property is gone — but the property that mattered
                 * survives intact, and it is narrower than "read-only": M&E may
                 * not touch the DATA being evaluated. They say who should be
                 * checked and accept what came back; a field worker records what
                 * they found. An evaluator who could edit a farmer's herd size
                 * directly would be evaluating their own entries.
                 *
                 * `community.farmers` stays at `view` for exactly that reason —
                 * NOT `validate`, which would let M&E close their own
                 * assignments from a desk.
                 *
                 * `community.coop.savings` and `shop.revenue` remain absent:
                 * they are money, and no indicator anybody has named needs them.
                 */
                'grants' => [
                    'community.validation' => ['view', 'create', 'edit', 'approve'],
                    'community.farmers' => ['view'],
                    'community.extension' => ['view'],
                    'milk.deliveries' => ['view'],
                    'milk.rejection' => ['view'],
                    'milk.grade' => ['view'],
                    'milk.totals.network' => ['view'],
                ],
            ],
            [
                'name' => 'Board',
                'description' => 'Read-only governance view of the network',
                'scope_type' => ScopeType::Network,
                'status' => Role::STATUS_ACTIVE,
                'accent' => 'accent',
                'responsibilities' => [
                    'Reads what the network produced and what it earned',
                    'Governs — does not operate any module',
                ],
                'restrictions' => [
                    'No payroll and no employee register — a standing read of salaries has no governance purpose',
                    'Writes nothing anywhere',
                ],
                /*
                 * Restriction: NO payroll and NO employee register. A board sees
                 * what the network produced and what it earned, which is what it
                 * governs; individual salaries and staff records are not that,
                 * and a standing read of them is a privacy exposure with no
                 * governance purpose behind it.
                 */
                'grants' => [
                    'milk.totals.network' => ['view'],
                    'milk.reconciliation' => ['view'],
                    'milk.batch.dispatch' => ['view'],
                    'shop.revenue' => ['view'],
                    'community.farmers' => ['view'],
                    'community.cooperatives' => ['view'],
                    'purchase.requisitions' => ['view'],
                ],
            ],
            [
                'name' => 'External Audit',
                'description' => 'Time-boxed read-only access for an engagement',
                'scope_type' => ScopeType::Network,
                'status' => Role::STATUS_ACTIVE,
                'accent' => 'warning',
                'responsibilities' => [
                    'Reads everything an audit engagement needs, including the audit log',
                ],
                'restrictions' => [
                    'Writes nothing, anywhere',
                    'Access is expected to carry a valid_until so it ends with the engagement',
                ],
                /*
                 * Restriction: reads everything an audit needs and writes
                 * nothing. Assignments of this role are expected to carry a
                 * `valid_until`, so the access ends with the engagement rather
                 * than when somebody remembers to revoke it.
                 */
                'grants' => [
                    /*
                     * Phase 7 — farmer payment. Stated HERE as well as in
                     * migration 001600 because this catalogue rewrites
                     * permission_role on every seed: a grant that lives only
                     * in a migration is taken straight back off at the next
                     * db:seed. That is exactly how milk.deliveries.cutoff_override
                     * ended up sensitive, real, and held by nobody.
                     */
                    'finance.farmer_payments' => ['view'],
                    'admin.audit' => ['view'],
                    'admin.settings' => ['view'],
                    'milk.points' => ['view'],
                    'milk.deliveries' => ['view'],
                    'milk.consignment.confirm' => ['view'],
                    'milk.adjustment' => ['view'],
                    'milk.grade' => ['view'],
                    'milk.rejection' => ['view'],
                    'milk.batch.dispatch' => ['view'],
                    'milk.reconciliation' => ['view'],
                    'milk.totals.network' => ['view'],
                    'logistics.trips' => ['view'],
                    'logistics.payments' => ['view'],
                    'purchase.requisitions' => ['view'],
                    'community.farmers' => ['view'],
                    'community.cooperatives' => ['view'],
                    'community.coop.savings' => ['view'],
                    'community.extension' => ['view'],
                    'shop.inventory' => ['view'],
                    'shop.sales' => ['view'],
                    'shop.revenue' => ['view'],
                    'hr.employees' => ['view'],
                    'hr.payroll' => ['view'],
                ],
            ],
            [
                'name' => 'Staff (self-service)',
                'description' => 'Own leave requests and own payslips',
                'scope_type' => ScopeType::Own,
                'status' => Role::STATUS_ACTIVE,
                'accent' => 'info',
                'mobile_home' => 'self_service',
                'responsibilities' => [
                    'Requests their own leave and reads their own payslips',
                ],
                'restrictions' => [
                    'Own records only — nobody else’s leave, payslip or figures',
                ],
                // ROLE-3 — held automatically by every user.
                'is_automatic' => true,
                'grants' => [
                    'hr.leave.own' => ['view', 'create'],
                    'hr.payslip.own' => ['view'],
                ],
            ],

            /*
             * ROLE-4 — the two over-broad roles retired on 30 Jul 2026. Their
             * grants are seeded as they were, because the audit trail of the
             * clean-up refers to them. No user holds either.
             */
            [
                'name' => 'Administrator',
                'description' => 'Bundled HR, production, IT, logistics and accounting',
                'scope_type' => ScopeType::Network,
                'status' => Role::STATUS_RETIRED,
                'retired_at' => '2026-07-30 15:28:00',
                'accent' => 'muted',
                'grants' => ['*'],
            ],
            [
                'name' => 'HR / IT Admin',
                'description' => 'Split into HR Manager and System Administrator',
                'scope_type' => ScopeType::Network,
                'status' => Role::STATUS_RETIRED,
                'retired_at' => '2026-07-30 15:28:00',
                'accent' => 'muted',
                'grants' => [
                    'hr.employees' => '*',
                    'hr.leave' => '*',
                    'hr.payroll' => '*',
                    'admin.users' => '*',
                    'admin.roles' => '*',
                    'admin.settings' => '*',
                    'admin.audit' => ['view'],
                    'shop.inventory' => '*',
                    'shop.sales' => '*',
                    'purchase.requisitions' => '*',
                ],
            ],
        ];
    }

    public function run(): void
    {
        /** @var array<string, int> $permissionIds keyed by "resource.action" */
        $permissionIds = Permission::query()
            ->get()
            ->mapWithKeys(fn (Permission $permission) => [
                $permission->resource_key.'.'.$permission->action => $permission->getKey(),
            ])
            ->all();

        // PERM-3 — a retired permission is never granted to a live role, but the
        // retired roles keep theirs so the before/after grant sets in the audit
        // log still resolve.
        $liveKeys = Permission::query()->live()->get()
            ->map(fn (Permission $permission) => $permission->resource_key.'.'.$permission->action)
            ->all();

        foreach ($this->roles() as $definition) {
            $role = Role::query()->updateOrCreate(
                ['name' => $definition['name']],
                [
                    'description' => $definition['description'],
                    'scope_type' => $definition['scope_type']->value,
                    'status' => $definition['status'],
                    'retired_at' => $definition['retired_at'] ?? null,
                    'is_automatic' => $definition['is_automatic'] ?? false,
                    'accent' => $definition['accent'] ?? null,
                    /*
                     * §16 — the persona, stored with the grant set it describes.
                     *
                     * A retired role keeps its grants for the audit trail but is
                     * given no responsibilities: nobody holds it, so there is no
                     * job to describe, and a client that somehow saw one should
                     * show an empty list rather than a job that no longer exists.
                     *
                     * ROLE-5 — Farm Manager is left empty for the opposite
                     * reason: the business has not described the job yet.
                     */
                    'responsibilities' => $definition['responsibilities'] ?? null,
                    'restrictions' => $definition['restrictions'] ?? null,
                    'mobile_home' => $definition['mobile_home'] ?? null,
                ],
            );

            $keys = $this->resolveGrants($definition['grants'], $liveKeys);

            $ids = array_values(array_filter(array_map(
                static fn (string $key) => $permissionIds[$key] ?? null,
                $keys,
            )));

            DB::table('permission_role')->where('role_id', $role->getKey())->delete();

            foreach ($ids as $permissionId) {
                DB::table('permission_role')->insert([
                    'role_id' => $role->getKey(),
                    'permission_id' => $permissionId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    /**
     * Expands the shorthand: `['*']` means every live permission, and `'*'` on a
     * resource means every action that resource actually supports.
     *
     * @param  array<int|string, mixed>  $grants
     * @param  array<int, string>  $liveKeys
     * @return array<int, string>
     */
    private function resolveGrants(array $grants, array $liveKeys): array
    {
        if ($grants === ['*']) {
            return $liveKeys;
        }

        $keys = [];

        foreach ($grants as $resourceKey => $actions) {
            if ($actions === '*') {
                foreach ($liveKeys as $key) {
                    if (str_starts_with($key, $resourceKey.'.')
                        && substr_count($key, '.') === substr_count($resourceKey, '.') + 1) {
                        $keys[] = $key;
                    }
                }

                continue;
            }

            foreach ((array) $actions as $action) {
                $keys[] = $resourceKey.'.'.$action;
            }
        }

        return array_values(array_unique(array_intersect($keys, $liveKeys)));
    }
}
