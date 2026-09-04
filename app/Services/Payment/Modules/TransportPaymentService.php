<?php

namespace App\Services\Payment\Modules;

use App\Exceptions\RuleViolationException;
use App\Models\Driver;
use App\Models\DriverWalletTransaction;
use App\Models\PaymentBatch;
use App\Models\PaymentBatchItem;
use App\Models\TransportPayment;
use App\Models\TransportPaymentDisbursement;
use App\Models\TransportPaymentRun;
use App\Models\Trip;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Finance\DriverWalletService;
use App\Services\Payment\BankService;
use App\Services\Payment\Contracts\ModulePaymentServiceInterface;
use App\Services\Payment\DTOs\BulkTransferRequest;
use App\Services\Payment\DTOs\PayoutRecipient;
use App\Services\Payment\PaymentService;
use App\Support\Wat;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TransportPaymentService implements ModulePaymentServiceInterface
{
    public function __construct(
        private readonly PaymentService $paymentService,
        private readonly DriverWalletService $walletService,
        private readonly BankService $bankService,
        private readonly AuditLogger $audit,
    ) {}

    public function getModuleKey(): string
    {
        return 'transport_payment';
    }

    /**
     * Create and record a payment batch for an approved transport payment run.
     *
     * @param TransportPaymentRun $subject
     * @param array<int, array{transport_payment_id: int, amount_minor?: int}>|null $selectedPayments
     */
    public function createBatch(
        Model $subject,
        string $gateway,
        User $actor,
        ?string $notes = null,
        ?array $selectedPayments = null
    ): PaymentBatch {
        if (!$subject instanceof TransportPaymentRun) {
            throw new Exception('Subject must be an instance of TransportPaymentRun');
        }

        if (!$subject->isApproved() && $subject->status !== TransportPaymentRun::STATUS_PAID) {
            throw RuleViolationException::make(
                'ST-1',
                'Only an approved transport payment run can have an electronic payment batch generated.',
                ['status' => $subject->status]
            );
        }

        return DB::transaction(function () use ($subject, $gateway, $actor, $notes, $selectedPayments): PaymentBatch {
            $batchReference = $this->paymentService->generateReference('PB-TRP-' . $subject->id);

            // Cancel and supersede any existing pending/processing/uncompleted batches for this run
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

            // Fetch payable transport payments
            $query = $subject->payments()
                ->where('status', '!=', TransportPayment::STATUS_PAID)
                ->where('status', '!=', TransportPayment::STATUS_REVERSED)
                ->with(['driver', 'disbursements']);

            // Validate that all payable recipients in this run have complete bank details
            $allPayablesInRun = $subject->payments()
                ->where('status', '!=', TransportPayment::STATUS_PAID)
                ->where('status', '!=', TransportPayment::STATUS_REVERSED)
                ->with('driver')
                ->get();

            $invalidInRun = $allPayablesInRun->filter(function ($p) {
                $cleanAcc = preg_replace('/\D/', '', (string) ($p->driver?->bank_account ?? ''));
                return strlen($cleanAcc) !== 10 || empty($p->driver?->bank_name);
            });

            if ($invalidInRun->isNotEmpty()) {
                $names = $invalidInRun->map(fn ($p) => $p->driver?->name)->filter()->join(', ');
                throw new Exception("Cannot initialise payment: The run contains recipient(s) without complete bank details ({$names}). Bank name and 10-digit NUBAN account number are required. Please update their bank details in the Fleet Register before initialising payout.");
            }

            if (!empty($selectedPayments)) {
                $ids = array_column($selectedPayments, 'transport_payment_id');
                $query->whereIn('id', $ids);
            }

            $payments = $query->get();

            if ($payments->isEmpty()) {
                throw new Exception('No payable driver or rider records selected or available on this run.');
            }

            $amountsMap = [];
            if (!empty($selectedPayments)) {
                foreach ($selectedPayments as $sp) {
                    if (isset($sp['transport_payment_id']) && isset($sp['amount_minor'])) {
                        $amountsMap[$sp['transport_payment_id']] = (int) $sp['amount_minor'];
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

                $driver = $payment->driver;
                $rawAccount = $driver?->bank_account ?? '';
                $cleanAccount = preg_replace('/\D/', '', (string) $rawAccount);

                if (strlen($cleanAccount) !== 10 || empty($driver?->bank_name)) {
                    throw new Exception("Driver/Rider {$driver->name} does not have valid bank details (Bank name and 10-digit account number required). Please update their bank details before initiating payout via " . ucfirst(str_replace('_', ' ', $gateway)) . ".");
                }

                $bankCode = $driver?->bank_code ?? $this->resolveBankCode($driver?->bank_name);
                $bankName = $driver?->bank_name ?? 'Commercial Bank';
                $accountName = $driver?->account_name ?? ($driver?->name ?? 'Transport Contractor');
                $accountNumber = $cleanAccount ?: '0000000000';

                $totalMinor += $amountToPay;
                $itemsPayload[] = [
                    'payment' => $payment,
                    'driver' => $driver,
                    'amount_minor' => $amountToPay,
                    'account_number' => $accountNumber,
                    'bank_code' => $bankCode,
                    'bank_name' => $bankName,
                    'account_name' => $accountName,
                ];
            }

            if (empty($itemsPayload)) {
                throw new Exception('Total payable amount for the selected recipients is zero.');
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
                /** @var TransportPayment $payment */
                $payment = $payload['payment'];
                $driver = $payload['driver'];
                $itemRef = $this->paymentService->generateReference('PBI-TRP-' . $payment->id);

                PaymentBatchItem::query()->create([
                    'payment_batch_id' => $batch->id,
                    'item_reference' => $itemRef,
                    'recipient_type' => $driver ? $driver->getMorphClass() : null,
                    'recipient_id' => $driver?->id,
                    'recipient_name' => $payload['account_name'],
                    'recipient_email' => null,
                    'recipient_phone' => $driver?->phone,
                    'recipient_bank_code' => $payload['bank_code'],
                    'recipient_bank_name' => $payload['bank_name'],
                    'recipient_account_number' => $payload['account_number'] ?: '0000000000',
                    'amount_minor' => $payload['amount_minor'],
                    'fee_minor' => 0,
                    'narration' => 'Transport Payout — ' . $subject->reference,
                    'status' => PaymentBatchItem::STATUS_INITIALIZED,
                    'gateway_response' => [
                        'transport_payment_id' => $payment->id,
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
            title: 'Transport Payment Batch — ' . $batch->batch_reference,
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
     * Authorize a pending batch payout with OTP and re-synchronize live settlement status with gateway.
     */
    public function authorizeBatchOtp(PaymentBatch $batch, string $otp, User $actor): PaymentBatch
    {
        $batch->load(['items', 'source']);

        try {
            $result = $this->paymentService->validateBatchOtp(
                $batch->batch_reference,
                $otp,
                $batch->gateway,
                $batch->gateway_batch_reference
            );

            if (!$result->success) {
                throw new Exception($result->message ?? 'Invalid OTP code or authorization failed.');
            }
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

        return $this->syncBatchStatus($batch, $actor);
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
     * 1. Debits from the driver's wallet via DriverWalletService.
     * 2. Records TransportPaymentDisbursement rows.
     * 3. Marks TransportPayment status as paid when outstanding balance reaches zero.
     * 4. Marks linked trips as paid.
     * 5. Marks TransportPaymentRun as paid when all driver payments are settled.
     */
    public function processSuccessfulDisbursements(PaymentBatch $batch, User $actor): void
    {
        $batch->load(['items', 'source']);
        /** @var TransportPaymentRun|null $run */
        $run = $batch->source instanceof TransportPaymentRun ? $batch->source : null;

        foreach ($batch->items as $item) {
            if ($item->status !== PaymentBatchItem::STATUS_SUCCESSFUL) {
                continue;
            }

            $transportPaymentId = data_get($item->gateway_response, 'transport_payment_id');
            $transportPayment = null;

            if ($transportPaymentId) {
                $transportPayment = TransportPayment::query()->with('driver')->find($transportPaymentId);
            } elseif ($run && $item->recipient_id) {
                $transportPayment = TransportPayment::query()
                    ->where('transport_payment_run_id', $run->id)
                    ->where('driver_id', $item->recipient_id)
                    ->with('driver')
                    ->first();
            }

            if (!$transportPayment || !$transportPayment->driver) {
                continue;
            }

            $driver = $transportPayment->driver;
            $externalRef = $item->gateway_reference ?: $item->item_reference;

            // Idempotency check: Don't duplicate wallet debit or disbursement record
            $alreadyRecorded = TransportPaymentDisbursement::query()
                ->where('transport_payment_id', $transportPayment->id)
                ->where('external_reference', $externalRef)
                ->exists();

            if ($alreadyRecorded) {
                continue;
            }

            // 1. Debit Driver Wallet
            $this->walletService->debit(
                driver: $driver,
                amountMinor: (int) $item->amount_minor,
                type: DriverWalletTransaction::TYPE_DEBIT,
                source: $item,
                description: "Transport payout via {$batch->gateway} ({$batch->batch_reference})",
                actor: $actor,
                meta: [
                    'transport_payment_run_id' => $run?->id,
                    'payment_batch_id' => $batch->id,
                    'payment_batch_item_id' => $item->id,
                    'gateway' => $batch->gateway,
                    'gateway_reference' => $item->gateway_reference,
                ]
            );

            // 2. Record TransportPaymentDisbursement
            TransportPaymentDisbursement::query()->create([
                'transport_payment_id' => $transportPayment->id,
                'method' => 'bank',
                'amount_minor' => (int) $item->amount_minor,
                'disbursed_at' => $item->paid_at ?? Wat::now(),
                'paid_by_user_id' => $actor->getKey(),
                'received_by' => $driver->name,
                'external_reference' => $externalRef,
            ]);

            // 3. Mark TransportPayment as paid if outstanding is 0, and close legs
            if ($transportPayment->fresh()->outstandingMinor() <= 0) {
                $transportPayment->forceFill([
                    'status' => TransportPayment::STATUS_PAID,
                ])->save();

                Trip::withoutDataScope()
                    ->whereIn('id', $transportPayment->lines()->select('trip_id'))
                    ->update(['payment_status' => Trip::PAYMENT_PAID]);
            }
        }

        // 4. Update TransportPaymentRun status to paid if all payments are settled
        if ($run) {
            $hasUnsettled = $run->payments()
                ->where('status', '!=', TransportPayment::STATUS_PAID)
                ->where('status', '!=', TransportPayment::STATUS_REVERSED)
                ->exists();

            if (!$hasUnsettled) {
                $run->forceFill([
                    'status' => TransportPaymentRun::STATUS_PAID,
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
                'logistics.payments',
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
