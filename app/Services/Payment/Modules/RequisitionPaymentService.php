<?php

namespace App\Services\Payment\Modules;

use App\Exceptions\RuleViolationException;
use App\Models\PaymentBatch;
use App\Models\PaymentBatchItem;
use App\Models\Requisition;
use App\Models\RequisitionExpenditure;
use App\Models\ServiceProvider;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Finance\RequisitionSpendService;
use App\Services\Payment\Contracts\ModulePaymentServiceInterface;
use App\Services\Payment\DTOs\BulkTransferRequest;
use App\Services\Payment\DTOs\PayoutRecipient;
use App\Services\Payment\PaymentService;
use App\Support\Money;
use App\Support\Wat;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Exception;

class RequisitionPaymentService implements ModulePaymentServiceInterface
{
    public function __construct(
        private readonly PaymentService $paymentService,
        private readonly RequisitionSpendService $spendService,
        private readonly AuditLogger $audit,
    ) {}

    public function getModuleKey(): string
    {
        return 'requisition';
    }

    /**
     * Create and record a payment batch for a single purchase requisition.
     *
     * @param Requisition $subject
     */
    public function createBatch(Model $subject, string $gateway, User $actor, ?string $notes = null): PaymentBatch
    {
        if (!$subject instanceof Requisition) {
            throw new Exception('Subject must be an instance of Requisition');
        }

        $remainingMinor = $this->spendService->remainingMinor($subject);
        return $this->processBatchDisbursement(
            [['requisition' => $subject, 'amount_minor' => $remainingMinor]],
            $gateway,
            $actor,
            $notes
        );
    }

    /**
     * Execute payout for an existing payment batch (e.g. from queue or retry).
     */
    public function disburseBatch(PaymentBatch $batch, ?string $otp = null): PaymentBatch
    {
        $batch->load(['items', 'source']);
        $recipients = [];

        foreach ($batch->items as $item) {
            $recipients[] = new PayoutRecipient(
                reference: $item->item_reference,
                name: $item->recipient_name,
                accountNumber: $item->recipient_account_number,
                bankCode: $item->recipient_bank_code ?? '044',
                amountMinor: (int) $item->amount_minor,
                bankName: $item->recipient_bank_name,
                email: $item->recipient_email,
                phone: $item->recipient_phone,
                narration: $item->narration,
            );
        }

        $bulkRequest = new BulkTransferRequest(
            batchReference: $batch->batch_reference,
            recipients: $recipients,
            title: 'Requisition Payment Batch — ' . $batch->batch_reference,
            currency: 'NGN',
            otp: $otp,
        );

        $transferResult = $this->paymentService->bulkTransfer($bulkRequest, $batch->gateway);

        DB::transaction(function () use ($batch, $transferResult): void {
            $now = Wat::now();

            if ($transferResult->success) {
                $isCompleted = ($transferResult->status === 'completed');

                foreach ($batch->items as $item) {
                    $itemStatus = $isCompleted ? PaymentBatchItem::STATUS_SUCCESSFUL : PaymentBatchItem::STATUS_INITIALIZED;
                    $item->forceFill([
                        'status' => $itemStatus,
                        'gateway_reference' => $transferResult->gatewayBatchReference,
                        'fee_minor' => 1000,
                        'paid_at' => $isCompleted ? $now : null,
                    ])->save();
                }

                $batchStatus = $isCompleted ? PaymentBatch::STATUS_COMPLETED : PaymentBatch::STATUS_PROCESSING;
                $batch->forceFill([
                    'gateway_batch_reference' => $transferResult->gatewayBatchReference,
                    'total_fee_minor' => $transferResult->totalFeeMinor,
                    'successful_items_count' => $isCompleted ? $batch->items->count() : 0,
                    'status' => $batchStatus,
                    'completed_at' => $isCompleted ? $now : null,
                ])->save();

                if ($isCompleted) {
                    $this->recordExpendituresForBatch($batch, $batch->initiatedBy ?? User::query()->first());
                }
            } else {
                foreach ($batch->items as $item) {
                    $item->forceFill([
                        'status' => PaymentBatchItem::STATUS_FAILED,
                        'failure_reason' => $transferResult->message,
                    ])->save();
                }

                $batch->forceFill([
                    'failed_items_count' => $batch->items->count(),
                    'status' => PaymentBatch::STATUS_FAILED,
                ])->save();
            }
        });

        return $batch->refresh();
    }

