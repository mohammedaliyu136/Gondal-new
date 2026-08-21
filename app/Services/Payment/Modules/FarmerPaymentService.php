<?php

namespace App\Services\Payment\Modules;

use App\Exceptions\RuleViolationException;
use App\Models\Farmer;
use App\Models\FarmerPayment;
use App\Models\FarmerPaymentDisbursement;
use App\Models\FarmerWalletTransaction;
use App\Models\PaymentBatch;
use App\Models\PaymentBatchItem;
use App\Models\PaymentRun;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Finance\FarmerWalletService;
use App\Services\Payment\BankService;
use App\Services\Payment\Contracts\ModulePaymentServiceInterface;
use App\Services\Payment\DTOs\BulkTransferRequest;
use App\Services\Payment\DTOs\PayoutRecipient;
use App\Services\Payment\PaymentService;
use App\Support\Money;
use App\Support\Wat;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FarmerPaymentService implements ModulePaymentServiceInterface
{
    public function __construct(
        private readonly PaymentService $paymentService,
        private readonly FarmerWalletService $walletService,
        private readonly BankService $bankService,
        private readonly AuditLogger $audit,
    ) {}

    public function getModuleKey(): string
    {
        return 'farmer_payment';
    }

    /**
     * Create and record a payment batch for a farmer payment run.
     *
     * @param PaymentRun $subject
     * @param array<int, array{farmer_payment_id: int, amount_minor?: int}>|null $selectedPayments
     */
    public function createBatch(
        Model $subject,
        string $gateway,
        User $actor,
        ?string $notes = null,
        ?array $selectedPayments = null
    ): PaymentBatch {
        if (!$subject instanceof PaymentRun) {
            throw new Exception('Subject must be an instance of PaymentRun');
        }

        if (!$subject->isApproved() && $subject->status !== PaymentRun::STATUS_PAID) {
            throw RuleViolationException::make(
                'ST-1',
                'Only an approved farmer payment run can have an electronic payment batch generated.',
                ['status' => $subject->status]
            );
        }

        return DB::transaction(function () use ($subject, $gateway, $actor, $notes, $selectedPayments): PaymentBatch {
            $batchReference = $this->paymentService->generateReference('PB-FARM-' . $subject->id);

            // Cancel and supersede any existing pending/processing/uncompleted batches for this payment run
            // to strictly prevent duplicate disbursements.
            $pendingBatches = PaymentBatch::query()
                ->where('source_type', $subject->getMorphClass())
                ->where('source_id', $subject->getKey())
                ->whereIn('status', [
                    PaymentBatch::STATUS_INITIALIZED,
                    PaymentBatch::STATUS_PROCESSING,
                    PaymentBatch::STATUS_PENDING_OTP,
                    PaymentBatch::STATUS_DRAFT,
                ])
                ->get();

            foreach ($pendingBatches as $oldBatch) {
                $oldBatch->items()
                    ->whereIn('status', [
                        PaymentBatchItem::STATUS_INITIALIZED,
                        PaymentBatchItem::STATUS_PENDING,
                    ])
                    ->update([
                        'status' => PaymentBatchItem::STATUS_CANCELLED,
                        'failure_reason' => "Superseded by new payout batch {$batchReference}",
                    ]);

                $oldBatch->update([
                    'status' => PaymentBatch::STATUS_CANCELLED,
                    'notes' => trim(($oldBatch->notes ? $oldBatch->notes . ' | ' : '') . "Cancelled: superseded by new batch {$batchReference}"),
                ]);
            }

            // Fetch payable farmer payments
            $query = $subject->payments()
                ->where('status', '!=', FarmerPayment::STATUS_PAID)
                ->where('status', '!=', FarmerPayment::STATUS_REVERSED)
                ->where('status', '!=', FarmerPayment::STATUS_HELD)
                ->with(['farmer', 'disbursements']);

            if (!empty($selectedPayments)) {
                $ids = array_column($selectedPayments, 'farmer_payment_id');
                $query->whereIn('id', $ids);
            }

            $payments = $query->get();

            if ($payments->isEmpty()) {
                throw new Exception('No payable farmer records selected or available on this run.');
            }

            $amountsMap = [];
            if (!empty($selectedPayments)) {
                foreach ($selectedPayments as $sp) {
                    if (isset($sp['farmer_payment_id']) && isset($sp['amount_minor'])) {
                        $amountsMap[$sp['farmer_payment_id']] = (int) $sp['amount_minor'];
                    }
                }
            }

            $totalMinor = 0;
            $itemsPayload = [];

            foreach ($payments as $payment) {
                $outstanding = $payment->outstandingMinor();
                $amountToPay = $amountsMap[$payment->id] ?? $outstanding;

                if ($amountToPay <= 0) {
                    continue;
                }

                if ($amountToPay > $outstanding) {
                    $amountToPay = $outstanding;
                }

                $farmer = $payment->farmer;
                $rawAccount = $farmer?->bank_account ?? ($farmer?->bank_account_number ?? '');
                $cleanAccount = preg_replace('/\D/', '', (string) $rawAccount);

                if (in_array(strtolower($gateway), ['monnify', 'paystack', 'zainpay'], true)) {
                    if (strlen($cleanAccount) !== 10) {
                        throw new Exception("Farmer {$farmer->name} ({$farmer->code}) does not have a valid 10-digit bank account number. Please update their bank details or deselect them before initiating payout via " . ucfirst($gateway) . ".");
                    }
                }

                $bankCode = $farmer?->bank_code ?? $this->resolveBankCode($farmer?->bank_name);
                $bankName = $farmer?->bank_name ?? 'Commercial Bank';
                $accountName = $farmer?->account_name ?? ($farmer?->bank_account_name ?? ($farmer?->name ?? 'Farmer Member'));
                $accountNumber = $cleanAccount ?: '0000000000';

                $totalMinor += $amountToPay;
                $itemsPayload[] = [
                    'payment' => $payment,
                    'farmer' => $farmer,
                    'amount_minor' => $amountToPay,
                    'account_number' => $accountNumber,
                    'bank_code' => $bankCode,
                    'bank_name' => $bankName,
                    'account_name' => $accountName,
                ];
            }

            if (empty($itemsPayload)) {
                throw new Exception('Total payable amount for the selected farmers is zero.');
            }

            $batch = PaymentBatch::query()->create([
                'batch_reference' => $batchReference,
                'source_module' => $this->getModuleKey(),
                'source_type' => $subject->getMorphClass(),
                'source_id' => $subject->getKey(),
                'gateway' => $gateway,
                'currency' => 'NGN',
                'total_amount_minor' => $totalMinor,
                'total_fee_minor' => 0,
                'total_items_count' => count($itemsPayload),
                'successful_items_count' => 0,
                'failed_items_count' => 0,
                'status' => PaymentBatch::STATUS_INITIALIZED,
                'notes' => $notes,
                'meta' => [
                    'run_reference' => $subject->reference,
                    'scope_type' => $subject->scope_type,
                    'scope_id' => $subject->scope_id,
                ],
                'initiated_by_user_id' => $actor->getKey(),
                'disbursed_at' => Wat::now(),
            ]);

            foreach ($itemsPayload as $payload) {
                /** @var FarmerPayment $payment */
                $payment = $payload['payment'];
                $farmer = $payload['farmer'];
                $itemRef = $this->paymentService->generateReference('PBI-FRM-' . $payment->id);

                PaymentBatchItem::query()->create([
                    'payment_batch_id' => $batch->id,
                    'item_reference' => $itemRef,
                    'recipient_type' => $farmer ? $farmer->getMorphClass() : null,
                    'recipient_id' => $farmer?->id,
                    'recipient_name' => $payload['account_name'],
                    'recipient_email' => $farmer?->email,
                    'recipient_phone' => $farmer?->phone,
                    'recipient_bank_code' => $payload['bank_code'],
                    'recipient_bank_name' => $payload['bank_name'],
                    'recipient_account_number' => $payload['account_number'] ?: '0000000000',
                    'amount_minor' => $payload['amount_minor'],
                    'fee_minor' => 0,
                    'narration' => 'Milk Payout — ' . $subject->reference,
                    'status' => PaymentBatchItem::STATUS_INITIALIZED,
                    'gateway_response' => [
                        'farmer_payment_id' => $payment->id,
                    ],
                ]);
            }

            return $batch;
        });
    }

    /**
     * Execute payout for an existing payment batch.
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
            title: 'Farmer Payment Batch — ' . $batch->batch_reference,
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
                    $this->processSuccessfulDisbursements($batch, $batch->initiatedBy ?? User::query()->first());
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
     * Authorize a pending batch with OTP.
     */
    public function authorizeBatchOtp(PaymentBatch $batch, string $otp, User $actor): PaymentBatch
    {
        $result = $this->paymentService->validateBatchOtp(
            $batch->batch_reference,
            $otp,
            $batch->gateway,
            $batch->gateway_batch_reference
        );

        if (!$result->success) {
            throw new Exception($result->message ?? 'Invalid OTP code or authorization failed.');
        }

        DB::transaction(function () use ($batch, $result, $actor): void {
            $now = Wat::now();

            foreach ($batch->items as $item) {
                $item->forceFill([
                    'status' => PaymentBatchItem::STATUS_SUCCESSFUL,
                    'paid_at' => $now,
                ])->save();
            }

            $batch->forceFill([
                'status' => PaymentBatch::STATUS_COMPLETED,
                'successful_items_count' => $batch->items->count(),
                'authorized_by_user_id' => $actor->getKey(),
                'completed_at' => $now,
            ])->save();

            $this->processSuccessfulDisbursements($batch, $actor);
        });

        return $batch->refresh();
    }

    /**
     * Resend OTP for a pending payment batch.
     */
    public function resendBatchOtp(PaymentBatch $batch, User $actor): void
    {
        $this->paymentService->resendBatchOtp(
            $batch->batch_reference,
            $batch->gateway,
            $batch->gateway_batch_reference
        );
    }

    /**
     * Sync live settlement status with payment gateway.
     */
    public function syncBatchStatus(PaymentBatch $batch, User $actor): PaymentBatch
    {
        $batch->load(['items', 'source']);

        $itemsPayload = $batch->items->map(fn ($item) => [
            'reference' => $item->item_reference,
            'account_number' => $item->recipient_account_number,
            'amount_minor' => $item->amount_minor,
            'gateway_reference' => $item->gateway_reference,
            'status' => $item->status,
        ])->toArray();

        $syncResult = $this->paymentService->verifyBatch(
            $batch->batch_reference,
            $batch->gateway,
            $batch->gateway_batch_reference,
            $itemsPayload
        );

        DB::transaction(function () use ($batch, $syncResult, $actor): void {
            $now = Wat::now();
            $successfulCount = 0;
            $failedCount = 0;

            $gatewayStatus = $syncResult->gatewayStatus
                ?? ($syncResult->rawResponse['responseBody']['batchStatus'] ?? ($syncResult->rawResponse['responseBody']['status'] ?? ($syncResult->status)));
            $isGatewayExpiredOrFailed = in_array(strtoupper((string) $gatewayStatus), ['EXPIRED', 'FAILED', 'CANCELLED', 'REJECTED'], true);

            if ($syncResult->status === 'completed' && !$isGatewayExpiredOrFailed) {
                foreach ($batch->items as $item) {
                    $item->forceFill([
                        'status' => PaymentBatchItem::STATUS_SUCCESSFUL,
                        'gateway_status' => 'SUCCESS',
                        'paid_at' => $item->paid_at ?? $now,
                    ])->save();
                    $successfulCount++;
                }
            } elseif (!empty($syncResult->itemResults)) {
                $resultsByRef = collect($syncResult->itemResults)->keyBy('reference');

                foreach ($batch->items as $item) {
                    $res = $resultsByRef->get($item->item_reference);
                    if ($res) {
                        $st = $res['status'] ?? 'processing';
                        $gwSt = $res['gateway_status'] ?? $gatewayStatus;
                        if ($st === 'successful') {
                            $item->forceFill([
                                'status' => PaymentBatchItem::STATUS_SUCCESSFUL,
                                'gateway_reference' => $res['gateway_reference'] ?? $item->gateway_reference,
                                'gateway_status' => $gwSt,
                                'paid_at' => $item->paid_at ?? $now,
                            ])->save();
                            $successfulCount++;
                        } elseif ($st === 'failed' || $isGatewayExpiredOrFailed) {
                            $item->forceFill([
                                'status' => PaymentBatchItem::STATUS_FAILED,
                                'gateway_reference' => $res['gateway_reference'] ?? $item->gateway_reference,
                                'gateway_status' => $gwSt,
                                'failure_reason' => $res['message'] ?? ($isGatewayExpiredOrFailed ? "Gateway batch {$gatewayStatus} (Authorization expired)" : 'Gateway transfer failed'),
                            ])->save();
                            $failedCount++;
                        } else {
                            $item->forceFill([
                                'gateway_status' => $gwSt,
                            ])->save();
                        }
                    } else {
                        if ($isGatewayExpiredOrFailed || $syncResult->status === 'failed') {
                            $item->forceFill([
                                'status' => PaymentBatchItem::STATUS_FAILED,
                                'gateway_status' => $gatewayStatus,
                                'failure_reason' => $syncResult->message ?: "Gateway batch {$gatewayStatus}",
                            ])->save();
                            $failedCount++;
                        }
                    }
                }
            } else {
                // If itemResults was empty but overall sync was failed or expired
                if ($isGatewayExpiredOrFailed || $syncResult->status === 'failed') {
                    foreach ($batch->items as $item) {
                        if ($item->status !== PaymentBatchItem::STATUS_SUCCESSFUL) {
                            $item->forceFill([
                                'status' => PaymentBatchItem::STATUS_FAILED,
                                'gateway_status' => $gatewayStatus,
                                'failure_reason' => $syncResult->message ?: "Gateway batch {$gatewayStatus}",
                            ])->save();
                            $failedCount++;
                        } else {
                            $successfulCount++;
                        }
                    }
                }
            }

            $batchStatus = match ($syncResult->status) {
                'completed' => ($failedCount > 0 ? PaymentBatch::STATUS_PARTIALLY_COMPLETED : PaymentBatch::STATUS_COMPLETED),
                'failed' => PaymentBatch::STATUS_FAILED,
                default => ($isGatewayExpiredOrFailed ? PaymentBatch::STATUS_FAILED : (($successfulCount > 0 && $failedCount > 0) ? PaymentBatch::STATUS_PARTIALLY_COMPLETED : ($successfulCount > 0 ? PaymentBatch::STATUS_COMPLETED : PaymentBatch::STATUS_PROCESSING))),
            };

            $batch->forceFill([
                'status' => $batchStatus,
                'gateway_status' => $gatewayStatus,
                'successful_items_count' => $successfulCount,
                'failed_items_count' => $failedCount,
                'completed_at' => ($batchStatus === PaymentBatch::STATUS_COMPLETED) ? ($batch->completed_at ?? $now) : null,
            ])->save();

            $this->processSuccessfulDisbursements($batch, $actor);
        });

        return $batch->refresh();
    }

    /**
     * Process successful disbursements:
     * 1. Debits from the farmer's wallet via FarmerWalletService.
     * 2. Records FarmerPaymentDisbursement rows.
     * 3. Marks FarmerPayment status as paid when outstanding balance reaches zero.
     * 4. Marks PaymentRun as paid when all farmer payments are settled.
     */
    public function processSuccessfulDisbursements(PaymentBatch $batch, User $actor): void
    {
        $batch->load(['items', 'source']);
        /** @var PaymentRun|null $run */
        $run = $batch->source instanceof PaymentRun ? $batch->source : null;

        foreach ($batch->items as $item) {
            if ($item->status !== PaymentBatchItem::STATUS_SUCCESSFUL) {
                continue;
            }

            $farmerPaymentId = data_get($item->gateway_response, 'farmer_payment_id');
            $farmerPayment = null;

            if ($farmerPaymentId) {
                $farmerPayment = FarmerPayment::query()->with('farmer')->find($farmerPaymentId);
            } elseif ($run && $item->recipient_id) {
                $farmerPayment = FarmerPayment::query()
                    ->where('payment_run_id', $run->id)
                    ->where('farmer_id', $item->recipient_id)
                    ->with('farmer')
                    ->first();
            }

            if (!$farmerPayment || !$farmerPayment->farmer) {
                continue;
            }

            $farmer = $farmerPayment->farmer;
            $externalRef = $item->gateway_reference ?: $item->item_reference;

            // Idempotency check: Don't duplicate wallet debit or disbursement record
            $alreadyRecorded = FarmerPaymentDisbursement::query()
                ->where('farmer_payment_id', $farmerPayment->id)
                ->where('external_reference', $externalRef)
                ->exists();

            if ($alreadyRecorded) {
                continue;
            }

            // 1. Debit Farmer Wallet
            $this->walletService->debit(
                farmer: $farmer,
                amountMinor: (int) $item->amount_minor,
                type: FarmerWalletTransaction::TYPE_DEBIT,
                source: $item,
                description: "Milk payment payout via {$batch->gateway} ({$batch->batch_reference})",
                actor: $actor,
                meta: [
                    'payment_run_id' => $run?->id,
                    'payment_batch_id' => $batch->id,
                    'payment_batch_item_id' => $item->id,
                    'gateway' => $batch->gateway,
                    'gateway_reference' => $item->gateway_reference,
                ]
            );

            // 2. Record FarmerPaymentDisbursement
            FarmerPaymentDisbursement::query()->create([
                'farmer_payment_id' => $farmerPayment->id,
                'method' => FarmerPaymentDisbursement::METHOD_BANK,
                'amount_minor' => (int) $item->amount_minor,
                'disbursed_at' => $item->paid_at ?? Wat::now(),
                'paid_by_user_id' => $actor->getKey(),
                'received_by' => $farmer->name,
                'received_by_relation' => 'self',
                'external_reference' => $externalRef,
            ]);

            // 3. Mark FarmerPayment as paid if outstanding is 0
            if ($farmerPayment->outstandingMinor() <= 0) {
                $farmerPayment->forceFill([
                    'status' => FarmerPayment::STATUS_PAID,
                ])->save();
            }
        }

        // 4. Update PaymentRun status to paid if all payments are settled
        if ($run) {
            $hasUnsettled = $run->payments()
                ->where('status', '!=', FarmerPayment::STATUS_PAID)
                ->where('status', '!=', FarmerPayment::STATUS_REVERSED)
                ->exists();

            if (!$hasUnsettled) {
                $run->forceFill([
                    'status' => PaymentRun::STATUS_PAID,
                    'paid_at' => Wat::now(),
                ])->save();
            }
        }
    }

    /**
     * Cancel an active or pending payment batch to prevent duplicate disbursement.
     */
    public function cancelBatch(PaymentBatch $batch, User $actor, ?string $reason = null): PaymentBatch
    {
        if (in_array($batch->status, [PaymentBatch::STATUS_COMPLETED, PaymentBatch::STATUS_CANCELLED], true)) {
            throw new Exception("Batch {$batch->batch_reference} cannot be cancelled because it is already {$batch->status}.");
        }

        return DB::transaction(function () use ($batch, $actor, $reason): PaymentBatch {
            $batch->items()
                ->whereNotIn('status', [PaymentBatchItem::STATUS_SUCCESSFUL])
                ->update([
                    'status' => PaymentBatchItem::STATUS_CANCELLED,
                    'failure_reason' => $reason ?: 'Cancelled by user ' . $actor->name,
                ]);

            $batch->update([
                'status' => PaymentBatch::STATUS_CANCELLED,
                'notes' => trim(($batch->notes ? $batch->notes . ' | ' : '') . 'Cancelled: ' . ($reason ?: 'Cancelled by ' . $actor->name)),
            ]);

            $this->audit->edited(
                $batch,
                "Cancelled payment batch {$batch->batch_reference}",
                'finance.farmer_payments',
                ['status' => $batch->getOriginal('status')],
                ['status' => PaymentBatch::STATUS_CANCELLED, 'reason' => $reason],
                $actor
            );

            return $batch->refresh();
        });
    }

    /**
     * Resolve 3-digit CBN bank code from bank name.
     */
    private function resolveBankCode(?string $bankName): string
    {
        if (empty($bankName)) {
            return '044';
        }

        $banks = $this->bankService->getBanks();
        foreach ($banks as $b) {
            if (strcasecmp($b['name'], $bankName) === 0) {
                return $b['code'];
            }
        }

        return '044';
    }
}
