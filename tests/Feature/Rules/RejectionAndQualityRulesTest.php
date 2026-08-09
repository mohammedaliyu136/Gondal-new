<?php

namespace Tests\Feature\Rules;

use App\Authorization\ScopeType;
use App\Exceptions\AccessDeniedException;
use App\Exceptions\RuleViolationException;
use App\Models\AuditEntry;
use App\Models\Consignment;
use App\Models\Delivery;
use App\Models\Grade;
use App\Models\Permission;
use App\Models\QualityFollowup;
use App\Models\QualityTestDefinition;
use App\Models\RejectionReason;
use App\Models\Role;
use App\Models\User;
use App\Services\Milk\ConsignmentService;
use App\Services\Milk\DeliveryService;
use App\Services\Milk\QualityFollowupService;
use App\Support\Settings;
use App\Support\Wat;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\GondalTestCase;

/** §7.1 — rejection and quality. */
class RejectionAndQualityRulesTest extends GondalTestCase
{
    /**
     * BR-1 — "Milk may be rejected only for a reason present in
     * rejection_reasons and enabled for that stage. Free-text reasons are never
     * accepted."
     */
    public function test_br1_rejection_requires_a_configured_reason(): void
    {
        $world = $this->makeMilkWorld();
        $agent = $this->makeUser('Sani Bello');
        $this->assignRole($agent, 'Collection Agent', ScopeType::Point, $world['pointA']->id);
        $this->actingAs($agent);

        $this->expectException(RuleViolationException::class);
        $this->expectExceptionMessage('Rejected volume needs a reason from the configured list.');

        app(DeliveryService::class)->record($world['pointA'], $world['farmer'], [
            'litres_presented' => '20.00',
            'litres_rejected' => '5.00',
            'rejection_reason_id' => null,
            'delivered_at' => Wat::todayAt(6, 20),
        ], $agent);
    }

    /** BR-1 — a reason not enabled for the POINT stage is refused there. */
    public function test_br1_reason_must_be_enabled_for_the_stage(): void
    {
        $world = $this->makeMilkWorld();
        $agent = $this->makeUser('Stage Agent');
        $this->assignRole($agent, 'Collection Agent', ScopeType::Point, $world['pointA']->id);
        $this->actingAs($agent);

        // The administrator disables this reason at the point stage.
        $reason = RejectionReason::query()->where('code', 'REJ-ADU')->firstOrFail();
        $reason->forceFill(['available_at_point' => false])->save();

        try {
            app(DeliveryService::class)->record($world['pointA'], $world['farmer'], [
                'litres_presented' => '20.00',
                'litres_rejected' => '5.00',
                'rejection_reason_id' => $reason->id,
                'delivered_at' => Wat::todayAt(6, 20),
            ], $agent);

            $this->fail('A reason disabled at the point stage should be refused.');
        } catch (RuleViolationException $exception) {
            $this->assertSame('BR-1', $exception->ruleId);
            $this->assertStringContainsString('not enabled for collection points', $exception->getMessage());
        }
    }

    /**
     * BR-2 — "Rejected volume is excluded from farmer payment and from transport
     * fee calculation." It never reaches litres_accepted, which is what payment
     * and transport are computed from.
     */
    public function test_br2_rejected_volume_is_excluded_from_accepted_litres(): void
    {
        $world = $this->makeMilkWorld();
        $agent = $this->makeUser('Exclusion Agent');
        $this->assignRole($agent, 'Collection Agent', ScopeType::Point, $world['pointA']->id);
        $this->actingAs($agent);

        $delivery = app(DeliveryService::class)->record($world['pointA'], $world['farmer'], [
            'litres_presented' => '34.00',
            'litres_rejected' => '6.00',
            'rejection_reason_id' => RejectionReason::query()->where('code', 'REJ-ADU')->value('id'),
            // Before the 07:00 cut-off, so BR-3 is not what this test measures.
            'delivered_at' => Wat::todayAt(6, 20),
        ], $agent);

        $this->assertSame('28.00', (string) $delivery->litres_accepted);
        $this->assertTrue(RejectionReason::query()->where('code', 'REJ-ADU')->value('excluded_from_payment'));
    }