    /**
     * Unified Batch Disbursement Engine for 1 or more purchase requisitions.
     *
     * @param array<int, array{requisition: Requisition, amount_minor: int}> $itemsPayload
     */
    public function processBatchDisbursement(
        array $itemsPayload,
        string $paymentMethod,
        User $actor,
        ?string $notes = null,
        ?string $otp = null
    ): PaymentBatch {
        if (empty($itemsPayload)) {
            throw new Exception('No requisitions selected for batch disbursement.');
        }

        $validatedItems = [];
        $recipients = [];
        $totalAmountMinor = 0;
        $batchReference = $this->paymentService->generateReference('PB-REQ');

        foreach ($itemsPayload as $entry) {
            /** @var Requisition $req */
            $req = $entry['requisition'];
            $amountMinor = (int) $entry['amount_minor'];

            if ($req->status !== Requisition::STATUS_APPROVED) {
                throw new Exception("Requisition {$req->reference} is not approved. Only approved requisitions can be paid.");
            }

            $remainingMinor = $this->spendService->remainingMinor($req);
            if ($amountMinor <= 0) {
                throw new Exception("Disbursement amount for {$req->reference} must be greater than zero.");
            }

            if ($amountMinor > $remainingMinor) {
                throw new Exception(sprintf(
                    'Disbursement amount for %s (%s) exceeds remaining authorized balance (%s).',
                    $req->reference,
                    Money::format($amountMinor),
                    Money::format($remainingMinor)
                ));
            }

            $provider = $req->serviceProvider;
            $recipientName = $provider?->account_name ?: ($provider?->name ?: ($req->suggested_vendor ?: $req->requester?->name ?: 'Vendor'));
            $recipientBankCode = $provider?->bank_code ?: '044';
            $recipientBankName = $provider?->bank_name ?: 'Commercial Bank';
            $recipientAccountNumber = $provider?->bank_account ?: '0000000000';
            $recipientEmail = $provider?->email ?: $req->requester?->email;
            $recipientPhone = $provider?->contact ?: $req->requester?->phone;

            $itemReference = $this->paymentService->generateReference('PBI-REQ-' . $req->id);

            $validatedItems[] = [
                'requisition' => $req,
                'provider' => $provider,
                'amount_minor' => $amountMinor,
                'recipient_name' => $recipientName,
                'recipient_bank_code' => $recipientBankCode,
                'recipient_bank_name' => $recipientBankName,
                'recipient_account_number' => $recipientAccountNumber,
                'recipient_email' => $recipientEmail,
                'recipient_phone' => $recipientPhone,
                'item_reference' => $itemReference,
            ];

            $recipients[] = new PayoutRecipient(
                reference: $itemReference,
                name: $recipientName,
                accountNumber: $recipientAccountNumber,
                bankCode: $recipientBankCode,
                amountMinor: $amountMinor,
                bankName: $recipientBankName,
                email: $recipientEmail,
                phone: $recipientPhone,
                narration: 'Payment for ' . $req->reference . ' — ' . $req->title,
            );

            $totalAmountMinor += $amountMinor;
        }

        $isGateway = in_array($paymentMethod, ['paystack', 'monnify', 'zainpay'], true);
        $transferResult = null;

        if ($isGateway) {
            $bulkRequest = new BulkTransferRequest(
                batchReference: $batchReference,
                recipients: $recipients,
                title: 'Requisition Payment Batch — ' . count($validatedItems) . ' Payout(s)',
                currency: 'NGN',
                otp: $otp,
            );

            $transferResult = $this->paymentService->bulkTransfer($bulkRequest, $paymentMethod);
            if (!$transferResult->success) {
                throw new Exception('Payment Gateway Error (' . ucfirst($paymentMethod) . '): ' . ($transferResult->message ?? 'Gateway rejected bulk transfer.'));
            }
        }

        return DB::transaction(function () use (
            $validatedItems,
            $paymentMethod,
            $totalAmountMinor,
            $actor,
            $notes,
            $batchReference,
            $isGateway,
            $transferResult
        ): PaymentBatch {
            $now = Wat::now();
            $firstReq = $validatedItems[0]['requisition'];

            $isCompleted = (!$isGateway) || ($transferResult && $transferResult->status === 'completed');
            $batchStatus = $isCompleted ? PaymentBatch::STATUS_COMPLETED : PaymentBatch::STATUS_PROCESSING;
            $itemStatus = $isCompleted ? PaymentBatchItem::STATUS_SUCCESSFUL : PaymentBatchItem::STATUS_INITIALIZED;

            $batch = PaymentBatch::query()->create([
                'batch_reference' => $batchReference,
                'gateway_batch_reference' => $transferResult?->gatewayBatchReference,
                'gateway_status' => $transferResult?->gatewayStatus ?? ($isCompleted ? 'SUCCESS' : 'PENDING_AUTHORIZATION'),
                'source_module' => $this->getModuleKey(),
                'source_type' => count($validatedItems) === 1 ? $firstReq->getMorphClass() : 'RequisitionBatch',
                'source_id' => count($validatedItems) === 1 ? $firstReq->getKey() : 0,
                'gateway' => $paymentMethod,
                'currency' => 'NGN',
                'total_amount_minor' => $totalAmountMinor,
                'total_fee_minor' => $isGateway ? (1000 * count($validatedItems)) : 0,
                'total_items_count' => count($validatedItems),
                'successful_items_count' => $isCompleted ? count($validatedItems) : 0,
                'failed_items_count' => 0,
                'status' => $batchStatus,
                'notes' => $notes,
                'meta' => [
                    'requisitions_count' => count($validatedItems),
                    'requisitions' => array_map(fn ($i) => [
                        'id' => $i['requisition']->id,
                        'reference' => $i['requisition']->reference,
                        'amount_minor' => $i['amount_minor'],
                    ], $validatedItems),
                ],
                'initiated_by_user_id' => $actor->getKey(),
                'authorized_by_user_id' => $isCompleted ? $actor->getKey() : null,
                'disbursed_at' => $now,
                'completed_at' => $isCompleted ? $now : null,
            ]);

            foreach ($validatedItems as $item) {
                $req = $item['requisition'];
                $itemAmt = $item['amount_minor'];

                PaymentBatchItem::query()->create([
                    'payment_batch_id' => $batch->id,
                    'item_reference' => $item['item_reference'],
                    'recipient_type' => $req->getMorphClass(),
                    'recipient_id' => $req->getKey(),
                    'recipient_name' => $item['recipient_name'],
                    'recipient_email' => $item['recipient_email'],
                    'recipient_phone' => $item['recipient_phone'],
                    'recipient_bank_code' => $item['recipient_bank_code'],
                    'recipient_bank_name' => $item['recipient_bank_name'],
                    'recipient_account_number' => $item['recipient_account_number'],
                    'amount_minor' => $itemAmt,
                    'fee_minor' => $isGateway ? 1000 : 0,
                    'narration' => 'Payment for ' . $req->reference . ' — ' . $req->title,
                    'status' => $itemStatus,
                    'gateway_reference' => $transferResult?->gatewayBatchReference,
                    'paid_at' => $isCompleted ? $now : null,
                ]);
            }

            // ONLY record RequisitionExpenditure if the transfer is finalized / completed!
            if ($isCompleted) {
                $this->recordExpendituresForBatch($batch, $actor);
            }

            return $batch;
        });
    }

