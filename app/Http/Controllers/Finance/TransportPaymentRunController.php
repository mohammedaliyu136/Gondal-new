<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\CollectionCenter;
use App\Models\TransportPayment;
use App\Models\TransportPaymentRun;
use App\Services\Finance\TransportDisbursementService;
use App\Services\Finance\TransportPaymentRunService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * §14 Phase 7 — the transport payment screens.
 *
 * The screen `logistics.payments` gated for three phases while nothing stood
 * behind it. Shaped like the farmer payment screens on purpose: a list of runs,
 * a run with its lines, an approval that rides the workflow, and a payout
 * record.
 */
class TransportPaymentRunController extends Controller
{
    public function __construct(
        private readonly TransportPaymentRunService $runs,
        private readonly TransportDisbursementService $disbursements,
    ) {}

    public function index(Request $request): View
    {
        $this->authorizeAccess('logistics.payments.view', null, 'Transport payments');

        return view('finance.transport.index', [
            'runs' => TransportPaymentRun::query()
                ->with(['runBy', 'approvedBy'])
                ->latest('id')
                ->paginate($this->perPage($request->integer('per_page') ?: null))
                ->withQueryString(),
            'centers' => CollectionCenter::query()->orderBy('name')->get(),
            'canCreate' => $this->allows('logistics.payments.create'),
            // The figure that answers "is there anything to run?" — legs that
            // have arrived, carry a fee, and no run has claimed.
            'unclaimedTrips' => $this->disbursements->unclaimedTripCount(),
        ]);
    }

    public function show(Request $request, TransportPaymentRun $run): View
    {
        // SCOPE-2 layer 2 — a run id typed into the URL is refused the same way
        // the list would have hidden it.
        $this->authorizeAccess('logistics.payments.view', $run, 'Transport run → '.$run->reference);

        return view('finance.transport.show', [
            'run' => $run->load(['runBy', 'approvedBy', 'workflowInstance']),
            'payments' => $run->payments()->with(['driver', 'disbursements'])->get()
                ->sortBy(fn (TransportPayment $payment) => $payment->driver?->name),
            'reconciliation' => $this->disbursements->reconcile($run),
            'canApprove' => $this->allows('logistics.payments.approve', $run),
            'canDisburse' => $this->allows('logistics.payments.disburse', $run),
            'canCancel' => $this->allows('logistics.payments.create', $run) && $run->isEditable(),
            'canReverse' => $this->allows('logistics.payments.reverse', $run) && $run->isApproved(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            // Blank means the whole network, which is the only way to reach a
            // leg whose collection centre was never recorded.
            'collection_center_id' => ['nullable', 'integer'],
            'period_start' => ['nullable', 'date'],
            'period_end' => ['nullable', 'date', 'after_or_equal:period_start'],
        ]);

        /*
         * withoutDataScope, deliberately, and for the reason set out at length
         * in PaymentRunController::store — an Accounts user holds
         * logistics.payments and no milk scope, so a plain findOrFail answers
         * 404 for a button they were just shown. The authority for this action
         * is logistics.payments.create, checked on the route and in the service.
         */
        $center = $validated['collection_center_id'] ?? null
            ? CollectionCenter::withoutDataScope()->findOrFail($validated['collection_center_id'])
            : null;

        $run = $this->runs->generate(
            $center,
            $this->currentUser(),
            $validated['period_start'] ?? null,
            $validated['period_end'] ?? null,
        );

        return redirect()->route('transport-payments.show', $run)->with(
            'success',
            $run->driver_count === 0
                // Not an error. It is what a correctly-behaving second run looks
                // like, and saying so stops somebody generating a third.
                ? $run->reference.' opened with nothing to pay — every arrived trip in this scope is already on a run.'
                : $run->reference.' generated.',
        );
    }

    public function submit(TransportPaymentRun $run): RedirectResponse
    {
        $this->authorizeAccess('logistics.payments.create', $run, 'Submit '.$run->reference);

        $this->runs->submitForApproval($run, $this->currentUser());

        return back()->with('success', $run->reference.' sent for approval.');
    }

    public function cancel(Request $request, TransportPaymentRun $run): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        $this->authorizeAccess('logistics.payments.create', $run, 'Cancel '.$run->reference);

        $this->runs->cancel($run, $this->currentUser(), $validated['reason']);

        return back()->with('success', $run->reference.' cancelled — its trips are released and will appear on the next run.');
    }

    public function disburse(Request $request, TransportPayment $payment): RedirectResponse
    {
        $validated = $request->validate([
            'amount_minor' => ['required', 'integer', 'min:1'],
            'method' => ['required', 'string', 'max:24'],
            'received_by' => ['nullable', 'string', 'max:255'],
            'external_reference' => ['nullable', 'string', 'max:255'],
            'disbursed_at' => ['nullable', 'date'],
        ]);

        $this->disbursements->record($payment, $validated, $this->currentUser());

        return back()->with('success', 'Payout recorded.');
    }

    public function reversePayment(Request $request, TransportPayment $payment): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        $paid = (int) $payment->disbursements()->sum('amount_minor');

        $this->disbursements->reverse($payment, $this->currentUser(), $validated['reason']);

        return back()->with('success', $paid > 0
            // Said plainly: there is no rider ledger to carry a balance, so an
            // overpayment here is a conversation rather than a database row.
            ? sprintf('Payment reversed. %s had already been handed over and is not recovered by this — it has to be collected from the driver.', Money::format($paid))
            : 'Payment reversed. Nothing had been paid out, and the trips are payable again.');
    }

    public function reverseRun(Request $request, TransportPaymentRun $run): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        $result = $this->disbursements->reverseRun($run, $this->currentUser(), $validated['reason']);

        return back()->with('success', sprintf('%s reversed — %d payment(s), %s already handed over.',
            $run->reference, $result['reversed'], Money::format($result['unrecovered_minor'])));
    }
}