    /**
     * BR-3 — "A delivery arriving after its point's cutoff_time may only be
     * recorded as rejected with reason `late`, or accepted with an explicit
     * supervisor override that is logged."
     */
    public function test_br3_late_delivery_without_override_is_refused(): void
    {
        $world = $this->makeMilkWorld();
        $agent = $this->makeUser('Late Agent');
        $this->assignRole($agent, 'Collection Agent', ScopeType::Point, $world['pointA']->id);
        $this->actingAs($agent);

        try {
            app(DeliveryService::class)->record($world['pointA'], $world['farmer'], [
                'litres_presented' => '20.00',
                'litres_rejected' => '0.00',
                // The point's cut-off is the 07:00 default from Settings.
                'delivered_at' => Wat::todayAt(9, 15),
            ], $agent);

            $this->fail('A late delivery with no override should be refused.');
        } catch (RuleViolationException $exception) {
            $this->assertSame('BR-3', $exception->ruleId);
        }
    }

    /** BR-3 — full rejection for the cut-off reason is the accepted route. */
    public function test_br3_late_delivery_may_be_rejected_in_full_for_the_cutoff_reason(): void
    {
        $world = $this->makeMilkWorld();
        $agent = $this->makeUser('Cutoff Agent');
        $this->assignRole($agent, 'Collection Agent', ScopeType::Point, $world['pointA']->id);
        $this->actingAs($agent);

        // BR-3's "late" reason is identified by the administrator's flag, not by
        // matching a code (§18.7).
        $cutoffReason = RejectionReason::cutoffBreach();
        $this->assertNotNull($cutoffReason, 'A reason must be marked as the cut-off breach.');

        $delivery = app(DeliveryService::class)->record($world['pointA'], $world['farmer'], [
            'litres_presented' => '20.00',
            'litres_rejected' => '20.00',
            'rejection_reason_id' => $cutoffReason->id,
            'delivered_at' => Wat::todayAt(9, 15),
        ], $agent);

        $this->assertSame(Delivery::STATUS_REJECTED, $delivery->status);
        $this->assertTrue($delivery->was_after_cutoff);
        $this->assertSame('0.00', (string) $delivery->litres_accepted);
    }

    /** BR-3 — an override is accepted only with a written reason, and is logged. */
    public function test_br3_supervisor_override_requires_a_logged_reason(): void
    {
        $world = $this->makeMilkWorld();
        $supervisor = $this->makeUser('Override Supervisor');
        $this->assignRole($supervisor, 'Milk Collection Supervisor');
        $this->grantCutoffOverride('Milk Collection Supervisor');
        $this->actingAs($supervisor);

        // Without a reason: refused.
        try {
            app(DeliveryService::class)->record($world['pointA'], $world['farmer'], [
                'litres_presented' => '20.00',
                'delivered_at' => Wat::todayAt(8, 30),
                'cutoff_override' => true,
            ], $supervisor);

            $this->fail('An override with no written reason should be refused.');
        } catch (RuleViolationException $exception) {
            $this->assertSame('BR-3', $exception->ruleId);
        }

        // With a reason: accepted, attributed and audited.
        $delivery = app(DeliveryService::class)->record($world['pointA'], $world['farmer'], [
            'litres_presented' => '20.00',
            'delivered_at' => Wat::todayAt(8, 30),
            'cutoff_override' => true,
            'cutoff_override_reason' => 'Rider delayed by a road closure; milk still cold.',
        ], $supervisor);

        $this->assertSame($supervisor->id, $delivery->cutoff_override_by_user_id);
        $this->assertDatabaseHas('audit_entries', [
            'subject_type' => Delivery::class,
            'subject_id' => $delivery->id,
            'event_type' => 'data_create',
        ]);
    }