    /**
     * Authorize a pending batch payout with OTP code and re-synchronize live status with gateway.
     */
    public function authorizeBatchOtp(PaymentBatch $batch, string $otp, User $actor): PaymentBatch
    {
        $batch->load(['items', 'source']);

        try {
            $this->paymentService->validateBatchOtp(
                batchReference: $batch->batch_reference,
                otp: $otp,
                gateway: $batch->gateway,
                gatewayBatchReference: $batch->gateway_batch_reference,
            );
        } catch (\Throwable $e) {
            $errorMessage = $e->getMessage();
            $lower = strtolower($errorMessage);

            $isOtpDisabled = str_contains($lower, 'otp has been disabled') || str_contains($lower, 'otp disabled');
            $isAlreadyProcessed = str_contains($lower, 'already processed') || str_contains($lower, 'already finalized');

            if (!$isOtpDisabled && !$isAlreadyProcessed) {
                throw new Exception(ucfirst($batch->gateway) . ' OTP Error: ' . $errorMessage);
            }
        }

        $batch->forceFill([
            'authorized_by_user_id' => $actor->getKey(),
        ])->save();

        // Immediately trigger live verification & status synchronization with gateway
        return $this->syncBatchStatus($batch, $actor);
    }

    /**
     * Re-query live payment gateway to synchronize batch and individual items,
     * updating requisition expenditures and settlement statuses accordingly.
     */
    public function syncBatchStatus(PaymentBatch $batch, User $actor): PaymentBatch
    {
        $batch->load(['items', 'source']);
        $now = Wat::now();

        // For offline / bank transfer / cash methods, already completed or internal
        if (in_array($batch->gateway, ['bank_transfer', 'cash'])) {
            if ($batch->status !== PaymentBatch::STATUS_COMPLETED) {
                $batch->forceFill([
                    'status' => PaymentBatch::STATUS_COMPLETED,
                    'successful_items_count' => $batch->items->count(),
                    'completed_at' => $now,
                ])->save();

                foreach ($batch->items as $item) {
                    $item->forceFill([
                        'status' => PaymentBatchItem::STATUS_SUCCESSFUL,
                        'paid_at' => $now,
                    ])->save();
                }

                $this->recordExpendituresForBatch($batch, $actor);
            }

            return $batch->refresh();
        }

        $items = [];
        foreach ($batch->items as $item) {
            $items[] = [
                'reference' => $item->item_reference,
                'account_number' => $item->recipient_account_number,
                'amount_minor' => (int) $item->amount_minor,
                'gateway_reference' => $item->gateway_reference,
                'status' => $item->status,
            ];
        }

        $syncResult = $this->paymentService->verifyBatch(
            batchReference: $batch->batch_reference,
            gateway: $batch->gateway,
            gatewayBatchReference: $batch->gateway_batch_reference,
            items: $items,
        );

        DB::transaction(function () use ($batch, $syncResult, $now, $actor): void {
            $successCount = 0;
            $failedCount = 0;

            foreach ($batch->items as $item) {
                $itemRes = $syncResult->itemResults[$item->item_reference] ?? null;
                if (!$itemRes) {
                    if ($item->status === PaymentBatchItem::STATUS_SUCCESSFUL) {
                        $successCount++;
                    } elseif ($item->status === PaymentBatchItem::STATUS_FAILED) {
                        $failedCount++;
                    }
                    continue;
                }

                $status = $itemRes['status'] ?? $item->status;
                $gwStatus = $itemRes['gateway_status'] ?? $item->gateway_status;
                $gwRef = $itemRes['gateway_reference'] ?? $item->gateway_reference;
                $msg = $itemRes['message'] ?? null;
                $raw = $itemRes['raw'] ?? $itemRes;

                if ($status === 'successful') {
                    $successCount++;
                    $itemPaidAt = $item->paid_at ?? $now;
                    $item->forceFill([
                        'status' => PaymentBatchItem::STATUS_SUCCESSFUL,
                        'gateway_reference' => $gwRef,
                        'gateway_status' => $gwStatus,
                        'gateway_response' => $raw,
                        'failure_reason' => null,
                        'paid_at' => $itemPaidAt,
                    ])->save();
                } elseif ($status === 'failed') {
                    $failedCount++;
                    $item->forceFill([
                        'status' => PaymentBatchItem::STATUS_FAILED,
                        'gateway_reference' => $gwRef,
                        'gateway_status' => $gwStatus,
                        'gateway_response' => $raw,
                        'failure_reason' => $msg ?: 'Transaction failed at gateway',
                        'paid_at' => null,
                    ])->save();
                } else {
                    $item->forceFill([
                        'status' => PaymentBatchItem::STATUS_INITIALIZED,
                        'gateway_reference' => $gwRef,
                        'gateway_status' => $gwStatus,
                        'gateway_response' => $raw,
                    ])->save();
                }
            }

            $batchStatus = match ($syncResult->status) {
                'completed' => ($failedCount > 0 ? PaymentBatch::STATUS_PARTIALLY_COMPLETED : PaymentBatch::STATUS_COMPLETED),
                'failed' => PaymentBatch::STATUS_FAILED,
                default => PaymentBatch::STATUS_PROCESSING,
            };

            $batch->forceFill([
                'gateway_status' => $syncResult->gatewayStatus ?? ($batchStatus === PaymentBatch::STATUS_COMPLETED ? 'SUCCESS' : 'PROCESSING'),
                'successful_items_count' => $successCount,
                'failed_items_count' => $failedCount,
                'status' => $batchStatus,
                'completed_at' => ($batchStatus === PaymentBatch::STATUS_COMPLETED) ? ($batch->completed_at ?? $now) : null,
            ])->save();

            // Settle expenditures for all verified successful items
            $this->recordExpendituresForBatch($batch, $actor);
        });

        return $batch->refresh();
    }

