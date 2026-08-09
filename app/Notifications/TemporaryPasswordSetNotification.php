<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * BR-31, qualified — an administrator set a temporary password on this account.
 *
 * The password is NOT in here, and must never be. The whole point of the
 * temporary-password path is that it works when the user cannot reach their
 * mailbox, so the administrator says the password down the phone or hands it over
 * in person; putting it in an email would place a live credential in an inbox for
 * no benefit at all, and would recreate by post the exposure the emailed-code flow
 * exists to avoid.
 *
 * What this message is for is the other half: an administrator can now sign in as
 * this person until they change it, and AUTH-8's guard cannot prevent that. So the
 * account holder is told it happened, by whom, and why — including when they never
 * asked for it, which is the case that matters. Detection, not prevention.
 */
class TemporaryPasswordSetNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $setByName,
        private readonly string $reason,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('A temporary password was set on your Gondal ERP account')
            ->greeting('Hello '.$notifiable->name)
            ->line($this->setByName.' set a temporary password on your account — '.$this->reason.'.')
            ->line('They will give you that password directly; it is deliberately not in this email. Your old password no longer works, and you have been signed out on every device including the mobile app.')
            ->line('The first time you sign in you will be taken straight to the change-password screen. Choose a password of your own there — after that, nobody but you knows it.')
            ->action('Sign in', route('login'))
            ->line('Until you change it, '.$this->setByName.' knows a password that opens your account.')
            ->line('If you did not ask for this, tell your manager or Internal Audit now, and change the password as soon as you are in.');
    }
}