    /**
     * BR-3 — "accepted with an explicit SUPERVISOR override that is logged."
     *
     * The override was logged and attributed and never authorised: guardCutoff()
     * asked only that the boolean was set and the reason was non-blank, then
     * stamped the recording user's own id into cutoff_override_by_user_id. A
     * Collection Agent scoped to one point could take 09:45 milk against a 07:00
     * cut-off by typing a sentence, on the web, on the REST API and through the
     * offline sync alike — the person carrying the milk authorising themself.
     */
    public function test_br3_the_cutoff_override_is_not_self_service(): void
    {
        $world = $this->makeMilkWorld();
        $this->grantCutoffOverride('Milk Collection Supervisor');

        $agent = $this->makeUser('Self Serving Agent');
        $this->assignRole($agent, 'Collection Agent', ScopeType::Point, $world['pointA']->id);
        $this->actingAs($agent);

        $late = [
            'litres_presented' => '20.00',
            'delivered_at' => Wat::todayAt(9, 45),
            'cutoff_override' => true,
            'cutoff_override_reason' => 'Agent said it was fine.',
        ];

        try {
            app(DeliveryService::class)->record($world['pointA'], $world['farmer'], $late, $agent);

            $this->fail('An agent must not be able to authorise their own cut-off override.');
        } catch (AccessDeniedException $exception) {
            $this->assertSame('milk.deliveries.cutoff_override', $exception->permissionKey);
            $this->assertSame(AccessDeniedException::REASON_PERMISSION, $exception->reason);
        }

        // BR-34 / AUDIT-5 — the refusal is on the record, with a quotable reference.
        $this->assertDatabaseHas('audit_entries', ['event_type' => 'blocked_access']);
        $this->assertSame(0, Delivery::withoutDataScope()->count(), 'The late milk was not recorded.');

        // The same request from a holder of the permission goes through.
        $supervisor = $this->makeUser('Actual Supervisor');
        $this->assignRole($supervisor, 'Milk Collection Supervisor');
        $this->actingAs($supervisor);

        $delivery = app(DeliveryService::class)->record($world['pointA'], $world['farmer'], $late, $supervisor);

        $this->assertTrue($delivery->was_after_cutoff);
        $this->assertSame($supervisor->id, $delivery->cutoff_override_by_user_id);
    }

    /**
     * BR-3 — the cut-off comparison is only meaningful against a real, recent day.
     *
     * `delivered_at` was validated as nothing more than a date on all three
     * surfaces, so milk could be dated into next month — where isAfterCutoff()
     * judges a morning nobody has lived through — or back into a day already
     * dispatched, reconciled and reported.
     */
    public function test_br3_a_delivery_cannot_be_dated_into_the_future_or_a_closed_day(): void
    {
        $world = $this->makeMilkWorld();
        $agent = $this->makeUser('Time Travelling Agent');
        $this->assignRole($agent, 'Collection Agent', ScopeType::Point, $world['pointA']->id);
        $this->actingAs($agent);

        foreach (['tomorrow' => 1, 'next month' => 30] as $label => $daysAhead) {
            try {
                app(DeliveryService::class)->record($world['pointA'], $world['farmer'], [
                    'litres_presented' => '20.00',
                    'delivered_at' => Wat::today()->addDays($daysAhead)->setTime(6, 0),
                ], $agent);

                $this->fail("A delivery dated {$label} should be refused.");
            } catch (RuleViolationException $exception) {
                $this->assertSame('BR-3', $exception->ruleId);
                $this->assertSame('delivered_at', $exception->field);
            }
        }

        // §9 / §18.7 — how far back is a setting, so the refusal moves with it.
        Settings::put(['milk.delivery_backdate_limit_days' => 3]);

        try {
            app(DeliveryService::class)->record($world['pointA'], $world['farmer'], [
                'litres_presented' => '20.00',
                'delivered_at' => Wat::today()->subDays(10)->setTime(6, 0),
            ], $agent);

            $this->fail('A delivery backdated past the configured limit should be refused.');
        } catch (RuleViolationException $exception) {
            $this->assertSame('BR-3', $exception->ruleId);
            $this->assertStringContainsString('3 days', $exception->getMessage());
        }

        // Inside the window, late data entry is still allowed.
        $delivery = app(DeliveryService::class)->record($world['pointA'], $world['farmer'], [
            'litres_presented' => '20.00',
            'delivered_at' => Wat::today()->subDays(2)->setTime(6, 0),
        ], $agent);

        $this->assertSame('20.00', (string) $delivery->litres_accepted);
    }

