<?php

namespace App\Http\Controllers\Milk;

use App\Http\Controllers\Controller;
use App\Models\AdjustmentReason;
use App\Models\CollectionPoint;
use App\Models\Delivery;
use App\Models\Farmer;
use App\Models\RejectionReason;
use App\Services\Milk\AdjustmentService;
use App\Services\Milk\DeliveryService;
use App\Support\Volume;
use App\Support\Wat;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * deliveries.html and delivery-detail.html — the "Milk Flow" screens.
 *
 * Every business rule lives in DeliveryService; this controller validates shape
 * and hands over, so the web and API surfaces cannot diverge (ARCH-2).
 */
class DeliveryController extends Controller
{
    public function __construct(
        private readonly DeliveryService $deliveries,
        private readonly AdjustmentService $adjustments,
    ) {}

    public function index(Request $request): View
    {
        $date = $request->date('date')?->toDateString() ?? Wat::today()->toDateString();

        /*
         * ARCH-9 — `delivered_at` is a UTC instant, $date is a WAT calendar day.
         * Filtering one by the other lost the first hour of every WAT day: an agent
         * recording at 00:30 watched the delivery vanish the moment the modal
         * closed, and its litres were added to a day already reported.
         */
        [$dayStart, $dayEnd] = Wat::dayRange($date);

        $deliveries = Delivery::query()
            ->with(['farmer', 'collectionPoint', 'rejectionReason', 'consignment', 'recordedBy'])
            ->when($request->filled('point'), fn ($query) => $query->where('collection_point_id', $request->integer('point')))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('q'), fn ($query) => $query->where(function ($inner) use ($request) {
                $term = '%'.$request->string('q').'%';
                $inner->where('reference', 'like', $term)
                    ->orWhereHas('farmer', fn ($f) => $f->where('name', 'like', $term)->orWhere('code', 'like', $term));
            }))
            ->when(! $request->filled('q'), fn ($query) => $query
                ->where('delivered_at', '>=', $dayStart)
                ->where('delivered_at', '<', $dayEnd))
            ->latest('delivered_at')
            ->paginate($this->perPage($request->integer('per_page') ?: null))
            ->withQueryString();

        // BR-35 — the day's totals exclude test activity.
        $totals = Delivery::query()
            ->excludingTestData()
            ->where('delivered_at', '>=', $dayStart)
            ->where('delivered_at', '<', $dayEnd)
            ->selectRaw('sum(litres_presented) as presented, sum(litres_rejected) as rejected, sum(litres_accepted) as accepted, count(*) as deliveries')
            ->first();

