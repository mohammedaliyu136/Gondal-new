<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * departments.html.
 *
 * SCOPE-1 — a department is a scope target: the Department Head role is assigned
 * with `department` scope so their approval queue is their own department's.
 */
class DepartmentController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): View
    {
        return view('hr.departments.index', [
            'canManage' => $this->allows('hr.employees.edit'),
            'heads' => User::query()->where('status', 'active')->orderBy('name')->get(['id', 'name', 'email']),
            'departments' => Department::query()
                ->with('head')
                ->withCount(['employees', 'requisitions'])
                ->orderBy('name')
                ->paginate($this->perPage($request->integer('per_page') ?: null)),
        ]);
    }

    /*
     * Gated on hr.employees.* rather than a resource of its own: the permission
     * catalogue already describes hr.employees as "Staff records and departments",
     * so this is the grant it was always meant to carry. If the Phase B reshape
     * wants departments held separately from staff records, it can split them.
     */
    public function store(Request $request): RedirectResponse
    {
        $this->authorizeAccess('hr.employees.create', null, 'Add a department');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:departments,name'],
            'cost_centre' => ['nullable', 'string', 'max:32'],
            'head_user_id' => ['nullable', 'exists:users,id'],
            'status' => ['nullable', 'in:active,inactive'],
        ]);

        $department = Department::query()->create($validated + ['status' => $validated['status'] ?? 'active']);

        $this->audit->created(
            $department,
            sprintf('Department "%s" created%s', $department->name,
                $department->head?->name ? ' — headed by '.$department->head->name : ''),
            'Human Resources',
            ['cost_centre' => $department->cost_centre],
            $this->currentUser(),
        );

        return back()->with('success', $department->name.' added.');
    }

    public function update(Request $request, Department $department): RedirectResponse
    {
        $this->authorizeAccess('hr.employees.edit', $department, 'Department → '.$department->name);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:departments,name,'.$department->getKey()],
            'cost_centre' => ['nullable', 'string', 'max:32'],
            'head_user_id' => ['nullable', 'exists:users,id'],
            'status' => ['nullable', 'in:active,inactive'],
        ]);

        $before = $department->only(['name', 'cost_centre', 'head_user_id', 'status']);

        $department->fill($validated)->save();

        $this->audit->edited(
            $department,
            'Department "'.$department->name.'" updated',
            'Human Resources',
            $before,
            $department->only(array_keys($before)),
            $this->currentUser(),
        );

        return back()->with('success', $department->name.' updated.');
    }
}
