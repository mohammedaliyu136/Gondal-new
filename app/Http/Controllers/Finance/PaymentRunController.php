<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\CollectionCenter;
use App\Models\Cooperative;
use App\Models\Farmer;
use App\Models\FarmerPayment;
use App\Models\PaymentBatch;
use App\Models\PaymentRun;
use App\Services\Finance\FarmerDisbursementService;
use App\Services\Finance\FarmerPaymentReversalService;
use App\Services\Finance\FarmerPaymentRunService;
use App\Services\Finance\FarmerStatementService;
use App\Services\Payment\Modules\FarmerPaymentService;
use App\Services\Payment\PaymentService;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * §14 Phase 7 — the farmer payment screens.
 *
 * The module the payroll screen said was "not available yet" for the whole of
 * phases 1-6. It is deliberately shaped like the payroll screens: a list of
 * runs, a run with its lines, an approval that rides the workflow, and a payout
 * record — because Accounts staff should learn one shape, not two.
 */
class PaymentRunController extends Controller
{
    public function __construct(
        private readonly FarmerPaymentRunService $runs,
        private readonly FarmerDisbursementService $disbursements,
        private readonly FarmerPaymentReversalService $reversals,
        private readonly FarmerStatementService $statements,
        private readonly FarmerPaymentService $farmerPaymentService,
        private readonly PaymentService $paymentService,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): View
    {
        $this->authorizeAccess('finance.farmer_payments.view', null, 'Farmer payments');

        return view('finance.payments.index', [
            'runs' => PaymentRun::query()
                ->with(['runBy', 'approvedBy'])
                ->latest('id')
                ->paginate($this->perPage($request->integer('per_page') ?: null))
                ->withQueryString(),
            'centers' => CollectionCenter::query()->orderBy('name')->get(),
            'cooperatives' => Cooperative::query()->orderBy('name')->get(),
            'canCreate' => $this->allows('finance.farmer_payments.create'),
        ]);
    }

    public function show(Request $request, PaymentRun $run): View
    {
        // SCOPE-2 layer 2 — a run id typed into the URL is refused the same way
        // the list would have hidden it.
        $this->authorizeAccess('finance.farmer_payments.view', $run, 'Payment run → '.$run->reference);

        $batches = PaymentBatch::query()
            ->where('source_type', $run->getMorphClass())
            ->where('source_id', $run->getKey())
            ->with(['items', 'initiatedBy', 'authorizedBy'])
            ->latest('id')
            ->get();

        $gateways = $this->paymentService->getGatewayStatuses();
        $canInitialize = $this->allows('payments.disbursements.initialize') || $this->allows('finance.farmer_payments.disburse');
        $canAuthorize = $this->allows('payments.disbursements.authorize') || $this->allows('finance.farmer_payments.disburse');

        return view('finance.payments.show', [
            'run' => $run->load(['runBy', 'approvedBy', 'workflowInstance']),
            'payments' => $run->payments()->with(['farmer', 'disbursements'])->get()->sortBy('farmer.name'),
            'reconciliation' => $this->disbursements->reconcile($run),
            'batches' => $batches,
            'gateways' => $gateways,
            'canApprove' => $this->allows('finance.farmer_payments.approve', $run),
            'canDisburse' => $this->allows('finance.farmer_payments.disburse', $run),
            'canInitialize' => $canInitialize,
            'canAuthorize' => $canAuthorize,
            'canCancel' => $this->allows('finance.farmer_payments.create', $run) && $run->isEditable(),
            'canReverse' => $this->allows('finance.farmer_payments.reverse', $run) && $run->isApproved(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'scope_type' => ['required', 'in:collection_center,cooperative'],
            'scope_id' => ['required', 'integer'],
            'period_start' => ['nullable', 'date'],
            'period_end' => ['nullable', 'date', 'after_or_equal:period_start'],
        ]);

        /*
         * withoutDataScope, deliberately — and this cost an hour to find.
         *
         * CollectionCenter and Cooperative are Scopeable, so a plain findOrFail
         * resolves them through the caller's MILK and COMMUNITY scopes. An
         * Accounts user holds finance.farmer_payments and neither of those, so
         * every centre was invisible to them, findOrFail threw
         * ModelNotFoundException, and generating a run answered 404 — a "that
         * URL does not exist" for a button the user had just been shown.
         *
         * The authority for this action is finance.farmer_payments.create,
         * checked on the route and again in the service. Which centre exists is
         * a structural fact, not a question about what Accounts may see.
         */
        $scope = $validated['scope_type'] === PaymentRun::SCOPE_CENTER
            ? CollectionCenter::withoutDataScope()->findOrFail($validated['scope_id'])
            : Cooperative::withoutDataScope()->findOrFail($validated['scope_id']);

        $run = $this->runs->generate(
            $scope,
            $this->currentUser(),
            $validated['period_start'] ?? null,
            $validated['period_end'] ?? null,
        );

        return redirect()->route('payment-runs.show', $run)->with(
            'success',
            $run->farmer_count === 0
                // Not an error. It is what a correctly-behaving second run looks
                // like, and saying so stops somebody generating a third.
                ? $run->reference.' opened with nothing to pay — every delivery in this scope is already on a run.'
                : $run->reference.' generated.',
        );
    }

    public function submit(PaymentRun $run): RedirectResponse
    {
        $this->authorizeAccess('finance.farmer_payments.create', $run, 'Submit '.$run->reference);

        $this->runs->submitForApproval($run, $this->currentUser());

        return back()->with('success', $run->reference.' sent for approval.');
    }

    public function cancel(Request $request, PaymentRun $run): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        $this->authorizeAccess('finance.farmer_payments.create', $run, 'Cancel '.$run->reference);

        $this->runs->cancel($run, $this->currentUser(), $validated['reason']);

        return back()->with('success', $run->reference.' cancelled — its deliveries are unclaimed and will appear on the next run.');
    }


