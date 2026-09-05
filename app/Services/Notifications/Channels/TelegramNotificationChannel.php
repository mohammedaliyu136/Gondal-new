<?php

namespace App\Services\Notifications\Channels;

use App\Models\NotificationEvent;
use App\Models\User;
use App\Services\Notifications\Contracts\NotificationChannelInterface;
use App\Services\Notifications\Telegram\TelegramService;
use Illuminate\Database\Eloquent\Model;

class TelegramNotificationChannel implements NotificationChannelInterface
{
    public function __construct(private readonly TelegramService $telegram) {}

    public function getKey(): string
    {
        return 'telegram';
    }

    public function send(
        User $recipient,
        NotificationEvent $event,
        string $title,
        ?string $body = null,
        ?string $actionUrl = null,
        ?Model $subject = null,
    ): bool {
        if (! $recipient->hasTelegram() || ! $this->telegram->isEnabled()) {
            return false;
        }

        // Format message with HTML tags
        $moduleTag = $event->module ? "<b>[{$event->module}]</b>\n" : '';
        $messageText = "🔔 <b>" . htmlspecialchars($title) . "</b>\n"
            . $moduleTag
            . ($body ? "\n" . htmlspecialchars($body) . "\n" : '');

        $result = $this->telegram->sendMessage(
            chatId: $recipient->telegram_chat_id,
            htmlText: $messageText,
            actionUrl: $actionUrl,
            actionText: 'View in Gondal ERP',
        );

        return $result['success'] ?? false;
    }
}
