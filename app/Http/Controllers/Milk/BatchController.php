<?php

namespace App\Http\Controllers\Milk;

use App\Http\Controllers\Controller;
use App\Models\Batch;
use App\Models\CollectionCenter;
use App\Models\Consignment;
use App\Services\Milk\BatchService;
use App\Support\Volume;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** batches.html and batch-detail.html. */
class BatchController extends Controller
{
    public function __construct(private readonly BatchService $batches) {}

    public function index(Request $request): View
    {
        $batches = Batch::query()
            ->with(['collectionCenter', 'trip.driver', 'dispatchedBy', 'reconciledBy', 'discrepancyCause'])
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('center'), fn ($query) => $query->where('collection_center_id', $request->integer('center')))
            ->latest('dispatched_at')
            ->paginate($this->perPage($request->integer('per_page') ?: null))
            ->withQueryString();

        return view('milk.batches.index', [
            'batches' => $batches,
            'centers' => CollectionCenter::query()->orderBy('name')->get(),
            'batchable' => Consignment::query()->batchable()->with(['collectionPoint', 'collectionCenter', 'grade'])->get(),
            'canDispatch' => $this->allows('milk.batch.dispatch.create'),
        ]);
    }

    public function show(Batch $batch): View
    {
        $this->authorizeAccess('milk.batch.dispatch.view', $batch, 'Batch → '.$batch->reference);

        return view('milk.batches.show', [
            'batch' => $batch->load([
                'collectionCenter', 'trip.driver', 'trip.vehicle', 'trip.route',
                'consignments.collectionPoint', 'consignments.grade',
                'dispatchedBy', 'reconciledBy', 'releasedBy', 'discrepancyCause', 'rejectionReason',
                'adjustments.reason',
            ]),
            'canReconcile' => $this->allows('milk.reconciliation.create', $batch),
            'canRelease' => $this->allows('milk.reconciliation.approve', $batch),
        ]);
    }

    /** BR-9 — only confirmed and graded consignments may join. */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'collection_center_id' => ['required', 'exists:collection_centers,id'],
            'consignment_ids' => ['required', 'array', 'min:1'],
            'consignment_ids.*' => ['integer', 'exists:consignments,id'],
            'containers' => ['nullable', 'integer', 'min:0'],
            'trip_id' => ['nullable', 'exists:trips,id'],
            'dispatched_at' => ['nullable', 'date'],
        ]);

        $center = CollectionCenter::query()->findOrFail($validated['collection_center_id']);

        $this->authorizeAccess('milk.batch.dispatch.create', $center, 'Dispatch a batch from '.$center->name);

        $batch = $this->batches->dispatch(
            $center,
            array_map('intval', $validated['consignment_ids']),
            $validated,
            $this->currentUser(),
        );

        return redirect()->route('batches.show', $batch)->with(
            'success',
            sprintf('%s dispatched — %s.', $batch->reference, Volume::format($batch->litres_dispatched)),
        );
    }
}
