<?php

namespace App\Services\Notifications\Contracts;

use App\Models\NotificationEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Contract for the main application notification dispatch engine.
 */
interface NotificationServiceInterface
{
    /**
     * Send a notification to one or more recipients across their preferred and enabled channels.
     *
     * @param  Collection<int, User>|array<int, User>  $recipients
     * @return int Count of recipients that received the notification
     */
    public function send(
        string $eventCode,
        Collection|array $recipients,
        string $title,
        ?string $body = null,
        ?string $actionUrl = null,
        ?Model $subject = null,
    ): int;

    /**
     * Check if a user has the required permission and data scope for the notification event.
     */
    public function mayReceive(User $user, NotificationEvent $event, ?Model $subject = null): bool;

    /**
     * Get the active delivery channels for a specific user and notification event.
     *
     * @return array<int, string> e.g. ['in_app', 'email', 'telegram']
     */
    public function channelsFor(User $user, NotificationEvent $event): array;

    /**
     * Resolve all active staff users holding a specific role or active delegation for that role.
     *
     * @return Collection<int, User>
     */
    public function usersHoldingRole(int $roleId): Collection;

    /**
     * Resolve all active staff users who hold a specific permission, scoped to the given subject.
     *
     * @return Collection<int, User>
     */
    public function usersWithPermission(string $permissionKey, ?Model $subject = null): Collection;
}
