<?php

namespace App\Http\Controllers\Community;

use App\Http\Controllers\Controller;
use App\Models\Community;
use App\Models\ExtensionAgent;
use App\Models\FieldActivity;
use App\Models\User;
use App\Services\Community\ExtensionAgentService;
use App\Support\Wat;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\View\View;

/**
 * extension.html and extension-agent-detail.html.
 *
 * SCOPE-1 — the Extension Agent role is `communities`-scoped, so an agent with
 * no agent_community rows sees nothing at all. The register was read-only:
 * community.extension.create and .edit were granted, surfaced to AgentConnect as
 * `can_manage_extension_agents`, and enforced on no route — a newly hired agent
 * could only be given their communities by a database insert.
 */
class ExtensionAgentController extends Controller
{
    public function __construct(private readonly ExtensionAgentService $agents) {}

    public function index(Request $request): View
    {
        $agents = ExtensionAgent::query()
            ->with(['user.department', 'communities', 'reportsTo'])
            ->withCount('communities')
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('q'), function ($query) use ($request): void {
                $term = '%'.$request->string('q').'%';

                $query->where(fn ($inner) => $inner
                    ->where('code', 'like', $term)
                    ->orWhereHas('user', fn ($user) => $user->where('name', 'like', $term)));
            })
            ->orderBy('code')
            ->paginate($this->perPage($request->integer('per_page') ?: null))
            ->withQueryString();

        $month = Wat::today();

        // Progress against each agent's own monthly target (§6.6).
        $activityCounts = FieldActivity::query()
            ->excludingTestData()
            ->inMonth((int) $month->format('Y'), (int) $month->format('n'))
            ->selectRaw('extension_agent_id, count(*) as activities, sum(farmers_reached) as reached')
            ->groupBy('extension_agent_id')
            ->get()
            ->keyBy('extension_agent_id');

        return view('community.extension-agents.index', [
            'agents' => $agents,
            'activityCounts' => $activityCounts,
            'month' => $month,
            /*
             * Route::has because the write routes are registered separately from
             * this screen; without the guard the page 500s while the two halves
             * are out of step, which is a worse failure than a missing button.
             */
            'canCreate' => $this->allows('community.extension.create') && Route::has('extension-agents.store'),
            'communities' => Community::query()->with('lga')->orderBy('name')->get(),
            // USER-1 — an agent IS staff, so the record attaches to an account
            // that already exists rather than creating one (BR-31).
            'candidates' => User::query()->where('status', 'active')
                ->whereDoesntHave('extensionAgentRecords')
                ->orderBy('name')->get(['id', 'name', 'email']),
            'supervisors' => User::query()->where('status', 'active')
                ->orderBy('name')->get(['id', 'name', 'email']),
            'suggestedCode' => $this->nextCode(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeAccess('community.extension.create', null, 'Add an extension agent');

        $validated = $request->validate($this->rules());

        $agent = $this->agents->create($validated, $this->currentUser());

        return redirect()->route('extension-agents.show', $agent)
            ->with('success', ($agent->user?->name ?? $agent->code).' added to the extension register.');
    }

    /**
     * Also the community-assignment control: coverage IS the agent's scope, so
     * assigning communities and editing the record are one authority, not two.
     */
    public function update(Request $request, ExtensionAgent $agent): RedirectResponse
    {
        $this->authorizeAccess('community.extension.edit', $agent, 'Extension agent → '.($agent->user?->name ?? $agent->code));

        $validated = $request->validate($this->rules($agent));

        $this->agents->update($agent, $validated, $this->currentUser());

        return back()->with('success', ($agent->user?->name ?? $agent->code).' updated.');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function rules(?ExtensionAgent $agent = null): array
    {
        return [
            'user_id' => [$agent === null ? 'required' : 'nullable', 'exists:users,id'],
            'code' => ['required', 'string', 'max:24', 'unique:extension_agents,code'.($agent ? ','.$agent->getKey() : '')],
            'reports_to_user_id' => ['nullable', 'exists:users,id'],
            'visit_target_monthly' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'enrolment_target_monthly' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'status' => ['nullable', 'in:active,inactive'],
            'community_ids' => ['sometimes', 'array'],
            'community_ids.*' => ['integer', 'exists:communities,id'],
        ];
    }

    /** A suggestion only — the officer can type their own. */
    private function nextCode(): string
    {
        $highest = (int) ExtensionAgent::withoutDataScope()
            ->selectRaw("max(cast(replace(code, 'EXT-', '') as integer)) as n")
            ->value('n');

        return 'EXT-'.str_pad((string) ($highest + 1), 3, '0', STR_PAD_LEFT);
    }

    public function show(ExtensionAgent $agent): View
    {
        $this->authorizeAccess('community.extension.view', $agent, 'Extension agent → '.($agent->user?->name ?? $agent->code));

        $month = Wat::today();

        return view('community.extension-agents.show', [
            'agent' => $agent->load(['user.department', 'communities.lga', 'reportsTo']),
            'activities' => $agent->fieldActivities()
                ->with(['activityType', 'community', 'farmer', 'closesFollowup.rejectionReason'])
                ->latest('activity_date')
                ->limit(25)
                ->get(),
            'monthActivities' => $agent->fieldActivities()
                ->excludingTestData()
                ->inMonth((int) $month->format('Y'), (int) $month->format('n'))
                ->count(),
            'monthReached' => (int) $agent->fieldActivities()
                ->excludingTestData()
                ->inMonth((int) $month->format('Y'), (int) $month->format('n'))
                ->sum('farmers_reached'),
            'month' => $month,
            // See index() for why Route::has is consulted here.
            'canEdit' => $this->allows('community.extension.edit', $agent) && Route::has('extension-agents.update'),
            'communities' => Community::query()->with('lga')->orderBy('name')->get(),
            'supervisors' => User::query()->where('status', 'active')->orderBy('name')->get(['id', 'name', 'email']),
        ]);
    }
}
