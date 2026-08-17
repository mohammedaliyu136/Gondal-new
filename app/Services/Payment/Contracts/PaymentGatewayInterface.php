<?php

namespace App\Services\Payment\Contracts;

use App\Services\Payment\DTOs\BulkTransferRequest;
use App\Services\Payment\DTOs\BulkTransferResult;
use App\Services\Payment\DTOs\PaymentInitRequest;
use App\Services\Payment\DTOs\PaymentInitResult;
use App\Services\Payment\DTOs\PaymentVerifyResult;
use App\Services\Payment\DTOs\PayoutRecipient;

interface PaymentGatewayInterface
{
    /**
     * Get the identifier key for this gateway (e.g., 'paystack', 'monnify', 'zainpay', 'bank_transfer').
     */
    public function getGatewayName(): string;

    /**
     * Determine whether the gateway is currently enabled in settings.
     */
    public function isEnabled(): bool;

    /**
     * Initialize a payment collection session/transaction.
     */
    public function initialize(PaymentInitRequest $request): PaymentInitResult;

    /**
     * Verify a collection or payout transaction by reference.
     */
    public function verify(string $reference): PaymentVerifyResult;

    /**
     * Verify and process an incoming webhook payload from the gateway.
     */
    public function webhook(array $payload, array $headers, string $rawBody): ?PaymentVerifyResult;

    /**
     * Initiate a single payout/transfer to a bank account.
     */
    public function initiateTransfer(PayoutRecipient $recipient, ?string $otp = null): PaymentInitResult;

    /**
     * Initiate a batch/bulk payout to multiple bank accounts.
     */
    public function initiateBulkTransfer(BulkTransferRequest $request): BulkTransferResult;

    /**
     * Authorize and finalize a pending batch transfer using an OTP / 2FA code.
     */
    public function validateBatchOtp(string $batchReference, string $otp, ?string $gatewayBatchReference = null): BulkTransferResult;

    /**
     * Resend authorization OTP/2FA code for a pending bulk transfer batch.
     */
    public function resendBatchOtp(string $batchReference, ?string $gatewayBatchReference = null): array;

    /**
     * Verify and synchronize live settlement status for a bulk transfer batch and its line items.
     *
     * @param array<int, array{reference: string, account_number?: string, amount_minor?: int, gateway_reference?: string, status?: string}> $items
     */
    public function verifyBatch(string $batchReference, ?string $gatewayBatchReference = null, array $items = []): BulkTransferResult;
}
