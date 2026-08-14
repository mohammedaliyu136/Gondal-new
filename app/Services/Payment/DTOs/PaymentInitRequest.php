<?php

namespace App\Services\Payment\DTOs;

final class PaymentInitRequest
{
    public function __construct(
        public readonly float $amount,
        public readonly string $email,
        public readonly string $reference,
        public readonly ?string $callbackUrl = null,
        public readonly string $currency = 'NGN',
        public readonly ?string $customerName = null,
        public readonly ?string $phone = null,
        public readonly ?string $description = null,
        public readonly array $metadata = []
    ) {}

    public static function make(
        float $amount,
        string $email,
        string $reference,
        ?string $callbackUrl = null,
        string $currency = 'NGN',
        ?string $customerName = null,
        ?string $phone = null,
        ?string $description = null,
        array $metadata = []
    ): self {
        return new self(
            amount: $amount,
            email: $email,
            reference: $reference,
            callbackUrl: $callbackUrl,
            currency: $currency,
            customerName: $customerName,
            phone: $phone,
            description: $description,
            metadata: $metadata
        );
    }

    public function toArray(): array
    {
        return [
            'amount' => $this->amount,
            'email' => $this->email,
            'reference' => $this->reference,
            'callback_url' => $this->callbackUrl,
            'currency' => $this->currency,
            'customer_name' => $this->customerName,
            'phone' => $this->phone,
            'description' => $this->description,
            'metadata' => $this->metadata,
        ];
    }
}
