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

            $bulkResponse = null;
            $isOtpRequiredError = false;

            try {
                $bulkResponse = $api->post('transfer/bulk', $bulkPayload);
            } catch (\Throwable $bulkEx) {
                $bulkErrMsg = strtolower($bulkEx->getMessage());
                if (
                    str_contains($bulkErrMsg, 'disable the otp requirement') ||
                    str_contains($bulkErrMsg, 'otp requirement') ||
                    str_contains($bulkErrMsg, 'otp is enabled')
                ) {
                    $isOtpRequiredError = true;
                } else {
                    throw $bulkEx;
                }
            }

            // If OTP is required on Paystack, bulk transfer endpoint is blocked by Paystack.
            // We initiate via standard single transfer endpoint so Paystack generates the OTP.
            if ($isOtpRequiredError || (isset($bulkResponse['message']) && str_contains(strtolower($bulkResponse['message']), 'disable the otp requirement'))) {
                Log::info('Paystack OTP is enabled — falling back to single transfers for OTP authorization.', ['batch' => $request->batchReference]);

                $allSuccess = true;
                $hasPendingOtp = false;
                $batchCode = null;

                foreach ($transfers as $t) {
                    $ref = $t['reference'];
                    try {
                        $trfPayload = [
                            'source' => 'balance',
                            'amount' => $t['amount'],
                            'recipient' => $t['recipient'],
                            'reason' => $t['reason'] ?? $request->title,
                            'reference' => $ref,
                        ];
                        if ($request->otp !== null) {
                            $trfPayload['otp'] = $request->otp;
                        }

                        $trfRes = $api->post('transfer', $trfPayload);

                        if (isset($trfRes['status']) && $trfRes['status'] === true) {
                            $data = $trfRes['data'] ?? [];
                            $trfCode = $data['transfer_code'] ?? ($data['id'] ?? $ref);
                            $rawStatus = strtolower($data['status'] ?? 'processing');

                            if (!$batchCode) {
                                $batchCode = (string) $trfCode;
                            }

                            if ($rawStatus === 'success' || $rawStatus === 'successful') {
                                $itemResults[$ref] = [
                                    'status' => 'successful',
                                    'gateway_status' => 'SUCCESS',
                                    'gateway_reference' => (string) $trfCode,
                                    'fee_minor' => 1000,
                                    'message' => $trfRes['message'] ?? 'Transfer successful',
                                    'raw' => $trfRes,
                                ];
                                $totalFeeMinor += 1000;
                                $successCount++;
                            } elseif ($rawStatus === 'otp' || $rawStatus === 'pending' || $rawStatus === 'processing') {
                                $allSuccess = false;
                                $hasPendingOtp = true;
                                $itemResults[$ref] = [
                                    'status' => 'processing',
                                    'gateway_status' => 'PENDING_AUTHORIZATION',
                                    'gateway_reference' => (string) $trfCode,
                                    'fee_minor' => 1000,
                                    'message' => $trfRes['message'] ?? 'Transfer requires OTP authorization',
                                    'raw' => $trfRes,
                                ];
                                $totalFeeMinor += 1000;
                            } else {
                                $allSuccess = false;
                                $itemResults[$ref] = [
                                    'status' => 'failed',
                                    'gateway_status' => strtoupper($rawStatus),
                                    'gateway_reference' => (string) $trfCode,
                                    'fee_minor' => 0,
                                    'message' => $trfRes['message'] ?? 'Transfer failed at Paystack',
                                    'raw' => $trfRes,
                                ];
                            }
                        } else {
                            $allSuccess = false;
                            $itemResults[$ref] = [
                                'status' => 'failed',
                                'gateway_status' => 'FAILED',
                                'gateway_reference' => $ref,
                                'fee_minor' => 0,
                                'message' => $trfRes['message'] ?? 'Transfer failed at Paystack',
                                'raw' => $trfRes,
                            ];
                        }
                    } catch (\Throwable $e) {
                        $allSuccess = false;
                        $itemResults[$ref] = [
                            'status' => 'failed',
                            'gateway_status' => 'FAILED',
                            'gateway_reference' => $ref,
                            'fee_minor' => 0,
                            'message' => $e->getMessage(),
                        ];
                    }
                }

                $totalItemsCount = count($transfers);
                $finalStatus = ($allSuccess && $successCount === $totalItemsCount && $totalItemsCount > 0)
                    ? 'completed'
                    : ($hasPendingOtp ? 'processing' : (($successCount > 0) ? 'partially_completed' : 'failed'));
                $gwStatus = ($allSuccess && $successCount === $totalItemsCount && $totalItemsCount > 0)
                    ? 'SUCCESS'
                    : ($hasPendingOtp ? 'PENDING_AUTHORIZATION' : (($successCount > 0) ? 'PROCESSING' : 'FAILED'));

                return BulkTransferResult::successful(
                    batchReference: $request->batchReference,
                    gatewayBatchReference: (string) ($batchCode ?: $request->batchReference),
                    status: $finalStatus,
                    message: $hasPendingOtp ? 'Transfers initiated — Paystack OTP authorization required.' : "Disbursed {$successCount} of {$totalItemsCount} transfers via Paystack.",
                    totalAmountMinor: $request->totalAmountMinor(),
                    totalFeeMinor: $totalFeeMinor,
                    itemResults: $itemResults,
                    rawResponse: ['transfers_count' => count($transfers), 'has_otp' => $hasPendingOtp],
                    gatewayStatus: $gwStatus,
                );
            }

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
                    $rawItemStatus = strtolower($matched['status'] ?? 'processing');
                    $isExplicitSuccess = ($rawItemStatus === 'success' || $rawItemStatus === 'successful');
                    $isExplicitFailed = ($rawItemStatus === 'failed' || $rawItemStatus === 'reversed' || $rawItemStatus === 'abandoned');

                    if (!$batchCode && !empty($itemTransferCode)) {
                        $batchCode = (string) $itemTransferCode;
                    }

                    $itemMsg = $matched['message'] ?? ($bulkResponse['message'] ?? ('Paystack: ' . ucfirst($rawItemStatus)));

                    if ($isExplicitSuccess) {
                        $itemResults[$ref] = [
                            'status' => 'successful',
                            'gateway_status' => strtoupper($rawItemStatus),
                            'gateway_reference' => (string) ($itemTransferCode ?: $request->batchReference),
                            'fee_minor' => 1000,
                            'message' => $itemMsg,
                            'raw' => $matched ?? ['status' => $rawItemStatus, 'transfer_code' => $itemTransferCode],
                        ];
                        $totalFeeMinor += 1000;
                        $successCount++;
                    } elseif ($isExplicitFailed) {
                        $allSuccess = false;
                        $itemResults[$ref] = [
                            'status' => 'failed',
                            'gateway_status' => strtoupper($rawItemStatus),
                            'gateway_reference' => (string) ($itemTransferCode ?: $request->batchReference),
                            'fee_minor' => 0,
                            'message' => $itemMsg,
                            'raw' => $matched ?? ['status' => $rawItemStatus, 'message' => $itemMsg],
                        ];
                    } else {
                        $allSuccess = false;
                        $itemResults[$ref] = [
                            'status' => 'processing',
                            'gateway_status' => strtoupper($rawItemStatus),
                            'gateway_reference' => (string) ($itemTransferCode ?: $request->batchReference),
                            'fee_minor' => 1000,
                            'message' => $itemMsg,
                            'raw' => $matched ?? ['status' => $rawItemStatus, 'transfer_code' => $itemTransferCode],
                        ];
                        $totalFeeMinor += 1000;
                    }
                }

                $totalItemsCount = count($transfers);
                $finalStatus = ($allSuccess && $successCount === $totalItemsCount && $totalItemsCount > 0) ? 'completed' : 'processing';
                $gwStatus = ($allSuccess && $successCount === $totalItemsCount && $totalItemsCount > 0) ? 'SUCCESS' : 'PROCESSING';

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
            Log::error('Paystack bulk transfer error: ' . $e->getMessage(), ['batch' => $request->batchReference]);
            return BulkTransferResult::failed($request->batchReference, $e->getMessage());
        }
    }

    /**
     * Authorize and validate an OTP/2FA code for a pending Paystack bulk transfer batch.
     */
    public function validateBatchOtp(string $batchReference, string $otp, ?string $gatewayBatchReference = null): BulkTransferResult
    {
        $api = PaystackApi::getInstance();
        $transferCode = $gatewayBatchReference ?: $batchReference;

        try {
            $response = $api->post('transfer/finalize_transfer', [
                'transfer_code' => $transferCode,
                'otp' => $otp,
            ]);

            if (!isset($response['status']) || $response['status'] !== true) {
                $msg = $response['message'] ?? 'Invalid Paystack OTP';
                throw new Exception($msg);
            }
        } catch (\Throwable $e) {
            $lower = strtolower($e->getMessage());
            if (
                str_contains($lower, 'already processed') ||
                str_contains($lower, 'already finalized') ||
                str_contains($lower, 'otp has been disabled') ||
                str_contains($lower, 'otp disabled')
            ) {
                $response = ['status' => true, 'message' => 'Transfer already finalized with Paystack'];
            } else {
                throw $e;
            }
        }

        return BulkTransferResult::successful(
            batchReference: $batchReference,
            gatewayBatchReference: $transferCode,
            status: 'completed',
            message: $response['message'] ?? 'Batch transfer finalized with Paystack',
            rawResponse: $response,
            gatewayStatus: 'SUCCESS',
        );
    }

    /**
     * Resend authorization OTP code for a pending Paystack bulk transfer batch.
     */
    public function resendBatchOtp(string $batchReference, ?string $gatewayBatchReference = null): array
    {
        $api = PaystackApi::getInstance();
        $response = $api->post('transfer/resend_otp', [
            'transfer_code' => $gatewayBatchReference ?: $batchReference,
            'reason' => 'resend_otp',
        ]);

        if (!isset($response['status']) || $response['status'] !== true) {
            $msg = $response['message'] ?? 'Failed to resend Paystack OTP';
            throw new Exception($msg);
        }

        return $response;
    }

    /**
     * Verify and synchronize live settlement status for a Paystack bulk transfer batch and its line items.
     *
     * @param array<int, array{reference: string, account_number?: string, amount_minor?: int, gateway_reference?: string, status?: string}> $items
     */
    public function verifyBatch(string $batchReference, ?string $gatewayBatchReference = null, array $items = []): BulkTransferResult
    {
        $api = PaystackApi::getInstance();

        // 1. Fetch recent Paystack transfers to match items in bulk
        $recentTransfers = [];
        try {
            $response = $api->get('transfer', ['perPage' => 50]);
            $recentTransfers = $response['data'] ?? [];
        } catch (\Throwable $e) {
            Log::info('Paystack transfers query note: ' . $e->getMessage());
        }

        $itemResults = [];
        $successCount = 0;
        $failedCount = 0;
        $latestGwStatus = 'PROCESSING';
        $totalFeeMinor = 0;

        foreach ($items as $item) {
            $ref = $item['reference'] ?? '';
            $gwRef = $item['gateway_reference'] ?? '';
            $accountNumber = $item['account_number'] ?? '';
            $amountMinor = (int) ($item['amount_minor'] ?? 0);
            $matchedTrf = null;

            // 1a. Check recent transfers list
            foreach ($recentTransfers as $trf) {
                $trfRef = $trf['reference'] ?? '';
                $trfCode = $trf['transfer_code'] ?? '';
                $trfAmount = (int) ($trf['amount'] ?? 0);
                $trfAccount = $trf['recipient']['details']['account_number'] ?? ($trf['recipient']['account_number'] ?? '');

                if (
                    ($ref && $trfRef === $ref)
                    || ($gwRef && $trfCode === $gwRef)
                    || ($accountNumber && $trfAccount === $accountNumber && $trfAmount === $amountMinor)
                ) {
                    $matchedTrf = $trf;
                    break;
                }
            }

            // 1b. If not in recent list and item has reference, verify individually
            if (!$matchedTrf && $ref) {
                try {
                    $res = $api->get('transfer/verify/' . $ref);
                    if (isset($res['data'])) {
                        $matchedTrf = $res['data'];
                    }
                } catch (\Throwable $e) {
                    if ($gwRef && str_starts_with($gwRef, 'TRF_')) {
                        try {
                            $res = $api->get('transfer/' . $gwRef);
                            if (isset($res['data'])) {
                                $matchedTrf = $res['data'];
                            }
                        } catch (\Throwable) {}
                    }
                }
            }

            if ($matchedTrf) {
                $rawStatus = strtolower($matchedTrf['status'] ?? 'processing');
                $itemGwStatus = strtoupper($rawStatus);
                $itemTrfCode = $matchedTrf['transfer_code'] ?? ($matchedTrf['id'] ?? ($gwRef ?: $batchReference));
                $itemMsg = $matchedTrf['message'] ?? ($matchedTrf['reason'] ?? 'Paystack: ' . ucfirst($rawStatus));

                if ($rawStatus === 'success' || $rawStatus === 'successful') {
                    $successCount++;
                    $totalFeeMinor += 1000;
                    $itemResults[$ref] = [
                        'status' => 'successful',
                        'gateway_reference' => (string) $itemTrfCode,
                        'gateway_status' => $itemGwStatus,
                        'gateway_response' => $matchedTrf,
                        'fee_minor' => 1000,
                        'message' => $itemMsg,
                    ];
                } elseif ($rawStatus === 'failed' || $rawStatus === 'reversed' || $rawStatus === 'abandoned') {
                    $failedCount++;
                    $itemResults[$ref] = [
                        'status' => 'failed',
                        'gateway_reference' => (string) $itemTrfCode,
                        'gateway_status' => $itemGwStatus,
                        'gateway_response' => $matchedTrf,
                        'fee_minor' => 0,
                        'message' => $itemMsg,
                    ];
                } else {
                    $itemResults[$ref] = [
                        'status' => 'processing',
                        'gateway_reference' => (string) $itemTrfCode,
                        'gateway_status' => $itemGwStatus,
                        'gateway_response' => $matchedTrf,
                        'fee_minor' => 1000,
                        'message' => $itemMsg,
                    ];
                    $totalFeeMinor += 1000;
                }

                $latestGwStatus = $itemGwStatus;
            } else {
                $prevItemStatus = $item['status'] ?? 'processing';
                if ($prevItemStatus === 'successful') {
                    $successCount++;
                    $totalFeeMinor += 1000;
                } elseif ($prevItemStatus === 'failed') {
                    $failedCount++;
                }

                $itemResults[$ref] = [
                    'status' => $prevItemStatus,
                    'gateway_reference' => $gwRef ?: $batchReference,
                    'gateway_status' => strtoupper($prevItemStatus),
                    'fee_minor' => ($prevItemStatus === 'successful' ? 1000 : 0),
                    'message' => null,
                ];
            }
        }

        $totalItems = count($items);
        $batchStatus = ($failedCount === 0 && $successCount === $totalItems && $totalItems > 0)
            ? 'completed'
            : (($successCount > 0 && $failedCount > 0)
                ? 'partially_completed'
                : (($failedCount === $totalItems && $totalItems > 0) ? 'failed' : 'processing'));

        return BulkTransferResult::successful(
            batchReference: $batchReference,
            gatewayBatchReference: $gatewayBatchReference ?: $batchReference,
            status: $batchStatus,
            message: "Paystack batch sync: {$successCount} successful, {$failedCount} failed",
            totalAmountMinor: 0,
            totalFeeMinor: $totalFeeMinor,
            itemResults: $itemResults,
            gatewayStatus: $latestGwStatus,
        );
    }
}
