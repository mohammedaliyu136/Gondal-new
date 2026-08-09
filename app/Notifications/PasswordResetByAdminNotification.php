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
 * Deliberately not AccountCreatedNotification. "Welcome, your account is ready"
 * arriving at an eight-month employee whose password an administrator has just
 * cleared reads as a mistake, or as phishing, and the one question they will
 * actually have — *why has my password stopped working?* — goes unanswered. This
 * message says what happened, who did it, the reason they gave, and what to do if
 * they did not ask for it.
 */
class PasswordResetByAdminNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $code,
        private readonly int $expiryMinutes,
        private readonly string $resetByName,
        private readonly string $reason,
        private readonly string $resetUrl,
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
            ->subject('Your Gondal ERP password has been reset')
            ->greeting('Hello '.$notifiable->name)
            ->line($this->resetByName.' reset the password on your Gondal ERP account — '.$this->reason.'.')
            ->line('Your old password no longer works, and nobody has set a new one for you. Press the button below, then enter this code to choose your own:')
            ->line('**'.$this->code.'**')
            ->action('Choose a new password', $this->resetUrl)
            ->line($days >= 1
                ? "The link and code are valid for {$days} ".($days === 1 ? 'day' : 'days').'.'
                : "The link and code are valid for {$this->expiryMinutes} minutes.")
            ->line('You have also been signed out on every device, including the mobile app.')
            ->line('If they have expired, use “Forgot password?” on the sign-in page to get a fresh code.')
            ->line('If you did not ask for this, tell your manager or Internal Audit now — an administrator did it, not you.');
    }
}
