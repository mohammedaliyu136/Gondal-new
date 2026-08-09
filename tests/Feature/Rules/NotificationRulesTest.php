<?php

namespace Tests\Feature\Rules;

use App\Authorization\ScopeType;
use App\Models\AppNotification;
use App\Models\Community;
use App\Models\Consignment;
use App\Models\Department;
use App\Models\NotificationEvent;
use App\Models\NotificationPreference;
use App\Models\Permission;
use App\Models\RejectionReason;
use App\Models\Requisition;
use App\Models\Role;
use App\Models\User;
use App\Notifications\GondalEventNotification;
use App\Services\Admin\RoleAdminService;
use App\Services\Milk\DeliveryService;
use App\Services\Notifications\NotificationService;
use App\Services\Purchases\RequisitionService;
use App\Services\Workflow\WorkflowEngine;
use App\Support\Wat;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\GondalTestCase;

/** §11 — notifications. */
class NotificationRulesTest extends GondalTestCase
{
    /** NOTIF-3 — the seeded event catalogue exists as rows. */
    public function test_notif3_the_event_catalogue_is_seeded(): void
    {
        $codes = NotificationEvent::query()->active()->pluck('code')->all();

        // Every event §11 lists.
        foreach ([
            'approval.queued',
            'requisition.decided',
            'approval.overdue',
            'consignment.awaiting_confirmation',
            'batch.discrepancy',
            'rejection.at_point',
            'quality.followup_opened',
            'role.changed',
            'signin.new_device',
            'shop.low_stock',
        ] as $code) {
            $this->assertContains($code, $codes, "NOTIF-3 — {$code} must be a seeded event.");
        }

        // And each carries the permission that gates it, so NOTIF-2 is data-driven.
        $this->assertSame(
            'milk.reconciliation.view',
            NotificationEvent::query()->where('code', 'batch.discrepancy')->value('required_permission'),
        );
    }

    /**
     * NOTIF-2 — "Notifications are permission-filtered — a user is never notified
     * about something they could not open."
     */
    public function test_notif2_recipients_are_filtered_by_permission(): void
    {
        $service = app(NotificationService::class);
        $event = NotificationEvent::query()->where('code', 'batch.discrepancy')->firstOrFail();

        $supervisor = $this->makeUser('Reconciling Supervisor');
        $this->assignRole($supervisor, 'Milk Collection Supervisor');

        $agent = $this->makeUser('Unrelated Agent');
        $this->assignRole($agent, 'Collection Agent', ScopeType::Network);

        $this->assertTrue($service->mayReceive($supervisor->fresh(), $event));
        $this->assertFalse(
            $service->mayReceive($agent->fresh(), $event),
            'A collection agent cannot open a reconciliation, so they are never told about one.',
        );

        // Sending to both delivers to one.
        $sent = $service->send(
            eventCode: 'batch.discrepancy',
            recipients: [$supervisor->fresh(), $agent->fresh()],
            title: 'A batch variance needs attention',
        );

        $this->assertSame(1, $sent);
        $this->assertDatabaseHas('notifications', ['user_id' => $supervisor->id]);
        $this->assertDatabaseMissing('notifications', ['user_id' => $agent->id]);
    }

    /** NOTIF-2 — the filter respects DATA SCOPE, not just the permission. */
    public function test_notif2_the_filter_respects_data_scope(): void
    {
        $world = $this->makeMilkWorld();
        $service = app(NotificationService::class);

        $ownCenter = $this->makeUser('Kumbotso Officer');
        $this->assignRole($ownCenter, 'Milk Collection Officer', ScopeType::Center, $world['centerA']->id);

        $otherCenter = $this->makeUser('Dawakin Officer');
        $this->assignRole($otherCenter, 'Milk Collection Officer', ScopeType::Center, $world['centerB']->id);

        $consignment = $this->asSystem(fn () => Consignment::query()->create([
            'reference' => 'CNS-0001',
            'collection_point_id' => $world['pointA']->id,
            'collection_center_id' => $world['centerA']->id,
            'dispatched_at' => Wat::now(),
            'litres_dispatched' => '100.00',
            'status' => Consignment::STATUS_AWAITING,
        ]));

        $sent = $service->send(
            eventCode: 'consignment.awaiting_confirmation',
            recipients: [$ownCenter->fresh(), $otherCenter->fresh()],
            title: 'CNS-0001 awaiting confirmation',
            subject: $consignment,
        );

        $this->assertSame(1, $sent, 'Only the officer whose scope admits the record.');
        $this->assertDatabaseHas('notifications', ['user_id' => $ownCenter->id]);
        $this->assertDatabaseMissing('notifications', ['user_id' => $otherCenter->id]);
    }