    /**
     * BR-1 — "Milk may be rejected only for a reason present in
     * rejection_reasons", read the other way round: a reason with nothing
     * rejected is not a rejection.
     *
     * The row was accepted with `status = accepted` and the reason attached, and
     * BR-5's counter — which keyed on rejection_reason_id alone — counted it. A
     * clerk who chose "adulteration" and then corrected the volume to zero
     * silently pre-loaded the threshold, so the farmer's first genuine rejection
     * arrived at count 3 and sent the extension team to their compound.
     */
    public function test_br1_a_reason_with_nothing_rejected_is_not_a_rejection(): void
    {
        $world = $this->makeMilkWorld();
        $agent = $this->makeUser('Zero Reject Agent');
        $this->assignRole($agent, 'Collection Agent', ScopeType::Point, $world['pointA']->id);
        $this->actingAs($agent);

        $reason = RejectionReason::query()->where('code', 'REJ-ADU')->firstOrFail();

        try {
            app(DeliveryService::class)->record($world['pointA'], $world['farmer'], [
                'litres_presented' => '18.00',
                'litres_rejected' => '0.00',
                'rejection_reason_id' => $reason->id,
                'delivered_at' => Wat::todayAt(6, 20),
            ], $agent);

            $this->fail('A rejection reason with nothing rejected should be refused.');
        } catch (RuleViolationException $exception) {
            $this->assertSame('BR-1', $exception->ruleId);
            $this->assertSame('litres_rejected', $exception->field);
        }

        $this->assertSame(0, Delivery::withoutDataScope()->count());
    }

    /**
     * BR-5 — the threshold counts rejections, not reason codes.
     *
     * The guard above stops new rows carrying the pattern; rows written before it
     * existed are still in the table, and countRecentRejections() counted them.
     */
    public function test_br5_zero_volume_rejections_do_not_count_toward_the_threshold(): void
    {
        $world = $this->makeMilkWorld();
        $agent = $this->makeUser('Historical Agent');
        $this->assignRole($agent, 'Collection Agent', ScopeType::Point, $world['pointA']->id);
        $this->actingAs($agent);

        $reason = RejectionReason::query()->where('code', 'REJ-ADU')->firstOrFail();

        // Two rows of the kind the BR-1 guard now refuses, written the only way
        // they can still arrive: already in the table.
        $this->asSystem(function () use ($world, $reason, $agent): void {
            foreach ([1, 2] as $n) {
                Delivery::query()->create([
                    'reference' => 'DEL-LEGACY-'.$n,
                    'collection_point_id' => $world['pointA']->id,
                    'farmer_id' => $world['farmer']->id,
                    'recorded_by_user_id' => $agent->id,
                    'delivered_at' => Wat::instant(Wat::today()->subDays($n)->setTime(6, 0)),
                    'litres_presented' => '18.00',
                    'litres_rejected' => '0.00',
                    'litres_accepted' => '18.00',
                    'litres_adjusted' => '0.00',
                    'litres_payable' => '18.00',
                    'rejection_reason_id' => $reason->id,
                    'status' => Delivery::STATUS_ACCEPTED,
                ]);
            }
        });

        $this->assertSame(
            0,
            app(QualityFollowupService::class)
                ->countRecentRejections($world['farmer'], $reason),
            'A reason with no rejected litres is not a rejection.',
        );

        // One genuine rejection counts once, and does not trip a 3-in-30 threshold.
        app(DeliveryService::class)->record($world['pointA'], $world['farmer'], [
            'litres_presented' => '18.00',
            'litres_rejected' => '5.00',
            'rejection_reason_id' => $reason->id,
            'delivered_at' => Wat::todayAt(6, 20),
        ], $agent);

        $this->assertSame(
            1,
            app(QualityFollowupService::class)
                ->countRecentRejections($world['farmer'], $reason),
        );
        $this->assertSame(0, QualityFollowup::query()->count(), 'No visit is scheduled on a data-entry artefact.');
    }

    /**
     * BR-4 — "Grade is assigned at consignment confirmation by a user holding
     * milk.grade.create, and only after all configured quality tests are
     * recorded."
     */
    public function test_br4_grade_requires_every_configured_quality_test(): void
    {
        $world = $this->makeMilkWorld();
        $officer = $this->makeUser('Halima Yusuf');
        $this->assignRole($officer, 'Milk Collection Officer', ScopeType::Center, $world['centerA']->id);
        $this->actingAs($officer);

        $consignment = $this->dispatchConsignment($world, $officer, '100.00');
        $grade = Grade::query()->where('code', 'GRD-A')->firstOrFail();

        try {
            app(ConsignmentService::class)->confirm($consignment, ['grade_id' => $grade->id], $officer);
            $this->fail('Grading without the required tests should be refused.');
        } catch (RuleViolationException $exception) {
            $this->assertSame('BR-4', $exception->ruleId);
            $this->assertStringContainsString('Record every required quality test', $exception->getMessage());
        }

        // Record them all, and the same call now succeeds.
        foreach (QualityTestDefinition::query()->required()->get() as $definition) {
            app(ConsignmentService::class)->recordQualityTest(
                $consignment,
                $definition,
                $definition->code === 'DENSITY' ? '1.031' : ($definition->code === 'TEMPERATURE' ? '18' : '1'),
                $officer,
            );
        }

        $confirmed = app(ConsignmentService::class)->confirm(
            $consignment->refresh(),
            ['grade_id' => $grade->id],
            $officer,
        );

        $this->assertSame($grade->id, $confirmed->grade_id);
    }

