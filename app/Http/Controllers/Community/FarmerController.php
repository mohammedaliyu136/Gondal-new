<?php

namespace App\Http\Controllers\Community;

use App\Authorization\ScopeType;
use App\Http\Controllers\Controller;
use App\Models\CollectionPoint;
use App\Models\Community;
use App\Models\Cooperative;
use App\Models\Delivery;
use App\Models\Farmer;
use App\Models\Lga;
use App\Services\Audit\AuditLogger;
use App\Services\Milk\QualityFollowupService;
use App\Support\Volume;
use App\Support\Wat;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * farmers.html and farmer-detail.html.
 *
 * USER-1 / USER-2 — a farmer is a record. There is no invite, no portal link and
 * no credential field anywhere on these screens.
 *
 * The persona note matters here: an Extension Agent holds community.farmers but
 * NOT milk.deliveries, so the delivery history section is permission-gated rather
 * than assumed.
 */
class FarmerController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly QualityFollowupService $followups,
    ) {}

    public function index(Request $request): View
    {
        $farmers = Farmer::query()
            ->with(['community.lga', 'cooperative', 'defaultCollectionPoint'])
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('lga'), fn ($query) => $query->where('lga_id', $request->integer('lga')))
            ->when($request->filled('cooperative'), fn ($query) => $query->where('cooperative_id', $request->integer('cooperative')))
            ->when($request->filled('q'), fn ($query) => $query->where(function ($inner) use ($request) {
                $term = '%'.$request->string('q').'%';
                $inner->where('name', 'like', $term)
                    ->orWhere('code', 'like', $term)
                    ->orWhere('phone', 'like', $term);
            }))
            ->orderBy('name')
            ->paginate($this->perPage($request->integer('per_page') ?: null))
            ->withQueryString();

        return view('community.farmers.index', [
            'farmers' => $farmers,
            'lgas' => Lga::query()->orderBy('name')->get(),
            'communities' => Community::query()->with('lga')->orderBy('name')->get(),
            'cooperatives' => Cooperative::query()->orderBy('name')->get(),
            'points' => CollectionPoint::query()->orderBy('name')->get(),
            'activeCount' => Farmer::query()->active()->count(),
            'canCreate' => $this->allows('community.farmers.create'),
        ]);
    }

    public function show(Farmer $farmer): View
    {
        $this->authorizeAccess('community.farmers.view', $farmer, 'Farmer → '.$farmer->name);

        // The persona boundary: an Extension Agent sees the farmer but not their
        // volumes (§16 — "No volumes or payment figures").
        $seesVolumes = $this->allows('milk.deliveries.view');

        $deliveries = $seesVolumes
            ? $farmer->deliveries()
                ->with(['collectionPoint', 'rejectionReason', 'consignment.grade'])
                ->latest('delivered_at')
                ->limit(25)
                ->get()
            : collect();

        return view('community.farmers.show', [
            'farmer' => $farmer->load(['community.lga', 'cooperative', 'defaultCollectionPoint.collectionCenter', 'enrolledBy']),
            'seesVolumes' => $seesVolumes,
            'deliveries' => $deliveries,
            'thirtyDayLitres' => $seesVolumes
                ? Volume::fromCentilitres((int) round(100 * (float) Delivery::query()
                    ->excludingTestData()
                    ->where('farmer_id', $farmer->getKey())
                    ->where('delivered_at', '>=', Wat::now()->subDays(30))
                    ->sum('litres_accepted')))
                : null,
            'openFollowups' => $this->followups->openFor($farmer),
            'activities' => $farmer->fieldActivities()->with(['activityType', 'extensionAgent.user'])->latest('activity_date')->limit(10)->get(),
            // BR-30 — deductions awaiting the farmer's next payment. §15.1 — the
            // payment module itself is blocked, so these are shown, not settled.
            'pendingDeductions' => $this->allows('shop.sales.view')
                ? $farmer->pendingDeductions()->pending()->with('sale')->get()
                : collect(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:24', 'unique:farmers,code'],
            'name' => ['required', 'string', 'max:255'],
            'gender' => ['nullable', 'string', 'max:16'],
            'year_of_birth' => ['nullable', 'integer', 'min:1900', 'max:'.(int) Wat::local()->format('Y')],
            'phone' => ['nullable', 'string', 'max:32'],
            'community_id' => ['required', 'exists:communities,id'],
            'cooperative_id' => ['nullable', 'exists:cooperatives,id'],
            'cooperative_member_no' => ['nullable', 'string', 'max:32'],
            'default_collection_point_id' => ['nullable', 'exists:collection_points,id'],
            'herd_size' => ['nullable', 'integer', 'min:0'],
            'lactating_count' => ['nullable', 'integer', 'min:0'],
        ]);

        $community = Community::query()->findOrFail($validated['community_id']);

        /*
         * ARCH-4 layer 2. `exists:communities,id` is a validation rule, not an
         * authorisation one: it accepted any community in the network, so a
         * point-scoped agent could enrol into a settlement they have no business
         * in — and the farmer, inheriting that community's LGA and cooperative,
         * would then vanish from the enroller's own register and read as a failed
         * enrolment worth re-entering. The default collection point below was
         * carefully forced into scope while the community it stands in was not.
         */
        $this->authorizeAccess('community.farmers.create', $community, 'Enrol a farmer in '.$community->name);

        /*
         * SCOPE-1 — nobody may enrol a farmer they will immediately be unable to see.
         *
         * A point-scoped agent's view of farmers is
         * `default_collection_point_id IN (their points)`, and NULL is in no list.
         * Leaving the point blank therefore created a real farmer that vanished
         * from its own enroller the instant it was saved: the redirect to the new
         * record answered 403, and the agent was left unsure whether the
         * enrolment had happened at all. It had.
         *
         * Where the enroller covers exactly one point, that is unambiguously the
         * right answer and is filled in for them. Where they cover several, the
         * form must say which — guessing would put the farmer at the wrong point,
         * and every delivery afterwards inherits that mistake.
         */
        $points = $this->currentUser()?->scopeSetFor('community.farmers.view')
            ->targetIdsFor(ScopeType::Point) ?? [];

        if (($validated['default_collection_point_id'] ?? null) === null && $points !== []) {
            if (count($points) === 1) {
                $validated['default_collection_point_id'] = $points[0];
            } else {
                return back()->withInput()->withErrors([
                    'default_collection_point_id' => 'Choose the collection point this farmer delivers to. Your access covers several, '
                        .'and without one the farmer would not appear in your own register.',
                ]);
            }
        }

        $farmer = Farmer::query()->create(array_merge($validated, [
            'lga_id' => $community->lga_id,
            'enrolled_by_user_id' => $this->currentUser()?->getKey(),
            'enrolled_on' => Wat::today()->toDateString(),
            'status' => 'active',
        ]));

        $this->audit->created(
            $farmer,
            sprintf('Farmer %s (%s) enrolled in %s', $farmer->name, $farmer->code, $community->name),
            'Community Engagement',
            ['herd_size' => $farmer->herd_size, 'rule' => 'USER-1'],
            $this->currentUser(),
        );

        return redirect()->route('farmers.show', $farmer)->with('success', $farmer->name.' enrolled.');
    }

    public function update(Request $request, Farmer $farmer): RedirectResponse
    {
        $this->authorizeAccess('community.farmers.edit', $farmer, 'Farmer → '.$farmer->name);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'cooperative_id' => ['nullable', 'exists:cooperatives,id'],
            'cooperative_member_no' => ['nullable', 'string', 'max:32'],
            'default_collection_point_id' => ['nullable', 'exists:collection_points,id'],
            'herd_size' => ['nullable', 'integer', 'min:0'],
            'lactating_count' => ['nullable', 'integer', 'min:0'],
            'status' => ['required', 'in:active,dormant,exited'],
        ]);

        $before = $farmer->only(array_keys($validated));

        $farmer->fill($validated)->save();

        $this->audit->edited(
            $farmer,
            $farmer->name.' record updated',
            'Community Engagement',
            $before,
            $farmer->only(array_keys($validated)),
            $this->currentUser(),
        );

        return back()->with('success', 'Farmer record updated.');
    }
}
