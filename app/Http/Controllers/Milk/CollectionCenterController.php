<?php

namespace App\Http\Controllers\Milk;

use App\Http\Controllers\Controller;
use App\Models\Adjustment;
use App\Models\AdjustmentReason;
use App\Models\CollectionCenter;
use App\Models\Consignment;
use App\Models\Grade;
use App\Models\Lga;
use App\Models\QualityTestDefinition;
use App\Models\RejectionReason;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Money;
use App\Support\Volume;
use App\Support\Wat;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * collection-centers.html and collection-center-detail.html.
 *
 * §4 — the LIST needs milk.points.view; the DETAIL needs
 * milk.consignment.confirm.view, because the detail screen is where confirmation
 * happens. Both are enforced on the route; the record check is here.
 */
class CollectionCenterController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): View
    {
        // ARCH-9 — `confirmed_at` is a UTC instant, so today's WAT day is a range.
        // Confirmations run late at the centres; the UTC day dropped the ones after
        // midnight onto the previous day's tile.
        [$dayStart, $dayEnd] = Wat::dayRange();

        $centers = CollectionCenter::query()
            ->with(['lga', 'officer', 'logisticsOfficer'])
            ->withCount('collectionPoints')
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->orderBy('name')
            ->paginate($this->perPage($request->integer('per_page') ?: null))
            ->withQueryString();

        // BR-35 — aggregates exclude test activity.
        $todayByCenter = Consignment::query()
            ->excludingTestData()
            ->whereNotNull('confirmed_at')
            ->where('confirmed_at', '>=', $dayStart)
            ->where('confirmed_at', '<', $dayEnd)
            ->selectRaw('collection_center_id, sum(litres_confirmed) as litres, count(*) as consignments')
            ->groupBy('collection_center_id')
            ->get()
            ->keyBy('collection_center_id');

        return view('milk.collection-centers.index', [
            'canCreate' => $this->allows('milk.points.create'),
            // REF-1 — the register was create-only, so reassigning a departing
            // centre officer or correcting a transport fee was a DBA operation
            // and nothing audited it.
            'canEdit' => $this->allows('milk.points.edit'),
            'lgas' => Lga::query()->orderBy('name')->get(['id', 'name']),
            // Identity lists for the two assignable posts, not browses.
            'staff' => User::query()->where('status', 'active')
                ->orderBy('name')->get(['id', 'name', 'email']),
            'centers' => $centers,
            'todayByCenter' => $todayByCenter,
        ]);
    }

    /**
     * Centres could only be created by a seeder — the screen was read-only while
     * `milk.points.create` was granted and checked by nothing here. A cooperative
     * that opens a new centre could not record it, and points have nowhere to feed.
     *
     * Gated on milk.points.* deliberately: a centre is the same class of
     * collection-network master data as a point, and the catalogue holds one
     * grant for it.
     */
    public function store(Request $request): RedirectResponse
    {
        $this->authorizeAccess('milk.points.create', null, 'Add a collection center');

        $validated = $request->validate($this->rules());

        $center = CollectionCenter::query()->create([
            'code' => $validated['code'],
            'name' => $validated['name'],
            'lga_id' => $validated['lga_id'],
            'officer_user_id' => $validated['officer_user_id'] ?? null,
            'logistics_user_id' => $validated['logistics_user_id'] ?? null,
            'cold_storage_litres' => $validated['cold_storage_litres'] ?? null,
            'distance_to_factory_km' => $validated['distance_to_factory_km'] ?? null,
            // ARCH-6 — naira in, kobo stored.
            'transport_fee_minor' => Money::fromMajor($validated['transport_fee'] ?? null),
            'status' => $validated['status'] ?? 'active',
        ]);

        $this->audit->created(
            $center,
            sprintf(
                'Collection center %s (%s) created in %s',
                $center->name,
                $center->code,
                $center->lga?->name ?? 'its LGA',
            ),
            'Milk Collection',
            [
                'distance_to_factory_km' => $center->distance_to_factory_km,
                'officer' => $center->officer?->name,
            ],
            $this->currentUser(),
        );

        return redirect()->route('collection-centers.show', $center)
            ->with('success', $center->name.' created. Points can now be assigned to it.');
    }

    public function update(Request $request, CollectionCenter $center): RedirectResponse
    {
        $this->authorizeAccess('milk.points.edit', $center, 'Collection center → '.$center->name);

        $validated = $request->validate($this->rules($center));

        // `code` is written by the fill below and was missing from the before/after
        // pair, so a re-code — the one change that breaks every reference already
        // printed on a consignment — was the one change the audit trail lost.
        $before = $center->only([
            'code', 'name', 'lga_id', 'officer_user_id', 'logistics_user_id',
            'cold_storage_litres', 'distance_to_factory_km', 'transport_fee_minor', 'status',
        ]);

        $center->fill([
            'code' => $validated['code'],
            'name' => $validated['name'],
            'lga_id' => $validated['lga_id'],
            'officer_user_id' => $validated['officer_user_id'] ?? null,
            'logistics_user_id' => $validated['logistics_user_id'] ?? null,
            'cold_storage_litres' => $validated['cold_storage_litres'] ?? null,
            'distance_to_factory_km' => $validated['distance_to_factory_km'] ?? null,
            'transport_fee_minor' => Money::fromMajor($validated['transport_fee'] ?? null),
            'status' => $validated['status'] ?? $center->status,
        ])->save();

        $this->audit->edited(
            $center,
            sprintf('Collection center %s (%s) updated', $center->name, $center->code),
            'Milk Collection',
            $before,
            $center->only(array_keys($before)),
            $this->currentUser(),
        );

        return back()->with('success', $center->name.' updated.');
    }

    /** @return array<string, array<int, mixed>> */
    private function rules(?CollectionCenter $center = null): array
    {
        return [
            'code' => ['required', 'string', 'max:24', 'unique:collection_centers,code'.($center ? ','.$center->getKey() : '')],
            'name' => ['required', 'string', 'max:255'],
            'lga_id' => ['required', 'exists:lgas,id'],
            'officer_user_id' => ['nullable', 'exists:users,id'],
            'logistics_user_id' => ['nullable', 'exists:users,id'],
            'cold_storage_litres' => ['nullable', 'numeric', 'min:0'],
            'distance_to_factory_km' => ['nullable', 'numeric', 'min:0'],
            'transport_fee' => ['nullable', 'string'],
            // The column's own vocabulary — see the migration.
            'status' => ['nullable', 'in:active,suspended'],
        ];
    }

    public function show(Request $request, CollectionCenter $center): View
    {
        // Either audience may open a center; the controller authorises whichever
        // permission this user actually holds, so the record-level scope check
        // still runs against the right one.
        // Ordered most-specific first: when a user holds both, the denial names the
        // one they most likely came here to use, which makes a scope refusal read
        // as "not for this center" rather than pointing at master data.
        $this->authorizeAnyAccess(
            ['milk.consignment.confirm.view', 'milk.points.view'],
            $center,
            'Collection center → '.$center->name,
        );

        // The confirmation queue is a second, narrower permission. Someone who may
        // see the center but not confirm its intake gets the center without the
        // queue, rather than a 403 on a record they are entitled to read.
        $canSeeQueue = $this->allows('milk.consignment.confirm.view', $center);

        // ARCH-9 — as on the index: a WAT day out of a UTC column is a range. The
        // three litre totals in the view are summed from $confirmedToday, so all of
        // them inherited the shift.
        [$dayStart, $dayEnd] = Wat::dayRange();

        $awaiting = $canSeeQueue
            ? $center->consignments()
                ->awaitingConfirmation()
                ->with(['collectionPoint', 'deliveries.farmer'])
                ->orderBy('dispatched_at')
                ->get()
            : new Collection;

        $confirmedToday = $center->consignments()
            ->excludingTestData()
            ->whereNotNull('confirmed_at')
            ->where('confirmed_at', '>=', $dayStart)
            ->where('confirmed_at', '<', $dayEnd)
            ->with(['collectionPoint', 'grade', 'rejectionReason'])
            ->get();

        return view('milk.collection-centers.show', [
            'center' => $center->load(['lga', 'officer', 'logisticsOfficer', 'collectionPoints']),
            'awaiting' => $awaiting,
            'confirmedToday' => $confirmedToday,
            'litresConfirmedToday' => Volume::sum($confirmedToday->pluck('litres_confirmed')->all()),
            'litresRejectedToday' => Volume::sum($confirmedToday->pluck('litres_rejected_at_center')->all()),
            'litresAdjustedToday' => $this->adjustmentTotalFor($confirmedToday),
            'canSeeQueue' => $canSeeQueue,
            'batchable' => $canSeeQueue
                // `grade` is eager-loaded because the batch modal prints it on
                // every checkbox — without it the list cost a query per row.
                ? $center->consignments()->batchable()->with(['collectionPoint', 'grade'])->get()
                : new Collection,
            // §9 — the pickers are reference data, never a hardcoded list.
            'grades' => Grade::query()->assignable()->orderBy('position')->get(),
            'centerReasons' => RejectionReason::query()->availableAt(RejectionReason::STAGE_CENTER)->orderBy('position')->get(),
            'qualityTests' => QualityTestDefinition::query()->active()->orderBy('position')->get(),
            'adjustmentReasons' => AdjustmentReason::query()->for('consignment')->orderBy('position')->get(),
            'canConfirm' => $canSeeQueue && $this->allows('milk.consignment.confirm.edit', $center),
            'canGrade' => $this->allows('milk.grade.create'),
            'canAdjust' => $canSeeQueue && $this->allows('milk.adjustment.create'),
            // The edit modal's pickers, and whether to render it at all.
            'canEdit' => $this->allows('milk.points.edit', $center),
            'lgas' => Lga::query()->orderBy('name')->get(['id', 'name']),
            'staff' => User::query()->where('status', 'active')
                ->orderBy('name')->get(['id', 'name', 'email']),
        ]);
    }

    /**
     * BR-8's adjustment total for a day's consignments, in ONE query.
     *
     * `Consignment::adjustmentTotal()` runs a SUM per call, and this page has no
     * pagination, so the tile cost a round trip per consignment confirmed today
     * and grew with the centre's volume. NFR-5 — the deltas are added through
     * Volume rather than summed as floats in SQL.
     *
     * @param  Collection<int, Consignment>  $consignments
     */
    private function adjustmentTotalFor(Collection $consignments): string
    {
        if ($consignments->isEmpty()) {
            return Volume::fromCentilitres(0);
        }

        return Volume::sum(
            Adjustment::query()
                ->where('adjustable_type', (new Consignment)->getMorphClass())
                ->whereIn('adjustable_id', $consignments->modelKeys())
                ->pluck('litres_delta')
                ->all(),
        );
    }
}
