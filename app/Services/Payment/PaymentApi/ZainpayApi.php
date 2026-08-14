<?php

namespace App\Services\Payment\PaymentApi;

use App\Support\Settings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class ZainpayApi
{
    private static ?self $instance = null;
    private string $publicKey;
    private string $baseUrl;

    private function __construct()
    {
        $this->publicKey = Settings::string('payment.zainpay.public_key', config('services.zainpay.public_key', ''));
        
        $mode = Settings::string('payment.zainpay.mode', 'test');
        $defaultUrl = ($mode === 'live') ? 'https://api.zainpay.ng' : 'https://sandbox.zainpay.ng';
        $this->baseUrl = rtrim(Settings::string('payment.zainpay.base_url', config('services.zainpay.base_url', $defaultUrl)), '/');
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
        if (empty($this->publicKey)) {
            throw new Exception('Zainpay Public Key is not configured in Payment Settings.');
        }

        $url = $this->baseUrl . '/' . ltrim($endpoint, '/');

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->publicKey,
            'Accept'        => 'application/json',
            'Content-Type'  => 'application/json',
        ])->timeout(30)->post($url, $body);

        if (!$response->successful()) {
            $this->handleError($response, 'POST', $endpoint);
        }

        return $response->json() ?? [];
    }

    public function get(string $endpoint, array $query = []): array
    {
        if (empty($this->publicKey)) {
            throw new Exception('Zainpay Public Key is not configured in Payment Settings.');
        }

        $url = $this->baseUrl . '/' . ltrim($endpoint, '/');

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->publicKey,
            'Accept'        => 'application/json',
        ])->timeout(30)->get($url, $query);

        if (!$response->successful()) {
            $this->handleError($response, 'GET', $endpoint);
        }

        return $response->json() ?? [];
    }

    private function handleError($response, string $method, string $endpoint): void
    {
        $data = $response->json();
        $message = $data['description'] ?? ($data['message'] ?? $response->body() ?? 'Zainpay API request failed');
        Log::error("Zainpay API Error [{$method} {$endpoint} - Status {$response->status()}]: {$message}");
        throw new Exception('Zainpay API error: ' . $message);
    }
}
