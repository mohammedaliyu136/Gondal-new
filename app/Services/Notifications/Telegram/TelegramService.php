<?php

namespace App\Services\Notifications\Telegram;

use App\Models\User;
use App\Support\Settings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Service for managing Telegram Bot integration, user onboarding, and messaging.
 */
class TelegramService
{
    private const TELEGRAM_API_BASE = 'https://api.telegram.org/bot';

    public function getBotToken(): ?string
    {
        $token = Settings::string('telegram.bot_token', '');

        if ($token !== '') {
            return trim($token);
        }

        return config('services.telegram.bot_token') ?: env('TELEGRAM_BOT_TOKEN');
    }

    public function getBotUsername(): ?string
    {
        $username = Settings::string('telegram.bot_username', '');

        if ($username !== '') {
            $cleaned = ltrim(trim($username), '@');
            // Telegram bot usernames must end with 'bot' (case-insensitive)
            if (str_ends_with(strtolower($cleaned), 'bot')) {
                return $cleaned;
            }
        }

        // If username doesn't end with bot or is empty, fetch official bot username directly from Telegram API
        $token = $this->getBotToken();
        if (! empty($token)) {
            $me = $this->getMe();
            if (! empty($me['bot']['username'])) {
                $officialUsername = $me['bot']['username'];
                Settings::put(['telegram.bot_username' => $officialUsername], null, 'general');

                return $officialUsername;
            }
        }

        if ($username !== '') {
            return ltrim(trim($username), '@');
        }

        return config('services.telegram.bot_username') ?: env('TELEGRAM_BOT_USERNAME');
    }

    public function isEnabled(): bool
    {
        $token = $this->getBotToken();

        return ! empty($token) && Settings::boolean('telegram.is_enabled', true);
    }

    /**
     * Build an HTTP client for Telegram requests.
     * When telegram.verify_ssl is disabled in Settings (or cacert.pem is not configured), SSL verification is skipped.
     */
    protected function http(int $timeout = 10): \Illuminate\Http\Client\PendingRequest
    {
        $verify = Settings::boolean('telegram.verify_ssl', false);

        $client = Http::timeout($timeout);

        if (! $verify) {
            $client = $client->withoutVerifying();
        }

        return $client;
    }

    /**
     * Send an HTML-formatted message to a specific Telegram chat ID with an optional inline URL button.
     *
     * @return array{success: bool, message: string, data?: array}
     */
    public function sendMessage(
        string|int $chatId,
        string $htmlText,
        ?string $actionUrl = null,
        ?string $actionText = 'Open in Gondal ERP',
    ): array {
        $token = $this->getBotToken();

        if (empty($token)) {
            return [
                'success' => false,
                'message' => 'Telegram Bot token is not configured in Settings.',
            ];
        }

        $payload = [
            'chat_id' => (string) $chatId,
            'text' => $htmlText,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => false,
        ];

        if (! empty($actionUrl)) {
            // Ensure absolute URL
            $url = str_starts_with($actionUrl, 'http') ? $actionUrl : url($actionUrl);
            $parsedHost = parse_url($url, PHP_URL_HOST);

            // Telegram strictly rejects localhost, 127.0.0.1, and private IPs in inline keyboard buttons
            $isLocalhost = in_array(strtolower((string) $parsedHost), ['localhost', '127.0.0.1', '::1'], true)
                || str_ends_with(strtolower((string) $parsedHost), '.test')
                || str_ends_with(strtolower((string) $parsedHost), '.local');

            if ($isLocalhost) {
                // On localhost/dev, append link in text instead of invalid inline button
                $payload['text'] .= "\n\n🔗 <b>" . htmlspecialchars($actionText ?: 'Link') . ":</b>\n<code>" . htmlspecialchars($url) . "</code>";
            } else {
                $payload['reply_markup'] = [
                    'inline_keyboard' => [
                        [
                            [
                                'text' => '🔗 ' . ($actionText ?: 'Open Record'),
                                'url' => $url,
                            ],
                        ],
                    ],
                ];
            }
        }

        try {
            $response = $this->http(10)->post(self::TELEGRAM_API_BASE . $token . '/sendMessage', $payload);

            // If Telegram rejected inline button URL, retry immediately by placing link inside text
            if (! $response->successful() && isset($payload['reply_markup']) && str_contains($response->json('description', ''), 'inline keyboard button URL')) {
                unset($payload['reply_markup']);
                if (! empty($url)) {
                    $payload['text'] .= "\n\n🔗 <b>" . htmlspecialchars($actionText ?: 'Link') . ":</b>\n" . htmlspecialchars($url);
                }
                $response = $this->http(10)->post(self::TELEGRAM_API_BASE . $token . '/sendMessage', $payload);
            }

            if ($response->successful() && ($response->json('ok') === true)) {
                return [
                    'success' => true,
                    'message' => 'Telegram notification delivered successfully.',
                    'data' => $response->json('result', []),
                ];
            }

            $errorDesc = $response->json('description', 'Telegram API returned HTTP ' . $response->status());
            Log::warning("Telegram sendMessage failed: {$errorDesc}", ['chat_id' => $chatId]);

            return [
                'success' => false,
                'message' => "Telegram error: {$errorDesc}",
            ];
        } catch (\Throwable $e) {
            Log::error("Telegram sendMessage exception: " . $e->getMessage(), ['chat_id' => $chatId]);

            return [
                'success' => false,
                'message' => "Telegram connection error: " . $e->getMessage(),
            ];
        }
    }

