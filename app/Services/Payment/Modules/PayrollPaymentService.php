<?php

namespace App\Services\Payment\Modules;

use App\Exceptions\RuleViolationException;
use App\Models\AuditEntry;
use App\Models\Employee;
use App\Models\PaymentBatch;
use App\Models\PaymentBatchItem;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Payment\Contracts\ModulePaymentServiceInterface;
use App\Services\Payment\DTOs\BulkTransferRequest;
use App\Services\Payment\DTOs\PayoutRecipient;
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

            $payslips = $subject->payslips()->where('status', '!=', Payslip::STATUS_PAID)->with('employee.department')->get();
            if ($payslips->isEmpty()) {
                throw new Exception('All payslips on this payroll run have already been marked as paid.');
            }
            $netTotalMinor = (int) $payslips->sum('net_minor');
            $grossTotalMinor = (int) $payslips->sum('gross_minor');
            $deductionsTotalMinor = (int) $payslips->sum('deductions_minor');

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
            title: 'Payroll Disbursement — ' . ($payrollRun instanceof PayrollRun ? $payrollRun->periodLabel() : 'Batch ' . $batch->batch_reference),
            currency: $batch->currency,
            otp: $otp,
        );

        $transferResult = $this->paymentService->bulkTransfer($bulkRequest, $batch->gateway);

        DB::transaction(function () use ($batch, $transferResult, $payrollRun): void {
            $now = Wat::now();
            $successfulCount = 0;
            $failedCount = 0;
            $totalFees = 0;

            foreach ($batch->items as $item) {
                $itemResult = $transferResult->itemResults[$item->item_reference] ?? null;
                $isItemSuccessful = $transferResult->success && ($itemResult === null || ($itemResult['status'] ?? '') === 'successful');

                if ($isItemSuccessful) {
                    $itemFee = (int) ($itemResult['fee_minor'] ?? 0);
                    $totalFees += $itemFee;
                    $item->forceFill([
                        'status' => PaymentBatchItem::STATUS_SUCCESSFUL,
                        'gateway_reference' => $itemResult['gateway_reference'] ?? $transferResult->gatewayBatchReference,
                        'fee_minor' => $itemFee,
                        'gateway_status' => 'SUCCESS',
                        'gateway_response' => $itemResult,
                        'paid_at' => $now,
                    ])->save();
                    $successfulCount++;

                    // Settle payslip
                    if ($payrollRun instanceof PayrollRun && $item->recipient_id) {
                        Payslip::query()
                            ->where('payroll_run_id', $payrollRun->id)
                            ->where('employee_id', $item->recipient_id)
                            ->update([
                                'status' => Payslip::STATUS_PAID,
                                'paid_at' => $now,
                            ]);
                    }
                } else {
                    $item->forceFill([
                        'status' => PaymentBatchItem::STATUS_FAILED,
                        'failure_reason' => $itemResult['message'] ?? $transferResult->message,
                        'gateway_status' => 'FAILED',
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

            $batch->forceFill([
                'gateway_batch_reference' => $transferResult->gatewayBatchReference,
                'gateway_status' => $transferResult->gatewayStatus ?? ($batchStatus === PaymentBatch::STATUS_COMPLETED ? 'SUCCESS' : 'PROCESSING'),
                'total_fee_minor' => $totalFees,
                'successful_items_count' => $successfulCount,
                'failed_items_count' => $failedCount,
                'status' => $batchStatus,
                'completed_at' => ($batchStatus === PaymentBatch::STATUS_COMPLETED) ? $now : null,
            ])->save();

            // Settle payroll run if all payslips are paid
            if ($payrollRun instanceof PayrollRun) {
                $unpaidCount = Payslip::query()
                    ->where('payroll_run_id', $payrollRun->id)
                    ->where('status', '!=', Payslip::STATUS_PAID)
                    ->count();

                if ($unpaidCount === 0) {
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
            }
        });

        return $batch->refresh();
    }

    /**
     * Convenience method to execute disbursement in one step without creating orphan failed batches.
     */
    public function disburseRun(PayrollRun $run, string $gateway, User $actor, ?string $notes = null, ?string $otp = null): PaymentBatch
    {
        $unpaidPayslips = $run->payslips()->where('status', '!=', Payslip::STATUS_PAID)->with('employee.department')->get();
        if ($unpaidPayslips->isEmpty()) {
            throw new Exception('All payslips in this payroll run have already been marked as paid.');
        }
        $payslips = $unpaidPayslips;

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

                $batch = PaymentBatch::query()->create([
                    'batch_reference' => $batchReference,
                    'gateway_batch_reference' => $transferResult->gatewayBatchReference,
                    'gateway_status' => $transferResult->gatewayStatus ?? ($transferResult->status === 'completed' ? 'SUCCESS' : 'PROCESSING'),
                    'source_module' => $this->getModuleKey(),
                    'source_type' => $run->getMorphClass(),
                    'source_id' => $run->getKey(),
                    'gateway' => $gateway,
                    'currency' => 'NGN',
                    'total_amount_minor' => $transferResult->totalAmountMinor,
                    'total_fee_minor' => $transferResult->totalFeeMinor,
                    'total_items_count' => $payslips->count(),
                    'successful_items_count' => 0,
                    'failed_items_count' => 0,
                    'status' => PaymentBatch::STATUS_INITIALIZED,
                    'notes' => $notes,
                    'meta' => [
                        'period' => $run->periodLabel(),
                        'gross_minor' => (int) $payslips->sum('gross_minor'),
                        'deductions_minor' => (int) $payslips->sum('deductions_minor'),
                    ],
                    'initiated_by_user_id' => $actor->getKey(),
                    'authorized_by_user_id' => ($transferResult->status === 'completed') ? $actor->getKey() : null,
                    'disbursed_at' => $now,
                ]);

                $successfulCount = 0;
                $failedCount = 0;

                foreach ($payslips as $payslip) {
                    $employee = $payslip->employee;
                    $itemRef = $payslipItemRefs[$payslip->id] ?? $this->paymentService->generateReference('PBI-PAY-' . $payslip->id);
                    $itemResult = $transferResult->itemResults[$itemRef] ?? null;

                    $isSuccess = $itemResult !== null && ($itemResult['status'] ?? '') === 'successful';
                    $isFailed = $itemResult !== null && ($itemResult['status'] ?? '') === 'failed';
                    $itemFee = (int) ($itemResult['fee_minor'] ?? 0);
                    $gwItemRef = (string) ($itemResult['gateway_reference'] ?? $transferResult->gatewayBatchReference);
                    $gwItemStatus = (string) ($itemResult['gateway_status'] ?? ($isSuccess ? 'SUCCESS' : ($isFailed ? 'FAILED' : 'PROCESSING')));
                    $itemMsg = $itemResult['message'] ?? null;
                    $rawResponse = $itemResult['raw'] ?? $itemResult;

                    if ($isSuccess) {
                        $successfulCount++;
                        $itemStatus = PaymentBatchItem::STATUS_SUCCESSFUL;
                        $payslip->forceFill(['status' => Payslip::STATUS_PAID, 'paid_at' => $now])->save();
                    } elseif ($isFailed) {
                        $failedCount++;
                        $itemStatus = PaymentBatchItem::STATUS_FAILED;
                    } else {
                        $itemStatus = PaymentBatchItem::STATUS_INITIALIZED;
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

                // Check if all payslips on the run are paid
                $unpaidCount = Payslip::query()
                    ->where('payroll_run_id', $run->id)
                    ->where('status', '!=', Payslip::STATUS_PAID)
                    ->count();

                if ($unpaidCount === 0) {
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

            // Auto-detect when OTP is disabled or transfers already processed
            $isOtpDisabled = str_contains($lower, 'otp has been disabled') || str_contains($lower, 'otp disabled');
            $isAlreadyProcessed = str_contains($lower, 'already processed') || str_contains($lower, 'already finalized');

            if ($isOtpDisabled || $isAlreadyProcessed) {
                goto finalize_batch;
            }

            // If OTP expired / not awaiting authorization, update batch status to failed
            if (
                str_contains($lower, 'expired') ||
                str_contains($lower, 'invalid otp') ||
                str_contains($lower, 'otp expired') ||
                str_contains($lower, 'token has expired') ||
                str_contains($lower, 'not awaiting authorization')
            ) {
                $batch->forceFill([
                    'status' => PaymentBatch::STATUS_FAILED,
                    'gateway_status' => 'EXPIRED',
                    'failed_items_count' => $batch->items->count(),
                    'notes' => trim(($batch->notes ? $batch->notes . "\n" : '') . 'Authorization failed: ' . $errorMessage),
                ])->save();

                foreach ($batch->items as $item) {
                    if ($item->status !== PaymentBatchItem::STATUS_SUCCESSFUL) {
                        $item->forceFill([
                            'status' => PaymentBatchItem::STATUS_FAILED,
                            'gateway_status' => 'EXPIRED',
                            'failure_reason' => 'Batch expired at gateway (Authorization window closed)',
                        ])->save();
                    }
                }
            }

            throw new Exception(ucfirst($batch->gateway) . ' OTP Error: ' . $errorMessage);
        }

        finalize_batch:
        $batch->forceFill([
            'authorized_by_user_id' => $actor->getKey(),
        ])->save();

        // Immediately trigger live status synchronization right after successful OTP authorization
        $syncedBatch = $this->syncBatchStatus($batch, $actor);

        if ($payrollRun instanceof PayrollRun) {
            $this->audit->edited(
                $payrollRun,
                sprintf(
                    '%s finalized with OTP via %s (%s) — %s to %d employees',
                    $payrollRun->periodLabel(),
                    strtoupper($batch->gateway),
                    $batch->batch_reference,
                    Money::format((int) $payrollRun->net_total_minor),
                    $syncedBatch->successful_items_count
                ),
                'Human Resources',
                ['status' => PayrollRun::STATUS_APPROVED],
                ['status' => $payrollRun->status, 'batch' => $batch->batch_reference],
                $actor
            );
        }

        return $syncedBatch;
    }

    /**
     * Resend 2FA authorization OTP code via gateway.
     */
    public function resendBatchOtp(PaymentBatch $batch, User $actor): void
    {
        $batch->load(['items', 'source']);

        $this->paymentService->resendBatchOtp(
            batchReference: $batch->batch_reference,
            gateway: $batch->gateway,
            gatewayBatchReference: $batch->gateway_batch_reference,
        );
    }

    /**
     * Re-query live payment gateway to synchronize batch and individual items,
     * updating payslip settlement statuses and overall payroll run state accordingly.
     */
    public function syncBatchStatus(PaymentBatch $batch, User $actor): PaymentBatch
    {
        $batch->load(['items', 'source']);
        $payrollRun = $batch->source;
        $now = Wat::now();

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

        DB::transaction(function () use ($batch, $syncResult, $now, $payrollRun): void {
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

                    // Settle payslip for this employee
                    if ($payrollRun instanceof PayrollRun && $item->recipient_id) {
                        Payslip::query()
                            ->where('payroll_run_id', $payrollRun->id)
                            ->where('employee_id', $item->recipient_id)
                            ->where('status', '!=', Payslip::STATUS_PAID)
                            ->update([
                                'status' => Payslip::STATUS_PAID,
                                'paid_at' => $itemPaidAt,
                            ]);
                    }
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

            $totalItems = $batch->items->count();
            $batchStatus = match ($syncResult->status) {
                'completed' => PaymentBatch::STATUS_COMPLETED,
                'failed' => PaymentBatch::STATUS_FAILED,
                'partially_completed' => PaymentBatch::STATUS_PARTIALLY_COMPLETED,
                default => ($failedCount === 0 && $successCount === $totalItems && $totalItems > 0)
                    ? PaymentBatch::STATUS_COMPLETED
                    : (($successCount > 0 && $failedCount > 0)
                        ? PaymentBatch::STATUS_PARTIALLY_COMPLETED
                        : (($failedCount === $totalItems && $totalItems > 0) ? PaymentBatch::STATUS_FAILED : PaymentBatch::STATUS_PROCESSING)),
            };

            $batch->forceFill([
                'status' => $batchStatus,
                'gateway_status' => $syncResult->gatewayStatus ?: $batch->gateway_status,
                'successful_items_count' => $successCount,
                'failed_items_count' => $failedCount,
                'completed_at' => ($batchStatus === PaymentBatch::STATUS_COMPLETED) ? ($batch->completed_at ?? $now) : null,
            ])->save();

            // Synchronize overall PayrollRun status if all payslips are settled
            if ($payrollRun instanceof PayrollRun) {
                $unpaidCount = Payslip::query()
                    ->where('payroll_run_id', $payrollRun->id)
                    ->where('status', '!=', Payslip::STATUS_PAID)
                    ->count();

                if ($unpaidCount === 0) {
                    $payrollRun->forceFill([
                        'status' => PayrollRun::STATUS_PAID,
                        'paid_at' => $payrollRun->paid_at ?? $now,
                    ])->save();
                }
            }
        });

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
