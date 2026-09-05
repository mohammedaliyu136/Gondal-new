<?php

namespace App\Services\Notifications;

use App\Authorization\Access;
use App\Models\NotificationEvent;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Services\Notifications\Channels\EmailNotificationChannel;
use App\Services\Notifications\Channels\InAppNotificationChannel;
use App\Services\Notifications\Channels\TelegramNotificationChannel;
use App\Services\Notifications\Contracts\NotificationChannelInterface;
use App\Services\Notifications\Contracts\NotificationServiceInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * §11 — the core application notification service.
 *
 * Implements NotificationServiceInterface with clean driver-based channel architecture:
 * In-App (database records), Email (queued Mailable / SMTP), Telegram (Telegram Bot API).
 *
 * NOTIF-2 — permission-filtered: a user is never notified about something they cannot open.
 * NOTIF-1 — per-user, per-event channel preferences defaulting to event catalogue settings.
 * USER-2 — recipients are always staff.
 */
class NotificationService implements NotificationServiceInterface
{
    /** @var array<string, NotificationChannelInterface> */
    private array $channels = [];

    public function __construct(
        private readonly Access $access,
        InAppNotificationChannel $inAppChannel,
        EmailNotificationChannel $emailChannel,
        TelegramNotificationChannel $telegramChannel,
    ) {
        $this->registerChannel($inAppChannel);
        $this->registerChannel($emailChannel);
        $this->registerChannel($telegramChannel);
    }

    public function registerChannel(NotificationChannelInterface $channel): self
    {
        $this->channels[$channel->getKey()] = $channel;

        return $this;
    }

    public function getChannel(string $key): ?NotificationChannelInterface
    {
        return $this->channels[$key] ?? null;
    }

    /**
     * @param  Collection<int, User>|array<int, User>  $recipients
     * @return int how many recipients actually received it
     */
    public function send(
        string $eventCode,
        Collection|array $recipients,
        string $title,
        ?string $body = null,
        ?string $actionUrl = null,
        ?Model $subject = null,
    ): int {
        $event = NotificationEvent::query()->active()->where('code', $eventCode)->first();

        if ($event === null) {
            // NOTIF-3 — event catalogue is seeded data. An unknown code is a configuration error.
            report(new \RuntimeException("Unknown notification event [{$eventCode}] (NOTIF-3)."));

            return 0;
        }

        $sent = 0;

        foreach (collect($recipients)->unique('id') as $recipient) {
            if (! $recipient instanceof User || ! $recipient->isActive()) {
                continue;
            }

            // NOTIF-2 — the permission & data-scope filter.
            if (! $this->mayReceive($recipient, $event, $subject)) {
                continue;
            }

            $userChannels = $this->channelsFor($recipient, $event);

            if ($userChannels === []) {
                continue;
            }

            $deliveredOnAnyChannel = false;

            foreach ($userChannels as $channelKey) {
                if (isset($this->channels[$channelKey])) {
                    $dispatched = $this->channels[$channelKey]->send(
                        recipient: $recipient,
                        event: $event,
                        title: $title,
                        body: $body,
                        actionUrl: $actionUrl,
                        subject: $subject,
                    );

                    if ($dispatched) {
                        $deliveredOnAnyChannel = true;
                    }
                }
            }

            if ($deliveredOnAnyChannel) {
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * NOTIF-2 — permission first, then data scope on the subject if there is one.
     */
    public function mayReceive(User $user, NotificationEvent $event, ?Model $subject = null): bool
    {
        if ($event->required_permission === null || $event->required_permission === '') {
            return true;
        }

        return $this->access->allows($user, $event->required_permission, $subject);
    }

    /**
     * NOTIF-1 — the recipient's own preference, falling back to event catalogue defaults.
     *
     * @return array<int, string>
     */
    public function channelsFor(User $user, NotificationEvent $event): array
    {
        $preference = NotificationPreference::query()
            ->where('user_id', $user->getKey())
            ->where('event_type', $event->code)
            ->first();

        $inApp = $preference ? $preference->in_app : $event->default_in_app;
        $email = $preference ? $preference->email : $event->default_email;
        $sms = $preference ? $preference->sms : $event->default_sms;
        $telegram = $preference ? $preference->telegram : $event->default_telegram;

        return array_values(array_filter([
            $inApp ? 'in_app' : null,
            $email ? 'email' : null,
            $sms ? 'sms' : null,
            $telegram ? 'telegram' : null,
        ]));
    }

    /**
     * BR-23 — active users holding the role and satisfying scope.
     * BR-24 — active delegations add delegates.
     *
     * @return Collection<int, User>
     */
    public function usersHoldingRole(int $roleId): Collection
    {
        $holders = User::query()
            ->where('status', 'active')
            ->whereHas('roles', fn ($query) => $query->where('roles.id', $roleId))
            ->get();

        $delegates = User::query()
            ->where('status', 'active')
            ->whereHas('delegationsReceived', fn ($query) => $query->active()->where('role_id', $roleId))
            ->get();

        return $holders->concat($delegates)->unique('id')->values();
    }

    /**
     * Recipients for an event chosen by permission rather than by role.
     *
     * @return Collection<int, User>
     */
    public function usersWithPermission(string $permissionKey, ?Model $subject = null): Collection
    {
        return User::query()
            ->where('status', 'active')
            ->get()
            ->filter(fn (User $user) => $this->access->allows($user, $permissionKey, $subject))
            ->values();
    }
}