    /** BR-4 — grading also needs the milk.grade.create permission specifically. */
    public function test_br4_grade_requires_the_grade_permission(): void
    {
        $world = $this->makeMilkWorld();

        // The Collection Agent role can dispatch but is not granted milk.grade.create.
        $agent = $this->makeUser('Ungraded Agent');
        $this->assignRole($agent, 'Collection Agent', ScopeType::Point, $world['pointA']->id);
        // Give them confirmation rights so only the GRADE permission is missing.
        $this->assignRole($agent, 'Milk Collection Officer', ScopeType::Center, $world['centerA']->id);
        $this->actingAs($agent);

        $this->assertTrue($agent->hasPermission('milk.grade.create'));

        // Now strip the grade permission from the officer role to isolate BR-4.
        $officerRole = Role::query()->where('name', 'Milk Collection Officer')->firstOrFail();
        $gradeCreate = Permission::query()
            ->where('resource_key', 'milk.grade')->where('action', 'create')->firstOrFail();
        $officerRole->permissions()->detach($gradeCreate->id);
        $agent->forgetAccessMemo();

        $this->assertFalse($agent->fresh()->hasPermission('milk.grade.create'));

        $consignment = $this->dispatchConsignment($world, $agent, '80.00');

        try {
            app(ConsignmentService::class)->confirm($consignment, [
                'grade_id' => Grade::query()->where('code', 'GRD-A')->value('id'),
            ], $agent->fresh());

            $this->fail('Grading without milk.grade.create should be refused.');
        } catch (AccessDeniedException $exception) {
            /*
             * A missing permission is an ACCESS denial, not a business-rule
             * breach: SCR-1's populated access-denied screen and BR-34's audit
             * entry, not a sentence in a form. The check used to be an unscoped
             * `hasPermission` in the service, which also meant an officer scoped
             * to one centre could grade at another — the record-level half of
             * ARCH-4 was simply not being asked.
             */
            $this->assertSame('milk.grade.create', $exception->permissionKey);
        }
    }

    /**
     * ARCH-4 — grading is scoped, not merely permitted.
     *
     * Holding `milk.grade.create` says a person may grade; it does not say WHICH
     * consignments. An officer covering one centre must not be able to grade a
     * consignment that arrived at another.
     */
    public function test_br4_grading_is_refused_outside_the_officers_own_center(): void
    {
        $world = $this->makeMilkWorld();

        $agent = $this->makeUser('Dispatching Agent');
        $this->assignRole($agent, 'Collection Agent', ScopeType::Point, $world['pointA']->id);
        $this->actingAs($agent);

        $consignment = $this->dispatchConsignment($world, $agent, '90.00');

        // An officer who holds the grant, but covers the OTHER centre.
        $outsider = $this->makeUser('Other Center Officer');
        $this->assignRole($outsider, 'Milk Collection Officer', ScopeType::Center, $world['centerB']->id);
        $this->actingAs($outsider);

        $this->assertTrue($outsider->hasPermission('milk.grade.create'));

        $this->expectException(AccessDeniedException::class);

        app(ConsignmentService::class)->confirm($consignment, [
            'grade_id' => Grade::query()->where('code', 'GRD-A')->value('id'),
        ], $outsider->fresh());
    }

