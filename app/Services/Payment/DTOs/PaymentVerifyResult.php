<?php

namespace App\Services\Payment\DTOs;

final class PaymentVerifyResult
{
    public function __construct(
        public readonly float $amountPaid,
        public readonly string $paymentDate,
        public readonly string $gateway,
        public readonly ?string $rawResponse = null,
        public readonly ?string $reference = null,
        public readonly bool $isSuccessful = true,
        public readonly string $status = 'success',
        public readonly ?string $customerEmail = null,
        public readonly ?string $channel = null,
        public readonly array $metadata = []
    ) {}

    public function isSuccess(): bool
    {
        return $this->isSuccessful && in_array(strtolower($this->status), ['success', 'successful', 'paid']);
    }

    public function toArray(): array
    {
        return [
            'is_successful' => $this->isSuccess(),
            'status' => $this->status,
            'reference' => $this->reference,
            'amount_paid' => $this->amountPaid,
            'gateway' => $this->gateway,
            'payment_date' => $this->paymentDate,
            'customer_email' => $this->customerEmail,
            'channel' => $this->channel,
            'metadata' => $this->metadata,
            'raw_response' => $this->rawResponse,
        ];
    }
}
