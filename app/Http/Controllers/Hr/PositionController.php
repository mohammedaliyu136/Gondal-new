<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Position;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * positions.html.
 *
 * §15.5 — recruitment applicants are a known gap, deliberately out of v1. This
 * screen is the vacancy register only, and says so.
 */
class PositionController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): View
    {
        return view('hr.positions.index', [
            'canManage' => $this->allows('hr.employees.edit'),
            'departments' => Department::query()->active()->orderBy('name')->get(['id', 'name']),
            'positions' => Position::query()
                ->with('department')
                ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
                ->orderByDesc('posted_on')
                ->paginate($this->perPage($request->integer('per_page') ?: null))
                ->withQueryString(),
            'openCount' => Position::query()->open()->count(),
            'openings' => (int) Position::query()->open()->sum('openings'),
        ]);
    }

    /** Same grant as departments — see DepartmentController::store. */
    public function store(Request $request): RedirectResponse
    {
        $this->authorizeAccess('hr.employees.create', null, 'Open a position');

        $validated = $request->validate($this->rules());

        $position = Position::query()->create($validated + ['status' => $validated['status'] ?? 'open']);

        $this->audit->created(
            $position,
            sprintf('Position "%s" opened — %d opening(s)', $position->title, (int) $position->openings),
            'Human Resources',
            ['department' => $position->department?->name, 'grade_level' => $position->grade_level],
            $this->currentUser(),
        );

        return back()->with('success', $position->title.' opened.');
    }

    public function update(Request $request, Position $position): RedirectResponse
    {
        $this->authorizeAccess('hr.employees.edit', $position, 'Position → '.$position->title);

        $validated = $request->validate($this->rules());

        $before = $position->only(['title', 'department_id', 'grade_level', 'openings', 'status']);

        $position->fill($validated)->save();

        $this->audit->edited(
            $position,
            'Position "'.$position->title.'" updated',
            'Human Resources',
            $before,
            $position->only(array_keys($before)),
            $this->currentUser(),
        );

        return back()->with('success', $position->title.' updated.');
    }

    /** @return array<string, array<int, mixed>> */
    private function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'department_id' => ['nullable', 'exists:departments,id'],
            'grade_level' => ['nullable', 'string', 'max:24'],
            'openings' => ['nullable', 'integer', 'min:1', 'max:999'],
            'posted_on' => ['nullable', 'date'],
            'closes_on' => ['nullable', 'date', 'after_or_equal:posted_on'],
            'status' => ['nullable', 'in:open,closed,filled'],
        ];
    }
}
