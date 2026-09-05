<?php

namespace App\Services\Notifications\Channels;

use App\Models\AppNotification;
use App\Models\NotificationEvent;
use App\Models\User;
use App\Services\Notifications\Contracts\NotificationChannelInterface;
use Illuminate\Database\Eloquent\Model;

class InAppNotificationChannel implements NotificationChannelInterface
{
    public function getKey(): string
    {
        return 'in_app';
    }

    public function send(
        User $recipient,
        NotificationEvent $event,
        string $title,
        ?string $body = null,
        ?string $actionUrl = null,
        ?Model $subject = null,
    ): bool {
        AppNotification::query()->create([
            'user_id' => $recipient->getKey(),
            'type' => $event->code,
            'title' => $title,
            'body' => $body,
            'action_url' => $actionUrl,
            'channel_flags' => ['in_app'],
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
        ]);

        return true;
    }
}
