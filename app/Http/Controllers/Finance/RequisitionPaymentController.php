<?php

namespace App\Http\Controllers\Finance;

use App\Authorization\Access;
use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\PaymentBatch;
use App\Models\Requisition;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Finance\RequisitionSpendService;
use App\Services\Payment\Modules\RequisitionPaymentService;
use App\Services\Payment\PaymentService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class RequisitionPaymentController extends Controller
{
    public function __construct(
        private readonly Access $access,
        private readonly RequisitionPaymentService $requisitionPaymentService,
        private readonly RequisitionSpendService $spendService,
        private readonly PaymentService $paymentService,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): View
    {
        $this->authorizeAnyAccess(
            ['purchase.requisitions.spend', 'payments.disbursements.view', 'purchase.approve.accounts'],
            null,
            'View Requisition Payments'
        );

        $activeTab = $request->input('tab', 'requisitions');

        $query = Requisition::query()
            ->where('status', Requisition::STATUS_APPROVED)
            ->with(['requester', 'department', 'serviceProvider', 'expenditures']);

        if ($deptId = $request->integer('department_id')) {
            $query->where('department_id', $deptId);
        }

        if ($search = trim((string) $request->input('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('reference', 'like', "%{$search}%")
                    ->orWhere('title', 'like', "%{$search}%")
                    ->orWhere('suggested_vendor', 'like', "%{$search}%")
                    ->orWhereHas('requester', fn ($rq) => $rq->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('serviceProvider', fn ($sp) => $sp->where('name', 'like', "%{$search}%"));
            });
        }

        $allApproved = (clone $query)->get();

        // Calculate metrics
        $totalApprovedMinor = 0;
        $totalDisbursedMinor = 0;
        $totalRemainingMinor = 0;
        $countPending = 0;
        $countPartial = 0;
        $countPaid = 0;

        foreach ($allApproved as $req) {
            $auth = $this->spendService->authorisedMinor($req);
            $spent = $this->spendService->spentMinor($req);
            $rem = $this->spendService->remainingMinor($req);

            $totalApprovedMinor += $auth;
            $totalDisbursedMinor += $spent;
            $totalRemainingMinor += $rem;

            if ($spent === 0) {
                $countPending++;
            } elseif ($rem > 0) {
                $countPartial++;
            } else {
                $countPaid++;
            }
        }

        $statusFilter = $request->input('payment_status', 'pending');

        $filteredRequisitions = $allApproved->filter(function (Requisition $req) use ($statusFilter) {
            $spent = $this->spendService->spentMinor($req);
            $rem = $this->spendService->remainingMinor($req);

            return match ($statusFilter) {
                'pending' => $spent === 0,
                'partial' => $spent > 0 && $rem > 0,
                'paid' => $rem === 0,
                'all' => true,
                default => true,
            };
        });

        // Manual pagination on the filtered collection
        $perPage = $this->perPage($request->integer('per_page') ?: 20);
        $page = $request->integer('page', 1);
        $paginated = new \Illuminate\Pagination\LengthAwarePaginator(
            $filteredRequisitions->forPage($page, $perPage)->values(),
            $filteredRequisitions->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        $stats = [
            'total_approved_minor' => $totalApprovedMinor,
            'total_disbursed_minor' => $totalDisbursedMinor,
            'total_remaining_minor' => $totalRemainingMinor,
            'count_pending' => $countPending,
            'count_partial' => $countPartial,
            'count_paid' => $countPaid,
            'count_total' => $allApproved->count(),
        ];

        $batches = PaymentBatch::query()
            ->where('source_module', 'requisition')
            ->with(['items', 'initiatedBy'])
            ->latest('id')
            ->paginate(15);

        $pendingBatchesCount = PaymentBatch::query()
            ->where('source_module', 'requisition')
            ->whereIn('status', [PaymentBatch::STATUS_INITIALIZED, PaymentBatch::STATUS_PROCESSING])
            ->count();

        return view('finance.requisition_payments.index', [
            'requisitions' => $paginated,
            'batches' => $batches,
            'activeTab' => $activeTab,
            'pendingBatchesCount' => $pendingBatchesCount,
            'stats' => $stats,
            'departments' => Department::query()->orderBy('name')->get(),
            'search' => $search,
            'selectedDepartment' => $deptId,
            'statusFilter' => $statusFilter,
            'gateways' => $this->paymentService->getGatewayStatuses(),
            'canDisburse' => $this->allows('purchase.requisitions.spend') || $this->allows('purchase.approve.accounts'),
            'spendService' => $this->spendService,
        ]);
    }

    public function show(Requisition $requisition, Request $request): View
    {
        $this->authorizeAnyAccess(
            ['purchase.requisitions.spend', 'payments.disbursements.view', 'purchase.approve.accounts'],
            $requisition,
            "Requisition Payment Details → {$requisition->reference}"
        );

        $requisition->load([
            'requester',
            'department',
            'serviceProvider',
            'items',
            'expenditures.recordedBy',
            'workflowInstance.currentStage',
        ]);

        $authorisedMinor = $this->spendService->authorisedMinor($requisition);
        $spentMinor = $this->spendService->spentMinor($requisition);
        $remainingMinor = $this->spendService->remainingMinor($requisition);

        $batches = PaymentBatch::query()
            ->where(function ($q) use ($requisition) {
                $q->where(function ($sub) use ($requisition) {
                    $sub->where('source_type', $requisition->getMorphClass())
                        ->where('source_id', $requisition->getKey());
                })->orWhere(function ($sub) use ($requisition) {
                    $sub->where('source_module', 'requisition')
                        ->where('meta->requisitions', 'like', '%"id":' . $requisition->id . '%');
                });
            })
            ->with(['items', 'initiatedBy'])
            ->latest('id')
            ->get();

        $gateways = $this->paymentService->getGatewayStatuses();

        return view('finance.requisition_payments.show', [
            'requisition' => $requisition,
            'authorisedMinor' => $authorisedMinor,
            'spentMinor' => $spentMinor,
            'remainingMinor' => $remainingMinor,
            'batches' => $batches,
            'gateways' => $gateways,
            'canDisburse' => ($this->allows('purchase.requisitions.spend') || $this->allows('purchase.approve.accounts')) && $remainingMinor > 0,
        ]);
    }

    public function disburse(Request $request, Requisition $requisition): RedirectResponse
    {
        $this->authorizeAnyAccess(
            ['purchase.requisitions.spend', 'purchase.approve.accounts'],
            $requisition,
            "Disburse Payment → {$requisition->reference}"
        );

        $validated = $request->validate([
            'payment_method' => ['required', 'string', 'in:bank_transfer,paystack,monnify,zainpay,cash'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'notes' => ['nullable', 'string', 'max:500'],
            'otp' => ['nullable', 'string', 'max:16'],
        ]);

        $amountMinor = Money::fromMajor($validated['amount']);

        try {
            $batch = $this->requisitionPaymentService->disburseRequisition(
                $requisition,
                $validated['payment_method'],
                $amountMinor,
                $this->currentUser(),
                $validated['notes'] ?? null,
                $validated['otp'] ?? null
            );

            if ($batch->status === PaymentBatch::STATUS_PROCESSING) {
                return redirect()->route('requisition-payments.batch', $batch)->with(
                    'warning',
                    sprintf(
                        'Batch payment %s initialized via %s. Please enter the authorization OTP code below to finalize disbursement.',
                        $batch->batch_reference,
                        ucfirst(str_replace('_', ' ', $validated['payment_method']))
                    )
                );
            }

            return redirect()->route('requisition-payments.show', $requisition)->with(
                'success',
                sprintf(
                    'Payment of %s has been successfully processed for %s via %s (Batch: %s).',
                    Money::format($amountMinor),
                    $requisition->reference,
                    ucfirst(str_replace('_', ' ', $validated['payment_method'])),
                    $batch->batch_reference
                )
            );
        } catch (\Throwable $e) {
            return redirect()->route('requisition-payments.show', $requisition)->with('error', $e->getMessage());
        }
    }

    /**
     * Disburse a batch of 1 or more selected requisitions.
     */
    public function disburseBatch(Request $request): RedirectResponse
    {
        $this->authorizeAnyAccess(
            ['purchase.requisitions.spend', 'purchase.approve.accounts'],
            null,
            'Disburse Requisition Batch Payment'
        );

        $validated = $request->validate([
            'payment_method' => ['required', 'string', 'in:bank_transfer,paystack,monnify,zainpay,cash'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.requisition_id' => ['required', 'exists:requisitions,id'],
            'items.*.amount' => ['required', 'numeric', 'min:0.01'],
            'notes' => ['nullable', 'string', 'max:500'],
            'otp' => ['nullable', 'string', 'max:16'],
        ]);

        $itemsPayload = [];
        foreach ($validated['items'] as $item) {
            $req = Requisition::query()->findOrFail($item['requisition_id']);
            $itemsPayload[] = [
                'requisition' => $req,
                'amount_minor' => Money::fromMajor($item['amount']),
            ];
        }

        try {
            $batch = $this->requisitionPaymentService->processBatchDisbursement(
                $itemsPayload,
                $validated['payment_method'],
                $this->currentUser(),
                $validated['notes'] ?? null,
                $validated['otp'] ?? null
            );

            if ($batch->status === PaymentBatch::STATUS_PROCESSING) {
                return redirect()->route('requisition-payments.batch', $batch)->with(
                    'warning',
                    sprintf(
                        'Batch payment %s initialized via %s (%d items, %s). Please enter the authorization OTP code below to finalize disbursement.',
                        $batch->batch_reference,
                        ucfirst(str_replace('_', ' ', $validated['payment_method'])),
                        $batch->total_items_count,
                        Money::format((int) $batch->total_amount_minor)
                    )
                );
            }

            return redirect()->route('requisition-payments.index')->with(
                'success',
                sprintf(
                    'Batch payment %s (%d requisition payout%s) of %s has been successfully processed via %s.',
                    $batch->batch_reference,
                    $batch->total_items_count,
                    $batch->total_items_count > 1 ? 's' : '',
                    Money::format((int) $batch->total_amount_minor),
                    ucfirst(str_replace('_', ' ', $validated['payment_method']))
                )
            );
        } catch (\Throwable $e) {
            return redirect()->route('requisition-payments.index')->with('error', $e->getMessage());
        }
    }

    public function batch(PaymentBatch $batch, Request $request): View
    {
        $this->authorizeAnyAccess(
            ['purchase.requisitions.spend', 'payments.disbursements.view', 'purchase.approve.accounts'],
            $batch,
            "Payment Batch → {$batch->batch_reference}"
        );

        // If batch is on a gateway and not completed (or explicitly requested via ?sync=1), trigger live sync
        if (in_array($batch->gateway, ['paystack', 'monnify', 'zainpay']) && ($batch->status !== PaymentBatch::STATUS_COMPLETED || $request->has('sync'))) {
            try {
                $batch = $this->requisitionPaymentService->syncBatchStatus($batch, $this->currentUser());
            } catch (\Throwable $e) {
                // Log and continue displaying page with current database record
            }
        }

        $batch->load(['items', 'initiatedBy', 'source']);

        return view('finance.requisition_payments.batch', [
            'batch' => $batch,
            'requisition' => $batch->source instanceof Requisition ? $batch->source : null,
        ]);
    }

    /**
     * Manually trigger live status synchronization with payment gateway.
     */
    public function syncBatch(Request $request, PaymentBatch $batch): RedirectResponse
    {
        $this->authorizeAnyAccess(
            ['purchase.requisitions.spend', 'payments.disbursements.view', 'purchase.approve.accounts'],
            $batch,
            "Sync Batch Status → {$batch->batch_reference}"
        );

        try {
            $syncedBatch = $this->requisitionPaymentService->syncBatchStatus($batch, $this->currentUser());

            return redirect()->route('requisition-payments.batch', $syncedBatch)->with(
                'success',
                sprintf(
                    'Batch %s synchronized with %s (%d successful, %d failed).',
                    $syncedBatch->batch_reference,
                    ucfirst($syncedBatch->gateway),
                    $syncedBatch->successful_items_count,
                    $syncedBatch->failed_items_count
                )
            );
        } catch (\Throwable $e) {
            return redirect()->route('requisition-payments.batch', $batch)->with('error', $e->getMessage());
        }
    }

    /**
     * Authorize a pending batch with OTP code.
     */
    public function validateBatchOtp(Request $request, PaymentBatch $batch): RedirectResponse
    {
        $this->authorizeAnyAccess(
            ['purchase.requisitions.spend', 'purchase.approve.accounts', 'payments.disbursements.authorize'],
            $batch,
            "Authorize Batch OTP → {$batch->batch_reference}"
        );

        $validated = $request->validate([
            'otp' => ['required', 'string', 'max:32'],
        ]);

        try {
            $syncedBatch = $this->requisitionPaymentService->authorizeBatchOtp($batch, $validated['otp'], $this->currentUser());

            return redirect()->route('requisition-payments.batch', $batch)->with(
                'success',
                sprintf(
                    'Batch payment %s has been successfully authorized and finalized via %s. Requisition records updated.',
                    $batch->batch_reference,
                    ucfirst($batch->gateway)
                )
            );
        } catch (\Throwable $e) {
            return redirect()->route('requisition-payments.batch', $batch)->with('error', $e->getMessage());
        }
    }

    /**
     * Resend OTP for pending batch.
     */
    public function resendBatchOtp(Request $request, PaymentBatch $batch): RedirectResponse
    {
        $this->authorizeAnyAccess(
            ['purchase.requisitions.spend', 'purchase.approve.accounts', 'payments.disbursements.authorize'],
            $batch,
            "Resend Batch OTP → {$batch->batch_reference}"
        );

        try {
            $this->requisitionPaymentService->resendBatchOtp($batch, $this->currentUser());

            return redirect()->route('requisition-payments.batch', $batch)->with(
                'success',
                sprintf('A new authorization OTP code has been dispatched by %s.', ucfirst($batch->gateway))
            );
        } catch (\Throwable $e) {
            return redirect()->route('requisition-payments.batch', $batch)->with('error', $e->getMessage());
        }
    }
}
