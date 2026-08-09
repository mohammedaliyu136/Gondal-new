<?php

namespace App\Notifications;

use App\Support\Wat;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/** AUTH-6 — "...locks the account for 30 minutes and notifies the user." */
class AccountLockedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly Carbon $until,
        private readonly int $failureCount,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Gondal ERP account temporarily locked')
            ->greeting('Hello '.$notifiable->name.',')
            ->line("We recorded {$this->failureCount} failed sign-in attempts on your account.")
            ->line('It is locked until '.Wat::dateTime($this->until).' (WAT).')
            ->line('If that was not you, contact IT Support now.');
    }
}
