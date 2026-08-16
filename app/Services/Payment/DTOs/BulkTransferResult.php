<?php

namespace App\Services\Payment\DTOs;

class BulkTransferResult
{
    /**
     * @param array<string, array{status: string, gateway_reference?: string, fee_minor?: int, message?: string, raw?: array}> $itemResults
     */
    public function __construct(
        public readonly bool $success,
        public readonly string $batchReference,
        public readonly ?string $gatewayBatchReference,
        public readonly string $status, // 'completed', 'processing', 'failed'
        public readonly string $message,
        public readonly int $totalAmountMinor = 0,
        public readonly int $totalFeeMinor = 0,
        public readonly array $itemResults = [],
        public readonly ?array $rawResponse = null,
        public readonly ?string $gatewayStatus = null,
    ) {}

    public static function successful(
        string $batchReference,
        ?string $gatewayBatchReference,
        string $status = 'completed',
        string $message = 'Disbursement batch processed successfully',
        int $totalAmountMinor = 0,
        int $totalFeeMinor = 0,
        array $itemResults = [],
        ?array $rawResponse = null,
        ?string $gatewayStatus = null,
    ): self {
        return new self(
            success: true,
            batchReference: $batchReference,
            gatewayBatchReference: $gatewayBatchReference,
            status: $status,
            message: $message,
            totalAmountMinor: $totalAmountMinor,
            totalFeeMinor: $totalFeeMinor,
            itemResults: $itemResults,
            rawResponse: $rawResponse,
            gatewayStatus: $gatewayStatus,
        );
    }

    public static function failed(string $batchReference, string $message, ?array $rawResponse = null): self
    {
        return new self(
            success: false,
            batchReference: $batchReference,
            gatewayBatchReference: null,
            status: 'failed',
            message: $message,
            totalAmountMinor: 0,
            totalFeeMinor: 0,
            itemResults: [],
            rawResponse: $rawResponse,
        );
    }
}
