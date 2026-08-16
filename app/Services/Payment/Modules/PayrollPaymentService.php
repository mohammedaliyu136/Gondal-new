<?php

namespace App\Services\Payment\Modules;

use App\Exceptions\RuleViolationException;
use App\Models\AuditEntry;
use App\Models\Employee;
use App\Models\PaymentBatch;
use App\Models\PaymentBatchItem;
use App\Models\PayrollRun;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Payment\Contracts\ModulePaymentServiceInterface;
use App\Services\Payment\DTOs\BulkTransferRequest;
use App\Services\Payment\DTOs\PayoutRecipient;
use App\Services\Payment\PaymentApi\MonnifyApi;
use App\Services\Payment\PaymentApi\PaystackApi;
use App\Services\Payment\PaymentService;
use App\Support\Money;
use App\Support\Wat;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

class PayrollPaymentService implements ModulePaymentServiceInterface
{
    public function __construct(
        private readonly PaymentService $paymentService,
        private readonly AuditLogger $audit,
    ) {}

    public function getModuleKey(): string
    {
        return 'payroll';
    }

    /**
     * Create and record a payment batch for a payroll run.
     *
     * @param PayrollRun $subject
     */
    public function createBatch(Model $subject, string $gateway, User $actor, ?string $notes = null): PaymentBatch
    {
        if (!$subject instanceof PayrollRun) {
            throw new Exception('Subject must be an instance of PayrollRun');
        }

        if ($subject->status !== PayrollRun::STATUS_APPROVED && $subject->status !== PayrollRun::STATUS_PAID) {
            throw RuleViolationException::make(
                'ST-1',
                'Only an approved payroll run can have a payment batch generated.',
                ['status' => $subject->status]
            );
        }

        return DB::transaction(function () use ($subject, $gateway, $actor, $notes): PaymentBatch {
            $batchReference = $this->paymentService->generateReference('PB-PAY-' . sprintf('%04d%02d', $subject->period_year, $subject->period_month));

            $payslips = $subject->payslips()->with('employee.department')->get();
            $netTotalMinor = (int) $payslips->sum('net_minor');
            $grossTotalMinor = (int) $payslips->sum('gross_minor');
            $deductionsTotalMinor = (int) $payslips->sum('deductions_minor');

            // Synchronize payroll run totals if they drifted
            $subject->forceFill([
                'employee_count' => $payslips->count(),
                'gross_total_minor' => $grossTotalMinor,
                'deductions_total_minor' => $deductionsTotalMinor,
                'net_total_minor' => $netTotalMinor,
            ])->save();

            $batch = PaymentBatch::query()->create([
                'batch_reference' => $batchReference,
                'source_module' => $this->getModuleKey(),
                'source_type' => $subject->getMorphClass(),
                'source_id' => $subject->getKey(),
                'gateway' => $gateway,
                'currency' => 'NGN',
                'total_amount_minor' => $netTotalMinor,
                'total_fee_minor' => 0,
                'total_items_count' => $payslips->count(),
                'successful_items_count' => 0,
                'failed_items_count' => 0,
                'status' => PaymentBatch::STATUS_INITIALIZED,
                'notes' => $notes,
                'meta' => [
                    'period' => $subject->periodLabel(),
                    'gross_minor' => $grossTotalMinor,
                    'deductions_minor' => $deductionsTotalMinor,
                ],
                'initiated_by_user_id' => $actor->getKey(),
                'disbursed_at' => Wat::now(),
            ]);

            foreach ($payslips as $payslip) {
                $employee = $payslip->employee;
                $itemRef = $this->paymentService->generateReference('PBI-PAY-' . $payslip->id);

                PaymentBatchItem::query()->create([
                    'payment_batch_id' => $batch->id,
                    'item_reference' => $itemRef,
                    'recipient_type' => $employee ? $employee->getMorphClass() : null,
                    'recipient_id' => $employee?->id,
                    'recipient_name' => $employee?->name ?? 'Staff Member',
                    'recipient_email' => $employee?->email,
                    'recipient_phone' => $employee?->phone,
                    'recipient_bank_code' => $employee?->bank_code ?? $this->resolveBankCode($employee?->bank_name),
                    'recipient_bank_name' => $employee?->bank_name ?? 'Commercial Bank',
                    'recipient_account_number' => $employee?->bank_account_number ?? ($employee?->bank_account_masked ?? '0000000000'),
                    'amount_minor' => (int) $payslip->net_minor,
                    'fee_minor' => 0,
                    'narration' => 'Salary for ' . $subject->periodLabel(),
                    'status' => PaymentBatchItem::STATUS_INITIALIZED,
                ]);
            }

            return $batch;
        });
    }

    /**
     * Execute disbursement for an existing payment batch.
     */
    public function disburseBatch(PaymentBatch $batch, ?string $otp = null): PaymentBatch
    {
        $batch->load(['items', 'source']);
        $payrollRun = $batch->source;

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
            title: 'Payroll Disbursement — ' . ($batch->meta['period'] ?? 'Staff Salaries'),
            currency: $batch->currency,
            otp: $otp,
        );

