<?php

namespace App\Services\Payment;

use App\Services\Payment\Contracts\PaymentGatewayInterface;
use App\Services\Payment\DTOs\PaymentInitRequest;
use App\Services\Payment\DTOs\PaymentInitResult;
use App\Services\Payment\DTOs\PaymentVerifyResult;
use App\Services\Payment\Gateways\PaymentGatewayFactory;
use App\Support\Settings;
use Illuminate\Support\Facades\Log;

class PaymentService
{
    /**
     * Get a specific gateway instance or the default configured gateway.
     */
    public function gateway(?string $gateway = null): PaymentGatewayInterface
    {
        return PaymentGatewayFactory::create($gateway);
    }

    /**
     * Initialize a payment transaction with the selected or default gateway.
     */
    public function initialize(PaymentInitRequest $request, ?string $gateway = null): PaymentInitResult
    {
        return $this->gateway($gateway)->initialize($request);
    }

    /**
     * Verify a payment transaction reference.
     */
    public function verify(string $reference, ?string $gateway = null): PaymentVerifyResult
    {
        return $this->gateway($gateway)->verify($reference);
    }

    /**
     * Handle and verify an incoming webhook from a payment gateway.
     */
    public function handleWebhook(string $gateway, array $payload, array $headers, string $rawBody): ?PaymentVerifyResult
    {
        return $this->gateway($gateway)->webhook($payload, $headers, $rawBody);
    }

    /**
     * Generate a standard payment reference string.
     * E.g. 'GON-PAY-20260814-ABCD1234' or 'ORD-101-1692012345'
     */
    public function generateReference(string $prefix = 'PAY'): string
    {
        return strtoupper($prefix . '-' . date('Ymd') . '-' . substr(bin2hex(random_bytes(4)), 0, 8));
    }

    /**
     * Get the default gateway key.
     */
    public function getDefaultGatewayName(): string
    {
        return Settings::string('payment.default_gateway', 'paystack');
    }

    /**
     * Get all available gateway definitions with their configuration status.
     */
    public function getGatewayStatuses(): array
    {
        $default = $this->getDefaultGatewayName();
        $statuses = [];

        foreach (PaymentGatewayFactory::supportedGateways() as $key => $label) {
            $gateway = $this->gateway($key);
            $statuses[$key] = [
                'key' => $key,
                'label' => $label,
                'is_default' => ($key === $default),
                'is_enabled' => $gateway->isEnabled(),
                'mode' => Settings::string("payment.{$key}.mode", 'test'),
            ];
        }

        return $statuses;
    }
}
