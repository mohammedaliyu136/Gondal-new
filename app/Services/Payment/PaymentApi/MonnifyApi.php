<?php

namespace App\Services\Payment\PaymentApi;

use App\Support\Settings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class MonnifyApi
{
    private static ?self $instance = null;
    private string $apiKey;
    private string $secretKey;
    private string $baseUrl;
    private ?string $token = null;

    private function __construct()
    {
        $this->apiKey = Settings::string('payment.monnify.api_key', config('services.monnify.api_key', ''));
        $this->secretKey = Settings::string('payment.monnify.secret_key', config('services.monnify.secret_key', ''));
        
        $mode = Settings::string('payment.monnify.mode', 'test');
        $defaultUrl = ($mode === 'live') ? 'https://api.monnify.com' : 'https://sandbox.monnify.com';
        $this->baseUrl = rtrim(Settings::string('payment.monnify.base_url', config('services.monnify.base_url', $defaultUrl)), '/');
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

    private function login(): string
    {
        if ($this->token !== null) {
            return $this->token;
        }

        if (empty($this->apiKey) || empty($this->secretKey)) {
            throw new Exception('Monnify API Key or Secret Key is not configured in Payment Settings.');
        }

        $url = $this->baseUrl . '/api/v1/auth/login';

        $response = Http::withoutVerifying()->withHeaders([
            'Authorization' => 'Basic ' . base64_encode($this->apiKey . ':' . $this->secretKey),
            'Content-Type'  => 'application/json',
        ])->timeout(30)->post($url);

        if ($response->successful()) {
            $data = $response->json();
            if (isset($data['requestSuccessful']) && $data['requestSuccessful'] === true) {
                $this->token = $data['responseBody']['accessToken'];
                return $this->token;
            }
        }

        $errorMessage = $response->json('responseMessage') ?? $response->body() ?? 'Authentication failed';
        Log::error("Monnify Login Failed [Status {$response->status()}]: {$errorMessage}");
        throw new Exception('Monnify login failed: ' . $errorMessage);
    }

    public function post(string $endpoint, array $body = []): array
    {
        $token = $this->login();
        $url = $this->baseUrl . '/' . ltrim($endpoint, '/');

        $response = Http::withoutVerifying()->withHeaders([
            'Authorization' => 'Bearer ' . $token,
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
        $token = $this->login();
        $url = $this->baseUrl . '/' . ltrim($endpoint, '/');

        $response = Http::withoutVerifying()->withHeaders([
            'Authorization' => 'Bearer ' . $token,
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
        $message = $data['responseMessage'] ?? ($data['error_description'] ?? $response->body() ?? 'Monnify API request failed');
        Log::error("Monnify API Error [{$method} {$endpoint} - Status {$response->status()}]: {$message}");
        throw new Exception('Monnify API error: ' . $message);
    }
}
