<?php

namespace App\Services\Payment\Modules;

use App\Exceptions\RuleViolationException;
use App\Models\PaymentBatch;
use App\Models\PaymentBatchItem;
use App\Models\Requisition;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Payment\Contracts\ModulePaymentServiceInterface;
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
        private readonly AuditLogger $audit,
    ) {}

    public function getModuleKey(): string
    {
        return 'requisition';
    }

    /**
     * Create and record a payment batch for a purchase requisition.
     *
     * @param Requisition $subject
     */
    public function createBatch(Model $subject, string $gateway, User $actor, ?string $notes = null): PaymentBatch
    {
        if (!$subject instanceof Requisition) {
            throw new Exception('Subject must be an instance of Requisition');
        }

        return DB::transaction(function () use ($subject, $gateway, $actor, $notes): PaymentBatch {
            $batchReference = $this->paymentService->generateReference('PB-REQ-' . $subject->id);
            $amountMinor = (int) ($subject->approved_total_minor ?? $subject->estimated_total_minor);

            $batch = PaymentBatch::query()->create([
                'batch_reference' => $batchReference,
                'source_module' => $this->getModuleKey(),
                'source_type' => $subject->getMorphClass(),
                'source_id' => $subject->getKey(),
                'gateway' => $gateway,
                'currency' => 'NGN',
                'total_amount_minor' => $amountMinor,
                'total_fee_minor' => 0,
                'total_items_count' => 1,
                'successful_items_count' => 0,
                'failed_items_count' => 0,
                'status' => PaymentBatch::STATUS_INITIALIZED,
                'notes' => $notes,
                'meta' => [
                    'requisition_reference' => $subject->reference,
                    'title' => $subject->title,
                ],
                'initiated_by_user_id' => $actor->getKey(),
                'disbursed_at' => Wat::now(),
            ]);

            PaymentBatchItem::query()->create([
                'payment_batch_id' => $batch->id,
                'item_reference' => $this->paymentService->generateReference('PBI-REQ-' . $subject->id),
                'recipient_type' => $subject->requester ? $subject->requester->getMorphClass() : null,
                'recipient_id' => $subject->requester_user_id,
                'recipient_name' => $subject->requester?->name ?? 'Vendor / Requester',
                'recipient_email' => $subject->requester?->email,
                'recipient_phone' => $subject->requester?->phone,
                'recipient_bank_code' => '044',
                'recipient_bank_name' => 'Commercial Bank',
                'recipient_account_number' => '0000000000',
                'amount_minor' => $amountMinor,
                'fee_minor' => 0,
                'narration' => 'Payment for ' . $subject->reference . ' — ' . $subject->title,
                'status' => PaymentBatchItem::STATUS_INITIALIZED,
            ]);

            return $batch;
        });
    }

    /**
     * Execute payout for the requisition batch.
     */
    public function disburseBatch(PaymentBatch $batch, ?string $otp = null): PaymentBatch
    {
        $batch->load(['items', 'source']);
        $item = $batch->items->first();

        if (!$item) {
            throw new Exception('No item found in requisition payment batch.');
        }

        $recipient = new PayoutRecipient(
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

        $transferResult = $this->paymentService->transfer($recipient, $batch->gateway, $otp);

        DB::transaction(function () use ($batch, $item, $transferResult): void {
            $now = Wat::now();

            if ($transferResult->success) {
                $item->forceFill([
                    'status' => PaymentBatchItem::STATUS_SUCCESSFUL,
                    'gateway_reference' => $transferResult->reference,
                    'fee_minor' => 1000,
                    'gateway_response' => $transferResult->data,
                    'paid_at' => $now,
                ])->save();

                $batch->forceFill([
                    'gateway_batch_reference' => $transferResult->reference,
                    'total_fee_minor' => 1000,
                    'successful_items_count' => 1,
                    'status' => PaymentBatch::STATUS_COMPLETED,
                    'completed_at' => $now,
                ])->save();
            } else {
                $item->forceFill([
                    'status' => PaymentBatchItem::STATUS_FAILED,
                    'failure_reason' => $transferResult->message,
                ])->save();

                $batch->forceFill([
                    'failed_items_count' => 1,
                    'status' => PaymentBatch::STATUS_FAILED,
                ])->save();
            }
        });

        return $batch->refresh();
    }
}