        $transferResult = $this->paymentService->bulkTransfer($bulkRequest, $batch->gateway);

        if (!$transferResult->success && in_array($batch->gateway, ['monnify', 'paystack', 'zainpay'])) {
            throw new Exception('Payment Gateway Error (' . ucfirst($batch->gateway) . '): ' . ($transferResult->message ?? 'Batch transfer failed.'));
        }

        DB::transaction(function () use ($batch, $transferResult, $payrollRun): void {
            $successfulCount = 0;
            $failedCount = 0;
            $totalFees = $transferResult->totalFeeMinor;

            foreach ($batch->items as $item) {
                $itemResult = $transferResult->itemResults[$item->item_reference] ?? null;

                if ($itemResult && ($itemResult['status'] === 'successful' || $transferResult->success)) {
                    $item->forceFill([
                        'status' => PaymentBatchItem::STATUS_SUCCESSFUL,
                        'gateway_reference' => $itemResult['gateway_reference'] ?? $transferResult->gatewayBatchReference,
                        'fee_minor' => $itemResult['fee_minor'] ?? 0,
                        'gateway_response' => $itemResult,
                        'paid_at' => Wat::now(),
                    ])->save();
                    $successfulCount++;
                } else {
                    $item->forceFill([
                        'status' => PaymentBatchItem::STATUS_FAILED,
                        'failure_reason' => $itemResult['message'] ?? $transferResult->message,
                        'gateway_response' => $itemResult,
                    ])->save();
                    $failedCount++;
                }
            }

            $batchStatus = ($transferResult->status === 'processing') ? PaymentBatch::STATUS_PROCESSING : PaymentBatch::STATUS_COMPLETED;
            if ($failedCount === count($batch->items) && !$transferResult->success) {
                $batchStatus = PaymentBatch::STATUS_FAILED;
            } elseif ($failedCount > 0 && $successfulCount > 0) {
                $batchStatus = PaymentBatch::STATUS_PARTIALLY_COMPLETED;
            }

            $now = Wat::now();

            $batch->forceFill([
                'gateway_batch_reference' => $transferResult->gatewayBatchReference,
                'total_fee_minor' => $totalFees,
                'successful_items_count' => $successfulCount,
                'failed_items_count' => $failedCount,
                'status' => $batchStatus,
                'completed_at' => ($batchStatus === PaymentBatch::STATUS_COMPLETED) ? $now : null,
            ])->save();

            // Settle payroll run if completed
            if ($payrollRun instanceof PayrollRun && $batchStatus === PaymentBatch::STATUS_COMPLETED) {
                $payrollRun->forceFill([
                    'status' => PayrollRun::STATUS_PAID,
                    'paid_at' => $now,
                ])->save();

                $this->audit->edited(
                    $payrollRun,
                    sprintf(
                        '%s disbursed via %s (%s) — %s to %d employees',
                        $payrollRun->periodLabel(),
                        strtoupper($batch->gateway),
                        $batch->batch_reference,
                        Money::format((int) $payrollRun->net_total_minor),
                        $successfulCount
                    ),
                    'Human Resources',
                    ['status' => PayrollRun::STATUS_APPROVED],
                    ['status' => PayrollRun::STATUS_PAID, 'batch' => $batch->batch_reference, 'fee_minor' => $totalFees],
                    $batch->authorizedBy ?? $batch->initiatedBy
                );
            }
        });

