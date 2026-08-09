<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * BR-31 — "Administrators never see or set a user's password. Creation and reset
 * both send a code; the user chooses their own password."
 *
 * AUTH-8 — there is no self-registration, so this is the only way an account
 * comes into being.
 */
class AccountCreatedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $code,
        private readonly int $expiryMinutes,
        private readonly string $createdByName,
        private readonly string $activationUrl,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $days = (int) round($this->expiryMinutes / 1440);

        return (new MailMessage)
            ->subject('Your Gondal ERP account is ready')
            ->greeting('Welcome, '.$notifiable->name)
            ->line($this->createdByName.' created an account for you on Gondal ERP.')
            ->line('Press the button below, then enter this code to choose your own password — nobody else has seen or set it:')
            ->line('**'.$this->code.'**')
            ->action('Choose your password', $this->activationUrl)
            ->line($days >= 1
                ? "The link and code are valid for {$days} ".($days === 1 ? 'day' : 'days').'.'
                : "The link and code are valid for {$this->expiryMinutes} minutes.")
            ->line('If they have expired, use “Forgot password?” on the sign-in page to get a fresh code.');
    }
}
