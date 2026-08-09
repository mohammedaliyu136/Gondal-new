<?php

namespace App\Http\Controllers\Community;

use App\Http\Controllers\Controller;
use App\Models\ActivityType;
use App\Models\Community;
use App\Models\ExtensionAgent;
use App\Models\Farmer;
use App\Models\FieldActivity;
use App\Models\QualityFollowup;
use App\Services\Audit\AuditLogger;
use App\Services\Milk\QualityFollowupService;
use App\Support\Sequences;
use App\Support\Wat;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * extension-activities.html.
 *
 * Phase 5 acceptance — "a third adulteration rejection within 30 days opens a
 * follow-up automatically and closing it requires a logged field activity."
 * The second half is enforced here: closing happens only as a side effect of
 * logging an activity, never as a standalone button.
 */
class FieldActivityController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly QualityFollowupService $followups,
    ) {}

    public function index(Request $request): View
    {
        $activities = FieldActivity::query()
            ->with(['extensionAgent.user', 'activityType', 'community', 'farmer', 'closesFollowup'])
            ->when($request->filled('agent'), fn ($query) => $query->where('extension_agent_id', $request->integer('agent')))
            ->when($request->filled('type'), fn ($query) => $query->where('activity_type_id', $request->integer('type')))
            ->when($request->filled('community'), fn ($query) => $query->where('community_id', $request->integer('community')))
            ->latest('activity_date')
            ->paginate($this->perPage($request->integer('per_page') ?: null))
            ->withQueryString();

        $month = Wat::today();

        return view('community.field-activities.index', [
            'activities' => $activities,
            'agents' => ExtensionAgent::query()->with('user')->active()->orderBy('code')->get(),
            'types' => ActivityType::query()->active()->orderBy('position')->get(),
            'communities' => Community::query()->with('lga')->orderBy('name')->get(),
            /*
             * The last of the capped pickers. `limit(500)` returned 500 of 1,842
             * active farmers ordered by name, so a visit to anyone after roughly
             * the letter D could not be logged against them at all — the
             * searchable select searches the HTML that was rendered and never
             * asks the server, so the missing farmers were not merely hard to
             * find, they were absent.
             *
             * NFR-2's "never return unbounded collections" is about lists a user
             * browses; this is the picker they record against, and the same
             * reasoning already removed the cap on the delivery and sale screens.
             * Only the three columns the <option> needs are hydrated, so the full
             * register costs less than the capped query did.
             */
            'farmers' => Farmer::query()->active()->orderBy('name')->get(['id', 'name', 'code']),
            // BR-5 — the automatic follow-ups waiting for an activity to close them.
            'openFollowups' => QualityFollowup::query()
                ->open()
                ->with(['subject', 'rejectionReason'])
                ->latest('opened_at')
                ->get(),
            'monthCount' => FieldActivity::query()
                ->excludingTestData()
                ->inMonth((int) $month->format('Y'), (int) $month->format('n'))
                ->count(),
            'monthReached' => (int) FieldActivity::query()
                ->excludingTestData()
                ->inMonth((int) $month->format('Y'), (int) $month->format('n'))
                ->sum('farmers_reached'),
            'canLog' => $this->allows('community.extension.create'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'extension_agent_id' => ['required', 'exists:extension_agents,id'],
            'activity_type_id' => ['required', 'exists:activity_types,id'],
            'community_id' => ['required', 'exists:communities,id'],
            'farmer_id' => ['nullable', 'exists:farmers,id'],
            'activity_date' => ['required', 'date'],
            'farmers_reached' => ['nullable', 'integer', 'min:0'],
            'topic' => ['nullable', 'string', 'max:255'],
            'findings' => ['nullable', 'string', 'max:4000'],
            // Phase 5 acceptance — closing a follow-up rides on the activity.
            'closes_followup_id' => ['nullable', 'exists:quality_followups,id'],
        ]);

        $agent = ExtensionAgent::query()->findOrFail($validated['extension_agent_id']);

        $this->authorizeAccess('community.extension.create', $agent, 'Log an activity for '.($agent->user?->name ?? $agent->code));

        /*
         * ARCH-4 layer 2, second subject. Authorising the AGENT alone left
         * community_id validated only by `exists`, so a visit — and the
         * farmers-reached figure that feeds programme reporting — could be logged
         * against any community in the network.
         */
        $community = Community::query()->findOrFail($validated['community_id']);

        $this->authorizeAccess('community.extension.create', $community, 'Log an activity in '.$community->name);

        $activity = DB::transaction(function () use ($validated): FieldActivity {
            return FieldActivity::query()->create([
                'reference' => Sequences::next('field_activities'),
                'extension_agent_id' => $validated['extension_agent_id'],
                'activity_type_id' => $validated['activity_type_id'],
                'community_id' => $validated['community_id'],
                'farmer_id' => $validated['farmer_id'] ?? null,
                'activity_date' => $validated['activity_date'],
                'farmers_reached' => $validated['farmers_reached'] ?? 0,
                'topic' => $validated['topic'] ?? null,
                'findings' => $validated['findings'] ?? null,
                'closes_followup_id' => $validated['closes_followup_id'] ?? null,
                // ARCH-2 — recorded through the web today, the API tomorrow.
                'source' => 'web',
                'synced_at' => Wat::now(),
            ]);
        });

        $this->audit->created(
            $activity,
            sprintf(
                '%s logged — %s in %s, %d farmers reached',
                $activity->reference,
                $activity->activityType?->name ?? 'activity',
                $activity->community?->name ?? 'community',
                (int) $activity->farmers_reached,
            ),
            'Community Engagement',
            ['topic' => $activity->topic],
            $this->currentUser(),
        );

        // Phase 5 acceptance — the close is validated by the service (BR-5), which
        // refuses an activity type the administrator has not allowed to close one.
        if ($activity->closes_followup_id !== null) {
            $followup = QualityFollowup::query()->findOrFail($activity->closes_followup_id);

            $this->followups->close($followup, $activity, $this->currentUser());

            return back()->with('success', sprintf(
                '%s logged, and the %s follow-up is now closed.',
                $activity->reference,
                $followup->rejectionReason?->name ?? 'quality',
            ));
        }

        return back()->with('success', $activity->reference.' logged.');
    }
}
