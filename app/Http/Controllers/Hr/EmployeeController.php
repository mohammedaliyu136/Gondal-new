<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Employee;
use App\Services\Hr\EmployeeService;
use App\Services\Hr\LeaveService;
use App\Support\Wat;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * hrm.html and employee-detail.html.
 *
 * G-6 — gross salary is an hr.payroll figure. The Employee record is visible to
 * anyone with hr.employees.view, but the money is withheld unless they also hold
 * hr.payroll.view, which is exactly the boundary the HR persona describes.
 */
class EmployeeController extends Controller
{
    public function __construct(
        private readonly EmployeeService $employees,
        private readonly LeaveService $leave,
    ) {}

    public function index(Request $request): View
    {
        $employees = Employee::query()
            ->with(['department', 'lineManager'])
            ->when($request->filled('department'), fn ($query) => $query->where('department_id', $request->integer('department')))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('q'), fn ($query) => $query->where(function ($inner) use ($request) {
                $term = '%'.$request->string('q').'%';
                $inner->where('name', 'like', $term)->orWhere('code', 'like', $term);
            }))
            ->orderBy('name')
            ->paginate($this->perPage($request->integer('per_page') ?: null))
            ->withQueryString();

        $seesSalaries = $this->allows('hr.payroll.view');

        return view('hr.employees.index', [
            'employees' => $employees,
            'departments' => Department::query()->active()->orderBy('name')->get(),
            'seesSalaries' => $seesSalaries,
            /*
             * BR-35 — the same population the payroll run pays, computed the same
             * way. Without excludingTestData() this tile and the run's net total
             * disagreed by exactly the test-linked employees' salaries, and
             * neither screen said why.
             */
            'payrollTotalMinor' => $seesSalaries
                ? (int) Employee::query()->onPayroll()->excludingTestData()->sum('gross_monthly_minor')
                : null,
            'headcount' => Employee::query()->onPayroll()->excludingTestData()->count(),
            'confirmedCount' => Employee::query()->where('status', 'confirmed')->excludingTestData()->count(),
            'canCreate' => $this->allows('hr.employees.create'),
            'canEdit' => $this->allows('hr.employees.edit'),
            /*
             * For the line-manager picker; an identity list, not a browse.
             *
             * Filtered with the model's own scope rather than a hand-written
             * status. There is no "active" employee — the register's vocabulary is
             * probation|confirmed|on_leave|exited — so `where('status','active')`
             * silently matched none of the 42 and the picker rendered empty. Using
             * onPayroll() means the list follows the register's definition instead
             * of a string that has to be remembered correctly in every query.
             */
            'managers' => Employee::withoutDataScope()->onPayroll()
                ->orderBy('name')->get(['id', 'name', 'code']),
            'suggestedCode' => $this->nextCode(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeAccess('hr.employees.create', null, 'Add an employee');

        $validated = $request->validate($this->rules());

        $employee = $this->employees->create($validated, $this->currentUser());

        return redirect()->route('employees.show', $employee)
            ->with('success', $employee->name.' added to the register.');
    }

    public function update(Request $request, Employee $employee): RedirectResponse
    {
        $this->authorizeAccess('hr.employees.edit', $employee, 'Employee → '.$employee->name);

        $validated = $request->validate($this->rules($employee));

        $this->employees->update($employee, $validated, $this->currentUser());

        return back()->with('success', $employee->name.' updated.');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function rules(?Employee $employee = null): array
    {
        return [
            'code' => ['required', 'string', 'max:24', 'unique:employees,code'.($employee ? ','.$employee->getKey() : '')],
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'department_id' => ['nullable', 'exists:departments,id'],
            'position' => ['nullable', 'string', 'max:255'],
            'grade_level' => ['nullable', 'string', 'max:24'],
            'employment_type' => ['nullable', 'string', 'max:24'],
            'duty_station' => ['nullable', 'string', 'max:255'],
            'line_manager_id' => ['nullable', 'exists:employees,id'],
            'joined_on' => ['nullable', 'date'],
            'confirmed_on' => ['nullable', 'date'],
            // Naira in; the service converts to kobo (ARCH-6).
            'gross_monthly' => ['nullable', 'string'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            // Only the last four digits are stored — see EmployeeService.
            'bank_account' => ['nullable', 'string', 'max:32'],
            'next_of_kin_name' => ['nullable', 'string', 'max:255'],
            'next_of_kin_phone' => ['nullable', 'string', 'max:32'],
            'status' => ['nullable', 'in:probation,confirmed,on_leave,exited'],
        ];
    }

    /** A suggestion only — the officer can type their own. */
    private function nextCode(): string
    {
        $highest = (int) Employee::withoutDataScope()
            ->selectRaw("max(cast(replace(code, 'EMP-', '') as integer)) as n")
            ->value('n');

        return 'EMP-'.str_pad((string) ($highest + 1), 4, '0', STR_PAD_LEFT);
    }

    public function show(Employee $employee): View
    {
        $this->authorizeAccess('hr.employees.view', $employee, 'Employee → '.$employee->name);

        $seesSalaries = $this->allows('hr.payroll.view');

        return view('hr.employees.show', [
            'employee' => $employee->load(['department', 'lineManager', 'reports', 'user.roles']),
            'seesSalaries' => $seesSalaries,
            'canEdit' => $this->allows('hr.employees.edit', $employee),
            'departments' => Department::query()->active()->orderBy('name')->get(),
            /*
             * The picker excludes this employee: an employee cannot report to
             * themselves, and EmployeeService refuses it — offering the option
             * only to reject it wastes the officer's time.
             */
            'managers' => Employee::withoutDataScope()->onPayroll()
                ->whereKeyNot($employee->getKey())
                ->orderBy('name')->get(['id', 'name', 'code']),
            'leave' => $this->allows('hr.leave.view')
                ? $employee->leaveRequests()->with('leaveType')->limit(10)->get()
                : collect(),
            /*
             * The entitlement is annual, so a list of past requests does not tell
             * an HR officer whether the next one fits. LeaveService owns the
             * arithmetic — the screen must not re-derive a number the refusal
             * path is decided on.
             */
            'leaveBalances' => $this->allows('hr.leave.view')
                ? $this->leave->balancesFor($employee, (int) Wat::today()->format('Y'))
                : [],
            'leaveYear' => (int) Wat::today()->format('Y'),
            'payslips' => $seesSalaries
                ? $employee->payslips()->with('payrollRun')->limit(6)->get()
                : collect(),
        ]);
    }
}
