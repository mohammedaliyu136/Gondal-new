<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Services\Hr\LeaveService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * leave.html.
 *
 * §4 — reachable with hr.leave.view OR hr.leave.own.view.
 * ROLE-3 — every user holds hr.leave.own, so this screen is the one thing every
 *   member of staff can always reach. The `own` scope on the LeaveRequest model
 *   is what makes "own" mean own.
 */
class LeaveController extends Controller
{
    public function __construct(private readonly LeaveService $leave) {}

    public function index(Request $request): View
    {
        $seesAll = $this->allows('hr.leave.view');

        $requests = LeaveRequest::query()
            ->with(['employee.department', 'leaveType', 'workflowInstance.currentStage.approvingRole'])
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->latest('starts_on')
            ->paginate($this->perPage($request->integer('per_page') ?: null))
            ->withQueryString();

        $employee = $this->currentUser()?->employee;

        return view('hr.leave.index', [
            'requests' => $requests,
            'seesAll' => $seesAll,
            'employee' => $employee,
            'leaveTypes' => LeaveType::query()->active()->orderBy('position')->get(),
            'employees' => $seesAll
                ? Employee::query()->onPayroll()->orderBy('name')->get()
                : collect(array_filter([$employee])),
            'awaitingCount' => LeaveRequest::query()->awaitingDecision()->count(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'employee_id' => ['nullable', 'exists:employees,id'],
            'leave_type_id' => ['required', 'exists:leave_types,id'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'submit' => ['nullable', 'boolean'],
        ]);

        $user = $this->currentUser();

        // Raising a request for SOMEBODY ELSE needs hr.leave.create; raising your
        // own needs only the automatic hr.leave.own.create (ROLE-3).
        $employeeId = $validated['employee_id'] ?? $user?->employee_id;

        if ($employeeId === null) {
            return back()->withErrors([
                'employee_id' => 'Your account is not linked to an employee record. Ask HR to link it.',
            ])->withInput();
        }

        /*
         * Resolved WITHOUT the employee data scope, for the same reason route
         * binding is (see AppliesDataScope): this is an identity lookup, not a
         * browse. A member of staff holds only the automatic Staff role (ROLE-3),
         * which grants hr.leave.own but not hr.employees.view — so a scoped lookup
         * would hide their own employee record and turn "raise my own leave" into a
         * 404. Raising leave for SOMEBODY ELSE is a different question, and the
         * authorize() call below asks it properly, with the record in hand.
         */
        $employee = Employee::withoutDataScope()->findOrFail($employeeId);

        if ((int) $employeeId !== (int) $user?->employee_id) {
            $this->authorizeAccess('hr.leave.create', $employee, 'Raise leave for '.$employee->name);
        }

        $leaveRequest = $this->leave->create($employee, $validated, $user);

        if ($request->boolean('submit')) {
            $this->leave->submit($leaveRequest, $user);

            return back()->with('success', 'Leave request submitted for approval.');
        }

        return back()->with('success', 'Leave request saved as a draft.');
    }

    public function submit(LeaveRequest $leaveRequest): RedirectResponse
    {
        $user = $this->currentUser();

        // Layer 2 — `own` scope means your own record only.
        $this->authorizeAnyAccess(
            ['hr.leave.create', 'hr.leave.own.create'],
            $leaveRequest,
            'Submit leave request',
        );

        $this->leave->submit($leaveRequest, $user);

        return back()->with('success', 'Leave request submitted for approval.');
    }
}
