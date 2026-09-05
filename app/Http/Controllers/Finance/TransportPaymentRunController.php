<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\CollectionCenter;
use App\Models\Driver;
use App\Models\PaymentBatch;
use App\Models\TransportPayment;
use App\Models\TransportPaymentRun;
use App\Services\Finance\TransportDisbursementService;
use App\Services\Finance\TransportPaymentRunService;
use App\Services\Payment\Modules\TransportPaymentService;
use App\Services\Payment\PaymentService;
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
        private readonly TransportPaymentService $transportPaymentService,
        private readonly PaymentService $paymentService,
    ) {}

    public function index(Request $request): View
    {
        $this->authorizeAccess('logistics.payments.view', null, 'Transport payments');

        $eligible = $this->runs->eligibleRecipients('all');
        $eligibleTotalMinor = (int) $eligible->sum('available_minor');
        $eligibleCount = $eligible->count();
        $driversCount = $eligible->filter(fn ($item) => $item['driver']->type === 'driver')->count();
        $ridersCount = $eligible->filter(fn ($item) => $item['driver']->type === 'rider')->count();

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
            'eligibleRecipients' => $eligible,
            'eligibleTotalMinor' => $eligibleTotalMinor,
            'eligibleCount' => $eligibleCount,
            'driversCount' => $driversCount,
            'ridersCount' => $ridersCount,
        ]);
    }

    public function show(Request $request, TransportPaymentRun $run): View
    {
        // SCOPE-2 layer 2 — a run id typed into the URL is refused the same way
        // the list would have hidden it.
        $this->authorizeAccess('logistics.payments.view', $run, 'Transport run → '.$run->reference);

        // Trigger sync with gateway for the latest batch before loading the view
        $latestBatch = PaymentBatch::query()
            ->where('source_type', $run->getMorphClass())
            ->where('source_id', $run->getKey())
            ->orderByDesc('id')
            ->first();

        if ($latestBatch && $latestBatch->status !== PaymentBatch::STATUS_CANCELLED) {
            try {
                $this->transportPaymentService->syncBatchStatus($latestBatch, $this->currentUser());
                $run->refresh();
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning("Auto-sync gateway status on show failed for batch {$latestBatch->batch_reference}: " . $e->getMessage());
            }
        }

        $existingDriverIds = $run->payments()->pluck('driver_id')->all();
        $availableToAdd = $run->status === TransportPaymentRun::STATUS_DRAFT
            ? $this->runs->eligibleRecipients('all')->filter(fn ($item) => !in_array($item['driver']->id, $existingDriverIds))->values()
            : collect();

        $batches = PaymentBatch::query()
            ->where('source_type', $run->getMorphClass())
            ->where('source_id', $run->getKey())
            ->with(['items', 'initiatedBy'])
            ->orderByDesc('id')
            ->get();

        $gateways = $this->paymentService->getGatewayStatuses();
        $canInitialize = $this->allows('payments.disbursements.initialize') || $this->allows('logistics.payments.disburse', $run);
        $canAuthorize = $this->allows('payments.disbursements.authorize') || $this->allows('logistics.payments.disburse', $run);

        return view('finance.transport.show', [
            'run' => $run->load(['runBy', 'approvedBy', 'workflowInstance']),
            'payments' => $run->payments()->with(['driver', 'disbursements'])->get()
                ->sortBy(fn (TransportPayment $payment) => $payment->driver?->name),
            'reconciliation' => $this->disbursements->reconcile($run),
            'batches' => $batches,
            'gateways' => $gateways,
            'availableToAdd' => $availableToAdd,
            'canApprove' => $this->allows('logistics.payments.approve', $run),
            'canDisburse' => $this->allows('logistics.payments.disburse', $run),
            'canInitialize' => $canInitialize,
            'canAuthorize' => $canAuthorize,
            'canCancel' => $this->allows('logistics.payments.create', $run) && $run->isEditable(),
            'canReverse' => $this->allows('logistics.payments.reverse', $run) && $run->isApproved(),
        ]);
    }

    public function addRecipient(Request $request, TransportPaymentRun $run): RedirectResponse
    {
        $validated = $request->validate([
            'driver_id' => ['required', 'integer', 'exists:drivers,id'],
            'amount' => ['nullable', 'numeric', 'min:0.01'],
            'amount_minor' => ['nullable', 'integer', 'min:1'],
        ]);

        $driver = Driver::withoutDataScope()->findOrFail($validated['driver_id']);
        $amountMinor = isset($validated['amount'])
            ? (int) round(((float) $validated['amount']) * 100)
            : (int) ($validated['amount_minor'] ?? 0);

        $this->runs->addRecipient($run, $driver, $amountMinor, $this->currentUser());

        return back()->with('success', $driver->name.' added to '.$run->reference.'.');
    }

    public function updateRecipient(Request $request, TransportPaymentRun $run, TransportPayment $payment): RedirectResponse
    {
        $validated = $request->validate([
            'amount' => ['nullable', 'numeric', 'min:0.01'],
            'amount_minor' => ['nullable', 'integer', 'min:1'],
        ]);

        $amountMinor = isset($validated['amount'])
            ? (int) round(((float) $validated['amount']) * 100)
            : (int) ($validated['amount_minor'] ?? 0);

        $this->runs->updateRecipientAmount($run, $payment, $amountMinor, $this->currentUser());

        return back()->with('success', 'Payment amount for '.$payment->driver?->name.' updated to '.\App\Support\Money::format($amountMinor).'.');
    }

    public function removeRecipient(TransportPaymentRun $run, TransportPayment $payment): RedirectResponse
    {
        $driverName = $payment->driver?->name ?? 'Recipient';
        $this->runs->removeRecipient($run, $payment, $this->currentUser());

        return back()->with('success', $driverName.' removed from '.$run->reference.'.');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'recipient_type' => ['nullable', 'string', 'in:all,driver,rider,individual'],
            'driver_ids' => ['nullable', 'array'],
            'driver_ids.*' => ['integer', 'exists:drivers,id'],
            'collection_center_id' => ['nullable', 'integer'],
            'period_start' => ['nullable', 'date'],
            'period_end' => ['nullable', 'date', 'after_or_equal:period_start'],
        ]);

        $recipientType = $validated['recipient_type'] ?? 'all';
        $driverIds = $validated['driver_ids'] ?? [];

        if ($recipientType === 'individual' && empty($driverIds)) {
            return back()->withErrors([
                'driver_ids' => 'Please select at least one rider or driver to generate a run for.',
            ])->withInput();
        }

        /*
         * withoutDataScope, deliberately, and for the reason set out at length
         * in PaymentRunController::store — an Accounts user holds
         * logistics.payments and no milk scope, so a plain findOrFail answers
         * 404 for a button they were just shown. The authority for this action
         * is logistics.payments.create, checked on the route and in the service.
         */
        $center = !empty($validated['collection_center_id'])
            ? CollectionCenter::withoutDataScope()->findOrFail($validated['collection_center_id'])
            : null;

        $run = $this->runs->generate(
            center: $center,
            actor: $this->currentUser(),
            periodStart: $validated['period_start'] ?? null,
            periodEnd: $validated['period_end'] ?? null,
            recipientType: $recipientType,
            driverIds: $driverIds,
        );

        return redirect()->route('transport-payments.show', $run)->with(
            'success',
            $run->driver_count === 0
                ? $run->reference.' opened with nothing to pay — no selected rider or driver has an available positive wallet balance.'
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

    /**
     * Initiate electronic gateway batch disbursement for approved transport payments.
     */
    public function disburseBatch(Request $request, TransportPaymentRun $run): RedirectResponse
    {
        $this->authorizeAnyAccess(
            ['logistics.payments.disburse', 'payments.disbursements.initialize'],
            $run,
            'Disburse Transport Payments Batch'
        );

        $validated = $request->validate([
            'gateway' => ['required', 'string', 'in:monnify,paystack,mock,bank_transfer'],
            'notes' => ['nullable', 'string', 'max:500'],
            'selected_payments' => ['nullable', 'array'],
            'selected_payments.*' => ['integer'],
            'amounts' => ['nullable', 'array'],
            'otp' => ['nullable', 'string', 'max:10'],
        ]);

        $selectedPayments = null;
        if (!empty($validated['selected_payments'])) {
            $selectedPayments = [];
            foreach ($validated['selected_payments'] as $paymentId) {
                $customAmount = isset($validated['amounts'][$paymentId])
                    ? (int) round(((float) $validated['amounts'][$paymentId]) * 100)
                    : null;

                $selectedPayments[] = [
                    'transport_payment_id' => (int) $paymentId,
                    'amount_minor' => $customAmount,
                ];
            }
        }

        try {
            $batch = $this->transportPaymentService->createBatch(
                $run,
                $validated['gateway'],
                $this->currentUser(),
                $validated['notes'] ?? null,
                $selectedPayments
            );

            $batch = $this->transportPaymentService->disburseBatch($batch, $validated['otp'] ?? null);

            if ($batch->status === PaymentBatch::STATUS_PROCESSING || $batch->status === PaymentBatch::STATUS_PENDING_OTP) {
                return redirect()->route('transport-payments.batches.show', [$run, $batch])
                    ->with('info', 'Payment batch initialized. Enter OTP if required or check live status.');
            }

            return redirect()->route('transport-payments.show', $run)
                ->with('success', 'Payment batch ' . $batch->batch_reference . ' disbursed successfully and driver wallets updated.');
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * View detailed payment batch and line items.
     */
    public function batch(TransportPaymentRun $run, PaymentBatch $batch): View
    {
        $this->authorizeAnyAccess(
            ['logistics.payments.view', 'payments.disbursements.view'],
            $run,
            'Transport Payment Batch Details'
        );

        if ($batch->status !== PaymentBatch::STATUS_CANCELLED && ($batch->status !== PaymentBatch::STATUS_COMPLETED || request()->has('sync'))) {
            try {
                $batch = $this->transportPaymentService->syncBatchStatus($batch, $this->currentUser());
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning("Auto-sync gateway status on batch view failed for batch {$batch->batch_reference}: " . $e->getMessage());
            }
        }

        $batch->load(['items', 'initiatedBy', 'authorizedBy']);

        $canAuthorize = $this->allows('payments.disbursements.authorize') || $this->allows('logistics.payments.disburse', $run);

        return view('finance.transport.batch', [
            'run' => $run,
            'batch' => $batch,
            'canAuthorize' => $canAuthorize,
        ]);
    }

    /**
     * Authorize batch payout with OTP.
     */
    public function validateBatchOtp(Request $request, TransportPaymentRun $run, PaymentBatch $batch): RedirectResponse
    {
        $this->authorizeAnyAccess(
            ['payments.disbursements.authorize', 'logistics.payments.disburse'],
            $run,
            'Authorize Transport Payment Batch'
        );

        $validated = $request->validate([
            'otp' => ['required', 'string', 'max:10'],
        ]);

        try {
            $this->transportPaymentService->authorizeBatchOtp($batch, $validated['otp'], $this->currentUser());

            return redirect()->route('transport-payments.batches.show', [$run, $batch])
                ->with('success', 'Batch OTP validated and payout successfully completed. Driver wallets debited.');
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Resend OTP for payment batch.
     */
    public function resendBatchOtp(TransportPaymentRun $run, PaymentBatch $batch): RedirectResponse
    {
        $this->authorizeAnyAccess(
            ['payments.disbursements.authorize', 'logistics.payments.disburse'],
            $run,
            'Resend Batch OTP'
        );

        try {
            $this->transportPaymentService->resendBatchOtp($batch, $this->currentUser());

            return back()->with('success', 'Authorization OTP resent to the registered device.');
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Query and sync live batch status from gateway.
     */
    public function syncBatchStatus(TransportPaymentRun $run, PaymentBatch $batch): RedirectResponse
    {
        $this->authorizeAnyAccess(
            ['logistics.payments.view', 'payments.disbursements.initialize'],
            $run,
            'Sync Batch Status'
        );

        try {
            $this->transportPaymentService->syncBatchStatus($batch, $this->currentUser());

            return back()->with('success', 'Batch status updated from gateway.');
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Cancel an active or pending payment batch to prevent duplicate disbursement.
     */
    public function cancelBatch(TransportPaymentRun $run, PaymentBatch $batch): RedirectResponse
    {
        $this->authorizeAnyAccess(
            ['logistics.payments.disburse', 'payments.disbursements.initialize'],
            $run,
            'Cancel Transport Payment Batch'
        );

        $reason = $run->reference . ' — cancelled by user';

        try {
            $this->transportPaymentService->cancelBatch($batch, $this->currentUser(), $reason);

            return redirect()->route('transport-payments.show', $run)
                ->with('success', 'Payment batch ' . $batch->batch_reference . ' cancelled successfully.');
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
