<?php

namespace App\Http\Controllers\Admin;

use App\Authorization\ScopeType;
use App\Http\Controllers\Controller;
use App\Models\CollectionCenter;
use App\Models\PermissionTestRun;
use App\Models\Role;
use App\Models\User;
use App\Services\PermissionTesting\PermissionTestRunner;
use App\Support\Navigation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * permission-test.html.
 *
 * TEST-3 — "Test runs may target the development or staging environment only.
 *   PRODUCTION MUST NOT BE OFFERABLE in the environment selector." The selector
 *   is fed from config('gondal.permission_test_environments'), and the runner
 *   re-checks it because the API can reach the same action.
 */
class PermissionTestController extends Controller
{
    public function __construct(private readonly PermissionTestRunner $runner) {}

    public function index(Request $request): View
    {
        $current = PermissionTestRun::query()
            ->with(['role', 'testUser', 'runBy', 'checks'])
            ->latest('id')
            ->first();

        return view('admin.permission-tests.index', [
            'current' => $current,
            'checks' => $current === null
                ? collect()
                : $current->checks()->paginate($this->perPage($request->integer('per_page') ?: null))->withQueryString(),
            'checksByModule' => $current === null
                ? collect()
                : $current->checks()->get()->groupBy('module'),
            'runs' => PermissionTestRun::query()
                ->with(['role', 'testUser', 'runBy'])
                ->latest('id')
                ->limit(10)
                ->get(),
            'roles' => Role::query()->assignable()->orderBy('name')->get(),
            // TEST-1 — only accounts flagged as test users may be targeted.
            'testUsers' => User::query()->where('is_test', true)->orderBy('name')->get(),
            // TEST-3
            'environments' => (array) config('gondal.permission_test_environments', ['development', 'staging']),
            'scopeTypes' => ScopeType::cases(),
            'centers' => CollectionCenter::withoutDataScope()->orderBy('name')->get(),
            'testUserCount' => User::query()->where('is_test', true)->count(),
            // SCR-2 — the nav the test user would see, rendered from their grants.
            'navigationPreview' => $current === null
                ? []
                : Navigation::forUser($current->testUser),
            'allNavigation' => Navigation::definition(),
        ]);
    }

    /**
     * TEST-3 — "production must not be offerable".
     *
     * The submitted `environment` is a LABEL the operator picked from a dropdown,
     * not a fact about the machine. Validating it against the allowed list proves
     * only that somebody chose "staging"; on a production host that choice was
     * still true of nothing, and the run went ahead against the real database,
     * hard-deleting the target's real role assignments and granting them the role
     * under test. The one thing the application never asked was where it was
     * actually running.
     */
    private function guardNotProduction(): void
    {
        abort_if(
            app()->environment('production'),
            403,
            'TEST-3 — permission test runs alter real role assignments, so they may not be started on '
            .'the production host. Run this against development or staging.',
        );
    }

    public function store(Request $request): RedirectResponse
    {
        $this->guardNotProduction();

        $allowed = (array) config('gondal.permission_test_environments', ['development', 'staging']);

        $validated = $request->validate([
            'role_id' => ['required', 'exists:roles,id'],
            'test_user_id' => ['required', 'exists:users,id'],
            'scope_type' => ['required', 'in:'.implode(',', ScopeType::values())],
            'scope_target_id' => ['nullable', 'integer'],
            // TEST-3 — production is not in the list, so it cannot be submitted.
            'environment' => ['required', 'in:'.implode(',', $allowed)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [
            'environment.in' => 'A test run may target the development or staging environment only.',
        ]);

        $run = $this->runner->start(
            Role::query()->findOrFail($validated['role_id']),
            User::query()->findOrFail($validated['test_user_id']),
            $validated,
            $this->currentUser(),
        );

        return redirect()->route('admin.permission-tests.index')->with(
            'success',
            $run->reference.' started. Run the checks to compare expected access with actual.',
        );
    }

    public function run(PermissionTestRun $run): RedirectResponse
    {
        $run = $this->runner->execute($run, $this->currentUser());

        return back()->with(
            $run->failed_count === 0 ? 'success' : 'warning',
            sprintf(
                '%s: %d passed, %d failed.%s',
                $run->reference,
                (int) $run->passed_count,
                (int) $run->failed_count,
                $run->failed_count === 0
                    ? ' The configuration can be approved for live use.'
                    : ' Resolve the failures before this reaches live staff.',
            ),
        );
    }

    /** TEST-5 */
    public function complete(PermissionTestRun $run): RedirectResponse
    {
        $this->runner->approveForLive($run, $this->currentUser());

        return back()->with('success', sprintf(
            '%s approved for live — %s is validated.',
            $run->reference,
            $run->role->name,
        ));
    }
}
