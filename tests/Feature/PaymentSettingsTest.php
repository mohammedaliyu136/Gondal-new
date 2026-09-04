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

    public function test_monnify_validate_account_calls_v2_endpoint(): void
    {
        \Illuminate\Support\Facades\Http::fake([
            '*/api/v1/auth/login' => \Illuminate\Support\Facades\Http::response([
                'requestSuccessful' => true,
                'responseBody' => ['accessToken' => 'fake-test-token'],
            ]),
            '*/api/v2/disbursements/account/validate*' => \Illuminate\Support\Facades\Http::response([
                'requestSuccessful' => true,
                'responseMessage' => 'Success',
                'responseBody' => [
                    'accountName' => 'MUSA GARBA',
                    'accountNumber' => '0123456789',
                    'bankCode' => '058',
                ],
            ]),
        ]);

        \App\Support\Settings::put([
            'payment.monnify.api_key' => 'test-api-key',
            'payment.monnify.secret_key' => 'test-secret-key',
            'payment.monnify.enabled' => true,
        ]);
        \App\Services\Payment\PaymentApi\MonnifyApi::flush();

        /** @var \App\Services\Payment\Gateways\MonnifyGateway $monnify */
        $monnify = PaymentGatewayFactory::create('monnify');
        $body = $monnify->validateAccount('0123456789', '058');

        $this->assertSame('MUSA GARBA', $body['accountName']);

        \Illuminate\Support\Facades\Http::assertSent(function ($request) {
            return str_contains($request->url(), 'api/v2/disbursements/account/validate');
        });
    }

    public function test_bank_service_verify_account_via_monnify_uses_v2_endpoint(): void
    {
        \Illuminate\Support\Facades\Http::fake([
            '*/api/v1/auth/login' => \Illuminate\Support\Facades\Http::response([
                'requestSuccessful' => true,
                'responseBody' => ['accessToken' => 'fake-test-token'],
            ]),
            '*/api/v2/disbursements/account/validate*' => \Illuminate\Support\Facades\Http::response([
                'requestSuccessful' => true,
                'responseMessage' => 'Success',
                'responseBody' => [
                    'accountName' => 'FATIMA ALIYU',
                    'accountNumber' => '9876543210',
                    'bankCode' => '057',
                ],
            ]),
        ]);

        \App\Support\Settings::put([
            'payment.default_gateway' => 'monnify',
            'payment.monnify.api_key' => 'test-api-key',
            'payment.monnify.secret_key' => 'test-secret-key',
            'payment.monnify.enabled' => true,
        ]);
        \App\Services\Payment\PaymentApi\MonnifyApi::flush();

        $bankService = app(\App\Services\Payment\BankService::class);
        $result = $bankService->verifyAccount('9876543210', '057');

        $this->assertTrue($result['success']);
        $this->assertSame('FATIMA ALIYU', $result['account_name']);
        $this->assertSame('monnify', $result['gateway']);

        \Illuminate\Support\Facades\Http::assertSent(function ($request) {
            return str_contains($request->url(), 'api/v2/disbursements/account/validate');
        });
    }
}
