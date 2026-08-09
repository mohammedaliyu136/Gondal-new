<?php

namespace App\Http\Controllers\Milk;

use App\Authorization\PermissionHolders;
use App\Http\Controllers\Controller;
use App\Models\CollectionCenter;
use App\Models\CollectionPoint;
use App\Models\Community;
use App\Models\Delivery;
use App\Models\Farmer;
use App\Models\Lga;
use App\Models\RejectionReason;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Milk\QualityFollowupService;
use App\Support\Money;
use App\Support\Settings;
use App\Support\Wat;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * collection-points.html and collection-point-detail.html.
 *
 * SCOPE-2 — the list is narrowed by the global scope; the detail screen adds the
 * record-level check, so a point id typed into the URL is refused the same way.
 */
class CollectionPointController extends Controller
{
    /**
     * Assigning a farmer to a point edits the farmer but is the point owner's
     * decision, so either side's grant opens it.
     */
    private const FARMER_ASSIGNMENT_GRANTS = ['community.farmers.edit', 'milk.points.edit'];

    /** Enough to choose from; few enough that the page stays small. */
    private const ASSIGN_SEARCH_LIMIT = 50;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly QualityFollowupService $followups,
        private readonly PermissionHolders $holders,
    ) {}

    /**
     * Candidate agents are whoever can actually record a delivery, not every
     * user in the system. Naming someone who cannot record milk as the agent of
     * a point is a silent dead end: the point looks staffed and nothing can be
     * entered against it.
     *
     * NFR-1 — asked as one join. This list was built by loading every active
     * user and calling hasPermission() on each, which costs three queries per
     * candidate: 113 of this screen's 159 queries existed only to fill the
     * dropdown, and the count grew with every hire rather than with the network.
     *
     * @return Collection<int, User>
     */
    private function candidateAgents(): Collection
    {
        return $this->holders->query('milk.deliveries.create')
            ->orderBy('name')
            ->get(['id', 'name', 'email']);
    }

    public function index(Request $request): View
    {
        // ARCH-9 — the officer reconciles these against the churns in front of
        // them, so "today" has to be the WAT day, not the UTC one that starts an
        // hour late. `delivered_at` is a UTC instant; the day is a range.
        [$dayStart, $dayEnd] = Wat::dayRange();

        $points = CollectionPoint::query()
            ->with(['community', 'lga', 'agent', 'collectionCenter'])
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('center'), fn ($query) => $query->where('collection_center_id', $request->integer('center')))
            ->when($request->filled('q'), fn ($query) => $query->where(function ($inner) use ($request) {
                $term = '%'.$request->string('q').'%';
                $inner->where('name', 'like', $term)->orWhere('code', 'like', $term);
            }))
            ->orderBy('name')
            ->paginate($this->perPage($request->integer('per_page') ?: null))
            ->withQueryString();

        // BR-35 — today's figures per point exclude test activity.
        $todayByPoint = Delivery::query()
            ->excludingTestData()
            ->where('delivered_at', '>=', $dayStart)
            ->where('delivered_at', '<', $dayEnd)
            ->selectRaw('collection_point_id, sum(litres_accepted) as litres, count(*) as deliveries')
            ->groupBy('collection_point_id')
            ->get()
            ->keyBy('collection_point_id');

        return view('milk.collection-points.index', [
            'points' => $points,
            'todayByPoint' => $todayByPoint,
            'centers' => CollectionCenter::query()->orderBy('name')->get(),
            'communities' => Community::query()->with('lga')->orderBy('name')->get(),
            'lgas' => Lga::query()->orderBy('name')->get(['id', 'name']),
            'agents' => $this->candidateAgents(),
            'canEdit' => $this->allows('milk.points.edit'),
            'defaultCutoff' => Settings::string('milk.delivery_cutoff_default', '07:00'),
            'latestCutoff' => Settings::string('milk.delivery_cutoff_latest_override', '08:00'),
        ]);
    }

    public function show(Request $request, CollectionPoint $point): View
    {
        // SCOPE-2, layer 2 — refuses a direct-ID read the list would have hidden.
        $this->authorizeAccess('milk.points.view', $point, 'Collection point → '.$point->name);

        // ARCH-9 — as on the index: a WAT day out of a UTC column is a range.
        [$dayStart, $dayEnd] = Wat::dayRange();

        $deliveries = $point->deliveries()
            ->with(['farmer', 'rejectionReason', 'recordedBy'])
            ->latest('delivered_at')
            ->paginate($this->perPage($request->integer('per_page') ?: null))
            ->withQueryString();

        $todayTotals = Delivery::query()
            ->excludingTestData()
            ->where('collection_point_id', $point->getKey())
            ->where('delivered_at', '>=', $dayStart)
            ->where('delivered_at', '<', $dayEnd)
            ->selectRaw('sum(litres_presented) as presented, sum(litres_rejected) as rejected, sum(litres_accepted) as accepted, count(*) as deliveries')
            ->first();

        return view('milk.collection-points.show', [
            'point' => $point->load(['community.lga', 'agent', 'collectionCenter']),
            'deliveries' => $deliveries,
            'todayTotals' => $todayTotals,
            'cutoff' => $point->effectiveCutoff(),
            'openFollowups' => $this->followups->openFor($point),
            'consignments' => $point->consignments()->latest('dispatched_at')->limit(6)->get(),
            'farmerCount' => $point->farmers()->count(),
            /*
             * A preview, not the roster. A busy point has sixty-odd farmers and
             * rendering all of them buried every other card on the page; the full
             * list has its own screen.
             */
            'farmerPreview' => $point->farmers()->orderBy('name')->limit(5)->get(['id', 'name', 'code']),
            /*
             * BR-35 — a test agent's deliveries inflated this participation figure
             * while $todayTotals five lines above correctly excluded them, so two
             * numbers on the same card disagreed.
             */
            'farmersDeliveredToday' => Delivery::query()
                ->excludingTestData()
                ->where('collection_point_id', $point->getKey())
                ->where('delivered_at', '>=', $dayStart)
                ->where('delivered_at', '<', $dayEnd)
                ->distinct()
                ->count('farmer_id'),
            'agents' => $this->candidateAgents(),
            'canAssignFarmers' => $this->allowsAny(self::FARMER_ASSIGNMENT_GRANTS, $point),

            /*
             * THE POINT'S OWN WORK, DONE HERE.
             *
             * Recording intake used to be a link to /milk-flow/deliveries with
             * `?point=`, and dispatching was on a third screen — so the agent
             * standing at the point moved between three pages to do one
             * morning's job, re-choosing the point on each. The two actions the
             * point exists for now happen on the point.
             */

            // Only this point's farmers: the picker on a point screen offering
            // the whole register is how milk gets recorded against the wrong one.
            'pointFarmers' => $point->farmers()->orderBy('name')->get(['farmers.id', 'farmers.name', 'farmers.code']),

            // BR-1 — the reasons configured for the POINT stage, never a
            // hardcoded list and never the centre's or the factory's.
            'pointReasons' => RejectionReason::query()
                ->availableAt(RejectionReason::STAGE_POINT)
                ->orderBy('position')
                ->get(),

            /*
             * What is standing here waiting to go. Loaded in the controller
             * rather than queried from the Blade — the dispatch modal on the
             * consignments screen does the latter, and a view that runs its own
             * queries is one nobody can see the cost of.
             */
            'awaitingDispatch' => Delivery::query()
                ->awaitingDispatch()
                ->where('collection_point_id', $point->getKey())
                ->with('farmer')
                ->latest('delivered_at')
                ->get(),

            'canRecordDelivery' => $this->allows('milk.deliveries.create', $point),
            'canDispatch' => $this->allows('milk.consignment.confirm.create', $point),
            'canOverrideCutoff' => $this->allows('milk.deliveries.cutoff_override', $point),
        ]);
    }

    /**
     * The full roster, on its own screen.
     *
     * It used to be a table on the point's detail page, where sixty rows pushed
     * everything else off the screen. A summary belongs on the record; the list
     * belongs where it can be paginated and searched.
     */
    public function farmers(Request $request, CollectionPoint $point): View
    {
        $this->authorizeAccess('milk.points.view', $point, 'Collection point → '.$point->name);

        $farmers = $point->farmers()
            ->with('cooperative')
            ->when($request->filled('q'), function ($query) use ($request): void {
                $term = '%'.$request->string('q').'%';

                $query->where(fn ($inner) => $inner
                    ->where('name', 'like', $term)
                    ->orWhere('code', 'like', $term));
            })
            ->orderBy('name')
            ->paginate($this->perPage($request->integer('per_page') ?: null))
            ->withQueryString();

        $assignSearch = trim((string) $request->string('assign'));

        return view('milk.collection-points.farmers', [
            'point' => $point->load('community.lga', 'collectionCenter'),
            'farmers' => $farmers,
            /*
             * Searched server-side rather than shipped whole.
             *
             * Every farmer who could be assigned is 1,784 of them, and rendering
             * them as <option>s made this page 317 KB — 90% of it a dropdown, on
             * a connection that drops. The picker now holds only what was searched
             * for, capped, and the page is a twentieth of the size.
             */
            'assignSearch' => $assignSearch,
            'assignableFarmers' => $assignSearch === ''
                ? collect()
                : Farmer::withoutDataScope()
                    ->active()
                    ->where(fn ($query) => $query
                        ->whereNull('default_collection_point_id')
                        ->orWhere('default_collection_point_id', '!=', $point->getKey()))
                    ->where(fn ($query) => $query
                        ->where('name', 'like', '%'.$assignSearch.'%')
                        ->orWhere('code', 'like', '%'.$assignSearch.'%'))
                    ->orderBy('name')
                    ->limit(self::ASSIGN_SEARCH_LIMIT)
                    ->get(['id', 'name', 'code', 'default_collection_point_id']),
            'canAssignFarmers' => $this->allowsAny(self::FARMER_ASSIGNMENT_GRANTS, $point),
        ]);
    }

    /**
     * Which farmers deliver here.
     *
     * A farmer's home point is a column on the farmer, so this edits the farmer —
     * but it is the POINT's owner who knows who turns up there, and they had no
     * way to say so. Either grant opens it: the community team maintains the
     * register, the collection network decides where milk is brought.
     */
    public function assignFarmer(Request $request, CollectionPoint $point): RedirectResponse
    {
        $this->authorizeAnyAccess(self::FARMER_ASSIGNMENT_GRANTS, $point, 'Assign a farmer to '.$point->name);

        $validated = $request->validate([
            'farmer_id' => ['required', 'exists:farmers,id'],
        ]);

        $farmer = Farmer::withoutDataScope()->findOrFail($validated['farmer_id']);
        $previous = $farmer->defaultCollectionPoint;

        $farmer->forceFill(['default_collection_point_id' => $point->getKey()])->save();

        $this->audit->edited(
            $farmer,
            sprintf(
                '%s (%s) now delivers to %s%s',
                $farmer->name,
                $farmer->code,
                $point->name,
                $previous ? ' — moved from '.$previous->name : '',
            ),
            'Community Engagement',
            ['default_collection_point_id' => $previous?->getKey()],
            ['default_collection_point_id' => $point->getKey()],
            $this->currentUser(),
        );

        return back()->with('success', $farmer->name.' now delivers to '.$point->name.'.');
    }

    /** Unassign, leaving the farmer on the register with no home point. */
    public function unassignFarmer(CollectionPoint $point, Farmer $farmer): RedirectResponse
    {
        $this->authorizeAnyAccess(self::FARMER_ASSIGNMENT_GRANTS, $point, 'Unassign a farmer from '.$point->name);

        abort_unless($farmer->default_collection_point_id === $point->getKey(), 404);

        $farmer->forceFill(['default_collection_point_id' => null])->save();

        $this->audit->edited(
            $farmer,
            sprintf('%s (%s) no longer delivers to %s', $farmer->name, $farmer->code, $point->name),
            'Community Engagement',
            ['default_collection_point_id' => $point->getKey()],
            ['default_collection_point_id' => null],
            $this->currentUser(),
        );

        return back()->with('success', $farmer->name.' removed from '.$point->name.'.');
    }

    public function store(Request $request): RedirectResponse
    {
        /*
         * A point needs a community to stand in and a centre to feed. Both used to
         * have to exist first, so opening a point in a new settlement meant
         * abandoning this form, creating the community elsewhere, then starting
         * again — and the half-typed point was lost on the way.
         *
         * Either can now be created from here instead. The pickers stay required
         * UNLESS the matching "not listed" block is filled in, and the whole thing
         * commits as one transaction: no orphan community left behind by a point
         * that failed validation two fields later.
         */
        $creatingCommunity = $request->filled('new_community_name');
        $creatingCenter = $request->filled('new_center_name');

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:24', 'unique:collection_points,code'],
            'name' => ['required', 'string', 'max:255'],
            'community_id' => [Rule::requiredIf(! $creatingCommunity), 'nullable', 'exists:communities,id'],
            'collection_center_id' => [Rule::requiredIf(! $creatingCenter), 'nullable', 'exists:collection_centers,id'],

            'new_community_name' => ['nullable', 'string', 'max:255'],
            'new_community_lga_id' => [Rule::requiredIf($creatingCommunity), 'nullable', 'exists:lgas,id'],

            'new_center_code' => [Rule::requiredIf($creatingCenter), 'nullable', 'string', 'max:24', 'unique:collection_centers,code'],
            'new_center_name' => ['nullable', 'string', 'max:255'],
            'new_center_lga_id' => [Rule::requiredIf($creatingCenter), 'nullable', 'exists:lgas,id'],
            'agent_user_id' => ['nullable', 'exists:users,id'],
            // BR-3 — a point may override the cut-off, bounded by Settings.
            'cutoff_time' => ['nullable', 'date_format:H:i'],
            'transport_fee' => ['nullable', 'string'],
            'status' => ['required', 'in:active,idle,suspended'],
            'opened_on' => ['nullable', 'date'],
        ]);

        // Creating either is a separate act with its own grant; the point's own
        // permission does not silently confer them.
        if ($creatingCommunity) {
            $this->authorizeAnyAccess(
                ['community.cooperatives.create', 'milk.points.create'],
                null,
                'Add a community while creating a point',
            );
        }

        if ($creatingCenter) {
            $this->authorizeAccess('milk.points.create', null, 'Add a center while creating a point');
        }

        [$community, $centerId] = DB::transaction(function () use ($validated, $creatingCommunity, $creatingCenter): array {
            $community = $creatingCommunity
                ? Community::query()->create([
                    'lga_id' => $validated['new_community_lga_id'],
                    'name' => $validated['new_community_name'],
                ])
                : Community::query()->findOrFail($validated['community_id']);

            $centerId = $creatingCenter
                ? CollectionCenter::query()->create([
                    'code' => $validated['new_center_code'],
                    'name' => $validated['new_center_name'],
                    'lga_id' => $validated['new_center_lga_id'],
                    'status' => 'active',
                ])->getKey()
                : (int) $validated['collection_center_id'];

            return [$community, $centerId];
        });

        if ($creatingCommunity) {
            $this->audit->created(
                $community,
                sprintf('Community "%s" added while creating a collection point', $community->name),
                'Community Engagement',
                ['lga' => $community->lga?->name],
                $this->currentUser(),
            );
        }

        if ($creatingCenter) {
            $center = CollectionCenter::withoutDataScope()->find($centerId);

            $this->audit->created(
                $center,
                sprintf('Collection center %s (%s) added while creating a collection point', $center->name, $center->code),
                'Milk Collection',
                [],
                $this->currentUser(),
            );
        }

        $point = CollectionPoint::query()->create([
            'code' => $validated['code'],
            'name' => $validated['name'],
            'community_id' => $community->getKey(),
            'lga_id' => $community->lga_id,
            'collection_center_id' => $centerId,
            'agent_user_id' => $validated['agent_user_id'] ?? null,
            'cutoff_time' => $validated['cutoff_time'] ?? null,
            'transport_fee_minor' => Money::fromMajor($validated['transport_fee'] ?? null),
            'status' => $validated['status'],
            'opened_on' => $validated['opened_on'] ?? Wat::today()->toDateString(),
        ]);

        $this->audit->created(
            $point,
            sprintf('Collection point %s (%s) created at %s', $point->name, $point->code, $community->name),
            'Milk Collection',
            ['cutoff' => $point->effectiveCutoff()],
            $this->currentUser(),
        );

        return redirect()->route('collection-points.show', $point)
            ->with('success', $point->name.' created.');
    }

    public function update(Request $request, CollectionPoint $point): RedirectResponse
    {
        $this->authorizeAccess('milk.points.edit', $point, 'Collection point → '.$point->name);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'agent_user_id' => ['nullable', 'exists:users,id'],
            'cutoff_time' => ['nullable', 'date_format:H:i'],
            'transport_fee' => ['nullable', 'string'],
            'status' => ['required', 'in:active,idle,suspended'],
        ]);

        $before = $point->only(['name', 'agent_user_id', 'cutoff_time', 'transport_fee_minor', 'status']);

        $point->fill([
            'name' => $validated['name'],
            'agent_user_id' => $validated['agent_user_id'] ?? null,
            'cutoff_time' => $validated['cutoff_time'] ?? null,
            'transport_fee_minor' => Money::fromMajor($validated['transport_fee'] ?? null),
            'status' => $validated['status'],
        ])->save();

        $this->audit->edited(
            $point,
            $point->name.' updated',
            'Milk Collection',
            $before,
            $point->only(array_keys($before)),
            $this->currentUser(),
        );

        return back()->with('success', 'Collection point updated.');
    }
}
