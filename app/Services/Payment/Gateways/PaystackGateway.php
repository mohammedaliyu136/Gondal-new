<?php

namespace App\Services\Payment\Gateways;

use App\Services\Payment\Contracts\PaymentGatewayInterface;
use App\Services\Payment\DTOs\PaymentInitRequest;
use App\Services\Payment\DTOs\PaymentInitResult;
use App\Services\Payment\DTOs\PaymentVerifyResult;
use App\Services\Payment\PaymentApi\PaystackApi;
use App\Support\Settings;
use Illuminate\Support\Facades\Log;
use Exception;

class PaystackGateway implements PaymentGatewayInterface
{
    public function getGatewayName(): string
    {
        return 'paystack';
    }

    public function isEnabled(): bool
    {
        return Settings::boolean('payment.paystack.enabled', true);
    }

    public function initialize(PaymentInitRequest $request): PaymentInitResult
    {
        if (!$this->isEnabled()) {
            throw new Exception('Paystack payment gateway is currently disabled.');
        }

        try {
            $api = PaystackApi::getInstance();
            
            $payload = [
                'amount' => (int) round($request->amount * 100), // convert major unit to kobo
                'email' => $request->email,
                'reference' => $request->reference,
                'currency' => $request->currency,
                'metadata' => array_filter(array_merge($request->metadata, [
                    'customer_name' => $request->customerName,
                    'phone' => $request->phone,
                    'description' => $request->description,
                ]), fn($val) => $val !== null),
            ];

            if (!empty($request->callbackUrl)) {
                $payload['callback_url'] = $request->callbackUrl;
            }

            $response = $api->post('transaction/initialize', $payload);

            if (isset($response['status']) && $response['status'] === true && !empty($response['data']['authorization_url'])) {
                $data = $response['data'];
                return new PaymentInitResult(
                    reference: $request->reference,
                    redirectUrl: $data['authorization_url'],
                    rawResponse: json_encode($response),
                    success: true,
                    message: $response['message'] ?? 'Initialization successful',
                    data: $data
                );
            }

            throw new Exception('Paystack initialization failed: ' . ($response['message'] ?? 'Unknown error'));
        } catch (\Throwable $e) {
            Log::error('Paystack initialization error: ' . $e->getMessage(), ['reference' => $request->reference]);
            throw new Exception('Paystack initialization failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function verify(string $reference): PaymentVerifyResult
    {
        if (empty($reference)) {
            throw new Exception('Transaction reference is required for Paystack verification.');
        }

        try {
            $api = PaystackApi::getInstance();
            $response = $api->get('transaction/verify/' . urlencode($reference));

            if (isset($response['status']) && $response['status'] === true) {
                $data = $response['data'] ?? [];
                $status = strtolower($data['status'] ?? 'unknown');
                $isSuccessful = ($status === 'success');

                return new PaymentVerifyResult(
                    amountPaid: (float) (($data['amount'] ?? 0) / 100),
                    paymentDate: $data['paid_at'] ?? ($data['created_at'] ?? date('Y-m-d H:i:s')),
                    gateway: 'paystack',
                    rawResponse: json_encode($data),
                    reference: $reference,
                    isSuccessful: $isSuccessful,
                    status: $status,
                    customerEmail: $data['customer']['email'] ?? null,
                    channel: $data['channel'] ?? null,
                    metadata: $data['metadata'] ?? []
                );
            }

            throw new Exception($response['message'] ?? 'Paystack transaction not found');
        } catch (\Throwable $e) {
            Log::error('Paystack verification error: ' . $e->getMessage(), ['reference' => $reference]);
            throw new Exception('Unable to verify Paystack transaction: ' . $e->getMessage(), 0, $e);
        }
    }

    public function webhook(array $payload, array $headers, string $rawBody): ?PaymentVerifyResult
    {
        $signature = $headers['x-paystack-signature'] ?? $headers['X-Paystack-Signature'] ?? null;
        if (is_array($signature)) {
            $signature = $signature[0];
        }

        $secretKey = Settings::string('payment.paystack.secret_key', config('services.paystack.secret_key', ''));

        if (!$signature || empty($secretKey)) {
            Log::warning('Paystack webhook received without signature or secret key is missing.');
            return null;
        }

        $computedSignature = hash_hmac('sha512', $rawBody, $secretKey);

        if (!hash_equals($computedSignature, $signature)) {
            Log::warning('Paystack webhook signature mismatch.');
            return null;
        }

        $data = $payload['data'] ?? [];
        $reference = $data['reference'] ?? ($payload['reference'] ?? null);

        if (!$reference) {
            Log::warning('Paystack webhook payload missing reference.');
            return null;
        }

        try {
            return $this->verify($reference);
        } catch (\Throwable $e) {
            Log::error('Paystack webhook verification failed for ref ' . $reference . ': ' . $e->getMessage());
            return null;
        }
    }
}
