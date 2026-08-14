<?php

namespace App\Services\Payment\Gateways;

use App\Services\Payment\Contracts\PaymentGatewayInterface;
use App\Services\Payment\DTOs\PaymentInitRequest;
use App\Services\Payment\DTOs\PaymentInitResult;
use App\Services\Payment\DTOs\PaymentVerifyResult;
use App\Services\Payment\PaymentApi\MonnifyApi;
use App\Support\Settings;
use Illuminate\Support\Facades\Log;
use Exception;

class MonnifyGateway implements PaymentGatewayInterface
{
    public function getGatewayName(): string
    {
        return 'monnify';
    }

    public function isEnabled(): bool
    {
        return Settings::boolean('payment.monnify.enabled', true);
    }

    public function initialize(PaymentInitRequest $request): PaymentInitResult
    {
        if (!$this->isEnabled()) {
            throw new Exception('Monnify payment gateway is currently disabled.');
        }

        $contractCode = Settings::string('payment.monnify.contract_code', config('services.monnify.contract_code', ''));
        if (empty($contractCode)) {
            throw new Exception('Monnify Contract Code is not configured in Payment Settings.');
        }

        try {
            $api = MonnifyApi::getInstance();
            
            $payload = [
                'amount' => (float) $request->amount,
                'customerName' => $request->customerName ?? 'Customer',
                'customerEmail' => $request->email,
                'paymentReference' => $request->reference,
                'paymentDescription' => $request->description ?? ('Payment ref ' . $request->reference),
                'currencyCode' => $request->currency ?? 'NGN',
                'contractCode' => $contractCode,
                'redirectUrl' => $request->callbackUrl ?? url('/'),
                'paymentMethods' => ['CARD', 'ACCOUNT_TRANSFER'],
            ];

            if (!empty($request->metadata)) {
                $payload['metaData'] = $request->metadata;
            }

            $response = $api->post('api/v1/merchant/transactions/init-transaction', $payload);

            if (isset($response['requestSuccessful']) && $response['requestSuccessful'] === true && !empty($response['responseBody']['checkoutUrl'])) {
                $data = $response['responseBody'];
                return new PaymentInitResult(
                    reference: $request->reference,
                    redirectUrl: $data['checkoutUrl'],
                    rawResponse: json_encode($response),
                    success: true,
                    message: $response['responseMessage'] ?? 'Initialization successful',
                    data: $data
                );
            }

            throw new Exception('Monnify initialization failed: ' . ($response['responseMessage'] ?? 'Unknown error'));
        } catch (\Throwable $e) {
            Log::error('Monnify initialization error: ' . $e->getMessage(), ['reference' => $request->reference]);
            throw new Exception('Monnify initialization failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function verify(string $reference): PaymentVerifyResult
    {
        if (empty($reference)) {
            throw new Exception('Transaction reference is required for Monnify verification.');
        }

        try {
            $api = MonnifyApi::getInstance();
            $response = $api->get('api/v1/merchant/transactions/query', [
                'paymentReference' => $reference,
            ]);

            if (isset($response['requestSuccessful']) && $response['requestSuccessful'] === true) {
                $data = $response['responseBody'] ?? [];
                $paymentStatus = strtoupper($data['paymentStatus'] ?? 'UNKNOWN');
                $isSuccessful = ($paymentStatus === 'PAID');

                return new PaymentVerifyResult(
                    amountPaid: (float) ($data['amountPaid'] ?? ($data['amount'] ?? 0)),
                    paymentDate: $data['completedOn'] ?? ($data['createdOn'] ?? date('Y-m-d H:i:s')),
                    gateway: 'monnify',
                    rawResponse: json_encode($data),
                    reference: $reference,
                    isSuccessful: $isSuccessful,
                    status: $paymentStatus,
                    customerEmail: $data['customer']['email'] ?? null,
                    channel: $data['paymentMethod'] ?? null,
                    metadata: $data['metaData'] ?? []
                );
            }

            throw new Exception($response['responseMessage'] ?? 'Monnify transaction not found');
        } catch (\Throwable $e) {
            Log::error('Monnify verification error: ' . $e->getMessage(), ['reference' => $reference]);
            throw new Exception('Unable to verify Monnify transaction: ' . $e->getMessage(), 0, $e);
        }
    }

    public function webhook(array $payload, array $headers, string $rawBody): ?PaymentVerifyResult
    {
        $signature = $headers['monnify-signature'] ?? $headers['Monnify-Signature'] ?? null;
        if (is_array($signature)) {
            $signature = $signature[0];
        }

        $secretKey = Settings::string('payment.monnify.secret_key', config('services.monnify.secret_key', ''));

        if (!$signature || empty($secretKey)) {
            Log::warning('Monnify webhook received without signature or secret key is missing.');
            return null;
        }

        $computedSignature = hash_hmac('sha512', $rawBody, $secretKey);

        if (!hash_equals($computedSignature, $signature)) {
            Log::warning('Monnify webhook signature mismatch.');
            return null;
        }

        $reference = $payload['eventData']['paymentReference'] ?? ($payload['paymentReference'] ?? null);

        if (!$reference) {
            Log::warning('Monnify webhook payload missing payment reference.');
            return null;
        }

        try {
            return $this->verify($reference);
        } catch (\Throwable $e) {
            Log::error('Monnify webhook verification failed for ref ' . $reference . ': ' . $e->getMessage());
            return null;
        }
    }
}
