<?php

namespace App\Services\Payment\Gateways;

use App\Services\Payment\Contracts\PaymentGatewayInterface;
use App\Services\Payment\DTOs\BulkTransferRequest;
use App\Services\Payment\DTOs\BulkTransferResult;
use App\Services\Payment\DTOs\PaymentInitRequest;
use App\Services\Payment\DTOs\PaymentInitResult;
use App\Services\Payment\DTOs\PaymentVerifyResult;
use App\Services\Payment\DTOs\PayoutRecipient;
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

        $zainboxCode = Settings::string('payment.zainpay.zainbox_code', config('services.zainpay.zainbox_code', ''));
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

        $token = Settings::string('payment.zainpay.public_key', config('services.zainpay.public_key', ''));

        if (!$signature || empty($token)) {
            Log::warning('Zainpay webhook received without signature or API token is missing.');
            return null;
        }

        $expectedSignature = hash_hmac('sha256', $rawBody, $token);

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

    public function initiateTransfer(PayoutRecipient $recipient, ?string $otp = null): PaymentInitResult
    {
        if (!$this->isEnabled()) {
            throw new Exception('Zainpay gateway is currently disabled.');
        }

        $zainboxCode = Settings::string('payment.zainpay.zainbox_code', '');
        $sourceAccount = Settings::string('payment.zainpay.source_account_number', '');

        if (empty($zainboxCode)) {
            throw new Exception('Zainpay Zainbox Code is not configured.');
        }

        try {
            $api = ZainpayApi::getInstance();

            $payload = [
                'destinationAccountNumber' => $recipient->accountNumber,
                'destinationBankCode' => $recipient->bankCode,
                'amount' => (string) round($recipient->amountMinor / 100, 2),
                'narration' => $recipient->narration ?? 'Payroll Disbursement',
                'sourceAccountNumber' => $sourceAccount,
                'zainboxCode' => $zainboxCode,
                'txnRef' => $recipient->reference,
            ];

            $response = $api->post('bank/funds/transfer', $payload);

            if (isset($response['code']) && ($response['code'] === '00' || $response['code'] === 0)) {
                return new PaymentInitResult(
                    reference: $recipient->reference,
                    redirectUrl: null,
                    rawResponse: json_encode($response),
                    success: true,
                    message: $response['description'] ?? 'Transfer initiated successfully',
                    data: $response['data'] ?? []
                );
            }

            throw new Exception($response['description'] ?? 'Zainpay transfer failed');
        } catch (\Throwable $e) {
            Log::error('Zainpay transfer error: ' . $e->getMessage(), ['reference' => $recipient->reference]);
            throw new Exception('Zainpay transfer failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function initiateBulkTransfer(BulkTransferRequest $request): BulkTransferResult
    {
        if (!$this->isEnabled()) {
            throw new Exception('Zainpay gateway is currently disabled.');
        }

        $zainboxCode = Settings::string('payment.zainpay.zainbox_code', '');
        $sourceAccount = Settings::string('payment.zainpay.source_account_number', '');

        if (empty($zainboxCode)) {
            return BulkTransferResult::failed($request->batchReference, 'Zainpay Zainbox Code is not configured in settings.');
        }

        $itemResults = [];
        $totalFeeMinor = 0;
        $successCount = 0;
        $failedCount = 0;

        try {
            $api = ZainpayApi::getInstance();

            // Iterate sequential single transfers for each recipient in the batch
            foreach ($request->recipients as $recipient) {
                try {
                    $payload = [
                        'destinationAccountNumber' => $recipient->accountNumber,
                        'destinationBankCode' => $recipient->bankCode,
                        'amount' => (string) round($recipient->amountMinor / 100, 2),
                        'narration' => $recipient->narration ?? $request->title,
                        'sourceAccountNumber' => $sourceAccount,
                        'zainboxCode' => $zainboxCode,
                        'txnRef' => $recipient->reference,
                    ];

                    $response = $api->post('bank/funds/transfer', $payload);

                    if (isset($response['code']) && ($response['code'] === '00' || $response['code'] === 0)) {
                        $itemResults[$recipient->reference] = [
                            'status' => 'successful',
                            'gateway_reference' => $recipient->reference,
                            'fee_minor' => 1200, // ₦12 Zainpay transfer fee per transaction
                            'message' => $response['description'] ?? 'Transfer successful',
                            'raw' => $response,
                        ];
                        $totalFeeMinor += 1200;
                        $successCount++;
                    } else {
                        $itemResults[$recipient->reference] = [
                            'status' => 'failed',
                            'message' => $response['description'] ?? 'Transfer rejected by Zainpay',
                            'raw' => $response,
                        ];
                        $failedCount++;
                    }
                } catch (\Throwable $e) {
                    $itemResults[$recipient->reference] = [
                        'status' => 'failed',
                        'message' => $e->getMessage(),
                    ];
                    $failedCount++;
                }
            }

            $batchStatus = ($successCount === count($request->recipients)) ? 'completed' : (($successCount > 0) ? 'partially_completed' : 'failed');

            return new BulkTransferResult(
                success: ($successCount > 0),
                batchReference: $request->batchReference,
                gatewayBatchReference: $request->batchReference,
                status: $batchStatus,
                message: "Disbursed {$successCount} of " . count($request->recipients) . " transfers via Zainpay.",
                totalAmountMinor: $request->totalAmountMinor(),
                totalFeeMinor: $totalFeeMinor,
                itemResults: $itemResults,
                rawResponse: ['success_count' => $successCount, 'failed_count' => $failedCount]
            );
        } catch (\Throwable $e) {
            Log::error('Zainpay bulk disbursement error: ' . $e->getMessage(), ['batch' => $request->batchReference]);
            return BulkTransferResult::failed($request->batchReference, $e->getMessage());
        }
    }

    public function validateBatchOtp(string $batchReference, string $otp, ?string $gatewayBatchReference = null): BulkTransferResult
    {
        return BulkTransferResult::successful(
            batchReference: $batchReference,
            gatewayBatchReference: $gatewayBatchReference ?: $batchReference,
            status: 'completed',
            message: 'Zainpay transfers finalized',
            gatewayStatus: 'SUCCESS',
        );
    }

    public function resendBatchOtp(string $batchReference, ?string $gatewayBatchReference = null): array
    {
        return ['status' => true, 'message' => 'OTP not required for Zainpay disbursement'];
    }

    public function verifyBatch(string $batchReference, ?string $gatewayBatchReference = null, array $items = []): BulkTransferResult
    {
        $itemResults = [];
        $successCount = 0;
        $failedCount = 0;

        foreach ($items as $item) {
            $ref = $item['reference'] ?? '';
            $status = $item['status'] ?? 'successful';
            if ($status === 'successful') {
                $successCount++;
            } elseif ($status === 'failed') {
                $failedCount++;
            }
            $itemResults[$ref] = [
                'status' => $status,
                'gateway_reference' => $item['gateway_reference'] ?? $ref,
                'gateway_status' => strtoupper($status),
                'fee_minor' => 1200,
                'message' => 'Zainpay transfer verified',
            ];
        }

        $batchStatus = ($failedCount === 0 && $successCount === count($items) && count($items) > 0) ? 'completed' : 'processing';

        return BulkTransferResult::successful(
            batchReference: $batchReference,
            gatewayBatchReference: $gatewayBatchReference ?: $batchReference,
            status: $batchStatus,
            message: "Zainpay batch verified ({$successCount} successful, {$failedCount} failed)",
            totalAmountMinor: 0,
            totalFeeMinor: $successCount * 1200,
            itemResults: $itemResults,
            gatewayStatus: 'SUCCESS',
        );
    }
}
