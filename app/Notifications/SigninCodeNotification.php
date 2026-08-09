<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * AUTH-1 / AUTH-4 — the 6-digit code, by e-mail.
 *
 * NOTIF-5 — queued, never synchronous with the request.
 * NFR-9 — the code appears in the message and nowhere else. It is not logged and
 * the database holds only its hash.
 */
class SigninCodeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $code,
        private readonly string $purpose,
        private readonly int $expiryMinutes,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $isReset = $this->purpose === 'reset';

        return (new MailMessage)
            ->subject($isReset ? 'Your Gondal ERP password reset code' : 'Your Gondal ERP sign-in code')
            ->greeting('Hello '.$notifiable->name.',')
            ->line($isReset
                ? 'Use this code to choose a new password:'
                : 'Use this code to finish signing in:')
            ->line('**'.$this->code.'**')
            ->line("The code expires in {$this->expiryMinutes} minutes and can be used once.")
            ->line('If you did not request it, you can ignore this message — and tell IT Support.');
    }
}
