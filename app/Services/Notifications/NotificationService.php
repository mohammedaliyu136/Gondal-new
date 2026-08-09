<?php

namespace App\Services\Notifications;

use App\Authorization\Access;
use App\Models\AppNotification;
use App\Models\NotificationEvent;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Notifications\GondalEventNotification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * §11 — the only way a notification is sent.
 *
 * NOTIF-2 — "Notifications are permission-filtered — a user is never notified
 *   about something they could not open." The gate is the event's
 *   `required_permission` column, checked against the recipient's effective
 *   permissions AND their data scope for the subject record. A center officer is
 *   not told about a discrepancy at someone else's center.
 * NOTIF-1 — per-user, per-event channel preferences, defaulted from the event.
 * NOTIF-5 — email and SMS go through the queue. The in-app row is written
 *   immediately because it is the record itself, not a send.
 * USER-2 — recipients are always staff. Farmers have no notification path.
 */
class NotificationService
{
    public function __construct(private readonly Access $access) {}

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
            // NOTIF-3 — the event catalogue is seeded data. An unknown code is a
            // configuration error, and silently dropping the notification is
            // better than inventing an event with no permission gate.
            report(new \RuntimeException("Unknown notification event [{$eventCode}] (NOTIF-3)."));

            return 0;
        }

        $sent = 0;

        foreach (collect($recipients)->unique('id') as $recipient) {
            if (! $recipient instanceof User || ! $recipient->isActive()) {
                continue;
            }

            // NOTIF-2 — the filter.
            if (! $this->mayReceive($recipient, $event, $subject)) {
                continue;
            }

            $channels = $this->channelsFor($recipient, $event);

            if ($channels === []) {
                continue;
            }

            if (in_array('in_app', $channels, true)) {
                AppNotification::query()->create([
                    'user_id' => $recipient->getKey(),
                    'type' => $event->code,
                    'title' => $title,
                    'body' => $body,
                    'action_url' => $actionUrl,
                    'channel_flags' => $channels,
                    'subject_type' => $subject?->getMorphClass(),
                    'subject_id' => $subject?->getKey(),
                ]);
            }

            $queued = array_values(array_intersect($channels, ['email', 'sms']));

            if ($queued !== []) {
                $recipient->notify(new GondalEventNotification(
                    event: $event,
                    title: $title,
                    body: $body,
                    actionUrl: $actionUrl,
                    channels: array_map(
                        static fn (string $channel) => $channel === 'email' ? 'mail' : $channel,
                        $queued,
                    ),
                ));
            }

            $sent++;
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
     * NOTIF-1 — the recipient's own preference, falling back to the event's
     * defaults when they have never expressed one.
     *
     * @return array<int, string>
     */
    public function channelsFor(User $user, NotificationEvent $event): array
    {
        $preference = NotificationPreference::query()
            ->where('user_id', $user->getKey())
            ->where('event_type', $event->code)
            ->first();

        $inApp = $preference?->in_app ?? $event->default_in_app;
        $email = $preference?->email ?? $event->default_email;
        $sms = $preference?->sms ?? $event->default_sms;

        return array_values(array_filter([
            $inApp ? 'in_app' : null,
            $email ? 'email' : null,
            $sms ? 'sms' : null,
        ]));
    }

    /**
     * BR-23 — "Any user holding the stage's role and satisfying scope sees the
     * item in /approvals." The same resolution decides who gets told about it.
     *
     * BR-24 — an active delegation adds the delegate.
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
     * §11 — recipients for an event chosen by permission rather than by role, e.g.
     * "rejection at a point I supervise".
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
