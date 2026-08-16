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
}
