<?php

namespace App\Http\Controllers\Milk;

use App\Http\Controllers\Controller;
use App\Models\Batch;
use App\Models\DiscrepancyCause;
use App\Models\RejectionReason;
use App\Services\Milk\BatchService;
use App\Support\Settings;
use App\Support\Volume;
use App\Support\Wat;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * factory-reconciliation.html.
 *
 * NG-5 — scope ends here. Nothing downstream of factory intake is modelled.
 * BR-10 / BR-11 — the discrepancy and the tolerance rule.
 */
class ReconciliationController extends Controller
{
    public function __construct(private readonly BatchService $batches) {}

    public function index(Request $request): View
    {
        $date = $request->date('date')?->toDateString() ?? Wat::today()->toDateString();

        $inTransit = Batch::query()
            ->inTransit()
            ->with(['collectionCenter', 'trip.driver', 'consignments'])
            ->orderBy('dispatched_at')
            ->get();

        /*
         * ARCH-9 — factory intake runs into the small hours, so this is the screen
         * most likely to be used inside the hour the UTC day is still yesterday. A
         * batch reconciled at 00:20 was filed against the previous day: the day
         * whose milk it was did not show it received, and the day before showed
         * litres received it had never been dispatched. BR-10/BR-11's variance
         * picture is summed from this same collection, so it inherited the shift.
         */
        [$dayStart, $dayEnd] = Wat::dayRange($date);

        $reconciledToday = Batch::query()
            ->whereNotNull('reconciled_at')
            ->where('reconciled_at', '>=', $dayStart)
            ->where('reconciled_at', '<', $dayEnd)
            ->with(['collectionCenter', 'discrepancyCause', 'reconciledBy', 'rejectionReason'])
            ->latest('reconciled_at')
            ->get();

        return view('milk.reconciliation.index', [
            'inTransit' => $inTransit,
            'reconciledToday' => $reconciledToday,
            'awaitingRelease' => Batch::query()->awaitingRelease()->with('collectionCenter')->get(),
            'date' => $date,
            // §9 — the tolerance is a setting.
            'tolerance' => Settings::decimalString('milk.batch_discrepancy_tolerance_pct', '1.0'),
            'causes' => DiscrepancyCause::query()->active()->orderBy('position')->get(),
            'factoryReasons' => RejectionReason::query()->availableAt(RejectionReason::STAGE_FACTORY)->orderBy('position')->get(),
            'litresReceivedToday' => Volume::sum($reconciledToday->pluck('litres_received')->all()),
            'litresDispatchedToday' => Volume::sum($reconciledToday->pluck('litres_dispatched')->all()),
            'canReconcile' => $this->allows('milk.reconciliation.create'),
            'canRelease' => $this->allows('milk.reconciliation.approve'),
        ]);
    }

    public function store(Request $request, Batch $batch): RedirectResponse
    {
        $this->authorizeAccess('milk.reconciliation.create', $batch, 'Reconcile '.$batch->reference);

        $validated = $request->validate([
            'litres_received' => ['required', 'numeric', 'min:0'],
            'containers_received' => ['nullable', 'integer', 'min:0'],
            'discrepancy_cause_id' => ['nullable', 'exists:discrepancy_causes,id'],
            'litres_rejected_at_factory' => ['nullable', 'numeric', 'min:0'],
            'rejection_reason_id' => ['nullable', 'exists:rejection_reasons,id'],
            'supervisor_notes' => ['nullable', 'string', 'max:2000'],
            'reconciled_at' => ['nullable', 'date'],
        ]);

        $batch = $this->batches->reconcile($batch, $validated, $this->currentUser());

        return back()->with(
            $batch->exceedsTolerance() ? 'warning' : 'success',
            sprintf(
                '%s reconciled — %s L variance (%s%%) against a %s%% tolerance.%s',
                $batch->reference,
                (string) $batch->discrepancy_litres,
                $batch->discrepancyPercentage() ?? '0.00',
                $batch->tolerancePercentage(),
                $batch->exceedsTolerance() ? ' A supervisor note is required before release.' : '',
            ),
        );
    }

    /** BR-11 */
    public function release(Request $request, Batch $batch): RedirectResponse
    {
        $this->authorizeAccess('milk.reconciliation.approve', $batch, 'Release '.$batch->reference);

        $validated = $request->validate([
            'supervisor_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->batches->release($batch, $validated['supervisor_notes'] ?? null, $this->currentUser());

        return back()->with('success', $batch->reference.' released to production and payment.');
    }
}