    /**
     * BR-5 — "When a farmer accumulates followup_threshold rejections of the same
     * reason within followup_window_days, the system opens a quality_followup
     * automatically and notifies the extension team. Defaults: adulteration
     * 3-in-30, spoilage 3-in-30, late 2-in-30."
     */
    public function test_br5_third_adulteration_rejection_opens_a_followup_automatically(): void
    {
        $world = $this->makeMilkWorld();
        $agent = $this->makeUser('Followup Agent');
        $this->assignRole($agent, 'Collection Agent', ScopeType::Point, $world['pointA']->id);
        $this->actingAs($agent);

        $reason = RejectionReason::query()->where('code', 'REJ-ADU')->firstOrFail();
        $this->assertSame(3, (int) $reason->followup_threshold);
        $this->assertSame(30, (int) $reason->followup_window_days);

        // Two rejections: no follow-up yet.
        foreach ([1, 2] as $n) {
            app(DeliveryService::class)->record($world['pointA'], $world['farmer'], [
                'litres_presented' => '20.00',
                'litres_rejected' => '4.00',
                'rejection_reason_id' => $reason->id,
                'delivered_at' => Wat::today()->subDays(3 - $n)->setTime(6, 0),
            ], $agent);
        }

        $this->assertSame(0, QualityFollowup::query()->count(), 'Two rejections must not open a follow-up.');

        // The third, inside the window, opens one.
        app(DeliveryService::class)->record($world['pointA'], $world['farmer'], [
            'litres_presented' => '20.00',
            'litres_rejected' => '4.00',
            'rejection_reason_id' => $reason->id,
            'delivered_at' => Wat::todayAt(6, 0),
        ], $agent);

        $followup = QualityFollowup::query()->first();

        $this->assertNotNull($followup, 'The third rejection must open a follow-up.');
        $this->assertSame(QualityFollowup::STATUS_OPEN, $followup->status);
        $this->assertSame($world['farmer']->id, (int) $followup->subject_id);
        $this->assertSame(3, (int) $followup->trigger_count);
        // The threshold in force is copied, so a later retune cannot rewrite it.
        $this->assertSame(3, (int) $followup->threshold);
        $this->assertSame(30, (int) $followup->window_days);
    }

    /** BR-5 — a rejection outside the window does not count towards the threshold. */
    public function test_br5_rejections_outside_the_window_do_not_count(): void
    {
        $world = $this->makeMilkWorld();
        $agent = $this->makeUser('Window Agent');
        $this->assignRole($agent, 'Collection Agent', ScopeType::Point, $world['pointA']->id);
        $this->actingAs($agent);

        $reason = RejectionReason::query()->where('code', 'REJ-ADU')->firstOrFail();

        /*
         * BR-3's backdating backstop would refuse a 40-day-old delivery, which is
         * exactly what it is for. This test is arranging history rather than
         * recording milk, so it turns the backstop off the way an administrator
         * would — through the setting, not by writing the rows behind the service.
         */
        Settings::put(['milk.delivery_backdate_limit_days' => 0]);

        // Two of the three are 40 and 35 days old — outside the 30-day window.
        foreach ([40, 35, 0] as $daysAgo) {
            app(DeliveryService::class)->record($world['pointA'], $world['farmer'], [
                'litres_presented' => '20.00',
                'litres_rejected' => '4.00',
                'rejection_reason_id' => $reason->id,
                'delivered_at' => Wat::today()->subDays($daysAgo)->setTime(6, 0),
            ], $agent);
        }

        $this->assertSame(0, QualityFollowup::query()->count());
    }

