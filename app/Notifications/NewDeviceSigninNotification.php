<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** AUTH-7 — "Sign-in from a new device notifies the user by email and in-app." */
class NewDeviceSigninNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $deviceLabel,
        private readonly ?string $ip,
        private readonly string $whenWat,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('New sign-in to your Gondal ERP account')
            ->greeting('Hello '.$notifiable->name.',')
            ->line('Your account was used to sign in from a device we have not seen before.')
            ->line('Device: '.$this->deviceLabel)
            ->line('IP address: '.($this->ip ?? 'unknown'))
            ->line('Time: '.$this->whenWat.' (WAT)')
            ->line('If this was not you, change your password and contact IT Support.');
    }
}