    /** NOTIF-1 — per-user, per-event channel preferences, defaulted from the event. */
    public function test_notif1_channel_preferences_are_per_user_and_per_event(): void
    {
        $service = app(NotificationService::class);
        $user = $this->makeUser('Preference Holder');
        $this->assignRole($user, 'Milk Collection Supervisor');

        $event = NotificationEvent::query()->where('code', 'batch.discrepancy')->firstOrFail();

        // The event's defaults apply when the user has expressed no preference.
        $this->assertTrue((bool) $event->default_in_app);
        $this->assertTrue((bool) $event->default_email);
        $this->assertSame(['in_app', 'email'], $service->channelsFor($user->fresh(), $event));

        // The user turns email off and SMS on.
        NotificationPreference::query()->create([
            'user_id' => $user->id,
            'event_type' => $event->code,
            'in_app' => true,
            'email' => false,
            'sms' => true,
        ]);

        $this->assertSame(['in_app', 'sms'], $service->channelsFor($user->fresh(), $event));

        // A different event still uses its own defaults.
        $other = NotificationEvent::query()->where('code', 'approval.queued')->firstOrFail();
        $this->assertSame(['in_app', 'email'], $service->channelsFor($user->fresh(), $other));
    }

    /** NOTIF-1 — the preferences screen only offers events the user could receive. */
    public function test_notif1_the_preferences_screen_is_permission_filtered(): void
    {
        $agent = $this->makeUser('Preferences Agent');
        $this->assignRole($agent, 'Collection Agent', ScopeType::Network);
        $this->actingAs($agent->fresh());

        $response = $this->get(route('notifications.index'));

        $response->assertOk();
        // A collection agent can be told about a rejection at a point.
        $response->assertSee('Rejection at a point I supervise');
        // But not about payroll-adjacent or reconciliation events they cannot open.
        $response->assertDontSee('Batch discrepancy');

        // And a preference for an event they cannot receive is refused.
        $this->put(route('notifications.preferences'), [
            'preferences' => [
                'batch.discrepancy' => ['in_app' => '1', 'email' => '1'],
            ],
        ])->assertRedirect();

        $this->assertDatabaseMissing('notification_preferences', [
            'user_id' => $agent->id,
            'event_type' => 'batch.discrepancy',
        ]);
    }

    /** NOTIF-5 — "All sends are queued, never synchronous with a request." */
    public function test_notif5_email_and_sms_are_queued(): void
    {
        Notification::fake();

        $service = app(NotificationService::class);
        $user = $this->makeUser('Queued Recipient');
        $this->assignRole($user, 'Milk Collection Supervisor');

        $service->send(
            eventCode: 'batch.discrepancy',
            recipients: [$user->fresh()],
            title: 'Queued send',
            body: 'This should be queued, not sent inline.',
        );

        Notification::assertSentTo($user, GondalEventNotification::class);

        // The notification class implements ShouldQueue, which is what makes it
        // queued rather than inline.
        $this->assertContains(
            ShouldQueue::class,
            class_implements(GondalEventNotification::class),
        );

        // The in-app row, by contrast, is written immediately — it IS the record.
        $this->assertDatabaseHas('notifications', [
            'user_id' => $user->id,
            'type' => 'batch.discrepancy',
            'title' => 'Queued send',
        ]);
    }

