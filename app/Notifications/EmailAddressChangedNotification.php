<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * AUTH-8 / BR-31 — the OLD address is told when an administrator moves an
 * account's identity to a new one.
 *
 * An e-mail address is not a profile field here: it is the address every
 * activation code and password reset is delivered to, so changing it hands
 * whoever holds the new mailbox a route to the account. The person who has just
 * lost that route is the only one who can tell that the change was not asked
 * for, and the new address will never tell them.
 *
 * NOTIF-5 — queued, never synchronous with the administrator's request.
 */
class EmailAddressChangedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $userName,
        private readonly string $newEmail,
        private readonly string $actorName,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Gondal ERP sign-in address changed')
            ->greeting('Hello '.$this->userName.',')
            ->line($this->actorName.' changed the e-mail address on your Gondal ERP account.')
            ->line('Sign-in codes, password resets and activation links now go to '.$this->maskedNewEmail().'.')
            ->line('This message was sent to your PREVIOUS address, because it is the only one that can tell us this was wrong.')
            ->line('If you did not ask for this, contact the General Manager or Internal Audit immediately — not the person who made the change.');
    }

    /**
     * The new address is shown masked. Telling the old mailbox exactly where the
     * account went would make this notification a way to read an address the
     * recipient may have no business knowing.
     */
    private function maskedNewEmail(): string
    {
        [$local, $domain] = array_pad(explode('@', $this->newEmail, 2), 2, '');

        return mb_substr($local, 0, 2).str_repeat('•', max(mb_strlen($local) - 2, 1)).'@'.$domain;
    }
}