    /**
     * BR-3 / PERM-1 — grant `milk.deliveries.cutoff_override` to a seeded role.
     *
     * The permission row itself arrives by migration, as PERM-1 requires, and is
     * therefore present here. The GRANT is not: RoleSeeder rewrites
     * `permission_role` from its own catalogue on every seed, and GondalTestCase
     * runs it — so until the catalogue carries `'milk.deliveries' => [...,
     * 'cutoff_override']` for Milk Collection Supervisor and Milk Collection
     * Officer, this is how a test gives a supervisor the authority the seeded
     * system is meant to give them. Delete this helper when the catalogue does.
     */
    private function grantCutoffOverride(string $roleName): void
    {
        DB::table('permission_role')->insertOrIgnore([
            'role_id' => DB::table('roles')->where('name', $roleName)->value('id'),
            'permission_id' => DB::table('permissions')
                ->where('resource_key', 'milk.deliveries')
                ->where('action', 'cutoff_override')
                ->value('id'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** Dispatch a single-delivery consignment, for the grading tests. */
    private function dispatchConsignment(array $world, $actor, string $litres)
    {
        $delivery = app(DeliveryService::class)->record($world['pointA'], $world['farmer'], [
            'litres_presented' => $litres,
            'delivered_at' => Wat::todayAt(6, 0),
        ], $actor);

        return app(ConsignmentService::class)->dispatch(
            $world['pointA'],
            [$delivery->id],
            ['dispatched_at' => Wat::todayAt(6, 45)],
            $actor,
        );
    }

    /**
     * BR-4 — the re-grade control break.
     *
     * A clerk assigns grades all morning; changing one that is already assigned
     * moves money for milk already accepted, so it needs `milk.grade.edit`, which
     * the clerk does not hold.
     */
    public function test_br4_a_clerk_can_grade_but_cannot_regrade(): void
    {
        $world = $this->makeMilkWorld();

        $officer = $this->makeUser('Grading Clerk');
        $this->assignRole($officer, 'Milk Collection Officer', ScopeType::Center, $world['centerA']->id);
        $this->actingAs($officer);

        // The break, stated as the two grants: assign yes, change no.
        $this->assertTrue($officer->hasPermission('milk.grade.create'));
        $this->assertFalse($officer->hasPermission('milk.grade.edit'));

        $consignment = $this->gradedConsignment($world, $officer, 'GRD-A');
        $gradeB = Grade::query()->where('code', 'GRD-B')->firstOrFail();

        $this->expectException(AccessDeniedException::class);

        app(ConsignmentService::class)->regrade($consignment, $gradeB, 'Lab re-test', $officer);
    }

    /**
     * BR-4 — a supervisor CAN re-grade, and the change is recorded with who,
     * what and why, so the exceptions list has something to show.
     */
    public function test_br4_a_supervisor_can_regrade_and_it_is_recorded(): void
    {
        $world = $this->makeMilkWorld();

        $officer = $this->makeUser('Assigning Clerk');
        $this->assignRole($officer, 'Milk Collection Officer', ScopeType::Center, $world['centerA']->id);
        $this->actingAs($officer);

        $consignment = $this->gradedConsignment($world, $officer, 'GRD-A');
        $originalRate = (int) $consignment->rate_per_litre_minor;

        $supervisor = $this->makeUser('Quality Supervisor');
        $this->assignRole($supervisor, 'Milk Collection Supervisor');
        $this->actingAs($supervisor);

        $gradeB = Grade::query()->where('code', 'GRD-B')->firstOrFail();

        $regraded = app(ConsignmentService::class)->regrade(
            $consignment->refresh(), $gradeB, 'Lab re-test returned a lower fat reading.', $supervisor,
        );

        $this->assertSame($gradeB->id, (int) $regraded->grade_id);
        $this->assertNotNull($regraded->regraded_at);
        $this->assertSame($supervisor->id, (int) $regraded->regraded_by_user_id);
        $this->assertSame('Lab re-test returned a lower fat reading.', $regraded->regrade_reason);

        // BR-13/BR-14 — the new rate is the one in force on the CONFIRMATION day.
        $expected = $gradeB->rateOn($regraded->confirmed_at);
        $this->assertNotNull($expected);
        $this->assertSame((int) $expected->rate_per_litre_minor, (int) $regraded->rate_per_litre_minor);
        $this->assertNotSame($originalRate, (int) $regraded->rate_per_litre_minor);

        // The audit entry names both grades, so the change is legible without
        // reading two rows and inferring the difference.
        $entry = AuditEntry::query()->latest('id')->first();
        $this->assertNotNull($entry);
        $this->assertStringContainsString('re-graded', $entry->summary);
    }

    /** BR-4 — a re-grade without a reason is refused; the list is read for the reasons. */
    public function test_br4_a_regrade_needs_a_reason(): void
    {
        $world = $this->makeMilkWorld();

        $officer = $this->makeUser('Reasonless Clerk');
        $this->assignRole($officer, 'Milk Collection Officer', ScopeType::Center, $world['centerA']->id);
        $this->actingAs($officer);

        $consignment = $this->gradedConsignment($world, $officer, 'GRD-A');

        $supervisor = $this->makeUser('Reasonless Supervisor');
        $this->assignRole($supervisor, 'Milk Collection Supervisor');
        $this->actingAs($supervisor);

        $gradeB = Grade::query()->where('code', 'GRD-B')->firstOrFail();

        try {
            app(ConsignmentService::class)->regrade($consignment->refresh(), $gradeB, '   ', $supervisor);
            $this->fail('A re-grade with no reason should be refused.');
        } catch (RuleViolationException $exception) {
            $this->assertSame('BR-4', $exception->ruleId);
            $this->assertStringContainsString('needs a reason', $exception->getMessage());
        }
    }

    /** BR-4 — re-grading something that was never graded is not a re-grade. */
    public function test_br4_an_ungraded_consignment_cannot_be_regraded(): void
    {
        $world = $this->makeMilkWorld();

        $supervisor = $this->makeUser('Early Supervisor');
        $this->assignRole($supervisor, 'Milk Collection Supervisor');
        $this->actingAs($supervisor);

        $consignment = $this->dispatchConsignment($world, $supervisor, '100.00');
        $grade = Grade::query()->where('code', 'GRD-A')->firstOrFail();

        try {
            app(ConsignmentService::class)->regrade($consignment, $grade, 'No grade yet', $supervisor);
            $this->fail('An ungraded consignment cannot be re-graded.');
        } catch (RuleViolationException $exception) {
            $this->assertSame('BR-4', $exception->ruleId);
            $this->assertStringContainsString('no grade yet', $exception->getMessage());
        }
    }

    /**
     * BR-4 — "a grade may be assigned only after every configured quality test
     * is recorded." One test, one answer.
     *
     * `recordQualityTest()` upserts on (consignment_id,
     * quality_test_definition_id) and the schema enforced no such key — only a
     * non-unique index on (consignment_id, test_type). Two submissions racing
     * each other both missed the SELECT and both INSERTed, and BR-4's
     * completeness check asks only whether each required definition has *a*
     * recorded test, so nothing surfaced the contradiction: the consignment had
     * two answers to "did it pass the alcohol test?" and the screen rendered
     * whichever the ordering returned. The confirmation form posts one row per
     * test from a single form identified by the clicked button, so a second
     * click on a slow connection is the expected interaction, not an edge case.
     */
    public function test_br4_a_consignment_cannot_hold_two_answers_to_one_quality_test(): void
    {
        $world = $this->makeMilkWorld();
        $officer = $this->makeUser('Duplicate Test Officer');
        $this->assignRole($officer, 'Milk Collection Officer', ScopeType::Center, $world['centerA']->id);
        $this->actingAs($officer);

        $consignment = $this->dispatchConsignment($world, $officer, '100.00');
        $definition = QualityTestDefinition::query()->required()->firstOrFail();

        // Two submissions of the same test, sequentially: one row, latest wins.
        app(ConsignmentService::class)->recordQualityTest($consignment, $definition, '1.031', $officer);
        app(ConsignmentService::class)->recordQualityTest($consignment, $definition, '1.029', $officer);

        $rows = DB::table('quality_tests')
            ->where('consignment_id', $consignment->id)
            ->where('quality_test_definition_id', $definition->id)
            ->whereNull('deleted_at')
            ->get();

        $this->assertCount(1, $rows);
        $this->assertSame('1.029', $rows->first()->reading);

        // And the database refuses the second row the race would have written,
        // rather than leaving the service's upsert key unbacked.
        $this->expectException(QueryException::class);

        DB::table('quality_tests')->insert([
            'consignment_id' => $consignment->id,
            'quality_test_definition_id' => $definition->id,
            'test_type' => $definition->code,
            'reading' => '1.020',
            'passed' => false,
            'created_at' => Wat::now(),
            'updated_at' => Wat::now(),
        ]);
    }

    /**
     * A confirmed consignment carrying the named grade, built the way the
     * application builds one — through the service, with its quality tests.
     */
    private function gradedConsignment(array $world, User $officer, string $gradeCode): Consignment
    {
        $consignment = $this->dispatchConsignment($world, $officer, '100.00');

        foreach (QualityTestDefinition::query()->required()->get() as $definition) {
            app(ConsignmentService::class)->recordQualityTest(
                $consignment,
                $definition,
                $definition->code === 'DENSITY' ? '1.031' : ($definition->code === 'TEMPERATURE' ? '18' : '1'),
                $officer,
            );
        }

        $grade = Grade::query()->where('code', $gradeCode)->firstOrFail();

        return app(ConsignmentService::class)->confirm(
            $consignment->refresh(), ['grade_id' => $grade->id], $officer,
        );
    }
}