    /** NOTIF-3 — "item enters my approval queue", end to end. */
    public function test_notif3_an_approval_queues_a_notification_for_the_stage_role(): void
    {
        $department = $this->asSystem(fn () => Department::query()->create(['name' => 'Logistics', 'status' => 'active']));

        $requester = $this->makeUser('Notif Requester', ['department_id' => $department->id]);
        $this->assignRole($requester, 'Logistics Officer', ScopeType::Network);

        $deptHead = $this->makeUser('Notif Dept Head', ['department_id' => $department->id]);
        $this->assignRole($deptHead, 'Department Head', ScopeType::Department, $department->id);

        $this->actingAs($requester->fresh());

        $service = app(RequisitionService::class);

        $requisition = $service->create([
            'title' => 'Diesel',
            'department_id' => $department->id,
            'urgency' => 'normal',
        ], [
            ['item' => 'Diesel', 'quantity' => 1, 'unit' => 'lot', 'unit_price_minor' => 3_400_000_00],
        ], $requester->fresh());

        $service->submit($requisition, $requester->fresh());

        // The stage's role holder is told.
        $this->assertDatabaseHas('notifications', [
            'user_id' => $deptHead->id,
            'type' => 'approval.queued',
        ]);

        // BR-18 — the requester is NOT told to approve their own submission.
        $this->assertDatabaseMissing('notifications', [
            'user_id' => $requester->id,
            'type' => 'approval.queued',
        ]);

        /*
         * NOTIF-3 — "my requisition approved or rejected". A single approval does
         * not decide a ₦3.4m requisition: BR-19 puts it in the Major band, so the
         * chain moves to Internal Audit. The requester is told when the item reaches
         * a DECISION, which a rejection is.
         */
        app(WorkflowEngine::class)->approve(
            $requisition->refresh()->workflowInstance,
            $deptHead->fresh(),
            null,
            'Approved at stage 2.',
        );

        $this->assertDatabaseMissing('notifications', [
            'user_id' => $requester->id,
            'type' => 'requisition.decided',
        ]);

        $this->assertSame('Internal Audit', $requisition->refresh()->workflowInstance->currentStage->name);

        // Internal Audit rejects it, which IS a decision.
        $audit = $this->makeUser('Notif Auditor');
        $this->assignRole($audit, 'Internal Audit');

        app(WorkflowEngine::class)->reject(
            $requisition->refresh()->workflowInstance,
            $audit->fresh(),
            'Only one quotation attached.',
        );

        $this->assertDatabaseHas('notifications', [
            'user_id' => $requester->id,
            'type' => 'requisition.decided',
        ]);
    }

