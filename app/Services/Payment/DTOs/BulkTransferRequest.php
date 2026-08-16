<?php

namespace App\Services\Payment\DTOs;

class BulkTransferRequest
{
    /**
     * @param PayoutRecipient[] $recipients
     */
    public function __construct(
        public readonly string $batchReference,
        public readonly array $recipients,
        public readonly string $title,
        public readonly ?string $currency = 'NGN',
        public readonly ?string $otp = null,
        public readonly array $meta = [],
    ) {}

    public function totalAmountMinor(): int
    {
        return array_sum(array_map(fn (PayoutRecipient $r) => $r->amountMinor, $this->recipients));
    }

    public function count(): int
    {
        return count($this->recipients);
    }
}