        return $batch->refresh();
    }

    /**
     * Convenience method to execute disbursement in one step without creating orphan failed batches.
     */
    public function disburseRun(PayrollRun $run, string $gateway, User $actor, ?string $notes = null, ?string $otp = null): PaymentBatch
    {
        $payslips = $run->payslips()->with('employee.department')->get();
        if ($payslips->isEmpty()) {
            throw new Exception('Cannot disburse payroll: No payslip items found on this run.');
        }

        $netTotalMinor = (int) $payslips->sum('net_minor');
        $run->forceFill([
            'employee_count' => $payslips->count(),
            'gross_total_minor' => (int) $payslips->sum('gross_minor'),
            'deductions_total_minor' => (int) $payslips->sum('deductions_minor'),
            'net_total_minor' => $netTotalMinor,
        ])->save();

        $batchReference = $this->paymentService->generateReference('PB-PAY-' . sprintf('%04d%02d', $run->period_year, $run->period_month));

        // For online payment gateways, test API batch transfer first before creating database batch
        if (in_array($gateway, ['monnify', 'paystack', 'zainpay'])) {
            $recipients = [];
            $payslipItemRefs = [];

            foreach ($payslips as $payslip) {
                $employee = $payslip->employee;
                $itemRef = $this->paymentService->generateReference('PBI-PAY-' . $payslip->id);
                $payslipItemRefs[$payslip->id] = $itemRef;

                $recipients[] = new PayoutRecipient(
                    reference: $itemRef,
                    name: $employee?->name ?? 'Staff Member',
                    accountNumber: $employee?->bank_account_number ?? ($employee?->bank_account_masked ?? '0000000000'),
                    bankCode: $employee?->bank_code ?? $this->resolveBankCode($employee?->bank_name),
                    amountMinor: (int) $payslip->net_minor,
                    bankName: $employee?->bank_name ?? 'Commercial Bank',
                    email: $employee?->email,
                    phone: $employee?->phone,
                    narration: 'Salary for ' . $run->periodLabel(),
                );
            }

            $bulkRequest = new BulkTransferRequest(
                batchReference: $batchReference,
                recipients: $recipients,
                title: 'Payroll Disbursement — ' . $run->periodLabel(),
                currency: 'NGN',
                otp: $otp,
            );

            $transferResult = $this->paymentService->bulkTransfer($bulkRequest, $gateway);

            if (!$transferResult->success) {
                throw new Exception('Payment Gateway Error (' . ucfirst($gateway) . '): ' . ($transferResult->message ?? 'Gateway rejected bulk transfer request.'));
            }

            return DB::transaction(function () use ($run, $gateway, $actor, $notes, $batchReference, $payslips, $transferResult, $payslipItemRefs): PaymentBatch {
                $now = Wat::now();

                $successfulCount = 0;
                $failedCount = 0;
                $totalFees = $transferResult->totalFeeMinor;

                $batch = PaymentBatch::query()->create([
                    'batch_reference' => $batchReference,
                    'gateway_batch_reference' => $transferResult->gatewayBatchReference,
                    'gateway_status' => $transferResult->gatewayStatus ?? ($transferResult->status === 'completed' ? 'SUCCESS' : 'PROCESSING'),
                    'source_module' => $this->getModuleKey(),
                    'source_type' => $run->getMorphClass(),
                    'source_id' => $run->getKey(),
                    'gateway' => $gateway,
                    'currency' => 'NGN',
                    'total_amount_minor' => (int) $run->net_total_minor,
                    'total_fee_minor' => $totalFees,
                    'total_items_count' => $payslips->count(),
                    'successful_items_count' => 0,
                    'failed_items_count' => 0,
                    'status' => PaymentBatch::STATUS_INITIALIZED,
                    'notes' => $notes,
                    'meta' => [
                        'period' => $run->periodLabel(),
                        'gross_minor' => $run->gross_total_minor,
                        'deductions_minor' => $run->deductions_total_minor,
                    ],
                    'initiated_by_user_id' => $actor->getKey(),
                    'authorized_by_user_id' => $actor->getKey(),
                    'disbursed_at' => $now,
                ]);

                foreach ($payslips as $payslip) {
                    $employee = $payslip->employee;
                    $itemRef = $payslipItemRefs[$payslip->id] ?? $this->paymentService->generateReference('PBI-PAY-' . $payslip->id);
                    $itemResult = $transferResult->itemResults[$itemRef] ?? null;

                    $isSuccess = $itemResult && ($itemResult['status'] === 'successful');
                    $itemStatus = $isSuccess ? PaymentBatchItem::STATUS_SUCCESSFUL : PaymentBatchItem::STATUS_FAILED;
                    $gwItemRef = $itemResult['gateway_reference'] ?? $transferResult->gatewayBatchReference;
                    $gwItemStatus = $itemResult['gateway_status'] ?? ($isSuccess ? 'SUCCESS' : 'FAILED');
                    $itemFee = $itemResult['fee_minor'] ?? 0;
                    $itemMsg = $itemResult['message'] ?? null;
                    $rawResponse = $itemResult['raw'] ?? (is_array($itemResult) ? $itemResult : ['message' => $itemMsg, 'status' => $gwItemStatus]);

                    if ($isSuccess) {
                        $successfulCount++;
                    } else {
                        $failedCount++;
                    }

                    PaymentBatchItem::query()->create([
                        'payment_batch_id' => $batch->id,
                        'item_reference' => $itemRef,
                        'recipient_type' => $employee ? $employee->getMorphClass() : null,
                        'recipient_id' => $employee?->id,
                        'recipient_name' => $employee?->name ?? 'Staff Member',
                        'recipient_email' => $employee?->email,
                        'recipient_phone' => $employee?->phone,
                        'recipient_bank_code' => $employee?->bank_code ?? $this->resolveBankCode($employee?->bank_name),
                        'recipient_bank_name' => $employee?->bank_name ?? 'Commercial Bank',
                        'recipient_account_number' => $employee?->bank_account_number ?? ($employee?->bank_account_masked ?? '0000000000'),
                        'amount_minor' => (int) $payslip->net_minor,
                        'fee_minor' => $itemFee,
                        'narration' => 'Salary for ' . $run->periodLabel(),
                        'status' => $itemStatus,
                        'gateway_reference' => $gwItemRef,
                        'gateway_status' => $gwItemStatus,
                        'gateway_response' => $rawResponse,
                        'failure_reason' => $isSuccess ? null : $itemMsg,
                        'paid_at' => $isSuccess ? $now : null,
                    ]);
                }

                $batchStatus = match ($transferResult->status) {
                    'completed' => ($failedCount > 0 ? PaymentBatch::STATUS_PARTIALLY_COMPLETED : PaymentBatch::STATUS_COMPLETED),
                    'failed' => PaymentBatch::STATUS_FAILED,
                    default => PaymentBatch::STATUS_PROCESSING,
                };

                $batch->forceFill([
                    'gateway_status' => $transferResult->gatewayStatus ?? ($batchStatus === PaymentBatch::STATUS_COMPLETED ? 'SUCCESS' : 'PROCESSING'),
                    'successful_items_count' => $successfulCount,
                    'failed_items_count' => $failedCount,
                    'status' => $batchStatus,
                    'completed_at' => ($batchStatus === PaymentBatch::STATUS_COMPLETED) ? $now : null,
                ])->save();

                if ($batchStatus === PaymentBatch::STATUS_COMPLETED) {
                    $run->forceFill([
                        'status' => PayrollRun::STATUS_PAID,
                        'paid_at' => $now,
                    ])->save();
                }

                return $batch;
            });
        }

        // Direct bank settlement / cash
        $batch = $this->createBatch($run, $gateway, $actor, $notes);
        $batch->forceFill(['authorized_by_user_id' => $actor->getKey()])->save();

        return $this->disburseBatch($batch, $otp);
    }

    /**
     * Authorize and finalize an existing pending payment batch with an OTP code.
     */
    public function authorizeBatchOtp(PaymentBatch $batch, string $otp, User $actor): PaymentBatch
    {
        $batch->load(['items', 'source']);
        $payrollRun = $batch->source;

        $reference = $batch->gateway_batch_reference ?: $batch->batch_reference;

        try {
            if ($batch->gateway === 'monnify') {
                $api = MonnifyApi::getInstance();
                $response = $api->post('api/v2/disbursements/batch/validate-otp', [
                    'reference' => $reference,
                    'authorizationCode' => $otp,
                ]);

                if (!isset($response['requestSuccessful']) || $response['requestSuccessful'] !== true) {
                    $msg = $response['responseMessage'] ?? 'Invalid authorization code';
                    throw new Exception($msg);
                }
            } elseif ($batch->gateway === 'paystack') {
                $api = PaystackApi::getInstance();
                $response = $api->post('transfer/finalize_transfer', [
                    'transfer_code' => $reference,
                    'otp' => $otp,
                ]);

                if (!isset($response['status']) || $response['status'] !== true) {
                    $msg = $response['message'] ?? 'Invalid OTP code';
                    throw new Exception($msg);
                }
            }
        } catch (\Throwable $e) {
            $errorMessage = $e->getMessage();
            $lower = strtolower($errorMessage);

            // If OTP is disabled on Paystack/Monnify and transfer is already running/processed without OTP
            $isOtpDisabledOrAlreadyDone = str_contains($lower, 'cannot be finalized with otp')
                || str_contains($lower, 'otp is disabled')
                || str_contains($lower, 'otp disabled')
                || str_contains($lower, 'not required')
                || str_contains($lower, 'already processed')
                || str_contains($lower, 'already queued')
                || str_contains($lower, 'already finalized');

            if ($isOtpDisabledOrAlreadyDone) {
                goto finalize_batch;
            }

            // Check if error indicates expired OTP or non-retryable batch state
            $isTerminalFailure = str_contains($lower, 'expired')
                || str_contains($lower, 'not awaiting authorization')
                || str_contains($lower, 'exceeded')
                || str_contains($lower, 'not found')
                || str_contains($lower, 'cancelled');

            if ($isTerminalFailure) {
                DB::transaction(function () use ($batch, $errorMessage): void {
                    $batch->forceFill([
                        'status' => PaymentBatch::STATUS_FAILED,
                        'failed_items_count' => $batch->items->count(),
                        'successful_items_count' => 0,
                    ])->save();

                    foreach ($batch->items as $item) {
                        $item->forceFill([
                            'status' => PaymentBatchItem::STATUS_FAILED,
                            'failure_reason' => $errorMessage,
                        ])->save();
                    }
                });
            }

            throw new Exception(ucfirst($batch->gateway) . ' OTP Error: ' . $errorMessage);
        }

        finalize_batch:
        // If gateway successfully validated OTP or OTP is disabled, finalize batch and payroll run
        DB::transaction(function () use ($batch, $actor, $payrollRun): void {
            $now = Wat::now();

            foreach ($batch->items as $item) {
                $item->forceFill([
                    'status' => PaymentBatchItem::STATUS_SUCCESSFUL,
                    'gateway_status' => 'SUCCESS',
                    'paid_at' => $now,
                ])->save();
            }

            $batch->forceFill([
                'status' => PaymentBatch::STATUS_COMPLETED,
                'gateway_status' => 'SUCCESS',
                'successful_items_count' => $batch->items->count(),
                'failed_items_count' => 0,
                'authorized_by_user_id' => $actor->getKey(),
                'completed_at' => $now,
            ])->save();

            if ($payrollRun instanceof PayrollRun) {
                $payrollRun->forceFill([
                    'status' => PayrollRun::STATUS_PAID,
                    'paid_at' => $now,
                ])->save();

                $this->audit->edited(
                    $payrollRun,
                    sprintf(
                        '%s finalized with OTP via %s (%s) — %s to %d employees',
                        $payrollRun->periodLabel(),
                        strtoupper($batch->gateway),
                        $batch->batch_reference,
                        Money::format((int) $payrollRun->net_total_minor),
                        $batch->items->count()
                    ),
                    'Human Resources',
                    ['status' => PayrollRun::STATUS_APPROVED],
                    ['status' => PayrollRun::STATUS_PAID, 'batch' => $batch->batch_reference],
                    $actor
                );
            }
        });

        return $batch->refresh();
    }

    /**
     * Re-query live gateway API and synchronize the status of a pending batch.
     */
    public function syncBatchStatus(PaymentBatch $batch, User $actor): PaymentBatch
    {
        $batch->load(['items', 'source']);
        $payrollRun = $batch->source;
        $reference = $batch->gateway_batch_reference ?: $batch->batch_reference;

        if ($batch->gateway === 'paystack') {
            $api = PaystackApi::getInstance();
            $gwRef = $batch->gateway_batch_reference;
            $firstItem = $batch->items->first();
            $itemRef = $firstItem?->item_reference ?: $reference;

            $status = null;
            $data = [];
            $matchedTransferCode = null;

            // 1. Try querying recent transfers list on Paystack
            try {
                $response = $api->get('transfer', ['perPage' => 25]);
                $recentTransfers = $response['data'] ?? [];

                foreach ($recentTransfers as $trf) {
                    $trfRef = $trf['reference'] ?? '';
                    $trfCode = $trf['transfer_code'] ?? '';
                    $trfAmount = (int) ($trf['amount'] ?? 0);
                    $trfAccount = $trf['recipient']['details']['account_number'] ?? ($trf['recipient']['account_number'] ?? '');

                    // Match by item reference, gateway ref, transfer code, or account number + amount
                    if (
                        $trfRef === $itemRef
                        || $trfRef === $reference
                        || $trfCode === $gwRef
                        || ($firstItem && $trfAccount === $firstItem->recipient_account_number && $trfAmount === (int) $firstItem->amount_minor)
                    ) {
                        $status = strtolower($trf['status'] ?? '');
                        $matchedTransferCode = $trfCode ?: ($trf['id'] ?? null);
                        break;
                    }
                }
            } catch (\Throwable $e) {
                Log::info('Paystack transfers list query note: ' . $e->getMessage());
            }

            // 2. If not found in list, try single transfer verify
            if (!$status) {
                try {
                    $response = $api->get('transfer/verify/' . $itemRef);
                    $data = $response['data'] ?? [];
                    $status = strtolower($data['status'] ?? '');
                    $matchedTransferCode = $data['transfer_code'] ?? null;
                } catch (\Throwable $e) {
                    if (!empty($gwRef)) {
                        try {
                            $response = $api->get('transfer/' . $gwRef);
                            $data = $response['data'] ?? [];
                            $status = strtolower($data['status'] ?? '');
                            $matchedTransferCode = $gwRef;
                        } catch (\Throwable $e2) {
                            Log::info("Paystack transfer lookup note for {$gwRef}: " . $e2->getMessage());
                        }
                    }
                }
            }

            if ($status === 'success' || $status === 'processing' || $status === 'pending' || $status === 'queued') {
                $now = Wat::now();
                $isDone = ($status === 'success' || $status === 'queued' || $status === 'pending');
                $gwStatus = strtoupper($status ?: 'SUCCESS');

                DB::transaction(function () use ($batch, $isDone, $now, $payrollRun, $actor, $matchedTransferCode, $gwStatus): void {
                    $batch->forceFill([
                        'gateway_batch_reference' => $matchedTransferCode ?: $batch->gateway_batch_reference,
                        'gateway_status' => $gwStatus,
                        'status' => $isDone ? PaymentBatch::STATUS_COMPLETED : PaymentBatch::STATUS_PROCESSING,
                        'successful_items_count' => $batch->items->count(),
                        'completed_at' => $isDone ? $now : null,
                    ])->save();

                    if ($isDone) {
                        foreach ($batch->items as $item) {
                            $item->forceFill([
                                'gateway_reference' => $matchedTransferCode ?: $item->gateway_reference,
                                'gateway_status' => $gwStatus,
                                'gateway_response' => ['status' => $gwStatus, 'transfer_code' => $matchedTransferCode],
                                'status' => PaymentBatchItem::STATUS_SUCCESSFUL,
                                'paid_at' => $now,
                            ])->save();
                        }

                        if ($payrollRun instanceof PayrollRun) {
                            $payrollRun->forceFill([
                                'status' => PayrollRun::STATUS_PAID,
                                'paid_at' => $now,
                            ])->save();
                        }
                    }
                });

                return $batch->refresh();
            }
        } elseif ($batch->gateway === 'monnify') {
            $api = MonnifyApi::getInstance();
            try {
                $response = $api->get('api/v2/disbursements/batch/summary', [
                    'reference' => $reference,
                ]);

                if (isset($response['requestSuccessful']) && $response['requestSuccessful'] === true) {
                    $body = $response['responseBody'] ?? [];
                    $status = strtoupper($body['status'] ?? '');
                    $txList = $body['transactionList'] ?? ($body['transactions'] ?? []);

                    $now = Wat::now();

                    DB::transaction(function () use ($batch, $now, $payrollRun, $actor, $status, $txList): void {
                        $successCount = 0;
                        $failedCount = 0;

                        foreach ($batch->items as $item) {
                            $matchedTx = null;
                            if (is_array($txList)) {
                                foreach ($txList as $tx) {
                                    if (is_array($tx) && (($tx['reference'] ?? null) === $item->item_reference || ($tx['destinationAccountNumber'] ?? null) === $item->recipient_account_number)) {
                                        $matchedTx = $tx;
                                        break;
                                    }
                                }
                            }

                            $txStatus = strtoupper($matchedTx['status'] ?? ($status === 'SUCCESS' ? 'SUCCESS' : 'PENDING'));
                            $txMsg = $matchedTx['responseMessage'] ?? ($matchedTx['message'] ?? null);
                            $isItemSuccess = ($txStatus === 'SUCCESS' || $txStatus === 'PAID');
                            $isItemFailed = ($txStatus === 'FAILED' || $txStatus === 'REVERSED');

                            if ($isItemSuccess) {
                                $successCount++;
                                $item->forceFill([
                                    'status' => PaymentBatchItem::STATUS_SUCCESSFUL,
                                    'gateway_reference' => $matchedTx['transactionReference'] ?? $item->gateway_reference,
                                    'gateway_status' => $txStatus,
                                    'gateway_response' => $matchedTx ?? ['status' => $txStatus, 'message' => $txMsg],
                                    'failure_reason' => null,
                                    'paid_at' => $now,
                                ])->save();
                            } elseif ($isItemFailed) {
                                $failedCount++;
                                $item->forceFill([
                                    'status' => PaymentBatchItem::STATUS_FAILED,
                                    'gateway_reference' => $matchedTx['transactionReference'] ?? $item->gateway_reference,
                                    'gateway_status' => $txStatus,
                                    'gateway_response' => $matchedTx ?? ['status' => $txStatus, 'message' => $txMsg],
                                    'failure_reason' => $txMsg ?: 'Transaction failed at Monnify',
                                    'paid_at' => null,
                                ])->save();
                            }
                        }

                        $batchStatus = ($failedCount === 0 && $successCount > 0 && ($status === 'SUCCESS' || $status === 'COMPLETED'))
                            ? PaymentBatch::STATUS_COMPLETED
                            : (($failedCount > 0 && $successCount > 0)
                                ? PaymentBatch::STATUS_PARTIALLY_COMPLETED
                                : (($failedCount > 0 && $successCount === 0) ? PaymentBatch::STATUS_FAILED : $batch->status));

                        $batch->forceFill([
                            'status' => $batchStatus,
                            'gateway_status' => $status,
                            'successful_items_count' => $successCount,
                            'failed_items_count' => $failedCount,
                            'completed_at' => ($batchStatus === PaymentBatch::STATUS_COMPLETED) ? $now : null,
                        ])->save();

                        if ($batchStatus === PaymentBatch::STATUS_COMPLETED && $payrollRun instanceof PayrollRun) {
                            $payrollRun->forceFill([
                                'status' => PayrollRun::STATUS_PAID,
                                'paid_at' => $now,
                            ])->save();
                        }
                    });

                    return $batch->refresh();
                }
            } catch (\Throwable $e) {
                Log::info('Monnify batch summary sync note: ' . $e->getMessage());
            }
        }

        return $batch->refresh();
    }

    /**
     * Revalidate all item settlement statuses directly from the payment gateway
     * without modifying the source PayrollRun or payslips.
     */
    public function revalidateBatchItems(PaymentBatch $batch, User $actor): PaymentBatch
    {
        $batch->load(['items']);
        $reference = $batch->gateway_batch_reference ?: $batch->batch_reference;
        $now = Wat::now();

        if ($batch->gateway === 'paystack') {
            $api = PaystackApi::getInstance();

            // Fetch recent Paystack transfers to batch-match
            $recentTransfers = [];
            try {
                $response = $api->get('transfer', ['perPage' => 50]);
                $recentTransfers = $response['data'] ?? [];
            } catch (\Throwable $e) {
                Log::info('Paystack transfers list query in revalidation: ' . $e->getMessage());
            }

            DB::transaction(function () use ($batch, $api, $recentTransfers, $now): void {
                $successCount = 0;
                $failedCount = 0;
                $latestGwStatus = $batch->gateway_status;

                foreach ($batch->items as $item) {
                    $matchedTrf = null;

                    // 1. Check recent transfers
                    foreach ($recentTransfers as $trf) {
                        $trfRef = $trf['reference'] ?? '';
                        $trfCode = $trf['transfer_code'] ?? '';
                        $trfAmount = (int) ($trf['amount'] ?? 0);
                        $trfAccount = $trf['recipient']['details']['account_number'] ?? ($trf['recipient']['account_number'] ?? '');

                        if (
                            $trfRef === $item->item_reference
                            || ($item->gateway_reference && $trfCode === $item->gateway_reference)
                            || ($trfAccount === $item->recipient_account_number && $trfAmount === (int) $item->amount_minor)
                        ) {
                            $matchedTrf = $trf;
                            break;
                        }
                    }

                    // 2. If not found in list and item has reference, try single verify
                    if (!$matchedTrf && $item->item_reference) {
                        try {
                            $res = $api->get('transfer/verify/' . $item->item_reference);
                            if (isset($res['data'])) {
                                $matchedTrf = $res['data'];
                            }
                        } catch (\Throwable $e) {
                            if ($item->gateway_reference && str_starts_with($item->gateway_reference, 'TRF_')) {
                                try {
                                    $res = $api->get('transfer/' . $item->gateway_reference);
                                    if (isset($res['data'])) {
                                        $matchedTrf = $res['data'];
                                    }
                                } catch (\Throwable) {}
                            }
                        }
                    }

                    if ($matchedTrf) {
                        $rawStatus = strtolower($matchedTrf['status'] ?? 'processing');
                        $itemGwStatus = strtoupper($rawStatus);
                        $itemTrfCode = $matchedTrf['transfer_code'] ?? ($matchedTrf['id'] ?? $item->gateway_reference);
                        $itemMsg = $matchedTrf['message'] ?? ($matchedTrf['reason'] ?? 'Paystack: ' . ucfirst($rawStatus));

                        if ($rawStatus === 'success' || $rawStatus === 'successful') {
                            $successCount++;
                            $item->forceFill([
                                'status' => PaymentBatchItem::STATUS_SUCCESSFUL,
                                'gateway_reference' => $itemTrfCode,
                                'gateway_status' => $itemGwStatus,
                                'gateway_response' => $matchedTrf,
                                'failure_reason' => null,
                                'paid_at' => $item->paid_at ?? $now,
                            ])->save();
                        } elseif ($rawStatus === 'failed' || $rawStatus === 'reversed' || $rawStatus === 'abandoned') {
                            $failedCount++;
                            $item->forceFill([
                                'status' => PaymentBatchItem::STATUS_FAILED,
                                'gateway_reference' => $itemTrfCode,
                                'gateway_status' => $itemGwStatus,
                                'gateway_response' => $matchedTrf,
                                'failure_reason' => $itemMsg,
                                'paid_at' => null,
                            ])->save();
                        } else {
                            $item->forceFill([
                                'status' => PaymentBatchItem::STATUS_INITIALIZED,
                                'gateway_reference' => $itemTrfCode,
                                'gateway_status' => $itemGwStatus,
                                'gateway_response' => $matchedTrf,
                            ])->save();
                        }

                        $latestGwStatus = $itemGwStatus;
                    } else {
                        if ($item->status === PaymentBatchItem::STATUS_SUCCESSFUL) {
                            $successCount++;
                        } elseif ($item->status === PaymentBatchItem::STATUS_FAILED) {
                            $failedCount++;
                        }
                    }
                }

                $newBatchStatus = ($failedCount === 0 && $successCount === $batch->items->count())
                    ? PaymentBatch::STATUS_COMPLETED
                    : (($successCount > 0 && $failedCount > 0)
                        ? PaymentBatch::STATUS_PARTIALLY_COMPLETED
                        : (($failedCount > 0 && $successCount === 0) ? PaymentBatch::STATUS_FAILED : $batch->status));

                $batch->forceFill([
                    'status' => $newBatchStatus,
                    'gateway_status' => $latestGwStatus ?: $batch->gateway_status,
                    'successful_items_count' => $successCount,
                    'failed_items_count' => $failedCount,
                    'completed_at' => ($newBatchStatus === PaymentBatch::STATUS_COMPLETED) ? ($batch->completed_at ?? $now) : null,
                ])->save();
            });
        } elseif ($batch->gateway === 'monnify') {
            $api = MonnifyApi::getInstance();
            try {
                $response = $api->get('api/v2/disbursements/batch/summary', [
                    'reference' => $reference,
                ]);

                if (isset($response['requestSuccessful']) && $response['requestSuccessful'] === true) {
                    $body = $response['responseBody'] ?? [];
                    $status = strtoupper($body['status'] ?? '');
                    $txList = $body['transactionList'] ?? ($body['transactions'] ?? []);

                    DB::transaction(function () use ($batch, $now, $status, $txList): void {
                        $successCount = 0;
                        $failedCount = 0;

                        foreach ($batch->items as $item) {
                            $matchedTx = null;
                            if (is_array($txList)) {
                                foreach ($txList as $tx) {
                                    if (is_array($tx) && (($tx['reference'] ?? null) === $item->item_reference || ($tx['destinationAccountNumber'] ?? null) === $item->recipient_account_number)) {
                                        $matchedTx = $tx;
                                        break;
                                    }
                                }
                            }

                            $txStatus = strtoupper($matchedTx['status'] ?? ($status === 'SUCCESS' ? 'SUCCESS' : 'PENDING'));
                            $txMsg = $matchedTx['responseMessage'] ?? ($matchedTx['message'] ?? null);
                            $isItemSuccess = ($txStatus === 'SUCCESS' || $txStatus === 'PAID');
                            $isItemFailed = ($txStatus === 'FAILED' || $txStatus === 'REVERSED');

                            if ($isItemSuccess) {
                                $successCount++;
                                $item->forceFill([
                                    'status' => PaymentBatchItem::STATUS_SUCCESSFUL,
                                    'gateway_reference' => $matchedTx['transactionReference'] ?? $item->gateway_reference,
                                    'gateway_status' => $txStatus,
                                    'gateway_response' => $matchedTx ?? ['status' => $txStatus, 'message' => $txMsg],
                                    'failure_reason' => null,
                                    'paid_at' => $item->paid_at ?? $now,
                                ])->save();
                            } elseif ($isItemFailed) {
                                $failedCount++;
                                $item->forceFill([
                                    'status' => PaymentBatchItem::STATUS_FAILED,
                                    'gateway_reference' => $matchedTx['transactionReference'] ?? $item->gateway_reference,
                                    'gateway_status' => $txStatus,
                                    'gateway_response' => $matchedTx ?? ['status' => $txStatus, 'message' => $txMsg],
                                    'failure_reason' => $txMsg ?: 'Transaction failed at Monnify',
                                    'paid_at' => null,
                                ])->save();
                            } else {
                                if ($item->status === PaymentBatchItem::STATUS_SUCCESSFUL) {
                                    $successCount++;
                                } elseif ($item->status === PaymentBatchItem::STATUS_FAILED) {
                                    $failedCount++;
                                }
                            }
                        }

                        $newBatchStatus = ($failedCount === 0 && $successCount === $batch->items->count())
                            ? PaymentBatch::STATUS_COMPLETED
                            : (($successCount > 0 && $failedCount > 0)
                                ? PaymentBatch::STATUS_PARTIALLY_COMPLETED
                                : (($failedCount > 0 && $successCount === 0) ? PaymentBatch::STATUS_FAILED : $batch->status));

                        $batch->forceFill([
                            'status' => $newBatchStatus,
                            'gateway_status' => $status ?: $batch->gateway_status,
                            'successful_items_count' => $successCount,
                            'failed_items_count' => $failedCount,
                            'completed_at' => ($newBatchStatus === PaymentBatch::STATUS_COMPLETED) ? ($batch->completed_at ?? $now) : null,
                        ])->save();
                    });
                }
            } catch (\Throwable $e) {
                Log::info('Monnify batch item revalidation note: ' . $e->getMessage());
            }
        }

        return $batch->refresh();
    }

    /**
     * Map common Nigerian commercial bank names to standard CBN 3-digit bank codes.
     */
    private function resolveBankCode(?string $bankName): string
    {
        if (!$bankName) {
            return '044'; // Access Bank default
        }

        $bank = strtolower(trim($bankName));

        return match (true) {
            str_contains($bank, 'access') => '044',
            str_contains($bank, 'gtb') || str_contains($bank, 'guaranty') => '058',
            str_contains($bank, 'zenith') => '057',
            str_contains($bank, 'first bank') || str_contains($bank, 'fbn') => '011',
            str_contains($bank, 'uba') || str_contains($bank, 'united bank') => '033',
            str_contains($bank, 'fcmb') => '214',
            str_contains($bank, 'fidelity') => '070',
            str_contains($bank, 'stanbic') => '221',
            str_contains($bank, 'sterling') => '232',
            str_contains($bank, 'union') => '032',
            str_contains($bank, 'wema') => '035',
            str_contains($bank, 'kuda') => '50211',
            str_contains($bank, 'opay') => '999992',
            str_contains($bank, 'palmpay') => '999991',
            str_contains($bank, 'moniepoint') => '50515',
            default => '044',
        };
    }
}
