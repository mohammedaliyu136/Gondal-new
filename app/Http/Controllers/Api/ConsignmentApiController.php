<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\ConsignmentResource;
use App\Models\CollectionPoint;
use App\Models\Consignment;
use App\Models\QualityTestDefinition;
use App\Services\Milk\ConsignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConsignmentApiController extends ApiController
{
    public function __construct(private readonly ConsignmentService $consignments) {}

    public function index(Request $request): JsonResponse
    {
        $consignments = Consignment::query()
            ->with(['collectionPoint', 'collectionCenter', 'grade', 'batch'])
            // BR-8 — ConsignmentResource renders adjustment_total for every row.
            // One aggregate for the page instead of one SUM per row.
            ->withSum('adjustments', 'litres_delta')
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('center'), fn ($query) => $query->where('collection_center_id', $request->integer('center')))
            ->latest('dispatched_at')
            ->paginate($this->perPage($request->integer('per_page') ?: null));

        return ConsignmentResource::collection($consignments)->response();
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'collection_point_id' => ['required', 'exists:collection_points,id'],
            'delivery_ids' => ['required', 'array', 'min:1'],
            'delivery_ids.*' => ['integer', 'exists:deliveries,id'],
            'containers' => ['nullable', 'integer', 'min:0'],
            'trip_id' => ['nullable', 'exists:trips,id'],
            'dispatched_at' => ['nullable', 'date'],
        ]);

        $point = CollectionPoint::query()->findOrFail($validated['collection_point_id']);

        $this->authorizeAccess('milk.consignment.confirm.create', $point, 'API: dispatch from '.$point->name);

        $consignment = $this->consignments->dispatch(
            $point,
            array_map('intval', $validated['delivery_ids']),
            $validated,
            $this->currentUser(),
        );

        return ConsignmentResource::make($consignment->load(['collectionPoint', 'collectionCenter', 'deliveries']))
            ->response()
            ->setStatusCode(201);
    }

    /** BR-4 */
    public function storeQualityTest(Request $request, Consignment $consignment): JsonResponse
    {
        $this->authorizeAccess('milk.grade.create', $consignment, 'API: quality test on '.$consignment->reference);

        $validated = $request->validate([
            'quality_test_definition_id' => ['required', 'exists:quality_test_definitions,id'],
            'reading' => ['required', 'string', 'max:32'],
        ]);

        $test = $this->consignments->recordQualityTest(
            $consignment,
            QualityTestDefinition::query()->findOrFail($validated['quality_test_definition_id']),
            $validated['reading'],
            $this->currentUser(),
        );

        return $this->ok([
            'test_type' => $test->test_type,
            'reading' => $test->reading,
            'acceptable_range' => $test->acceptable_range,
            'passed' => (bool) $test->passed,
        ], 201);
    }

    /**
     * BR-8 / BR-13 / BR-14, and NFR-4: a client that read the consignment sends
     * its lock_version back. A stale version is a 422 with the rule ID rather
     * than a silent overwrite.
     */
    public function confirm(Request $request, Consignment $consignment): JsonResponse
    {
        $this->authorizeAccess('milk.consignment.confirm.edit', $consignment, 'API: confirm '.$consignment->reference);

        $validated = $request->validate([
            'litres_rejected_at_center' => ['nullable', 'numeric', 'min:0'],
            'rejection_reason_id' => ['nullable', 'exists:rejection_reasons,id'],
            'grade_id' => ['nullable', 'exists:grades,id'],
            'intake_temperature_c' => ['nullable', 'numeric'],
            'officer_notes' => ['nullable', 'string', 'max:2000'],
            // BR-14 — a record of when, never the price. See the same field on
            // ConsignmentController::confirm; the service anchors the rate
            // snapshot to the server clock whatever arrives here.
            'confirmed_at' => ['nullable', 'date', 'before_or_equal:now'],
            'lock_version' => ['nullable', 'integer'],
        ]);

        // NFR-4 — an explicit stale-read check, so the client hears about it
        // before any work is attempted.
        if (array_key_exists('lock_version', $validated)
            && $validated['lock_version'] !== null
            && (int) $validated['lock_version'] !== (int) $consignment->lock_version) {
            return response()->json([
                'message' => 'This consignment changed since you read it. Fetch it again.',
                'rule' => 'NFR-4',
                'context' => [
                    'your_version' => (int) $validated['lock_version'],
                    'current_version' => (int) $consignment->lock_version,
                ],
            ], 422);
        }

        $consignment = $this->consignments->confirm($consignment, $validated, $this->currentUser());

        return ConsignmentResource::make($consignment->load(['grade', 'gradeRate', 'collectionPoint', 'qualityTests']))
            ->response();
    }
}
