<?php

namespace App\Services\Payment\DTOs;

class PayoutRecipient
{
    public function __construct(
        public readonly string $reference,
        public readonly string $name,
        public readonly string $accountNumber,
        public readonly string $bankCode,
        public readonly int $amountMinor,
        public readonly ?string $bankName = null,
        public readonly ?string $email = null,
        public readonly ?string $phone = null,
        public readonly ?string $narration = null,
        public readonly array $meta = [],
    ) {}

    public function toArray(): array
    {
        return [
            'reference' => $this->reference,
            'name' => $this->name,
            'account_number' => $this->accountNumber,
            'bank_code' => $this->bankCode,
            'bank_name' => $this->bankName,
            'amount_minor' => $this->amountMinor,
            'email' => $this->email,
            'phone' => $this->phone,
            'narration' => $this->narration,
            'meta' => $this->meta,
        ];
    }
}