    /**
     * Undo one farmer's payment.
     *
     * If money already left, this creates a debt the farmer carries — see
     * FarmerPaymentReversalService. The confirmation on the screen says so in
     * naira before anyone presses it.
     */
    public function reversePayment(Request $request, FarmerPayment $payment): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        $clawback = $this->reversals->clawbackPreviewMinor($payment);

        $this->reversals->reversePayment($payment, $this->currentUser(), $validated['reason']);

        return back()->with('success', $clawback > 0
            ? sprintf('Payment reversed. %s already paid is now recoverable from future milk.',
                \App\Support\Money::format($clawback))
            : 'Payment reversed. Nothing had been paid out, and the milk is payable again.');
    }

    public function reverseRun(Request $request, PaymentRun $run): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        $result = $this->reversals->reverseRun($run, $this->currentUser(), $validated['reason']);

        return back()->with('success', sprintf('%s reversed — %d payment(s), %s recoverable.',
            $run->reference, $result['reversed'], \App\Support\Money::format($result['clawback_minor'])));
    }


    /**
     * One farmer's money history, printable.
     *
     * USER-2 — a farmer has no login, so this is a page an officer prints and
     * hands over. Two permissions are checked, not one: the caller must be
     * allowed to see farmer payment figures AND allowed to see this particular
     * farmer, because the record scope on `farmers` is what stops a Numan
     * officer printing a Girei farmer's earnings.
     */
    public function statement(Request $request, Farmer $farmer): View
    {
        $this->authorizeAccess('finance.farmer_payments.view', null, 'Farmer statement');
        $this->authorizeAccess('community.farmers.view', $farmer, 'Statement → '.$farmer->name);

        return view('finance.payments.statement', array_merge($this->statements->build(
            $farmer->load(['community.lga', 'cooperative', 'defaultCollectionPoint.collectionCenter']),
            $request->string('from')->toString() ?: null,
            $request->string('to')->toString() ?: null,
        ), [
            'canEditPayout' => $this->allows('finance.farmer_payments.create'),
        ]));
    }


    /**
     * Where this farmer's money is sent.
     *
     * Gated on finance.farmer_payments.create, NOT on community.farmers.edit —
     * which is the whole point of it being a separate action. An Extension Agent
     * holds farmers.edit and is trusted to correct a herd size; letting the same
     * grant redirect somebody's bank payments is the fraud the plan calls the
     * largest surface in the ERP (§7). Two different jobs, two different keys.
     *
     * The account number is NEVER stored in full. Only the last four digits
     * survive, which is enough for a payer to check they are looking at the
     * right account and not enough to move money with if this database leaks.
     */
    public function updatePayoutDetails(Request $request, Farmer $farmer): RedirectResponse
    {
        $this->authorizeAccess('finance.farmer_payments.create', null, 'Farmer payout details');
        $this->authorizeAccess('community.farmers.view', $farmer, 'Payout details → '.$farmer->name);

        $validated = $request->validate([
            'payout_method' => ['nullable', 'in:cash,bank,mobile_money,via_cooperative'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'bank_code' => ['nullable', 'string', 'max:16'],
            'bank_account' => ['nullable', 'string', 'max:32'],
            'bank_account_number' => ['nullable', 'string', 'max:32'],
            'account_name' => ['nullable', 'string', 'max:255'],
            'mobile_money_number' => ['nullable', 'string', 'max:32'],
        ]);

        $before = $farmer->only(['payout_method', 'bank_name', 'bank_code', 'bank_account', 'account_name', 'bank_account_masked', 'mobile_money_number']);

        $rawAcc = $validated['bank_account'] ?? ($validated['bank_account_number'] ?? '');
        $account = preg_replace('/\D/', '', (string) $rawAcc);

        $farmer->fill([
            'payout_method' => $validated['payout_method'] ?? null,
            'bank_name' => $validated['bank_name'] ?? null,
            'bank_code' => $validated['bank_code'] ?? null,
            'bank_account' => $account ?: null,
            'account_name' => $validated['account_name'] ?? null,
            'mobile_money_number' => $validated['mobile_money_number'] ?? null,
        ]);

        // Blank leaves the stored mask alone; clearing it is done by clearing the
        // bank name, so a typo in the account field cannot silently wipe it.
        if ($account !== '') {
            $farmer->bank_account_masked = str_repeat('*', max(0, strlen($account) - 4)).substr($account, -4);
        }

        if (($validated['bank_name'] ?? null) === null) {
            $farmer->bank_account = null;
            $farmer->bank_code = null;
            $farmer->account_name = null;
            $farmer->bank_account_masked = null;
        }

        $farmer->save();

        $this->audit->edited(
            $farmer,
            sprintf('Payout details for %s changed to %s', $farmer->name,
                $farmer->payout_method ? \Illuminate\Support\Str::headline($farmer->payout_method) : 'none set'),
            'Finance',
            $before,
            $farmer->only(['payout_method', 'bank_name', 'bank_account_masked', 'mobile_money_number']),
            $this->currentUser(),
        );

        return back()->with('success', 'Payout details updated.');
    }

    public function disburse(Request $request, FarmerPayment $payment): RedirectResponse
    {
        $validated = $request->validate([
            'amount_minor' => ['required', 'integer', 'min:1'],
            'method' => ['required', 'string', 'max:24'],
            'received_by' => ['nullable', 'string', 'max:255'],
            'received_by_relation' => ['nullable', 'string', 'max:32'],
            'proxy_authority_ref' => ['nullable', 'string', 'max:255'],
            'external_reference' => ['nullable', 'string', 'max:255'],
            'disbursed_at' => ['nullable', 'date'],
        ]);

        $this->disbursements->record($payment, $validated, $this->currentUser());

        return back()->with('success', 'Payout recorded.');
    }

    /**
     * Initiate electronic gateway batch disbursement for approved farmer payments.
     */
    public function disburseBatch(Request $request, PaymentRun $run): RedirectResponse
    {
        $this->authorizeAnyAccess(
            ['finance.farmer_payments.disburse', 'payments.disbursements.initialize'],
            $run,
            'Disburse Farmer Payments Batch'
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
                    'farmer_payment_id' => (int) $paymentId,
                    'amount_minor' => $customAmount,
                ];
            }
        }

        try {
            $batch = $this->farmerPaymentService->createBatch(
                $run,
                $validated['gateway'],
                $this->currentUser(),
                $validated['notes'] ?? null,
                $selectedPayments
            );

            $batch = $this->farmerPaymentService->disburseBatch($batch, $validated['otp'] ?? null);

            if ($batch->status === PaymentBatch::STATUS_PROCESSING) {
                return redirect()->route('payment-runs.batches.show', [$run, $batch])
                    ->with('info', 'Payment batch initialized. Enter OTP if required or check live status.');
            }

            return redirect()->route('payment-runs.show', $run)
                ->with('success', 'Payment batch ' . $batch->batch_reference . ' disbursed successfully and farmer wallets updated.');
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * View detailed payment batch and line items.
     */
    public function batch(PaymentRun $run, PaymentBatch $batch): View
    {
        $this->authorizeAnyAccess(
            ['finance.farmer_payments.view', 'payments.disbursements.view'],
            $run,
            'Farmer Payment Batch Details'
        );

        $batch->load(['items', 'initiatedBy', 'authorizedBy']);

        $canAuthorize = $this->allows('payments.disbursements.authorize') || $this->allows('finance.farmer_payments.disburse');

        return view('finance.payments.batch', [
            'run' => $run,
            'batch' => $batch,
            'canAuthorize' => $canAuthorize,
        ]);
    }

    /**
     * Authorize batch payout with OTP.
     */
    public function validateBatchOtp(Request $request, PaymentRun $run, PaymentBatch $batch): RedirectResponse
    {
        $this->authorizeAnyAccess(
            ['payments.disbursements.authorize', 'finance.farmer_payments.disburse'],
            $run,
            'Authorize Farmer Payment Batch'
        );

        $validated = $request->validate([
            'otp' => ['required', 'string', 'max:10'],
        ]);

        try {
            $this->farmerPaymentService->authorizeBatchOtp($batch, $validated['otp'], $this->currentUser());

            return redirect()->route('payment-runs.batches.show', [$run, $batch])
                ->with('success', 'Batch OTP validated and payout successfully completed. Farmer wallets debited.');
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Resend OTP for payment batch.
     */
    public function resendBatchOtp(PaymentRun $run, PaymentBatch $batch): RedirectResponse
    {
        $this->authorizeAnyAccess(
            ['payments.disbursements.authorize', 'finance.farmer_payments.disburse'],
            $run,
            'Resend Batch OTP'
        );

        try {
            $this->farmerPaymentService->resendBatchOtp($batch, $this->currentUser());

            return back()->with('success', 'Authorization OTP resent to the registered device.');
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Query and sync live batch status from gateway.
     */
    public function syncBatchStatus(PaymentRun $run, PaymentBatch $batch): RedirectResponse
    {
        $this->authorizeAnyAccess(
            ['finance.farmer_payments.view', 'payments.disbursements.initialize'],
            $run,
            'Sync Batch Status'
        );

        try {
            $this->farmerPaymentService->syncBatchStatus($batch, $this->currentUser());

            return back()->with('success', 'Batch status updated from gateway.');
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Cancel an active or pending payment batch to prevent duplicate disbursement.
     */
    public function cancelBatch(PaymentRun $run, PaymentBatch $batch): RedirectResponse
    {
        $this->authorizeAnyAccess(
            ['finance.farmer_payments.disburse', 'payments.disbursements.initialize'],
            $run,
            'Cancel Payment Batch'
        );

        if ($batch->source_id !== $run->id || $batch->source_type !== $run->getMorphClass()) {
            abort(404);
        }

        try {
            $this->farmerPaymentService->cancelBatch($batch, $this->currentUser(), request('reason'));

            return back()->with('success', "Payment batch {$batch->batch_reference} has been cancelled.");
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
