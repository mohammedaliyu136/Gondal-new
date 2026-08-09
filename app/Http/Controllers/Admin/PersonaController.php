<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Farmer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\View\View;

/**
 * personas.html.
 *
 * §16 — "personas.html is the authoritative persona reference: 14 personas across
 * 19 roles, each with its responsibilities, landing screen, data scope and key
 * restriction. Implementers should read it before Phase 1 — it explains WHY each
 * permission boundary exists, which the permission matrix alone does not."
 *
 * The screen is therefore built from real roles and real assignment counts, with
 * the narrative (responsibilities, landing screen, restriction) held here because
 * it is documentation of intent rather than operational data. Nothing on this
 * screen grants or denies anything; it explains what the grants mean.
 */
class PersonaController extends Controller
{
    /**
     * @return array<int, array<string, mixed>>
     */
    private function personas(): array
    {
        return [
            [
                'group' => 'Milk Collection',
                'name' => 'Collection Agent',
                'role' => 'Collection Agent',
                'scope' => 'One point',
                'lands_on' => 'Milk Flow',
                'day' => [
                    'Meets farmers at the point from 05:30 and records each delivery in litres',
                    'Runs the lactometer check and rejects milk using the configured reasons only',
                    'Dispatches the accepted volume to the center as one consignment',
                ],
                'restriction' => 'No network totals, no payments',
                'cannot' => [
                    "Any other point's deliveries or the network total",
                    'Farmer payment amounts, payroll, or shop revenue',
                ],
            ],
            [
                'group' => 'Milk Collection',
                'name' => 'Milk Collection Officer',
                'role' => 'Milk Collection Officer',
                'scope' => 'One center',
                'lands_on' => 'My Center',
                'day' => [
                    'Confirms the litre count every agent reported, and records adjustments with a reason',
                    'Runs quality tests and assigns a grade — this sets what farmers are paid',
                    "Combines the day's consignments into one batch and hands it to logistics",
                ],
                'restriction' => 'No other center, no payroll',
                'cannot' => [
                    "Other centers' consignments, or the network production total",
                    'Payroll, transport payments, or shop figures',
                ],
            ],
            [
                'group' => 'Milk Collection',
                'name' => 'Logistics Officer',
                'role' => 'Logistics Officer',
                'scope' => 'Own routes',
                'lands_on' => 'Logistics',
                'day' => [
                    'Logs which rider or vehicle moved each consignment and batch',
                    'Records the route fee so the transport payment run can pay riders',
                    'Raises requisitions for diesel, servicing and spares',
                ],
                'restriction' => 'Cannot grade or reject milk',
                'cannot' => [
                    'Quality grades or rejection decisions — not their call',
                    'Approve their own requisition at any stage',
                ],
            ],
            [
                'group' => 'Milk Collection',
                'name' => 'Milk Collection Supervisor',
                'role' => 'Milk Collection Supervisor',
                'scope' => 'All centers',
                'lands_on' => 'Reconciliation',
                'day' => [
                    'Reviews every point and center, and chases anything flagged',
                    'Reconciles each batch at the factory — records discrepancies and any final rejection',
                    'Releases reconciled volume to production and payment',
                ],
                'restriction' => 'No payroll or shop revenue',
                'cannot' => [
                    'Payroll and employee salary records',
                    'Shop revenue totals',
                ],
            ],
            [
                'group' => 'Community Engagement',
                'name' => 'Extension Agent',
                'role' => 'Extension Agent',
                'scope' => 'Own communities',
                'lands_on' => 'Field Activities',
                'day' => [
                    'Visits households, enrols new farmers and records herd details',
                    'Runs training sessions on clean milk production and feed',
                    'Closes quality follow-ups raised automatically by repeat rejections',
                ],
                'restriction' => 'No volumes or payment figures',
                'cannot' => [
                    'Milk volumes or payment amounts for the farmers they visit',
                    "Other agents' communities, cooperative savings, or any financial screen",
                ],
            ],
            [
                'group' => 'Community Engagement',
                'name' => 'Community Engagement Officer',
                'role' => 'Community Engagement Officer',
                'scope' => 'All communities',
                'lands_on' => 'Extension Agents',
                'day' => [
                    'Assigns communities to agents and tracks visit and enrolment targets',
                    'Maintains farmer and cooperative records, including savings and social fund entries',
                    'Escalates agents who fall behind, and plans the training calendar',
                ],
                'restriction' => 'No approval authority',
                'cannot' => [
                    'Payroll, or approve spend at any requisition stage',
                    'Milk grading decisions at the centers',
                ],
            ],
            [
                'group' => 'Community Engagement',
                'name' => 'Delivery Lead',
                'role' => 'Delivery Lead',
                'scope' => 'All communities',
                'lands_on' => 'Dashboard',
                'day' => ['Heads community engagement delivery'],
                'restriction' => 'No payroll',
                'cannot' => ['Payroll and salary records'],
            ],
            [
                'group' => 'One-Stop Shop',
                'name' => 'One-Stop Shop Manager',
                'role' => 'One-Stop Shop Manager',
                'scope' => 'Shop',
                'lands_on' => 'Inventory',
                'day' => [
                    'Creates and retires product categories without a system change',
                    'Watches stock levels and raises restock requisitions',
                    'Reviews daily sales, credit exposure per cooperative, and margins',
                ],
                'restriction' => 'No milk or payroll data',
                'cannot' => [
                    'Milk production volumes or farmer payments',
                    'Payroll or employee records',
                ],
            ],
            [
                'group' => 'One-Stop Shop',
                'name' => 'Sales Officer',
                'role' => 'Sales Officer',
                'scope' => 'Own sales',
                'lands_on' => 'Sales',
                'day' => ['Records each sale, takes payment or books it to a cooperative\'s credit'],
                'restriction' => 'No revenue totals',
                'cannot' => [
                    'Daily or monthly revenue totals — only their own transactions',
                    'Total milk production litres',
                ],
            ],
            [
                'group' => 'One-Stop Shop',
                'name' => 'Inventory Officer',
                'role' => 'Inventory Officer',
                'scope' => 'Stock',
                'lands_on' => 'Inventory',
                'day' => ['Counts stock, receives deliveries, flags low items'],
                'restriction' => 'Quantities only — no financial values',
                'cannot' => ['Stock values at cost or margin'],
            ],
            [
                'group' => 'Approvers & Administration',
                'name' => 'Department Head',
                'role' => 'Department Head',
                'scope' => 'Own department',
                'lands_on' => 'My Approvals',
                'day' => ['First approval for their department at stage 2'],
                'restriction' => "Only their own department's requests",
                'cannot' => ['Other departments\' requisitions'],
            ],
            [
                'group' => 'Approvers & Administration',
                'name' => 'Internal Audit',
                'role' => 'Internal Audit',
                'scope' => 'Network',
                'lands_on' => 'My Approvals',
                'day' => [
                    'Works an approval queue: verifies quotations, budget lines and that quantities match usage',
                    'Approves, reduces the amount, asks for more information, or rejects with a reason',
                    'Reviews the audit log for permission changes and blocked access attempts',
                ],
                'restriction' => 'Reads data, does not edit it',
                'cannot' => [
                    'Edit operational records — audit reads, it does not change data',
                    'Approve at any stage other than Internal Audit',
                ],
            ],
            [
                'group' => 'Approvers & Administration',
                'name' => 'Accounts',
                'role' => 'Accounts',
                'scope' => 'All financial',
                'lands_on' => 'Payroll',
                'day' => [
                    'Confirms funds and raises payment once a requisition clears the ED',
                    'Runs payroll, and settles transport and farmer payments',
                    'Reconciles cooperative credit against milk payment deductions',
                ],
                'restriction' => 'Cannot change grades or litre counts',
                'cannot' => [
                    'Change a quality grade or a confirmed litre count',
                    'Create users or edit roles',
                ],
            ],
            [
                'group' => 'Approvers & Administration',
                'name' => 'Executive Director / General Manager',
                'role' => 'Executive Director',
                'scope' => 'Network',
                'lands_on' => 'Dashboard',
                'day' => [
                    'Authorise major spend — anything above the band threshold routes through both',
                    'Review network production, quality and rejection trends',
                    'Read every module, but operate none of them day to day',
                ],
                'restriction' => 'Cannot raise and approve the same request',
                'cannot' => ['Approve their own submission at any stage'],
            ],
            [
                'group' => 'Approvers & Administration',
                'name' => 'HR Manager',
                'role' => 'HR Manager',
                'scope' => 'All employees',
                'lands_on' => 'Employees',
                'day' => ['Employee records, leave, positions and payroll'],
                'restriction' => 'No operational milk data',
                'cannot' => ['Milk collection, grading or logistics screens'],
            ],
            [
                'group' => 'Approvers & Administration',
                'name' => 'System Administrator',
                'role' => 'System Administrator',
                'scope' => 'All',
                'lands_on' => 'Permission Testing',
                'day' => [
                    'Creates users, edits roles, maintains settings',
                    'Tests every change with a test user before it reaches live staff',
                ],
                'restriction' => 'Never sees user passwords',
                'cannot' => [
                    "See or set any user's password",
                    'Skip the audit log — every action is recorded',
                ],
            ],
        ];
    }

    public function __invoke(): View
    {
        $roles = Role::query()->withCount('users')->get()->keyBy('name');

        return view('admin.personas', [
            'personas' => collect($this->personas())
                ->map(function (array $persona) use ($roles) {
                    $role = $roles->get($persona['role']);

                    return array_merge($persona, [
                        'role_model' => $role,
                        'user_count' => (int) ($role->users_count ?? 0),
                        'permission_count' => $role === null ? 0 : $role->livePermissions()->count(),
                    ]);
                })
                ->groupBy('group'),
            'roles' => $roles,
            'counts' => [
                'personas' => count($this->personas()),
                'roles' => Role::query()->where('status', '!=', Role::STATUS_RETIRED)->count(),
                'users' => User::query()->where('status', 'active')->count(),
                'farmers' => Farmer::query()->count(),
                'undefined' => Role::query()->where('status', Role::STATUS_DRAFT)->count(),
                'permissions' => Permission::query()->live()->count(),
            ],
        ]);
    }
}
