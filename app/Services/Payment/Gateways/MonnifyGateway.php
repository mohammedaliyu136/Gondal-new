<?php

namespace App\Services\Payment\Gateways;

use App\Services\Payment\Contracts\PaymentGatewayInterface;
use App\Services\Payment\DTOs\BulkTransferRequest;
use App\Services\Payment\DTOs\BulkTransferResult;
use App\Services\Payment\DTOs\PaymentInitRequest;
use App\Services\Payment\DTOs\PaymentInitResult;
use App\Services\Payment\DTOs\PaymentVerifyResult;
use App\Services\Payment\DTOs\PayoutRecipient;
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

    public function initiateTransfer(PayoutRecipient $recipient, ?string $otp = null): PaymentInitResult
    {
        if (!$this->isEnabled()) {
            throw new Exception('Monnify gateway is currently disabled.');
        }

        try {
            $api = MonnifyApi::getInstance();
            $majorAmount = round($recipient->amountMinor / 100, 2);

            $payload = [
                'amount' => $majorAmount,
                'reference' => $recipient->reference,
                'narration' => $recipient->narration ?? 'Payroll Disbursement',
                'destinationBankCode' => $recipient->bankCode,
                'destinationAccountNumber' => $recipient->accountNumber,
                'currency' => 'NGN',
                'sourceAccountNumber' => Settings::string('payment.monnify.source_account_number', ''),
            ];

            $response = $api->post('api/v2/disbursements/single-transfer', $payload);

            if (isset($response['requestSuccessful']) && $response['requestSuccessful'] === true) {
                $data = $response['responseBody'] ?? [];
                return new PaymentInitResult(
                    reference: $recipient->reference,
                    redirectUrl: null,
                    rawResponse: json_encode($response),
                    success: true,
                    message: $response['responseMessage'] ?? 'Transfer dispatched',
                    data: $data
                );
            }

            throw new Exception($response['responseMessage'] ?? 'Monnify transfer failed');
        } catch (\Throwable $e) {
            Log::error('Monnify transfer error: ' . $e->getMessage(), ['reference' => $recipient->reference]);
            throw new Exception('Monnify transfer failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function initiateBulkTransfer(BulkTransferRequest $request): BulkTransferResult
    {
        if (!$this->isEnabled()) {
            throw new Exception('Monnify gateway is currently disabled.');
        }

        $itemResults = [];
        $totalFeeMinor = 0;

        try {
            $api = MonnifyApi::getInstance();
            $transactionList = [];

            foreach ($request->recipients as $recipient) {
                $transactionList[] = [
                    'amount' => round($recipient->amountMinor / 100, 2),
                    'reference' => $recipient->reference,
                    'narration' => $recipient->narration ?? $request->title,
                    'destinationBankCode' => $recipient->bankCode,
                    'destinationAccountNumber' => $recipient->accountNumber,
                    'destinationAccountName' => $recipient->name,
                    'currency' => 'NGN',
                ];
            }

            $payload = [
                'title' => $request->title,
                'batchReference' => $request->batchReference,
                'narration' => $request->title,
                'sourceAccountNumber' => Settings::string('payment.monnify.source_account_number', ''),
                'transactionList' => $transactionList,
            ];

            // Monnify Bulk Disbursement API v2 endpoint: POST /api/v2/disbursements/batch
            $response = $api->post('api/v2/disbursements/batch', $payload);

            if (isset($response['requestSuccessful']) && $response['requestSuccessful'] === true) {
                $body = $response['responseBody'] ?? [];
                $batchId = $body['transactionBatchReference'] ?? ($body['batchReference'] ?? $request->batchReference);

                // If OTP was provided and batch requires validation
                if ($request->otp !== null && !empty($request->otp)) {
                    try {
                        $api->post('api/v2/disbursements/batch/validate-otp', [
                            'batchReference' => $body['batchReference'] ?? $request->batchReference,
                            'reference' => $body['batchReference'] ?? $request->batchReference,
                            'authorizationCode' => $request->otp,
                        ]);
                    } catch (\Throwable $e) {
                        Log::warning('Monnify batch OTP validation warning: ' . $e->getMessage());
                    }
                }

                $gwStatus = strtoupper($body['batchStatus'] ?? ($body['status'] ?? 'PENDING_AUTHORIZATION'));
                $isCompleted = ($gwStatus === 'SUCCESS' || $gwStatus === 'COMPLETED');
                $batchStatus = $isCompleted ? 'completed' : 'processing';
                if (isset($body['totalFee'])) {
                    $totalFeeMinor = (int) round(((float) $body['totalFee']) * 100);
                }

                foreach ($request->recipients as $recipient) {
                    $ref = $recipient->reference;
                    $matchedTx = null;

                    if (!empty($transactions) && is_array($transactions)) {
                        foreach ($transactions as $tx) {
                            if (is_array($tx) && (($tx['reference'] ?? null) === $ref || ($tx['destinationAccountNumber'] ?? null) === $recipient->accountNumber)) {
                                $matchedTx = $tx;
                                break;
                            }
                        }
                    }

                    $txStatus = strtoupper($matchedTx['status'] ?? ($isCompleted ? 'SUCCESS' : 'PENDING'));
                    $txMsg = $matchedTx['responseMessage'] ?? ($matchedTx['message'] ?? ($response['responseMessage'] ?? 'Dispatched to Monnify'));
                    $isTxSuccess = ($txStatus === 'SUCCESS' || $txStatus === 'PAID');
                    $isTxFailed = ($txStatus === 'FAILED' || $txStatus === 'REVERSED');

                    if ($isTxSuccess) {
                        $itemResults[$ref] = [
                            'status' => 'successful',
                            'gateway_status' => $txStatus,
                            'gateway_reference' => (string) ($matchedTx['transactionReference'] ?? ($matchedTx['reference'] ?? $batchId)),
                            'fee_minor' => 1500,
                            'message' => $txMsg,
                            'raw' => $matchedTx ?? ['status' => $txStatus, 'message' => $txMsg],
                        ];
                        $hasSuccess = true;
                    } elseif ($isTxFailed) {
                        $allSuccess = false;
                        $itemResults[$ref] = [
                            'status' => 'failed',
                            'gateway_status' => $txStatus,
                            'gateway_reference' => (string) ($matchedTx['transactionReference'] ?? ($matchedTx['reference'] ?? $batchId)),
                            'fee_minor' => 0,
                            'message' => $txMsg,
                            'raw' => $matchedTx ?? ['status' => $txStatus, 'message' => $txMsg],
                        ];
                    } else {
                        $itemResults[$ref] = [
                            'status' => 'processing',
                            'gateway_status' => $txStatus,
                            'gateway_reference' => (string) ($matchedTx['transactionReference'] ?? ($matchedTx['reference'] ?? $batchId)),
                            'fee_minor' => 1500,
                            'message' => $txMsg,
                            'raw' => $matchedTx ?? ['status' => $txStatus, 'message' => $txMsg],
                        ];
                    }
                }

                return BulkTransferResult::successful(
                    batchReference: $request->batchReference,
                    gatewayBatchReference: (string) $batchId,
                    status: $batchStatus,
                    message: $response['responseMessage'] ?? 'Batch transfer initiated with Monnify',
                    totalAmountMinor: $request->totalAmountMinor(),
                    totalFeeMinor: $totalFeeMinor,
                    itemResults: $itemResults,
                    rawResponse: $response,
                    gatewayStatus: $gwStatus,
                );
            }

            return BulkTransferResult::failed($request->batchReference, $response['responseMessage'] ?? 'Monnify batch transfer failed', $response);
        } catch (\Throwable $e) {
            Log::error('Monnify bulk transfer error: ' . $e->getMessage(), ['batch' => $request->batchReference]);
            return BulkTransferResult::failed($request->batchReference, $e->getMessage());
        }
    }

    /**
     * Authorize and validate an OTP/2FA code for a pending Monnify bulk transfer batch.
     */
    public function validateBatchOtp(string $batchReference, string $otp, ?string $gatewayBatchReference = null): BulkTransferResult
    {
        $api = MonnifyApi::getInstance();
        $response = $api->post('api/v2/disbursements/batch/validate-otp', [
            'batchReference' => $batchReference,
            'reference' => $batchReference,
            'authorizationCode' => $otp,
        ]);

        if (!isset($response['requestSuccessful']) || $response['requestSuccessful'] !== true) {
            $msg = $response['responseMessage'] ?? 'Invalid Monnify authorization code';
            throw new Exception($msg);
        }

        return BulkTransferResult::successful(
            batchReference: $batchReference,
            gatewayBatchReference: $gatewayBatchReference ?: $batchReference,
            status: 'completed',
            message: $response['responseMessage'] ?? 'Batch authorization successful',
            rawResponse: $response,
            gatewayStatus: 'SUCCESS',
        );
    }

    /**
     * Resend authorization OTP code for a pending Monnify bulk transfer batch.
     */
    public function resendBatchOtp(string $batchReference, ?string $gatewayBatchReference = null): array
    {
        $api = MonnifyApi::getInstance();
        $response = $api->post('api/v2/disbursements/batch/resend-otp', [
            'batchReference' => $batchReference,
            'reference' => $batchReference,
        ]);

        if (!isset($response['requestSuccessful']) || $response['requestSuccessful'] !== true) {
            $msg = $response['responseMessage'] ?? 'Failed to resend Monnify OTP';
            throw new Exception($msg);
        }

        return $response;
    }

    /**
     * Verify and synchronize live settlement status for a Monnify bulk transfer batch and its line items.
     *
     * @param array<int, array{reference: string, account_number?: string, amount_minor?: int, gateway_reference?: string, status?: string}> $items
     */
    public function verifyBatch(string $batchReference, ?string $gatewayBatchReference = null, array $items = []): BulkTransferResult
    {
        $api = MonnifyApi::getInstance();
        $response = $api->get('api/v2/disbursements/batch/summary', [
            'reference' => $batchReference,
        ]);

        if (!isset($response['requestSuccessful']) || $response['requestSuccessful'] !== true) {
            return BulkTransferResult::failed($batchReference, $response['responseMessage'] ?? 'Failed to fetch Monnify batch summary', $response);
        }

        $body = $response['responseBody'] ?? [];
        $status = strtoupper($body['batchStatus'] ?? ($body['status'] ?? 'PENDING'));
        $txList = $body['transactionList'] ?? ($body['transactions'] ?? []);
        $isBatchExpiredOrFailed = in_array($status, ['EXPIRED', 'FAILED', 'CANCELLED', 'REJECTED']);

        $itemResults = [];
        $successCount = 0;
        $failedCount = 0;
        $totalFeeMinor = isset($body['totalFee']) ? (int) round(((float) $body['totalFee']) * 100) : 0;
        $totalAmountMinor = isset($body['totalAmount']) ? (int) round(((float) $body['totalAmount']) * 100) : 0;

        foreach ($items as $item) {
            $ref = $item['reference'] ?? '';
            $accountNumber = $item['account_number'] ?? '';
            $matchedTx = null;

            if (is_array($txList)) {
                foreach ($txList as $tx) {
                    if (is_array($tx) && (($tx['reference'] ?? null) === $ref || ($tx['destinationAccountNumber'] ?? null) === $accountNumber)) {
                        $matchedTx = $tx;
                        break;
                    }
                }
            }

            $defaultItemStatus = ($status === 'SUCCESS' || $status === 'COMPLETED') ? 'SUCCESS' : ($isBatchExpiredOrFailed ? $status : 'PENDING');
            $txStatus = strtoupper($matchedTx['status'] ?? $defaultItemStatus);
            $txMsg = $matchedTx['responseMessage'] ?? ($matchedTx['message'] ?? null);
            $isItemSuccess = ($txStatus === 'SUCCESS' || $txStatus === 'PAID');
            $isItemFailed = ($txStatus === 'FAILED' || $txStatus === 'REVERSED' || $isBatchExpiredOrFailed);

            if ($isItemSuccess) {
                $successCount++;
                $itemResults[$ref] = [
                    'status' => 'successful',
                    'gateway_reference' => $matchedTx['transactionReference'] ?? ($item['gateway_reference'] ?? $gatewayBatchReference),
                    'gateway_status' => $txStatus,
                    'fee_minor' => 1500,
                    'message' => $txMsg,
                    'raw' => $matchedTx ?? ['status' => $txStatus, 'message' => $txMsg],
                ];
            } elseif ($isItemFailed) {
                $failedCount++;
                $itemResults[$ref] = [
                    'status' => 'failed',
                    'gateway_reference' => $matchedTx['transactionReference'] ?? ($item['gateway_reference'] ?? $gatewayBatchReference),
                    'gateway_status' => $txStatus ?: $status,
                    'fee_minor' => 0,
                    'message' => $txMsg ?: ($isBatchExpiredOrFailed ? "Batch {$status} at Monnify (Authorization window closed)" : 'Transaction failed at Monnify'),
                    'raw' => $matchedTx ?? ['status' => $txStatus, 'message' => $txMsg],
                ];
            } else {
                $prevItemStatus = $item['status'] ?? 'processing';
                if ($prevItemStatus === 'successful') {
                    $successCount++;
                } elseif ($prevItemStatus === 'failed') {
                    $failedCount++;
                }

                $itemResults[$ref] = [
                    'status' => $prevItemStatus,
                    'gateway_reference' => $matchedTx['transactionReference'] ?? ($item['gateway_reference'] ?? $gatewayBatchReference),
                    'gateway_status' => $txStatus,
                    'fee_minor' => 1500,
                    'message' => $txMsg,
                    'raw' => $matchedTx ?? ['status' => $txStatus, 'message' => $txMsg],
                ];
            }
        }

        $totalItems = count($items);
        $batchStatus = ($failedCount === 0 && $successCount === $totalItems && $totalItems > 0 && ($status === 'SUCCESS' || $status === 'COMPLETED'))
            ? 'completed'
            : (($successCount > 0 && $failedCount > 0)
                ? 'partially_completed'
                : (($failedCount === $totalItems && $totalItems > 0) ? 'failed' : 'processing'));

        return BulkTransferResult::successful(
            batchReference: $batchReference,
            gatewayBatchReference: $gatewayBatchReference ?: $batchReference,
            status: $batchStatus,
            message: "Monnify batch status: {$status} ({$successCount} successful, {$failedCount} failed)",
            totalAmountMinor: $totalAmountMinor,
            totalFeeMinor: $totalFeeMinor,
            itemResults: $itemResults,
            rawResponse: $response,
            gatewayStatus: $status,
        );
    }

    /**
     * Validate/resolve a bank account using Monnify v2 Account Validation endpoint.
     *
     * @return array<string, mixed>
     */
    public function validateAccount(string $accountNumber, string $bankCode): array
    {
        if (!$this->isEnabled()) {
            throw new Exception('Monnify gateway is currently disabled.');
        }

        try {
            $api = MonnifyApi::getInstance();
            $response = $api->get('api/v2/disbursements/account/validate', [
                'accountNumber' => $accountNumber,
                'bankCode' => $bankCode,
            ]);

            return $response['responseBody'] ?? [];
        } catch (\Throwable $e) {
            Log::error('Monnify account validation error: ' . $e->getMessage(), [
                'account_number' => $accountNumber,
                'bank_code' => $bankCode,
            ]);
            throw new Exception('Monnify account validation failed: ' . $e->getMessage(), 0, $e);
        }
    }
}