    /**
     * Test connection and retrieve Telegram bot information.
     *
     * @return array{success: bool, message: string, bot?: array}
     */
    public function getMe(): array
    {
        $token = $this->getBotToken();

        if (empty($token)) {
            return [
                'success' => false,
                'message' => 'No Telegram Bot Token provided.',
            ];
        }

        try {
            $response = $this->http(8)->get(self::TELEGRAM_API_BASE . $token . '/getMe');

            if ($response->successful() && $response->json('ok')) {
                $bot = $response->json('result');

                return [
                    'success' => true,
                    'message' => "Connected successfully to Telegram as @{$bot['username']} ({$bot['first_name']}).",
                    'bot' => $bot,
                ];
            }

            return [
                'success' => false,
                'message' => 'Invalid Bot Token: ' . $response->json('description', 'Authentication failed'),
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => 'Connection failed: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Register the webhook URL with Telegram.
     */
    public function setWebhook(string $webhookUrl): array
    {
        $token = $this->getBotToken();

        if (empty($token)) {
            return ['success' => false, 'message' => 'Bot token is missing.'];
        }

        try {
            $response = $this->http(10)->post(self::TELEGRAM_API_BASE . $token . '/setWebhook', [
                'url' => $webhookUrl,
            ]);

            if ($response->successful() && $response->json('ok')) {
                return [
                    'success' => true,
                    'message' => 'Webhook registered successfully: ' . $response->json('description'),
                ];
            }

            return [
                'success' => false,
                'message' => 'Failed to set webhook: ' . $response->json('description'),
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Poll updates from Telegram (used by `php artisan telegram:poll` for local development).
     */
    public function getUpdates(int $offset = 0, int $limit = 20, int $timeout = 0): array
    {
        $token = $this->getBotToken();

        if (empty($token)) {
            return [];
        }

        try {
            $response = $this->http($timeout + 5)->get(self::TELEGRAM_API_BASE . $token . '/getUpdates', [
                'offset' => $offset,
                'limit' => $limit,
                'timeout' => $timeout,
            ]);

            return $response->json('result', []) ?: [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Generate the one-click deep linking onboarding URL for a user to link their Telegram account.
     */
    public function generateOnboardingUrl(User $user): string
    {
        $token = $user->generateTelegramOnboardingToken();
        $botUsername = $this->getBotUsername();

        if (empty($botUsername)) {
            $botInfo = $this->getMe();
            $botUsername = $botInfo['bot']['username'] ?? 'GondalErpBot';
        }

        return "https://t.me/{$botUsername}?start={$token}";
    }

    /**
     * Process an incoming Telegram update object (from webhook or polling).
     * Handles /start <token> onboarding and links the Telegram account to the User.
     */
    public function processUpdate(array $update): ?User
    {
        $message = $update['message'] ?? ($update['edited_message'] ?? null);

        if (! $message || empty($message['text'])) {
            return null;
        }

        $chatId = $message['chat']['id'] ?? null;
        $username = $message['from']['username'] ?? null;
        $firstName = $message['from']['first_name'] ?? 'Staff Member';
        $text = trim($message['text']);

        if (! $chatId) {
            return null;
        }

        // Check for onboarding command: "/start <token>"
        if (str_starts_with($text, '/start')) {
            $parts = explode(' ', $text, 2);
            $token = isset($parts[1]) ? trim($parts[1]) : null;

            if ($token) {
                $user = User::query()
                    ->where('telegram_onboarding_token', $token)
                    ->where('status', 'active')
                    ->first();

                if ($user) {
                    $user->forceFill([
                        'telegram_chat_id' => (string) $chatId,
                        'telegram_username' => $username,
                        'telegram_onboarding_token' => null,
                    ])->save();

                    // Send welcome confirmation message
                    $welcomeMsg = "<b>✅ Account Linked Successfully!</b>\n\n"
                        . "Hello <b>" . htmlspecialchars($user->name) . "</b>,\n"
                        . "Your Telegram account has been connected to <b>Gondal ERP</b>.\n\n"
                        . "You will now receive notifications here according to your notification preferences.\n\n"
                        . "To manage your notification settings, open Gondal ERP on your device.";

                    $this->sendMessage($chatId, $welcomeMsg, url('/notifications'));

                    Log::info("Telegram account linked for user [{$user->id}] {$user->name} via chat ID {$chatId}");

                    return $user;
                }
            }

            // If token invalid or missing, send guidance
            $helpMsg = "<b>Gondal ERP Notifications Bot</b>\n\n"
                . "Hello {$firstName}! To link your Telegram account with Gondal ERP:\n\n"
                . "1. Sign in to Gondal ERP on your browser.\n"
                . "2. Navigate to <b>Notifications</b>.\n"
                . "3. Click <b>Connect Telegram</b> and tap the direct link to activate.";

            $this->sendMessage($chatId, $helpMsg);

            return null;
        }

        // Help command
        if ($text === '/help' || $text === '/status') {
            $user = User::query()->where('telegram_chat_id', (string) $chatId)->first();

            if ($user) {
                $statusMsg = "<b>Gondal ERP Notification Status</b>\n\n"
                    . "Connected User: <b>" . htmlspecialchars($user->name) . "</b>\n"
                    . "Email: <code>" . htmlspecialchars($user->email) . "</code>\n"
                    . "Chat ID: <code>{$chatId}</code>\n"
                    . "Status: <b>Active</b>\n\n"
                    . "You will receive system notifications based on your event preferences.";
            } else {
                $statusMsg = "<b>Gondal ERP Notification Bot</b>\n\n"
                    . "Your Telegram account is not yet connected to a user in Gondal ERP.\n"
                    . "Open Gondal ERP > Notifications to connect your account.";
            }

            $this->sendMessage($chatId, $statusMsg, url('/notifications'));
        }

        return null;
    }
}
