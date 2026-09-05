<?php

namespace App\Services\Notifications\Contracts;

use App\Models\NotificationEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Contract for an individual notification delivery channel (e.g. In-App, Email, Telegram, SMS).
 */
interface NotificationChannelInterface
{
    /**
     * Unique key identifying the channel, matching notification_events/preferences (e.g. 'in_app', 'email', 'telegram').
     */
    public function getKey(): string;

    /**
     * Send a notification to a specific recipient over this channel.
     *
     * @return bool Whether dispatch was initiated successfully
     */
    public function send(
        User $recipient,
        NotificationEvent $event,
        string $title,
        ?string $body = null,
        ?string $actionUrl = null,
        ?Model $subject = null,
    ): bool;
}
