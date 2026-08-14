<?php

namespace App\Services\Payment\Contracts;

use App\Services\Payment\DTOs\PaymentInitRequest;
use App\Services\Payment\DTOs\PaymentInitResult;
use App\Services\Payment\DTOs\PaymentVerifyResult;

interface PaymentGatewayInterface
{
    /**
     * Get the identifier key for this gateway (e.g., 'paystack', 'monnify', 'zainpay').
     */
    public function getGatewayName(): string;

    /**
     * Determine whether the gateway is currently enabled in settings.
     */
    public function isEnabled(): bool;

    /**
     * Initialize a payment session/transaction with the gateway.
     */
    public function initialize(PaymentInitRequest $request): PaymentInitResult;

    /**
     * Verify a transaction by its reference.
     */
    public function verify(string $reference): PaymentVerifyResult;

    /**
     * Verify and process a webhook payload from the gateway.
     */
    public function webhook(array $payload, array $headers, string $rawBody): ?PaymentVerifyResult;
}
