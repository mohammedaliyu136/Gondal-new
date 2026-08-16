<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\HrCompensationType;
use App\Models\LeaveType;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class HrSetupController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Display HR Setup Hub with modular tabs for Compensation, Loans, Commissions, Leave Types, and Organization.
     */
    public function index(Request $request): View
    {
        $this->authorizeAnyAccess(
            ['hr.setup.view', 'hr.employees.view', 'admin.settings.view', 'hr.payroll.view'],
            null,
            'HR Setup & Master Configuration'
        );

        $activeTab = $request->query('tab', 'loans');

        $types = HrCompensationType::query()
            ->orderBy('category')
            ->orderBy('name')
            ->get()
            ->groupBy('category');

        $leaveTypes = LeaveType::query()->orderBy('position')->orderBy('name')->get();
        $departments = Department::query()->withCount('employees')->orderBy('name')->get();

        $canEdit = $this->allowsAny(['hr.setup.edit', 'hr.setup.create', 'admin.settings.edit', 'hr.employees.create', 'hr.employees.edit']);

        return view('hr.setup.index', [
            'types' => $types,
            'leaveTypes' => $leaveTypes,
            'departments' => $departments,
            'activeTab' => $activeTab,
            'canEdit' => $canEdit,
        ]);
    }

    /**
     * Create a new compensation / loan scheme / commission milestone type.
     */
    public function store(Request $request): RedirectResponse
    {
        $this->authorizeAnyAccess(
            ['hr.setup.create', 'hr.setup.edit', 'admin.settings.edit', 'hr.employees.create'],
            null,
            'Create HR Compensation Type'
        );

        $validated = $request->validate([
            'category' => ['required', 'in:allowance,loan,commission,overtime,deduction'],
            'code' => ['required', 'string', 'max:32', 'unique:hr_compensation_types,code'],
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'is_taxable' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $type = HrCompensationType::query()->create([
            'category' => $validated['category'],
            'code' => strtoupper(trim($validated['code'])),
            'name' => trim($validated['name']),
            'description' => $validated['description'] ? trim($validated['description']) : null,
            'is_taxable' => $request->boolean('is_taxable', true),
            'is_active' => $request->boolean('is_active', true),
        ]);

        $this->audit->created(
            $type,
            sprintf('Created HR %s type: %s (%s)', $type->category, $type->name, $type->code),
            'Human Resources',
            [],
            $this->currentUser()
        );

        $tabMap = [
            'loan' => 'loans',
            'commission' => 'commissions',
            'allowance' => 'allowances',
            'overtime' => 'allowances',
            'deduction' => 'deductions',
        ];

        return redirect()->route('hr.setup.index', ['tab' => $tabMap[$type->category] ?? 'loans'])
            ->with('success', sprintf('HR %s type "%s" created successfully.', ucfirst($type->category), $type->name));
    }

    /**
     * Update an existing HR compensation / loan / commission type.
     */
    public function update(Request $request, HrCompensationType $type): RedirectResponse
    {
        $this->authorizeAnyAccess(
            ['hr.setup.edit', 'admin.settings.edit'],
            null,
            'Update HR Compensation Type'
        );

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'is_taxable' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $type->update([
            'name' => trim($validated['name']),
            'description' => $validated['description'] ? trim($validated['description']) : null,
            'is_taxable' => $request->boolean('is_taxable'),
            'is_active' => $request->boolean('is_active'),
        ]);

        $this->audit->edited(
            $type,
            sprintf('Updated HR %s type: %s (%s)', $type->category, $type->name, $type->code),
            'Human Resources',
            [],
            [],
            $this->currentUser()
        );

        $tabMap = [
            'loan' => 'loans',
            'commission' => 'commissions',
            'allowance' => 'allowances',
            'overtime' => 'allowances',
            'deduction' => 'deductions',
        ];

        return redirect()->route('hr.setup.index', ['tab' => $tabMap[$type->category] ?? 'loans'])
            ->with('success', sprintf('HR %s type "%s" updated successfully.', ucfirst($type->category), $type->name));
    }

    /**
     * Delete an unreferenced compensation type.
     */
    public function destroy(HrCompensationType $type): RedirectResponse
    {
        $this->authorizeAnyAccess(
            ['hr.setup.delete', 'hr.setup.edit', 'admin.settings.edit'],
            null,
            'Delete HR Compensation Type'
        );

        $category = $type->category;
        $name = $type->name;

        // Check if referenced by active loans
        if ($type->staffLoans()->exists()) {
            return redirect()->back()->with('error', "Cannot delete {$name} as it is referenced in employee staff loans.");
        }

        $type->delete();

        $this->audit->deleted(
            $type,
            sprintf('Deleted HR %s type: %s', $category, $name),
            'Human Resources',
            [],
            $this->currentUser()
        );

        $tabMap = [
            'loan' => 'loans',
            'commission' => 'commissions',
            'allowance' => 'allowances',
            'overtime' => 'allowances',
            'deduction' => 'deductions',
        ];

        return redirect()->route('hr.setup.index', ['tab' => $tabMap[$category] ?? 'loans'])
            ->with('success', sprintf('HR %s type "%s" deleted.', ucfirst($category), $name));
    }

    /**
     * Store a new Leave Type.
     */
    public function storeLeaveType(Request $request): RedirectResponse
    {
        $this->authorizeAnyAccess(
            ['hr.setup.create', 'hr.setup.edit', 'admin.settings.edit', 'hr.leave.create'],
            null,
            'Create Leave Type'
        );

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:32', 'unique:leave_types,code'],
            'name' => ['required', 'string', 'max:100'],
            'annual_entitlement_days' => ['required', 'integer', 'min:0', 'max:365'],
            'requires_document' => ['nullable', 'boolean'],
            'status' => ['required', 'in:active,inactive'],
        ]);

        $maxPos = LeaveType::query()->max('position') ?? 0;

        $leaveType = LeaveType::query()->create([
            'code' => strtoupper(trim($validated['code'])),
            'name' => trim($validated['name']),
            'annual_entitlement_days' => (int) $validated['annual_entitlement_days'],
            'requires_document' => $request->boolean('requires_document'),
            'status' => $validated['status'],
            'position' => $maxPos + 1,
        ]);

        $this->audit->created(
            $leaveType,
            sprintf('Created Leave Type: %s (%d days/yr)', $leaveType->name, $leaveType->annual_entitlement_days),
            'Human Resources',
            [],
            $this->currentUser()
        );

        return redirect()->route('hr.setup.index', ['tab' => 'leave_types'])
            ->with('success', sprintf('Leave Type "%s" created successfully.', $leaveType->name));
    }

    /**
     * Update an existing Leave Type.
     */
    public function updateLeaveType(Request $request, LeaveType $leaveType): RedirectResponse
    {
        $this->authorizeAnyAccess(
            ['hr.setup.edit', 'admin.settings.edit'],
            null,
            'Update Leave Type'
        );

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'annual_entitlement_days' => ['required', 'integer', 'min:0', 'max:365'],
            'requires_document' => ['nullable', 'boolean'],
            'status' => ['required', 'in:active,inactive'],
        ]);

        $leaveType->update([
            'name' => trim($validated['name']),
            'annual_entitlement_days' => (int) $validated['annual_entitlement_days'],
            'requires_document' => $request->boolean('requires_document'),
            'status' => $validated['status'],
        ]);

        $this->audit->edited(
            $leaveType,
            sprintf('Updated Leave Type: %s (%d days/yr)', $leaveType->name, $leaveType->annual_entitlement_days),
            'Human Resources',
            [],
            [],
            $this->currentUser()
        );

        return redirect()->route('hr.setup.index', ['tab' => 'leave_types'])
            ->with('success', sprintf('Leave Type "%s" updated.', $leaveType->name));
    }
}
