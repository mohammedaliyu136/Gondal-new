<?php

namespace App\Services\Payment\Gateways;

use App\Services\Payment\Contracts\PaymentGatewayInterface;
use App\Services\Payment\DTOs\BulkTransferRequest;
use App\Services\Payment\DTOs\BulkTransferResult;
use App\Services\Payment\DTOs\PaymentInitRequest;
use App\Services\Payment\DTOs\PaymentInitResult;
use App\Services\Payment\DTOs\PaymentVerifyResult;
use App\Services\Payment\DTOs\PayoutRecipient;
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

    public function initiateTransfer(PayoutRecipient $recipient, ?string $otp = null): PaymentInitResult
    {
        if (!$this->isEnabled()) {
            throw new Exception('Paystack gateway is currently disabled.');
        }

        try {
            $api = PaystackApi::getInstance();

            // Create transfer recipient
            $recipientPayload = [
                'type' => 'nuban',
                'name' => $recipient->name,
                'account_number' => $recipient->accountNumber,
                'bank_code' => $recipient->bankCode,
                'currency' => 'NGN',
            ];

            $rcpResponse = $api->post('transferrecipient', $recipientPayload);
            $recipientCode = $rcpResponse['data']['recipient_code'] ?? null;

            if (!$recipientCode) {
                throw new Exception('Failed to create Paystack transfer recipient: ' . ($rcpResponse['message'] ?? 'Unknown error'));
            }

            // Initiate transfer
            $transferPayload = [
                'source' => 'balance',
                'amount' => $recipient->amountMinor,
                'recipient' => $recipientCode,
                'reference' => $recipient->reference,
                'reason' => $recipient->narration ?? 'Payroll Disbursement',
            ];

            if ($otp !== null) {
                $transferPayload['otp'] = $otp;
            }

            $transferResponse = $api->post('transfer', $transferPayload);

            if (isset($transferResponse['status']) && $transferResponse['status'] === true) {
                $data = $transferResponse['data'] ?? [];
                return new PaymentInitResult(
                    reference: $recipient->reference,
                    redirectUrl: null,
                    rawResponse: json_encode($transferResponse),
                    success: true,
                    message: $transferResponse['message'] ?? 'Transfer initiated successfully',
                    data: $data
                );
            }

            throw new Exception($transferResponse['message'] ?? 'Transfer failed');
        } catch (\Throwable $e) {
            Log::error('Paystack transfer error: ' . $e->getMessage(), ['reference' => $recipient->reference]);
            throw new Exception('Paystack transfer failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function initiateBulkTransfer(BulkTransferRequest $request): BulkTransferResult
    {
        if (!$this->isEnabled()) {
            throw new Exception('Paystack gateway is currently disabled.');
        }

        $itemResults = [];
        $totalFeeMinor = 0;
        $successCount = 0;

        try {
            $api = PaystackApi::getInstance();
            $transfers = [];

            $recipientErrors = [];

            foreach ($request->recipients as $recipient) {
                try {
                    // Create Paystack transfer recipient
                    $rcpResponse = $api->post('transferrecipient', [
                        'type' => 'nuban',
                        'name' => $recipient->name,
                        'account_number' => $recipient->accountNumber,
                        'bank_code' => $recipient->bankCode,
                        'currency' => 'NGN',
                    ]);

                    $recipientCode = $rcpResponse['data']['recipient_code'] ?? null;
                    if ($recipientCode) {
                        $transfers[] = [
                            'amount' => $recipient->amountMinor,
                            'recipient' => $recipientCode,
                            'reference' => $recipient->reference,
                            'reason' => $recipient->narration ?? $request->title,
                        ];
                    } else {
                        $errMsg = $rcpResponse['message'] ?? 'Could not create Paystack recipient code';
                        $recipientErrors[] = "{$recipient->name} ({$recipient->accountNumber} / Bank: {$recipient->bankCode}): {$errMsg}";
                        Log::error("Paystack recipient creation failed for {$recipient->name}: {$errMsg}", [
                            'recipient' => $recipient->toArray(),
                            'response' => $rcpResponse,
                        ]);
                        $itemResults[$recipient->reference] = [
                            'status' => 'failed',
                            'message' => $errMsg,
                        ];
                    }
                } catch (\Throwable $e) {
                    $errMsg = $e->getMessage();
                    $recipientErrors[] = "{$recipient->name} ({$recipient->accountNumber} / Bank: {$recipient->bankCode}): {$errMsg}";
                    Log::error("Paystack recipient creation exception for {$recipient->name}: {$errMsg}", [
                        'recipient' => $recipient->toArray(),
                        'error' => $errMsg,
                    ]);
                    $itemResults[$recipient->reference] = [
                        'status' => 'failed',
                        'message' => $errMsg,
                    ];
                }
            }

            if (empty($transfers)) {
                $detailStr = !empty($recipientErrors) ? implode('; ', array_slice($recipientErrors, 0, 3)) : 'No valid recipient accounts could be created.';
                Log::error("Paystack bulk transfer preparation failed — 0 recipients created for batch {$request->batchReference}: {$detailStr}", [
                    'batch' => $request->batchReference,
                    'errors' => $recipientErrors,
                ]);
                return BulkTransferResult::failed($request->batchReference, 'Failed to prepare Paystack recipient accounts: ' . $detailStr);
            }

            $bulkPayload = [
                'currency' => 'NGN',
                'source' => 'balance',
                'transfers' => $transfers,
            ];

            if ($request->otp !== null) {
                $bulkPayload['otp'] = $request->otp;
            }

            $bulkResponse = $api->post('transfer/bulk', $bulkPayload);

            if (isset($bulkResponse['status']) && $bulkResponse['status'] === true) {
                $responseData = $bulkResponse['data'] ?? [];
                $transfersList = is_array($responseData) && isset($responseData[0]) ? $responseData : ($responseData['transfers'] ?? [$responseData]);

                $allSuccess = true;
                $batchCode = is_array($responseData) && isset($responseData['batch_code']) ? $responseData['batch_code'] : null;

                foreach ($transfers as $t) {
                    $ref = $t['reference'];
                    $matched = null;
                    foreach ($transfersList as $tRes) {
                        if (is_array($tRes) && (($tRes['reference'] ?? null) === $ref || ($tRes['recipient'] ?? null) === $t['recipient'])) {
                            $matched = $tRes;
                            break;
                        }
                    }

                    $itemTransferCode = $matched['transfer_code'] ?? ($matched['id'] ?? $batchCode);
                    $itemStatus = strtolower($matched['status'] ?? 'success');
                    $isItemSuccess = ($itemStatus === 'success' || $itemStatus === 'pending' || $itemStatus === 'processing' || $itemStatus === 'queued');

                    if (!$batchCode && !empty($itemTransferCode)) {
                        $batchCode = (string) $itemTransferCode;
                    }

                    $itemMsg = $matched['message'] ?? ($bulkResponse['message'] ?? ('Paystack: ' . ucfirst($itemStatus)));

                    if ($isItemSuccess) {
                        $itemResults[$ref] = [
                            'status' => 'successful',
                            'gateway_status' => strtoupper($itemStatus),
                            'gateway_reference' => (string) ($itemTransferCode ?: $request->batchReference),
                            'fee_minor' => 1000, // ₦10 standard transfer fee per item
                            'message' => $itemMsg,
                            'raw' => $matched ?? ['status' => $itemStatus, 'transfer_code' => $itemTransferCode],
                        ];
                        $totalFeeMinor += 1000;
                        $successCount++;
                    } else {
                        $allSuccess = false;
                        $itemResults[$ref] = [
                            'status' => 'failed',
                            'gateway_status' => strtoupper($itemStatus),
                            'gateway_reference' => (string) ($itemTransferCode ?: $request->batchReference),
                            'fee_minor' => 0,
                            'message' => $itemMsg,
                            'raw' => $matched ?? ['status' => $itemStatus, 'message' => $itemMsg],
                        ];
                    }
                }

                // If transfers were accepted and OTP is disabled, mark batch completed immediately
                $finalStatus = ($allSuccess && $successCount > 0) ? 'completed' : 'processing';
                $gwStatus = ($allSuccess && $successCount > 0) ? 'SUCCESS' : 'PROCESSING';

                return BulkTransferResult::successful(
                    batchReference: $request->batchReference,
                    gatewayBatchReference: (string) ($batchCode ?: $request->batchReference),
                    status: $finalStatus,
                    message: $bulkResponse['message'] ?? 'Bulk transfer submitted to Paystack',
                    totalAmountMinor: $request->totalAmountMinor(),
                    totalFeeMinor: $totalFeeMinor,
                    itemResults: $itemResults,
                    rawResponse: $bulkResponse,
                    gatewayStatus: $gwStatus,
                );
            }

            return BulkTransferResult::failed($request->batchReference, $bulkResponse['message'] ?? 'Bulk transfer request failed', $bulkResponse);
        } catch (\Throwable $e) {
            Log::error('Paystack bulk transfer failed: ' . $e->getMessage(), ['batch' => $request->batchReference]);
            return BulkTransferResult::failed($request->batchReference, $e->getMessage());
        }
    }
}