    /** NOTIF-4 — overdue reminders follow the stage SLA and the workflow setting. */
    public function test_notif4_overdue_reminders_follow_the_sla(): void
    {
        $department = $this->asSystem(fn () => Department::query()->create(['name' => 'Logistics', 'status' => 'active']));

        $requester = $this->makeUser('Overdue Requester', ['department_id' => $department->id]);
        $this->assignRole($requester, 'Logistics Officer', ScopeType::Network);

        $deptHead = $this->makeUser('Overdue Dept Head', ['department_id' => $department->id]);
        $this->assignRole($deptHead, 'Department Head', ScopeType::Department, $department->id);

        $this->actingAs($requester->fresh());

        $service = app(RequisitionService::class);
        $requisition = $service->create([
            'title' => 'Spares',
            'department_id' => $department->id,
            'urgency' => 'normal',
        ], [
            ['item' => 'Spares', 'quantity' => 1, 'unit' => 'lot', 'unit_price_minor' => 200_000_00],
        ], $requester->fresh());

        $service->submit($requisition, $requester->fresh());

        $instance = $requisition->refresh()->workflowInstance;

        // The Department Head stage carries a 24h SLA, so a due time was set.
        $this->assertNotNull($instance->current_stage_due_at);
        $this->assertSame(24, (int) $instance->currentStage->sla_hours);
        $this->assertFalse($instance->isOverdue());

        // Once the SLA lapses, the reminder goes out.
        $instance->forceFill(['current_stage_due_at' => Wat::now()->subHours(2)])->save();

        $this->assertTrue($instance->refresh()->isOverdue());

        AppNotification::query()->delete();

        $sent = app(WorkflowEngine::class)->sendOverdueReminders();

        $this->assertGreaterThanOrEqual(1, $sent);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $deptHead->id,
            'type' => 'approval.overdue',
        ]);

        // A workflow whose reminder setting is "never" sends nothing.
        $instance->workflow->forceFill([
            'options' => array_merge($instance->workflow->options ?? [], ['overdue_reminder' => 'never']),
        ])->save();

        AppNotification::query()->delete();

        $this->assertSame(0, app(WorkflowEngine::class)->sendOverdueReminders());
    }

    /** BR-5 / NOTIF-3 — an automatic follow-up notifies the extension team. */
    public function test_the_extension_team_is_notified_when_a_followup_opens(): void
    {
        $world = $this->makeMilkWorld();

        $extensionOfficer = $this->makeUser('Notified Extension Officer');
        $this->assignRole(
            $extensionOfficer,
            'Community Engagement Officer',
            ScopeType::Communities,
            null,
            Community::query()->pluck('id')->all(),
        );

        $agent = $this->makeUser('Notif Agent');
        $this->assignRole($agent, 'Collection Agent', ScopeType::Point, $world['pointA']->id);
        $this->actingAs($agent->fresh());

        $reason = RejectionReason::query()->where('code', 'REJ-ADU')->firstOrFail();

        foreach ([2, 1, 0] as $daysAgo) {
            app(DeliveryService::class)->record($world['pointA'], $world['farmer'], [
                'litres_presented' => '20.00',
                'litres_rejected' => '4.00',
                'rejection_reason_id' => $reason->id,
                'delivered_at' => Wat::todayAt(6, 0)->subDays($daysAgo),
            ], $agent->fresh());
        }

        $this->assertDatabaseHas('notifications', [
            'user_id' => $extensionOfficer->id,
            'type' => 'quality.followup_opened',
        ]);

        // The agent who recorded it is not on the extension team, so is not told.
        $this->assertDatabaseMissing('notifications', [
            'user_id' => $agent->id,
            'type' => 'quality.followup_opened',
        ]);
    }

    /** NOTIF-3 — "role or permission changed" reaches the affected users. */
    public function test_a_role_change_notifies_its_holders(): void
    {
        $admin = $this->makeUser('Notif Admin');
        $this->assignRole($admin, 'System Administrator');

        $holder = $this->makeUser('Notified Holder');
        $this->assignRole($holder, 'Inventory Officer', ScopeType::Network);

        $this->actingAs($admin->fresh());

        $role = Role::query()->where('name', 'Inventory Officer')->firstOrFail();
        $extra = Permission::query()
            ->where('resource_key', 'shop.sales')->where('action', 'view')->firstOrFail();

        app(RoleAdminService::class)->syncPermissions(
            $role,
            $role->permissions->pluck('id')->push($extra->id)->all(),
            $admin->fresh(),
        );

        $this->assertDatabaseHas('notifications', [
            'user_id' => $holder->id,
            'type' => 'role.changed',
        ]);
    }

    /** USER-2 — there is no notification path to a farmer. */
    public function test_user2_farmers_receive_nothing(): void
    {
        $world = $this->makeMilkWorld();

        // A farmer is not Notifiable, has no user_id, and no notification row can
        // name one: the column is a foreign key to `users`.
        $this->assertFalse(
            in_array(Notifiable::class, class_uses_recursive($world['farmer']), true),
        );

        $this->assertSame(0, AppNotification::query()->count());

        // The notifications table cannot even reference a farmer id that is not a user.
        $this->expectException(QueryException::class);

        AppNotification::query()->create([
            'user_id' => 999_999,
            'type' => 'signin.new_device',
            'title' => 'Should be impossible',
        ]);
    }
}
