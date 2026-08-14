<?php

namespace Tests\Feature;

use App\Services\Payment\DTOs\PaymentInitRequest;
use App\Services\Payment\Gateways\PaymentGatewayFactory;
use App\Services\Payment\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentSettingsTest extends TestCase
{
    /*
     * getGatewayStatuses() reads each gateway's mode out of Settings, which is a
     * table read. Without migrations the sqlite database is empty and the read
     * fails on "no such table: settings" rather than on anything to do with
     * payments.
     */
    use RefreshDatabase;

    public function test_payment_gateway_factory_resolves_gateways(): void
    {
        $paystack = PaymentGatewayFactory::create('paystack');
        $monnify = PaymentGatewayFactory::create('monnify');
        $zainpay = PaymentGatewayFactory::create('zainpay');

        $this->assertSame('paystack', $paystack->getGatewayName());
        $this->assertSame('monnify', $monnify->getGatewayName());
        $this->assertSame('zainpay', $zainpay->getGatewayName());
    }

    public function test_payment_service_generate_reference(): void
    {
        $service = new PaymentService();
        $ref = $service->generateReference('TEST');

        $this->assertStringStartsWith('TEST-', $ref);
        $this->assertNotEmpty($service->getGatewayStatuses());
    }

    public function test_payment_init_request_dto(): void
    {
        $dto = PaymentInitRequest::make(
            amount: 5000.0,
            email: 'farmer@example.com',
            reference: 'REF-12345',
            callbackUrl: 'https://example.com/callback',
            customerName: 'Aliyu Farmer',
            phone: '08012345678',
            description: 'Milk delivery fee'
        );

        $this->assertSame(5000.0, $dto->amount);
        $this->assertSame('farmer@example.com', $dto->email);
        $this->assertSame('REF-12345', $dto->reference);
    }
}
