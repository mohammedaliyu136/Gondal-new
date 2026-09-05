<?php

namespace Tests\Feature;

use App\Models\NotificationEvent;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Services\Notifications\Contracts\ApprovalNotificationServiceInterface;
use App\Services\Notifications\Contracts\HrNotificationServiceInterface;
use App\Services\Notifications\Contracts\MilkCollectionNotificationServiceInterface;
use App\Services\Notifications\Contracts\NotificationServiceInterface;
use App\Services\Notifications\NotificationService;
use App\Services\Notifications\Telegram\TelegramService;
use Illuminate\Support\Facades\Http;
use Tests\GondalTestCase;

class NotificationSystemTest extends GondalTestCase
{
    public function test_interfaces_are_properly_bound_in_container(): void
    {
        $notificationService = app(NotificationServiceInterface::class);
        $this->assertInstanceOf(NotificationService::class, $notificationService);

        $milkNotifier = app(MilkCollectionNotificationServiceInterface::class);
        $this->assertNotNull($milkNotifier);

        $approvalNotifier = app(ApprovalNotificationServiceInterface::class);
        $this->assertNotNull($approvalNotifier);

        $hrNotifier = app(HrNotificationServiceInterface::class);
        $this->assertNotNull($hrNotifier);
    }

    public function test_notification_dispatches_in_app_and_telegram(): void
    {
        config(['services.telegram.bot_token' => '123456:ABC-DEF']);

        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 999]], 200),
        ]);

        $user = $this->makeUser('Test Officer', [
            'telegram_chat_id' => '123456789',
            'telegram_username' => 'testofficer',
        ]);

        $event = NotificationEvent::firstOrCreate(
            ['code' => 'test.event'],
            [
                'name' => 'Test Event',
                'category' => 'general',
                'description' => 'Test event notification',
                'default_in_app' => true,
                'default_email' => false,
                'default_telegram' => true,
            ]
        );

        NotificationPreference::updateOrCreate(
            ['user_id' => $user->id, 'event_type' => $event->code],
            ['in_app' => true, 'email' => false, 'telegram' => true]
        );

        /** @var NotificationServiceInterface $service */
        $service = app(NotificationServiceInterface::class);

        $result = $service->send(
            eventCode: 'test.event',
            recipients: [$user],
            title: 'Important Alert',
            body: 'This is a notification test body.',
            actionUrl: '/dashboard',
        );

        $this->assertEquals(1, $result);

        // Verify In-App record
        $this->assertDatabaseHas('notifications', [
            'user_id' => $user->id,
            'title' => 'Important Alert',
        ]);

        // Verify Telegram API call was made
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'sendMessage') &&
                   $request['chat_id'] === '123456789' &&
                   str_contains($request['text'], 'Important Alert');
        });
    }

    public function test_telegram_onboarding_process_update_links_user(): void
    {
        $user = $this->makeUser('New Telegram User');
        $token = $user->generateTelegramOnboardingToken();

        $telegramService = app(TelegramService::class);

        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        $update = [
            'update_id' => 12345,
            'message' => [
                'message_id' => 10,
                'from' => [
                    'id' => 987654321,
                    'is_bot' => false,
                    'first_name' => 'John',
                    'username' => 'john_doe',
                ],
                'chat' => [
                    'id' => 987654321,
                    'type' => 'private',
                ],
                'date' => time(),
                'text' => "/start {$token}",
            ],
        ];

        $processed = $telegramService->processUpdate($update);
        $this->assertInstanceOf(User::class, $processed);

        $user->refresh();
        $this->assertEquals('987654321', $user->telegram_chat_id);
        $this->assertEquals('john_doe', $user->telegram_username);
        $this->assertNull($user->telegram_onboarding_token);
    }

    public function test_user_can_manually_connect_and_disconnect_telegram_chat_id(): void
    {
        $user = $this->makeUser('Manual User');

        $response = $this->actingAs($user)->post(route('notifications.telegram.connect'), [
            'telegram_chat_id' => '555444333',
            'telegram_username' => '@manualuser',
        ]);

        $response->assertRedirect();
        $user->refresh();
        $this->assertEquals('555444333', $user->telegram_chat_id);
        $this->assertEquals('manualuser', $user->telegram_username);

        // Disconnect
        $response = $this->actingAs($user)->post(route('notifications.telegram.disconnect'));
        $response->assertRedirect();
        $user->refresh();
        $this->assertNull($user->telegram_chat_id);
        $this->assertNull($user->telegram_username);
    }

    public function test_telegram_status_endpoint_returns_json_for_qr_polling(): void
    {
        $user = $this->makeUser('QR Polling User', [
            'telegram_chat_id' => '777888999',
            'telegram_username' => 'qruser',
        ]);

        $response = $this->actingAs($user)->get(route('notifications.telegram.status'));

        $response->assertOk();
        $response->assertJson([
            'connected' => true,
            'chat_id' => '777888999',
            'username' => 'qruser',
        ]);
    }

    public function test_user_can_save_notification_preferences_with_telegram(): void
    {
        $user = $this->makeUser('Pref User');
        $event = NotificationEvent::firstOrCreate(
            ['code' => 'pref.test'],
            [
                'name' => 'Pref Test',
                'category' => 'general',
                'required_permission' => null,
                'default_in_app' => true,
                'default_email' => false,
                'default_telegram' => false,
            ]
        );

        $response = $this->actingAs($user)->put(route('notifications.preferences'), [
            'preferences' => [
                'pref.test' => [
                    'in_app' => 1,
                    'email' => 0,
                    'telegram' => 1,
                ],
            ],
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('notification_preferences', [
            'user_id' => $user->id,
            'event_type' => 'pref.test',
            'in_app' => true,
            'telegram' => true,
        ]);
    }

    public function test_domain_notification_services_are_callable(): void
    {
        $notificationService = app(NotificationServiceInterface::class);
        $this->assertNotNull($notificationService);

        /** @var MilkCollectionNotificationServiceInterface $milkService */
        $milkService = app(MilkCollectionNotificationServiceInterface::class);
        $this->assertTrue(method_exists($milkService, 'notifyConsignmentAwaitingConfirmation'));
        $this->assertTrue(method_exists($milkService, 'notifyBatchDiscrepancy'));
        $this->assertTrue(method_exists($milkService, 'notifyRejectionAtPoint'));
        $this->assertTrue(method_exists($milkService, 'notifyDeliveryRecorded'));

        /** @var ApprovalNotificationServiceInterface $approvalService */
        $approvalService = app(ApprovalNotificationServiceInterface::class);
        $this->assertTrue(method_exists($approvalService, 'notifyApprovalQueued'));
        $this->assertTrue(method_exists($approvalService, 'notifyRequisitionDecided'));
        $this->assertTrue(method_exists($approvalService, 'notifyApprovalOverdue'));

        /** @var HrNotificationServiceInterface $hrService */
        $hrService = app(HrNotificationServiceInterface::class);
        $this->assertTrue(method_exists($hrService, 'notifyLeaveRequested'));
        $this->assertTrue(method_exists($hrService, 'notifyLeaveDecided'));
        $this->assertTrue(method_exists($hrService, 'notifyPayrollRunGenerated'));
        $this->assertTrue(method_exists($hrService, 'notifyPayrollDisbursed'));
    }

    public function test_admin_settings_page_renders_with_email_and_telegram_settings(): void
    {
        $admin = $this->makeUser('System Admin');
        $this->assignRole($admin, 'System Administrator');

        $response = $this->actingAs($admin)->get(route('admin.settings'));

        $response->assertOk();
        $response->assertSee('Outbound SMTP Email Configuration');
        $response->assertSee('Telegram Bot Integration');
    }
}
