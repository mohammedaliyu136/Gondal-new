<?php

namespace App\Services\Payment\PaymentApi;

use App\Support\Settings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class PaystackApi
{
    private static ?self $instance = null;
    private string $secretKey;
    private string $baseUrl;

    private function __construct()
    {
        $this->secretKey = Settings::string('payment.paystack.secret_key', config('services.paystack.secret_key', ''));
        $this->baseUrl = rtrim(Settings::string('payment.paystack.base_url', config('services.paystack.base_url', 'https://api.paystack.co')), '/');
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function flush(): void
    {
        self::$instance = null;
    }

    public function post(string $endpoint, array $body = []): array
    {
        if (empty($this->secretKey)) {
            throw new Exception('Paystack Secret Key is not configured in Payment Settings.');
        }

        $url = $this->baseUrl . '/' . ltrim($endpoint, '/');

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->secretKey,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ])->timeout(30)->post($url, $body);

        if (!$response->successful()) {
            $this->handleError($response, 'POST', $endpoint);
        }

        return $response->json() ?? [];
    }

    public function get(string $endpoint, array $query = []): array
    {
        if (empty($this->secretKey)) {
            throw new Exception('Paystack Secret Key is not configured in Payment Settings.');
        }

        $url = $this->baseUrl . '/' . ltrim($endpoint, '/');

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->secretKey,
            'Accept' => 'application/json',
        ])->timeout(30)->get($url, $query);

        if (!$response->successful()) {
            $this->handleError($response, 'GET', $endpoint);
        }

        return $response->json() ?? [];
    }

    private function handleError($response, string $method, string $endpoint): void
    {
        $data = $response->json();
        $message = $data['message'] ?? $response->body() ?? 'Paystack API request failed';
        Log::error("Paystack API Error [{$method} {$endpoint} - Status {$response->status()}]: {$message}");
        throw new Exception('Paystack API error: ' . $message);
    }
}
