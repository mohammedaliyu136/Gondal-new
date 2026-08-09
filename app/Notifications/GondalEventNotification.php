<?php

namespace App\Notifications;

use App\Models\NotificationEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * §11 — the generic operational notification.
 *
 * NOTIF-1 in-app, email and SMS, per-user per-event
 * NOTIF-2 permission-filtered before it is ever queued (see NotificationService)
 * NOTIF-5 always queued
 *
 * The in-app row is written by NotificationService rather than through a database
 * channel, because §6.9 specifies the `notifications` table's own shape.
 */
class GondalEventNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<int, string>  $channels
     */
    public function __construct(
        public readonly NotificationEvent $event,
        public readonly string $title,
        public readonly ?string $body,
        public readonly ?string $actionUrl,
        private readonly array $channels,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return array_values(array_intersect($this->channels, ['mail']));
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject($this->title)
            ->greeting('Hello '.$notifiable->name.',')
            ->line($this->body ?? $this->title);

        if ($this->actionUrl !== null) {
            $message->action('Open in Gondal ERP', $this->actionUrl);
        }

        return $message;
    }
}
