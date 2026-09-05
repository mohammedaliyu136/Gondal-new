<?php

namespace App\Http\Controllers;

use App\Models\AppNotification;
use App\Models\NotificationEvent;
use App\Models\NotificationPreference;
use App\Services\Notifications\NotificationService;
use App\Services\Notifications\Telegram\TelegramService;
use App\Support\Wat;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * notifications.html.
 *
 * NOTIF-1 — preferences modal per-user, per-event across In-App, Email, and Telegram.
 * NOTIF-2 — permission-gated: users only configure events they can receive.
 */
class NotificationController extends Controller
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly TelegramService $telegram,
    ) {}

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
            'telegramEnabled' => $this->telegram->isEnabled(),
            'telegramConnected' => $user->hasTelegram(),
            'telegramOnboardingUrl' => $this->telegram->generateOnboardingUrl($user),
            'botUsername' => $this->telegram->getBotUsername(),
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
            'preferences.*.telegram' => ['nullable', 'boolean'],
        ]);

        $user = $this->currentUser();

        foreach ($validated['preferences'] as $eventCode => $channels) {
            $event = NotificationEvent::query()->where('code', $eventCode)->first();

            // NOTIF-2 — cannot set a preference for an event you could never receive.
            if ($event === null || ! $this->notifications->mayReceive($user, $event)) {
                continue;
            }

            NotificationPreference::query()->updateOrCreate(
                ['user_id' => $user->getKey(), 'event_type' => $eventCode],
                [
                    'in_app' => (bool) ($channels['in_app'] ?? false),
                    'email' => (bool) ($channels['email'] ?? false),
                    'sms' => (bool) ($channels['sms'] ?? false),
                    'telegram' => (bool) ($channels['telegram'] ?? false),
                ],
            );
        }

        return back()->with('success', 'Notification preferences saved.');
    }

    /**
     * Manually connect a user's Telegram by entering their Chat ID or username.
     */
    public function connectTelegram(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'telegram_chat_id' => ['required', 'string', 'max:64'],
            'telegram_username' => ['nullable', 'string', 'max:64'],
        ]);

        $user = $this->currentUser();
        $user->forceFill([
            'telegram_chat_id' => trim($validated['telegram_chat_id']),
            'telegram_username' => isset($validated['telegram_username']) ? ltrim(trim($validated['telegram_username']), '@') : $user->telegram_username,
        ])->save();

        // Send a test welcome message
        if ($this->telegram->isEnabled()) {
            $this->telegram->sendMessage(
                chatId: $user->telegram_chat_id,
                htmlText: "<b>✅ Telegram Connected!</b>\n\nHello <b>{$user->name}</b>, your Telegram account is now connected to Gondal ERP.",
                actionUrl: url('/notifications'),
            );
        }

        return back()->with('success', 'Telegram account connected successfully.');
    }

    /**
     * Disconnect the user's Telegram integration.
     */
    public function disconnectTelegram(): RedirectResponse
    {
        $user = $this->currentUser();
        $user->forceFill([
            'telegram_chat_id' => null,
            'telegram_username' => null,
            'telegram_onboarding_token' => null,
        ])->save();

        return back()->with('success', 'Telegram integration disconnected.');
    }

    /**
     * Send a test Telegram notification to the current user.
     */
    public function sendTestTelegram(): RedirectResponse
    {
        $user = $this->currentUser();

        if (! $user->hasTelegram()) {
            return back()->with('error', 'Please connect your Telegram account first.');
        }

        if (! $this->telegram->isEnabled()) {
            return back()->with('error', 'Telegram Bot is not configured or enabled by administrator.');
        }

        $now = Wat::now()->format('H:i:s, d M Y');
        $text = "🔔 <b>Test Notification from Gondal ERP</b>\n\n"
            . "Hello <b>" . htmlspecialchars($user->name) . "</b>!\n"
            . "This test message confirms your Telegram integration is active and working properly at {$now}.\n\n"
            . "You will receive real-time notifications for approved events.";

        $result = $this->telegram->sendMessage(
            chatId: $user->telegram_chat_id,
            htmlText: $text,
            actionUrl: url('/notifications'),
            actionText: 'Open Gondal Notifications',
        );

        if ($result['success'] ?? false) {
            return back()->with('success', 'Test message sent to Telegram! Check your Telegram app.');
        }

        return back()->with('error', $result['message'] ?? 'Failed to send test Telegram message.');
    }
}
