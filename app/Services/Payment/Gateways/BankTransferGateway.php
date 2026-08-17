<?php

namespace App\Services\Payment\Gateways;

use App\Services\Payment\Contracts\PaymentGatewayInterface;
use App\Services\Payment\DTOs\BulkTransferRequest;
use App\Services\Payment\DTOs\BulkTransferResult;
use App\Services\Payment\DTOs\PaymentInitRequest;
use App\Services\Payment\DTOs\PaymentInitResult;
use App\Services\Payment\DTOs\PaymentVerifyResult;
use App\Services\Payment\DTOs\PayoutRecipient;

class BankTransferGateway implements PaymentGatewayInterface
{
    public function getGatewayName(): string
    {
        return 'bank_transfer';
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function initialize(PaymentInitRequest $request): PaymentInitResult
    {
        return new PaymentInitResult(
            reference: $request->reference,
            redirectUrl: null,
            rawResponse: json_encode(['method' => 'bank_transfer']),
            success: true,
            message: 'Direct bank transfer invoice generated',
            data: ['method' => 'bank_transfer']
        );
    }

    public function verify(string $reference): PaymentVerifyResult
    {
        return new PaymentVerifyResult(
            amountPaid: 0.0,
            paymentDate: date('Y-m-d H:i:s'),
            gateway: 'bank_transfer',
            rawResponse: json_encode(['status' => 'settled']),
            reference: $reference,
            isSuccessful: true,
            status: 'success',
        );
    }

    public function webhook(array $payload, array $headers, string $rawBody): ?PaymentVerifyResult
    {
        return null;
    }

    public function initiateTransfer(PayoutRecipient $recipient, ?string $otp = null): PaymentInitResult
    {
        return new PaymentInitResult(
            reference: $recipient->reference,
            redirectUrl: null,
            rawResponse: json_encode(['method' => 'bank_transfer', 'recipient' => $recipient->toArray()]),
            success: true,
            message: 'Bank transfer recorded',
            data: $recipient->toArray()
        );
    }

    public function initiateBulkTransfer(BulkTransferRequest $request): BulkTransferResult
    {
        $itemResults = [];
        foreach ($request->recipients as $recipient) {
            $itemResults[$recipient->reference] = [
                'status' => 'successful',
                'gateway_reference' => 'EFT-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8)),
                'fee_minor' => 0, // Direct bank settlement has zero gateway fees
                'message' => 'Direct bank settlement recorded',
            ];
        }

        return BulkTransferResult::successful(
            batchReference: $request->batchReference,
            gatewayBatchReference: 'EFT-BATCH-' . date('Ymd') . '-' . substr(bin2hex(random_bytes(3)), 0, 6),
            status: 'completed',
            message: 'Bank transfer disbursement recorded successfully',
            totalAmountMinor: $request->totalAmountMinor(),
            totalFeeMinor: 0,
            itemResults: $itemResults,
            rawResponse: ['method' => 'direct_bank_settlement'],
            gatewayStatus: 'SETTLED',
        );
    }

    public function validateBatchOtp(string $batchReference, string $otp, ?string $gatewayBatchReference = null): BulkTransferResult
    {
        return BulkTransferResult::successful(
            batchReference: $batchReference,
            gatewayBatchReference: $gatewayBatchReference ?: $batchReference,
            status: 'completed',
            message: 'Direct bank settlement completed',
            gatewayStatus: 'SETTLED',
        );
    }

    public function resendBatchOtp(string $batchReference, ?string $gatewayBatchReference = null): array
    {
        return ['status' => true, 'message' => 'OTP not required for direct bank settlement'];
    }

    public function verifyBatch(string $batchReference, ?string $gatewayBatchReference = null, array $items = []): BulkTransferResult
    {
        $itemResults = [];
        foreach ($items as $item) {
            $ref = $item['reference'] ?? '';
            $itemResults[$ref] = [
                'status' => 'successful',
                'gateway_reference' => $item['gateway_reference'] ?? ('EFT-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8))),
                'gateway_status' => 'SETTLED',
                'fee_minor' => 0,
                'message' => 'Settled via direct bank transfer',
            ];
        }

        return BulkTransferResult::successful(
            batchReference: $batchReference,
            gatewayBatchReference: $gatewayBatchReference ?: $batchReference,
            status: 'completed',
            message: 'Direct bank settlement verified',
            totalAmountMinor: 0,
            totalFeeMinor: 0,
            itemResults: $itemResults,
            gatewayStatus: 'SETTLED',
        );
    }
}
