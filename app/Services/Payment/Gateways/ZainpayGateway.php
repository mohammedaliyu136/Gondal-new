<?php

namespace App\Services\Payment\Gateways;

use App\Services\Payment\Contracts\PaymentGatewayInterface;
use App\Services\Payment\DTOs\PaymentInitRequest;
use App\Services\Payment\DTOs\PaymentInitResult;
use App\Services\Payment\DTOs\PaymentVerifyResult;
use App\Services\Payment\PaymentApi\ZainpayApi;
use App\Support\Settings;
use Illuminate\Support\Facades\Log;
use Exception;

class ZainpayGateway implements PaymentGatewayInterface
{
    public function getGatewayName(): string
    {
        return 'zainpay';
    }

    public function isEnabled(): bool
    {
        return Settings::boolean('payment.zainpay.enabled', true);
    }

    public function initialize(PaymentInitRequest $request): PaymentInitResult
    {
        if (!$this->isEnabled()) {
            throw new Exception('Zainpay payment gateway is currently disabled.');
        }

        $zainboxCode = Settings::string('payment.zainpay.zainbox_code', config('services.zainpay.zainbox_code', '')) ;
        if (empty($zainboxCode)) {
            throw new Exception('Zainpay Zainbox Code is not configured in Payment Settings.');
        }

        try {
            $api = ZainpayApi::getInstance();
            
            $payload = [
                'amount' => (string) $request->amount,
                'txnRef' => $request->reference,
                'mobileNumber' => $request->phone ?? '',
                'emailAddress' => $request->email,
                'zainboxCode' => $zainboxCode,
                'callBackUrl' => $request->callbackUrl ?? url('/'),
            ];

            $response = $api->post('zainbox/card/initialize/payment', $payload);

            if (isset($response['code']) && ($response['code'] === '00' || $response['code'] === 0)) {
                return new PaymentInitResult(
                    reference: $request->reference,
                    redirectUrl: $response['data'],
                    rawResponse: json_encode($response),
                    success: true,
                    message: $response['description'] ?? 'Initialization successful',
                    data: ['redirect_url' => $response['data']]
                );
            }

            throw new Exception('Zainpay initialization failed: ' . ($response['description'] ?? 'Unknown error'));
        } catch (\Throwable $e) {
            Log::error('Zainpay initialization error: ' . $e->getMessage(), ['reference' => $request->reference]);
            throw new Exception('Zainpay initialization failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function verify(string $reference): PaymentVerifyResult
    {
        if (empty($reference)) {
            throw new Exception('Transaction reference is required for Zainpay verification.');
        }

        try {
            $api = ZainpayApi::getInstance();
            $response = $api->get('virtual-account/wallet/deposit/verify/v2/' . urlencode($reference));

            if (isset($response['code']) && ($response['code'] === '00' || $response['code'] === 0)) {
                $data = $response['data'] ?? [];
                $status = strtolower($data['status'] ?? 'success');
                $isSuccessful = in_array($status, ['success', 'successful', '00', 'completed']);

                return new PaymentVerifyResult(
                    amountPaid: (float) ($data['amount'] ?? ($data['depositedAmount'] ?? 0)),
                    paymentDate: $data['paymentDate'] ?? ($data['dateCreated'] ?? date('Y-m-d H:i:s')),
                    gateway: 'zainpay',
                    rawResponse: json_encode($data),
                    reference: $reference,
                    isSuccessful: $isSuccessful,
                    status: $status,
                    customerEmail: $data['email'] ?? ($data['senderEmail'] ?? null),
                    channel: $data['paymentMethod'] ?? null,
                    metadata: $data
                );
            }

            throw new Exception($response['description'] ?? 'Zainpay transaction not found');
        } catch (\Throwable $e) {
            Log::error('Zainpay verification error: ' . $e->getMessage(), ['reference' => $reference]);
            throw new Exception('Unable to verify Zainpay transaction: ' . $e->getMessage(), 0, $e);
        }
    }

    public function webhook(array $payload, array $headers, string $rawBody): ?PaymentVerifyResult
    {
        $signature = $headers['zainpay-signature'] ?? $headers['Zainpay-Signature'] ?? null;
        if (is_array($signature)) {
            $signature = $signature[0];
        }

        $publicKey = Settings::string('payment.zainpay.public_key', config('services.zainpay.public_key', ''));

        if (!$signature || empty($publicKey)) {
            Log::warning('Zainpay webhook received without signature or public key is missing.');
            return null;
        }

        $expectedSignature = hash_hmac('sha256', $rawBody, $publicKey);

        if (!hash_equals($expectedSignature, $signature)) {
            Log::warning('Zainpay webhook signature mismatch.');
            return null;
        }

        $data = $payload['data'] ?? [];
        $reference = $data['paymentRef'] ?? ($payload['paymentRef'] ?? ($payload['reference'] ?? null));

        if (!$reference) {
            Log::warning('Zainpay webhook payload missing reference.');
            return null;
        }

        try {
            return $this->verify($reference);
        } catch (\Throwable $e) {
            Log::error('Zainpay webhook verification failed for ref ' . $reference . ': ' . $e->getMessage());
            return null;
        }
    }
}
