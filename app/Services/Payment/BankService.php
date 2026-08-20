<?php

namespace App\Services\Payment;

use App\Services\Payment\PaymentApi\MonnifyApi;
use App\Services\Payment\PaymentApi\PaystackApi;
use App\Services\Payment\PaymentApi\ZainpayApi;
use App\Support\Settings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class BankService
{
    /**
     * Standard CBN-recognized Nigerian Commercial Banks & Fintech institutions.
     */
    private const MASTER_BANKS = [
        ['code' => '044', 'name' => 'Access Bank'],
        ['code' => '023', 'name' => 'Citibank Nigeria'],
        ['code' => '050', 'name' => 'Ecobank Nigeria'],
        ['code' => '070', 'name' => 'Fidelity Bank'],
        ['code' => '011', 'name' => 'First Bank of Nigeria'],
        ['code' => '214', 'name' => 'First City Monument Bank (FCMB)'],
        ['code' => '058', 'name' => 'Guaranty Trust Bank (GTBank)'],
        ['code' => '030', 'name' => 'Heritage Bank'],
        ['code' => '301', 'name' => 'Jaiz Bank'],
        ['code' => '082', 'name' => 'Keystone Bank'],
        ['code' => '50211', 'name' => 'Kuda Microfinance Bank'],
        ['code' => '303', 'name' => 'Lotus Bank'],
        ['code' => '50515', 'name' => 'Moniepoint Microfinance Bank'],
        ['code' => '999992', 'name' => 'OPay (PayCom)'],
        ['code' => '999991', 'name' => 'PalmPay'],
        ['code' => '076', 'name' => 'Polaris Bank'],
        ['code' => '101', 'name' => 'Providus Bank'],
        ['code' => '221', 'name' => 'Stanbic IBTC Bank'],
        ['code' => '068', 'name' => 'Standard Chartered Bank'],
        ['code' => '232', 'name' => 'Sterling Bank'],
        ['code' => '100', 'name' => 'SunTrust Bank'],
        ['code' => '302', 'name' => 'TAJ Bank'],
        ['code' => '102', 'name' => 'Titan Trust Bank'],
        ['code' => '032', 'name' => 'Union Bank of Nigeria'],
        ['code' => '033', 'name' => 'United Bank for Africa (UBA)'],
        ['code' => '215', 'name' => 'Unity Bank'],
        ['code' => '566', 'name' => 'VFD Microfinance Bank'],
        ['code' => '035', 'name' => 'Wema Bank (ALAT)'],
        ['code' => '057', 'name' => 'Zenith Bank'],
    ];

    /**
     * Get list of banks, prioritized by the default payment gateway with fallback.
     *
     * @return array<int, array{code: string, name: string}>
     */
    public function getBanks(): array
    {
        return Cache::remember('nigerian_banks_list', 86400, function (): array {
            $defaultGateway = Settings::string('payment.default_gateway', 'paystack');

            try {
                if ($defaultGateway === 'paystack') {
                    $key = Settings::string('payment.paystack.secret_key', '');
                    if (!empty($key)) {
                        $api = PaystackApi::getInstance();
                        $res = $api->get('bank', ['country' => 'nigeria', 'perPage' => 100]);
                        if (!empty($res['data'])) {
                            $banks = array_map(static fn ($b) => [
                                'code' => (string) $b['code'],
                                'name' => (string) $b['name'],
                            ], $res['data']);

                            usort($banks, fn ($a, $b) => strcmp($a['name'], $b['name']));
                            return $banks;
                        }
                    }
                } elseif ($defaultGateway === 'zainpay') {
                    $key = Settings::string('payment.zainpay.public_key', '');
                    if (!empty($key)) {
                        $api = ZainpayApi::getInstance();
                        $res = $api->get('bank/list');
                        $list = $res['data'] ?? $res;
                        if (is_array($list) && !empty($list)) {
                            $banks = array_map(static fn ($b) => [
                                'code' => (string) ($b['bankCode'] ?? $b['code']),
                                'name' => (string) ($b['bankName'] ?? $b['name']),
                            ], $list);

                            usort($banks, fn ($a, $b) => strcmp($a['name'], $b['name']));
                            return $banks;
                        }
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('Failed fetching live banks from gateway, using master list: ' . $e->getMessage());
            }

            $banks = self::MASTER_BANKS;
            usort($banks, fn ($a, $b) => strcmp($a['name'], $b['name']));
            return $banks;
        });
    }

    /**
     * Verify / resolve account holder name using the default configured payment gateway.
     *
     * @param string $accountNumber 10-digit NUBAN account number
     * @param string $bankCode Bank 3-digit or 6-digit code
     * @return array{success: bool, account_name: string|null, account_number: string, bank_code: string, bank_name: string|null, gateway: string, message: string|null}
     */
    public function verifyAccountNumber(string $accountNumber, string $bankCode): array
    {
        return $this->verifyAccount($accountNumber, $bankCode);
    }

    public function verifyAccount(string $accountNumber, string $bankCode): array
    {
        $accountNumber = preg_replace('/\D/', '', $accountNumber) ?? '';
        $bankCode = trim($bankCode);

        if (strlen($accountNumber) !== 10) {
            return [
                'success' => false,
                'account_name' => null,
                'account_number' => $accountNumber,
                'bank_code' => $bankCode,
                'bank_name' => $this->resolveBankName($bankCode),
                'gateway' => 'none',
                'message' => 'NUBAN account number must be exactly 10 digits.',
            ];
        }

        $defaultGateway = Settings::string('payment.default_gateway', 'paystack');
        $bankName = $this->resolveBankName($bankCode);

        try {
            if ($defaultGateway === 'paystack') {
                return $this->verifyViaPaystack($accountNumber, $bankCode, $bankName);
            } elseif ($defaultGateway === 'monnify') {
                return $this->verifyViaMonnify($accountNumber, $bankCode, $bankName);
            } elseif ($defaultGateway === 'zainpay') {
                return $this->verifyViaZainpay($accountNumber, $bankCode, $bankName);
            }

            return $this->verifyViaPaystack($accountNumber, $bankCode, $bankName);
        } catch (\Throwable $e) {
            Log::error('Bank verification error via ' . $defaultGateway . ': ' . $e->getMessage(), [
                'account_number' => $accountNumber,
                'bank_code' => $bankCode,
            ]);

            return [
                'success' => false,
                'account_name' => null,
                'account_number' => $accountNumber,
                'bank_code' => $bankCode,
                'bank_name' => $bankName,
                'gateway' => $defaultGateway,
                'message' => 'Account verification failed: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Resolve via Paystack resolve endpoint: GET /bank/resolve?account_number=...&bank_code=...
     */
    private function verifyViaPaystack(string $accountNumber, string $bankCode, ?string $bankName): array
    {
        $secretKey = Settings::string('payment.paystack.secret_key', '');

        if (empty($secretKey)) {
            // Development fallback simulation
            return $this->simulateVerification($accountNumber, $bankCode, $bankName, 'paystack (Simulated - Add Secret Key in Settings)');
        }

        $api = PaystackApi::getInstance();
        $response = $api->get('bank/resolve', [
            'account_number' => $accountNumber,
            'bank_code' => $bankCode,
        ]);

        if (isset($response['status']) && $response['status'] === true && !empty($response['data']['account_name'])) {
            return [
                'success' => true,
                'account_name' => strtoupper(trim((string) $response['data']['account_name'])),
                'account_number' => $accountNumber,
                'bank_code' => $bankCode,
                'bank_name' => $bankName,
                'gateway' => 'paystack',
                'message' => 'Account verified successfully.',
            ];
        }

        throw new Exception($response['message'] ?? 'Could not resolve account details with Paystack.');
    }

    /**
     * Resolve via Monnify validate endpoint.
     */
    private function verifyViaMonnify(string $accountNumber, string $bankCode, ?string $bankName): array
    {
        $apiKey = Settings::string('payment.monnify.api_key', '');

        if (empty($apiKey)) {
            return $this->simulateVerification($accountNumber, $bankCode, $bankName, 'monnify (Simulated - Add API Key in Settings)');
        }

        $api = MonnifyApi::getInstance();
        $response = $api->get('api/v1/disbursements/account/validate', [
            'accountNumber' => $accountNumber,
            'bankCode' => $bankCode,
        ]);

        $body = $response['responseBody'] ?? [];
        if (!empty($body['accountName'])) {
            return [
                'success' => true,
                'account_name' => strtoupper(trim((string) $body['accountName'])),
                'account_number' => $accountNumber,
                'bank_code' => $bankCode,
                'bank_name' => $bankName,
                'gateway' => 'monnify',
                'message' => 'Account verified successfully.',
            ];
        }

        throw new Exception($response['responseMessage'] ?? 'Could not resolve account details with Monnify.');
    }

    /**
     * Resolve via Zainpay name enquiry endpoint.
     */
    private function verifyViaZainpay(string $accountNumber, string $bankCode, ?string $bankName): array
    {
        $publicKey = Settings::string('payment.zainpay.public_key', '');

        if (empty($publicKey)) {
            return $this->simulateVerification($accountNumber, $bankCode, $bankName, 'zainpay (Simulated - Add Public Key in Settings)');
        }

        $api = ZainpayApi::getInstance();
        
        // Zainpay supports GET /bank/name-enquiry/{bankCode}/{accountNumber} or with query params
        try {
            $response = $api->get("bank/name-enquiry/{$bankCode}/{$accountNumber}");
        } catch (\Throwable $e) {
            $response = $api->get('bank/name-enquiry', [
                'bankCode' => $bankCode,
                'accountNumber' => $accountNumber,
            ]);
        }

        $accountName = $response['data']['accountName'] ?? ($response['data']['account_name'] ?? ($response['accountName'] ?? null));

        if (!empty($accountName)) {
            return [
                'success' => true,
                'account_name' => strtoupper(trim((string) $accountName)),
                'account_number' => $accountNumber,
                'bank_code' => $bankCode,
                'bank_name' => $bankName,
                'gateway' => 'zainpay',
                'message' => 'Account verified successfully.',
            ];
        }

        throw new Exception($response['description'] ?? ($response['message'] ?? 'Could not resolve account details with Zainpay.'));
    }

    /**
     * Helper to lookup bank name from code.
     */
    public function resolveBankName(string $bankCode): ?string
    {
        foreach ($this->getBanks() as $bank) {
            if ($bank['code'] === $bankCode) {
                return $bank['name'];
            }
        }

        return 'Commercial Bank';
    }

    /**
     * Simulation for offline/test instances when credentials are not configured in settings.
     */
    private function simulateVerification(string $accountNumber, string $bankCode, ?string $bankName, string $gateway): array
    {
        return [
            'success' => true,
            'account_name' => 'VERIFIED ACCOUNT HOLDER (' . ($bankName ?? 'BANK') . ')',
            'account_number' => $accountNumber,
            'bank_code' => $bankCode,
            'bank_name' => $bankName,
            'gateway' => $gateway,
            'message' => 'Account verified (Simulated fallback).',
        ];
    }
}
