<?php

namespace App\Services\Payment\DTOs;

final class PaymentInitResult
{
    public function __construct(
        public readonly string $reference,
        public readonly string $redirectUrl,
        public readonly ?string $rawResponse = null,
        public readonly bool $success = true,
        public readonly ?string $message = null,
        public readonly array $data = []
    ) {}

    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'reference' => $this->reference,
            'redirect_url' => $this->redirectUrl,
            'message' => $this->message,
            'data' => $this->data,
            'raw_response' => $this->rawResponse,
        ];
    }
}
