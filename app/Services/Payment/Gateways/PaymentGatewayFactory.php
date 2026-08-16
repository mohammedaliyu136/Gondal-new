<?php

namespace App\Services\Payment\Gateways;

use App\Services\Payment\Contracts\PaymentGatewayInterface;
use App\Support\Settings;
use Exception;

class PaymentGatewayFactory
{
    /**
     * Supported payment gateways.
     */
    public const SUPPORTED_GATEWAYS = [
        'paystack'      => 'Paystack',
        'monnify'       => 'Monnify',
        'zainpay'       => 'Zainpay',
        'bank_transfer' => 'Direct Bank Settlement / EFT',
    ];

    /**
     * Create an instance of a payment gateway.
     */
    public static function create(?string $gateway = null): PaymentGatewayInterface
    {
        $gatewayKey = $gateway ?: Settings::string('payment.default_gateway', 'paystack');
        $gatewayKey = strtolower(trim($gatewayKey));

        return match ($gatewayKey) {
            'paystack'                 => new PaystackGateway(),
            'monnify'                  => new MonnifyGateway(),
            'zainpay'                  => new ZainpayGateway(),
            'bank_transfer', 'cash'    => new BankTransferGateway(),
            default                    => throw new Exception("Unsupported payment gateway: [{$gatewayKey}]."),
        };
    }

    /**
     * Get all supported gateway keys with their display labels.
     */
    public static function supportedGateways(): array
    {
        return self::SUPPORTED_GATEWAYS;
    }

    /**
     * Get all currently enabled payment gateways.
     *
     * @return array<string, PaymentGatewayInterface>
     */
    public static function activeGateways(): array
    {
        $active = [];
        foreach (array_keys(self::SUPPORTED_GATEWAYS) as $key) {
            $gateway = self::create($key);
            if ($gateway->isEnabled()) {
                $active[$key] = $gateway;
            }
        }
        return $active;
    }

    /**
     * Check if a specific gateway is enabled in settings.
     */
    public static function isEnabled(string $gateway): bool
    {
        if ($gateway === 'bank_transfer' || $gateway === 'cash') {
            return true;
        }
        return Settings::boolean("payment.{$gateway}.enabled", true);
    }
}
