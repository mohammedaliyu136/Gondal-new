<?php

namespace App\Http\Controllers;

use App\Models\AppNotification;
use App\Models\NotificationEvent;
use App\Models\NotificationPreference;
use App\Services\Notifications\NotificationService;
use App\Support\Wat;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * notifications.html.
 *
 * NOTIF-1 — the preferences modal is per-user, per-event.
 * NOTIF-2 — a user only ever sees events they could have received, so the
 *   preferences list is filtered by the same permission gate that filters sends.
 */
class NotificationController extends Controller
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function index(Request $request): View
    {
        $user = $this->currentUser();

        return view('notifications.index', [
            'notifications' => $user->appNotifications()
                ->paginate($this->perPage($request->integer('per_page') ?: null))
                ->withQueryString(),
            'unreadCount' => $user->appNotifications()->unread()->count(),
            // NOTIF-2 — only the events this user could actually receive.
            'events' => NotificationEvent::query()
                ->active()
                ->orderBy('position')
                ->get()
                ->filter(fn (NotificationEvent $event) => $this->notifications->mayReceive($user, $event))
                ->values(),
            'preferences' => NotificationPreference::query()
                ->where('user_id', $user->getKey())
                ->get()
                ->keyBy('event_type'),
            'channelsFor' => fn (NotificationEvent $event) => $this->notifications->channelsFor($user, $event),
        ]);
    }

    public function markRead(AppNotification $notification): RedirectResponse
    {
        abort_unless($notification->user_id === $this->currentUser()?->getKey(), 404);

        $notification->forceFill(['read_at' => Wat::now()])->save();

        return back();
    }

    public function markAllRead(): RedirectResponse
    {
        $this->currentUser()->appNotifications()->unread()->update(['read_at' => Wat::now()]);

        return back()->with('success', 'All notifications marked as read.');
    }

    public function updatePreferences(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'preferences' => ['required', 'array'],
            'preferences.*.in_app' => ['nullable', 'boolean'],
            'preferences.*.email' => ['nullable', 'boolean'],
            'preferences.*.sms' => ['nullable', 'boolean'],
        ]);

        $user = $this->currentUser();

        foreach ($validated['preferences'] as $eventCode => $channels) {
            $event = NotificationEvent::query()->where('code', $eventCode)->first();

            // NOTIF-2 — you cannot set a preference for something you could never
            // receive.
            if ($event === null || ! $this->notifications->mayReceive($user, $event)) {
                continue;
            }

            NotificationPreference::query()->updateOrCreate(
                ['user_id' => $user->getKey(), 'event_type' => $eventCode],
                [
                    'in_app' => (bool) ($channels['in_app'] ?? false),
                    'email' => (bool) ($channels['email'] ?? false),
                    'sms' => (bool) ($channels['sms'] ?? false),
                ],
            );
        }

        return back()->with('success', 'Notification preferences saved.');
    }
}