    /**
     * Resend 2FA authorization OTP code via gateway.
     */
    public function resendBatchOtp(PaymentBatch $batch, User $actor): void
    {
        $this->paymentService->resendBatchOtp(
            batchReference: $batch->batch_reference,
            gateway: $batch->gateway,
            gatewayBatchReference: $batch->gateway_batch_reference,
        );

        $this->audit->action(
            $batch,
            sprintf('Authorization OTP resent for batch %s via %s', $batch->batch_reference, ucfirst($batch->gateway)),
            'Finance',
            ['batch_reference' => $batch->batch_reference],
            $actor
        );
    }

    /**
     * Record RequisitionExpenditure entries for all successful items in a completed batch.
     */
    private function recordExpendituresForBatch(PaymentBatch $batch, User $actor): void
    {
        $batch->load('items');

        $spendMethod = match ($batch->gateway) {
            'cash' => 'cash',
            'paystack', 'monnify', 'zainpay' => 'gateway',
            default => 'bank',
        };

        foreach ($batch->items as $item) {
            if ($item->status !== PaymentBatchItem::STATUS_SUCCESSFUL) {
                continue;
            }

            // Find requisition directly from item's recipient or batch source
            $requisition = null;
            if ($item->recipient_type === Requisition::class || $item->recipient_type === 'requisition') {
                $requisition = Requisition::query()->find($item->recipient_id);
            }
            if (!$requisition && $batch->source instanceof Requisition) {
                $requisition = $batch->source;
            }

            if (!$requisition) {
                continue;
            }

            // Check if expenditure already recorded for this invoice reference
            $invoiceRef = $batch->batch_reference . ($batch->items->count() > 1 ? '-' . $requisition->id : '');
            $existing = RequisitionExpenditure::query()
                ->where('requisition_id', $requisition->id)
                ->where('invoice_reference', $invoiceRef)
                ->first();

            if (!$existing) {
                $this->spendService->record($requisition, [
                    'amount_minor' => (int) $item->amount_minor,
                    'vendor' => $item->recipient_name,
                    'invoice_reference' => $invoiceRef,
                    'method' => $spendMethod,
                    'spent_on' => ($batch->completed_at ?? Wat::now())->toDateString(),
                    'notes' => $batch->notes ?: ('Disbursed via ' . ucfirst(str_replace('_', ' ', $batch->gateway)) . ' (Batch: ' . $batch->batch_reference . ')'),
                ], $actor);

                $this->audit->approval(
                    $requisition,
                    sprintf(
                        'Disbursed %s for %s via %s (Batch: %s)',
                        Money::format((int) $item->amount_minor),
                        $requisition->reference,
                        ucfirst(str_replace('_', ' ', $batch->gateway)),
                        $batch->batch_reference
                    ),
                    [
                        'amount_minor' => (int) $item->amount_minor,
                        'method' => $batch->gateway,
                        'batch_reference' => $batch->batch_reference,
                        'recipient' => $item->recipient_name,
                        'bank_account' => $item->recipient_account_number,
                        'bank_name' => $item->recipient_bank_name,
                    ],
                    $actor
                );
            }
        }
    }

    /**
     * Helper to disburse a single requisition using the unified batch system.
     */
    public function disburseRequisition(
        Requisition $requisition,
        string $paymentMethod,
        int $amountMinor,
        User $actor,
        ?string $notes = null,
        ?string $otp = null
    ): PaymentBatch {
        return $this->processBatchDisbursement(
            [['requisition' => $requisition, 'amount_minor' => $amountMinor]],
            $paymentMethod,
            $actor,
            $notes,
            $otp
        );
    }
}
