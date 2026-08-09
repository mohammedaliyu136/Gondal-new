<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\DeliveryResource;
use App\Models\CollectionPoint;
use App\Models\Delivery;
use App\Models\Farmer;
use App\Models\RejectionReason;
use App\Services\Milk\DeliveryService;
use App\Support\Wat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The field-capture endpoint ARCH-2 exists for.
 *
 * ARCH-7 — a client that is unsure whether its POST landed retries with the same
 * Idempotency-Key and gets the original delivery back rather than a duplicate.
 */
class DeliveryApiController extends ApiController
{
    public function __construct(private readonly DeliveryService $deliveries) {}

    public function index(Request $request): JsonResponse
    {
        // NFR-2 — paginated, never unbounded. SCOPE-2 — the global scope has
        // already narrowed this to the caller.
        $deliveries = Delivery::query()
            ->with(['farmer', 'collectionPoint', 'rejectionReason', 'consignment'])
            ->when($request->filled('point'), fn ($query) => $query->where('collection_point_id', $request->integer('point')))
            /*
             * ARCH-2/ARCH-9 — the API and the web must answer the same question.
             * `date` is the caller's WAT calendar day, so it resolves to the UTC
             * range bounding it; Request::date() alone parses in config
             * app.timezone (UTC), which handed a phone asking for "today" the hour
             * 01:00 WAT today through 00:59 WAT tomorrow.
             */
            ->when($request->filled('date'), function ($query) use ($request) {
                [$dayStart, $dayEnd] = Wat::dayRange($request->string('date')->toString());

                $query->where('delivered_at', '>=', $dayStart)->where('delivered_at', '<', $dayEnd);
            })
            /*
             * `since` is an instant, and a naive one is read as WAT like every other
             * operator-supplied time (Wat::instant). Read as UTC it sat an hour in
             * the future, so a sync cursor expressed in local wall clock silently
             * skipped an hour of records on every poll.
             */
            ->when($request->filled('since'), fn ($query) => $query
                ->where('updated_at', '>=', Wat::instant($request->string('since')->toString())))
            ->latest('delivered_at')
            ->paginate($this->perPage($request->integer('per_page') ?: null));

        return DeliveryResource::collection($deliveries)->response();
    }

    public function show(Delivery $delivery): JsonResponse
    {
        $this->authorizeAccess('milk.deliveries.view', $delivery, 'API: delivery '.$delivery->reference);

        return DeliveryResource::make($delivery->load([
            'farmer.cooperative', 'collectionPoint.collectionCenter', 'rejectionReason',
            'consignment', 'recordedBy',
        ]))->response();
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'collection_point_id' => ['required', 'exists:collection_points,id'],
            'farmer_id' => ['required', 'exists:farmers,id'],
            'delivered_at' => ['nullable', 'date'],
            'litres_presented' => ['required', 'numeric', 'min:0.01'],
            'litres_rejected' => ['nullable', 'numeric', 'min:0'],
            'rejection_reason_id' => ['nullable', 'exists:rejection_reasons,id'],
            'containers' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'cutoff_override' => ['nullable', 'boolean'],
            'cutoff_override_reason' => ['nullable', 'string', 'max:255'],
        ]);

        $point = CollectionPoint::query()->findOrFail($validated['collection_point_id']);
        $farmer = Farmer::query()->findOrFail($validated['farmer_id']);

        $this->authorizeAccess('milk.deliveries.create', $point, 'API: record intake at '.$point->name);

        $delivery = $this->deliveries->record($point, $farmer, $validated, $this->currentUser());

        return DeliveryResource::make($delivery->load(['farmer', 'collectionPoint', 'rejectionReason']))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * The pickers a field client needs, so it can validate offline against the
     * same reference data the server will enforce (BR-1, BR-3).
     */
    public function captureContext(Request $request): JsonResponse
    {
        $this->authorizeAccess('milk.deliveries.create', null, 'API: capture context');

        $points = CollectionPoint::query()->active()->with(['community.lga', 'collectionCenter'])->get();

        return $this->ok([
            'server_time_wat' => Wat::now()->toIso8601String(),
            'points' => $points->map(fn (CollectionPoint $point) => [
                'id' => $point->id,
                'code' => $point->code,
                'name' => $point->name,
                // BR-3 — the client can warn before it submits.
                'cutoff_time' => $point->effectiveCutoff(),
                'center' => $point->collectionCenter?->name,
            ]),
            // BR-1 — the ONLY reasons the server will accept at a point.
            'rejection_reasons' => RejectionReason::query()
                ->availableAt(RejectionReason::STAGE_POINT)
                ->orderBy('position')
                ->get()
                ->map(fn ($reason) => [
                    'id' => $reason->id,
                    'code' => $reason->code,
                    'name' => $reason->name,
                    'help_text' => $reason->help_text,
                ]),
            'idempotency_header' => config('gondal.idempotency.header'),
        ]);
    }
}