        return view('milk.deliveries.index', [
            'deliveries' => $deliveries,
            'totals' => $totals,
            'date' => $date,
            'points' => CollectionPoint::query()->orderBy('name')->get(),
            /*
             * NFR-2's "never return unbounded collections" is about lists a user
             * browses; this is the picker they record a delivery from, and a
             * capped picker is not a slower screen, it is a farmer who cannot be
             * paid. `limit(500)` returned 500 of 1,842 active farmers ordered by
             * name — everyone after roughly the letter D could not have a
             * delivery recorded against them at all, because the searchable
             * select searches the HTML that was rendered and never asks the
             * server. SaleController reached the same conclusion and removed its
             * own cap ("worse than a long list"); the milk screens were missed.
             *
             * Only the three columns the <option> needs are hydrated, so a full
             * register costs a fraction of what the capped query cost. The proper
             * fix is a server-side typeahead against GET /api/v1/farmers/search,
             * which already exists, is paginated and is scoped.
             */
            'farmers' => Farmer::query()->active()->orderBy('name')->get(['id', 'name', 'code']),
            // BR-1 — the picker is the configured list for the POINT stage only.
            'pointReasons' => RejectionReason::query()->availableAt(RejectionReason::STAGE_POINT)->orderBy('position')->get(),
            'canRecord' => $this->allows('milk.deliveries.create'),
            'awaitingDispatch' => Delivery::query()->awaitingDispatch()->count(),
        ]);
    }

    public function show(Delivery $delivery): View
    {
        $this->authorizeAccess('milk.deliveries.view', $delivery, 'Delivery → '.$delivery->reference);

        return view('milk.deliveries.show', [
            'delivery' => $delivery->load([
                'farmer.cooperative', 'collectionPoint.collectionCenter', 'rejectionReason',
                'recordedBy', 'consignment.grade', 'adjustments.reason', 'cutoffOverriddenBy',
            ]),
            'adjustmentReasons' => AdjustmentReason::query()->for('delivery')->orderBy('position')->get(),
            'canAdjust' => $this->allows('milk.adjustment.create', $delivery),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'collection_point_id' => ['required', 'exists:collection_points,id'],
            'farmer_id' => ['required', 'exists:farmers,id'],
            /*
             * BR-3 — shape only, deliberately. The bounds (not in the future, not
             * further back than Settings allows) live in
             * DeliveryService::guardDeliveredAt, because the REST API and the
             * offline sync reach the same service and must obey the same limits —
             * and because `before_or_equal:now` here would be judged in the app's
             * UTC against a WAT wall-clock string, refusing every delivery
             * recorded in the last hour (ARCH-9).
             */
            'delivered_at' => ['nullable', 'date'],
            'litres_presented' => ['required', 'numeric', 'min:0.01', 'max:99999999'],
            'litres_rejected' => ['nullable', 'numeric', 'min:0'],
            // BR-1 — an id from the list, never free text.
            'rejection_reason_id' => ['nullable', 'exists:rejection_reasons,id'],
            'containers' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
            // BR-3
            'cutoff_override' => ['nullable', 'boolean'],
            'cutoff_override_reason' => ['nullable', 'string', 'max:255'],
        ]);

        $point = CollectionPoint::query()->findOrFail($validated['collection_point_id']);
        $farmer = Farmer::query()->findOrFail($validated['farmer_id']);

        // Layer 2 — may this user record AT THIS POINT?
        $this->authorizeAccess('milk.deliveries.create', $point, 'Record intake at '.$point->name);

        $delivery = $this->deliveries->record($point, $farmer, $validated, $this->currentUser());

        $message = sprintf(
            '%s recorded — %s accepted from %s.',
            $delivery->reference,
            Volume::format($delivery->litres_accepted),
            $farmer->name,
        );

        /*
         * An agent records sixty of these in one morning with a queue in front of
         * them. Sending them to the delivery's detail page after each save cost
         * three navigations per farmer — back to the list, reopen the modal,
         * re-choose the point — roughly 180 extra taps before the truck leaves.
         *
         * "Save and add another" returns to the list with the modal already open
         * and the point remembered, so the next delivery starts at the farmer
         * field. The plain Save still goes to the detail page, which is what you
         * want for the one delivery you are actually inspecting.
         */
        if ($request->boolean('add_another')) {
            return redirect()
                ->to(route('deliveries.index', ['point' => $point->getKey()]).'#modal-record')
                ->with('success', $message);
        }

        return redirect()->route('deliveries.show', $delivery)->with('success', $message);
    }

    public function update(Request $request, Delivery $delivery): RedirectResponse
    {
        $this->authorizeAccess('milk.deliveries.edit', $delivery, 'Delivery → '.$delivery->reference);

        $validated = $request->validate([
            'containers' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->deliveries->updateDetails($delivery, $validated, $this->currentUser());

        return back()->with('success', 'Delivery details updated.');
    }

    /** BR-12 — a volume change is an adjustment, with a reason and an explanation. */
    public function adjust(Request $request, Delivery $delivery): RedirectResponse
    {
        $this->authorizeAccess('milk.adjustment.create', $delivery, 'Adjust delivery '.$delivery->reference);

        $validated = $request->validate([
            'litres_delta' => ['required', 'numeric'],
            'adjustment_reason_id' => ['required', 'exists:adjustment_reasons,id'],
            'explanation' => ['required', 'string', 'max:2000'],
        ]);

        $this->adjustments->record(
            $delivery,
            (string) $validated['litres_delta'],
            (int) $validated['adjustment_reason_id'],
            $validated['explanation'],
            $this->currentUser(),
        );

        return back()->with('success', 'Adjustment recorded.');
    }
}
