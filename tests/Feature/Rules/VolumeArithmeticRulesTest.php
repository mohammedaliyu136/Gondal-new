<?php

namespace Tests\Feature\Rules;

use App\Authorization\ScopeType;
use App\Exceptions\RuleViolationException;
use App\Models\Adjustment;
use App\Models\AdjustmentReason;
use App\Models\Batch;
use App\Models\Consignment;
use App\Models\Delivery;
use App\Models\DiscrepancyCause;
use App\Models\Grade;
use App\Models\QualityTestDefinition;
use App\Models\RejectionReason;
use App\Services\Milk\AdjustmentService;
use App\Services\Milk\BatchService;
use App\Services\Milk\ConsignmentService;
use App\Services\Milk\DeliveryService;
use App\Support\Settings;
use App\Support\Volume;
use App\Support\Wat;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\GondalTestCase;

/** §7.2 — volume arithmetic. */
class VolumeArithmeticRulesTest extends GondalTestCase
{
    /** BR-6 — "deliveries.litres_accepted = litres_presented − litres_rejected". */
    public function test_br6_accepted_is_presented_minus_rejected(): void
    {
        [$world, $agent] = $this->agentAtPoint();

        $delivery = $this->record($world, $agent, '34.00', '6.00');

        $this->assertSame('28.00', (string) $delivery->litres_accepted);
    }

    /**
     * DM-1 — "Enforce with a database check constraint." Bypassing the service
     * still cannot store a wrong figure.
     */
    public function test_dm1_database_refuses_an_inconsistent_accepted_figure(): void
    {
        [$world, $agent] = $this->agentAtPoint();
        $delivery = $this->record($world, $agent, '34.00', '6.00');

        $this->expectException(QueryException::class);

        DB::table('deliveries')->where('id', $delivery->id)->update(['litres_accepted' => '99.00']);
    }

    /** BR-6 — rejected volume cannot exceed what was presented. */
    public function test_br6_rejected_cannot_exceed_presented(): void
    {
        [$world, $agent] = $this->agentAtPoint();

        try {
            $this->record($world, $agent, '10.00', '12.00');
            $this->fail('Rejecting more than was presented should be refused.');
        } catch (RuleViolationException $exception) {
            $this->assertSame('BR-6', $exception->ruleId);
        }
    }

    /** BR-7 — "consignments.litres_dispatched = Σ litres_accepted of its deliveries". */
    public function test_br7_dispatched_is_the_sum_of_accepted_litres(): void
    {
        [$world, $agent] = $this->agentAtPoint();

        $ids = [
            $this->record($world, $agent, '30.00', '0.00')->id,
            $this->record($world, $agent, '25.50', '5.50')->id,   // 20.00 accepted
            $this->record($world, $agent, '12.25', '0.00')->id,
        ];

        $consignment = app(ConsignmentService::class)->dispatch(
            $world['pointA'],
            $ids,
            ['dispatched_at' => Wat::todayAt(7, 0)],
            $agent,
        );

        // 30.00 + 20.00 + 12.25
        $this->assertSame('62.25', (string) $consignment->litres_dispatched);
    }

    /**
     * DM-2 — "A delivery's consignment_id is null until the agent dispatches.
     * Fully rejected deliveries never receive one."
     */
    public function test_dm2_a_fully_rejected_delivery_cannot_be_dispatched(): void
    {
        [$world, $agent] = $this->agentAtPoint();

        $rejected = $this->record($world, $agent, '15.00', '15.00');
        $this->assertNull($rejected->consignment_id);

        try {
            app(ConsignmentService::class)->dispatch($world['pointA'], [$rejected->id], [], $agent);
            $this->fail('A fully rejected delivery has no volume to dispatch.');
        } catch (RuleViolationException $exception) {
            $this->assertSame('DM-2', $exception->ruleId);
        }
    }

    /** DM-2 — a delivery already on a consignment cannot be dispatched twice. */
    public function test_dm2_a_delivery_cannot_join_two_consignments(): void
    {
        [$world, $agent] = $this->agentAtPoint();
        $delivery = $this->record($world, $agent, '20.00', '0.00');

        app(ConsignmentService::class)->dispatch($world['pointA'], [$delivery->id], [], $agent);

        try {
            app(ConsignmentService::class)->dispatch($world['pointA'], [$delivery->id], [], $agent);
            $this->fail('A delivery already dispatched must not be dispatched again.');
        } catch (RuleViolationException $exception) {
            $this->assertSame('DM-2', $exception->ruleId);
        }
    }

    /**
     * BR-8 — "consignments.litres_confirmed = litres_dispatched + Σ adjustments
     * − litres_rejected_at_center".
     */
    public function test_br8_confirmed_applies_adjustments_and_center_rejection(): void
    {
        [$world, $officer] = $this->officerAtCenter();

        $consignment = $this->dispatch($world, $officer, ['100.00', '50.00']);   // 150.00

        app(AdjustmentService::class)->record(
            $consignment,
            '-14.00',
            AdjustmentReason::query()->where('code', 'ADJ-MEAS')->value('id'),
            'Re-measured at the center.',
            $officer,
        );

        $this->recordTests($consignment, $officer);

        $confirmed = app(ConsignmentService::class)->confirm($consignment->refresh(), [
            'litres_rejected_at_center' => '30.00',
            'rejection_reason_id' => RejectionReason::query()->where('code', 'REJ-ADU')->value('id'),
            'grade_id' => Grade::query()->where('code', 'GRD-A')->value('id'),
        ], $officer);

        // 150.00 − 14.00 − 30.00
        $this->assertSame('106.00', (string) $confirmed->litres_confirmed);
        $this->assertSame(Consignment::STATUS_PARTLY_REJECTED, $confirmed->status);
    }

    /**
     * BR-8 — adjustments and rejection cannot take confirmed volume negative.
     *
     * The adjustment on its own can no longer get here: BR-12 now refuses one
     * that would take dispatched + Σ adjustments below zero at the keyboard, so
     * this arranges the combination that only confirmation can see — a legal
     * adjustment plus a legal centre rejection that together overdraw the
     * consignment.
     */
    public function test_br8_confirmed_cannot_go_negative(): void
    {
        [$world, $officer] = $this->officerAtCenter();
        $consignment = $this->dispatch($world, $officer, ['20.00']);

        app(AdjustmentService::class)->record(
            $consignment,
            '-15.00',
            AdjustmentReason::query()->where('code', 'ADJ-MEAS')->value('id'),
            'Re-measured after decanting; the point over-reported.',
            $officer,
        );

        $this->recordTests($consignment, $officer);

        try {
            // 20 dispatched − 15 adjusted − 10 rejected = −5 L.
            app(ConsignmentService::class)->confirm($consignment->refresh(), [
                'litres_rejected_at_center' => '10.00',
                'rejection_reason_id' => RejectionReason::query()->where('code', 'REJ-ADU')->value('id'),
            ], $officer);

            $this->fail('A negative confirmed volume should be refused.');
        } catch (RuleViolationException $exception) {
            $this->assertSame('BR-8', $exception->ruleId);
        }
    }

    /**
     * BR-12 — an adjustment cannot strand a consignment below zero.
     *
     * Before this guard the magnitude was unchecked at write time and BR-8 caught
     * it only at confirmation — fatally. A 30 L consignment carrying a mistyped
     * −500 L adjustment could never be confirmed, so `isBatchable()` never held:
     * the milk never joined a batch, never reached the factory and never became
     * payable, with no route back that any screen or message mentioned.
     */
    public function test_br12_an_adjustment_cannot_take_a_consignment_below_zero(): void
    {
        [$world, $officer] = $this->officerAtCenter();
        $consignment = $this->dispatch($world, $officer, ['30.00']);

        try {
            app(AdjustmentService::class)->record(
                $consignment,
                '-500.00',
                AdjustmentReason::query()->where('code', 'ADJ-MEAS')->value('id'),
                'Fat fingers on the keypad.',
                $officer,
            );

            $this->fail('An adjustment larger than the consignment should be refused.');
        } catch (RuleViolationException $exception) {
            $this->assertSame('BR-12', $exception->ruleId);
            $this->assertStringContainsString('30 L', $exception->getMessage());
        }

        // Refused at the keyboard means refused entirely: nothing was written, and
        // the consignment is still confirmable.
        $this->assertSame(0, $consignment->adjustments()->count());
        $this->assertSame('0.00', $consignment->refresh()->adjustmentTotal());
    }

    /**
     * BR-9 — "batches.litres_dispatched = Σ litres_confirmed of its consignments.
     * Only confirmed and graded consignments may join a batch."
     */
    public function test_br9_batch_volume_is_the_sum_of_confirmed_consignments(): void
    {
        [$world, $officer] = $this->officerAtCenter();

        $first = $this->confirmedConsignment($world, $officer, ['200.00']);
        $second = $this->confirmedConsignment($world, $officer, ['150.00']);

        $batch = app(BatchService::class)->dispatch(
            $world['centerA'],
            [$first->id, $second->id],
            ['dispatched_at' => Wat::todayAt(8, 30)],
            $officer,
        );

        $this->assertSame('350.00', (string) $batch->litres_dispatched);
    }

    /** BR-9 — an unconfirmed consignment cannot join a batch. */
    public function test_br9_unconfirmed_consignment_cannot_join_a_batch(): void
    {
        [$world, $officer] = $this->officerAtCenter();
        $unconfirmed = $this->dispatch($world, $officer, ['100.00']);

        try {
            app(BatchService::class)->dispatch($world['centerA'], [$unconfirmed->id], [], $officer);
            $this->fail('An unconfirmed consignment must not join a batch.');
        } catch (RuleViolationException $exception) {
            $this->assertSame('BR-9', $exception->ruleId);
            $this->assertStringContainsString('has not been confirmed', $exception->getMessage());
        }
    }

    /** BR-9 — a confirmed but UNGRADED consignment cannot join a batch either. */
    public function test_br9_ungraded_consignment_cannot_join_a_batch(): void
    {
        [$world, $officer] = $this->officerAtCenter();

        $consignment = $this->dispatch($world, $officer, ['100.00']);
        $this->recordTests($consignment, $officer);

        // Confirmed with no grade.
        app(ConsignmentService::class)->confirm($consignment->refresh(), [], $officer);

        try {
            app(BatchService::class)->dispatch($world['centerA'], [$consignment->id], [], $officer);
            $this->fail('An ungraded consignment must not join a batch.');
        } catch (RuleViolationException $exception) {
            $this->assertSame('BR-9', $exception->ruleId);
            $this->assertStringContainsString('no grade assigned', $exception->getMessage());
        }
    }

    /**
     * BR-10 — "batches.discrepancy_litres = litres_received − litres_dispatched
     * (negative for a shortfall)."
     */
    public function test_br10_discrepancy_is_signed(): void
    {
        [$world, $officer] = $this->officerAtCenter();
        $batch = $this->batch($world, $officer, ['1000.00']);

        app(BatchService::class)->reconcile($batch, [
            'litres_received' => '992.00',
            'discrepancy_cause_id' => DiscrepancyCause::query()->where('code', 'DIS-CONT')->value('id'),
        ], $officer);

        $this->assertSame('-8.00', (string) $batch->refresh()->discrepancy_litres);
        $this->assertSame('0.80', $batch->discrepancyPercentage());
        $this->assertSame(Batch::STATUS_RECONCILED, $batch->status);
    }

    /**
     * BR-11 — "If |discrepancy| / litres_dispatched exceeds the configured
     * tolerance (default 1%), the supervisor must supply supervisor_notes before
     * the batch can be released. Reject the write otherwise."
     */
    public function test_br11_release_beyond_tolerance_requires_a_supervisor_note(): void
    {
        [$world, $officer] = $this->officerAtCenter();
        $batch = $this->batch($world, $officer, ['1000.00']);

        // 5% short — well beyond the 1% tolerance.
        app(BatchService::class)->reconcile($batch, [
            'litres_received' => '950.00',
            'discrepancy_cause_id' => DiscrepancyCause::query()->where('code', 'DIS-SPILL')->value('id'),
        ], $officer);

        $batch->refresh();

        $this->assertTrue($batch->exceedsTolerance());
        $this->assertSame(Batch::STATUS_DISCREPANCY, $batch->status);

        try {
            app(BatchService::class)->release($batch, null, $officer);
            $this->fail('Releasing beyond tolerance with no note should be refused.');
        } catch (RuleViolationException $exception) {
            $this->assertSame('BR-11', $exception->ruleId);
        }

        $released = app(BatchService::class)->release(
            $batch->refresh(),
            'Tanker leak confirmed by the driver and the factory gate log.',
            $officer,
        );

        $this->assertSame(Batch::STATUS_RELEASED, $released->status);
    }

    /** BR-11 — the tolerance is a SETTING, so changing it changes the outcome. */
    public function test_br11_tolerance_comes_from_settings_not_from_code(): void
    {
        [$world, $officer] = $this->officerAtCenter();
        $batch = $this->batch($world, $officer, ['1000.00']);

        app(BatchService::class)->reconcile($batch, [
            'litres_received' => '985.00',   // 1.5% short
            'discrepancy_cause_id' => DiscrepancyCause::query()->where('code', 'DIS-MEAS')->value('id'),
        ], $officer);

        $this->assertTrue($batch->refresh()->exceedsTolerance(), 'At a 1% tolerance, 1.5% is beyond it.');

        // The administrator widens the tolerance to 2%.
        Settings::put(['milk.batch_discrepancy_tolerance_pct' => '2.0']);

        $this->assertFalse($batch->refresh()->exceedsTolerance(), 'At a 2% tolerance, 1.5% is inside it.');
        $this->assertSame('2.0', $batch->tolerancePercentage());
    }

    /** BR-11 — a variance beyond tolerance must also name its cause. */
    public function test_br11_variance_beyond_tolerance_requires_a_cause(): void
    {
        [$world, $officer] = $this->officerAtCenter();
        $batch = $this->batch($world, $officer, ['1000.00']);

        try {
            app(BatchService::class)->reconcile($batch, ['litres_received' => '900.00'], $officer);
            $this->fail('A large variance with no cause should be refused.');
        } catch (RuleViolationException $exception) {
            $this->assertSame('BR-11', $exception->ruleId);
        }
    }

    /** BR-12 — "Every adjustment requires a reason and an explanation." */
    public function test_br12_adjustment_requires_reason_and_explanation(): void
    {
        [$world, $officer] = $this->officerAtCenter();
        $consignment = $this->dispatch($world, $officer, ['100.00']);

        try {
            app(AdjustmentService::class)->record(
                $consignment,
                '-5.00',
                AdjustmentReason::query()->where('code', 'ADJ-MEAS')->value('id'),
                '   ',
                $officer,
            );

            $this->fail('An adjustment with a blank explanation should be refused.');
        } catch (RuleViolationException $exception) {
            $this->assertSame('BR-12', $exception->ruleId);
        }

        $adjustment = app(AdjustmentService::class)->record(
            $consignment,
            '-5.00',
            AdjustmentReason::query()->where('code', 'ADJ-MEAS')->value('id'),
            'Re-measured after decanting.',
            $officer,
        );

        // BR-12 — never silent: the audit log carries it.
        $this->assertDatabaseHas('audit_entries', [
            'subject_type' => Adjustment::class,
            'subject_id' => $adjustment->id,
        ]);
    }

    /** BR-12 — a reason that does not apply to this record type is refused. */
    public function test_br12_reason_must_apply_to_the_record_type(): void
    {
        [$world, $officer] = $this->officerAtCenter();
        $consignment = $this->dispatch($world, $officer, ['100.00']);

        // A stock reason has no business on a consignment.
        $stockReason = AdjustmentReason::query()->where('code', 'ADJ-DAMAGE')->firstOrFail();

        try {
            app(AdjustmentService::class)->record(
                $consignment,
                '-1.00',
                $stockReason->id,
                'Wrong kind of reason.',
                $officer,
            );

            $this->fail('A stock reason should not apply to a consignment.');
        } catch (RuleViolationException $exception) {
            $this->assertSame('BR-12', $exception->ruleId);
        }
    }

    /**
     * DM-2 — "a delivery already on a consignment cannot be dispatched twice."
     *
     * The guard used to read the deliveries, check them, and then write with a
     * WHERE of only `id in (…)`. Under READ COMMITTED two requests that both
     * read before either commits both passed the check, and the second UPDATE
     * re-evaluated a clause that still matched and overwrote. One 40 L delivery
     * produced two consignments of 40 L each — the first orphaned with volume
     * and no deliveries, still batchable once confirmed, its phantom litres
     * flowing into BR-9's batch, BR-10's discrepancy and every aggregate above.
     *
     * A sequential double-click was always safe; genuine concurrency — a phone
     * retrying while the first request is in flight — was not. The interleaving
     * is forced here at the exact point it happens in production: after our
     * guard has read the rows and before our write claims them.
     */
    public function test_dm2_a_concurrent_dispatch_cannot_claim_the_same_deliveries_twice(): void
    {
        $this->travelTo(Wat::todayAt(10, 0));

        [$world, $agent] = $this->agentAtPoint();
        $delivery = $this->record($world, $agent, '40.00');

        // The rival request, which commits first.
        $rival = $this->asSystem(fn () => Consignment::query()->create([
            'reference' => 'CNS-RIVAL',
            'collection_point_id' => $world['pointA']->id,
            'collection_center_id' => $world['centerA']->id,
            'dispatched_by_user_id' => $agent->id,
            'dispatched_at' => Wat::now(),
            'litres_dispatched' => '40.00',
            'status' => Consignment::STATUS_AWAITING,
        ]));

        Consignment::created(function (Consignment $created) use ($delivery, $rival): void {
            if ($created->getKey() === $rival->getKey()) {
                return;
            }

            Delivery::withoutDataScope()
                ->where('id', $delivery->id)
                ->update(['consignment_id' => $rival->getKey()]);
        });

        try {
            app(ConsignmentService::class)->dispatch($world['pointA'], [$delivery->id], [], $agent);
            $this->fail('Dispatching deliveries another request already claimed must be refused.');
        } catch (RuleViolationException $exception) {
            $this->assertSame('DM-2', $exception->ruleId);
        }

        // The refusal has to take the whole transaction with it: an orphan
        // consignment carrying the volume is worse than the refusal, because it
        // batches.
        $this->assertSame(1, $this->asSystem(fn () => Consignment::query()->count()));
        $this->assertSame(
            '40.00',
            $this->asSystem(fn () => Volume::sum(
                Consignment::query()->pluck('litres_dispatched')->all(),
            )),
            '40 L of milk must not appear as 80 L of consignments.',
        );
    }

    /**
     * DM-2 / BR-9 — the same race one level up, where it makes a phantom BATCH.
     *
     * `BatchService::dispatch()` had the identical read-check-write shape, so
     * two concurrent batch dispatches each claimed the same confirmed litres and
     * one batch was left holding volume with no consignments behind it — volume
     * that then reconciles against the factory intake as a shortfall nobody can
     * explain.
     */
    public function test_dm2_a_concurrent_batch_dispatch_cannot_claim_the_same_consignments_twice(): void
    {
        $this->travelTo(Wat::todayAt(10, 0));

        [$world, $officer] = $this->officerAtCenter();
        $consignment = $this->confirmedConsignment($world, $officer, ['200.00']);

        $rival = $this->asSystem(fn () => Batch::query()->create([
            'reference' => 'BATCH-RIVAL',
            'collection_center_id' => $world['centerA']->id,
            'dispatched_by_user_id' => $officer->id,
            'dispatched_at' => Wat::now(),
            'litres_dispatched' => '200.00',
            'status' => Batch::STATUS_IN_TRANSIT,
        ]));

        Batch::created(function (Batch $created) use ($consignment, $rival): void {
            if ($created->getKey() === $rival->getKey()) {
                return;
            }

            Consignment::withoutDataScope()
                ->where('id', $consignment->id)
                ->update(['batch_id' => $rival->getKey()]);
        });

        try {
            app(BatchService::class)->dispatch($world['centerA'], [$consignment->id], [], $officer);
            $this->fail('Batching consignments another request already claimed must be refused.');
        } catch (RuleViolationException $exception) {
            $this->assertSame('DM-2', $exception->ruleId);
        }

        $this->assertSame(1, $this->asSystem(fn () => Batch::query()->count()));
    }

    /**
     * BR-11 — "if |discrepancy| / litres_dispatched exceeds the configured
     * tolerance … the supervisor must supply supervisor_notes before the batch
     * can be released."
     *
     * A zero-litre batch made that unreachable: the ratio has no denominator, so
     * `exceedsTolerance()` answered false however much arrived at the factory,
     * and the batch released with no cause and no note. A consignment rejected
     * in full at the centre is confirmed, graded and `partly_rejected` — every
     * condition `scopeBatchable` asks for — so it was one wholly-spoiled can
     * away, not a contrived case.
     */
    public function test_br9_a_zero_litre_batch_is_refused_so_br11_cannot_be_bypassed(): void
    {
        $this->travelTo(Wat::todayAt(10, 0));

        [$world, $officer] = $this->officerAtCenter();

        $consignment = $this->dispatch($world, $officer, ['100.00']);
        $this->recordTests($consignment, $officer);

        // Rejected in full at the centre: confirmed, graded, and worth 0 L.
        $consignment = app(ConsignmentService::class)->confirm($consignment->refresh(), [
            'litres_rejected_at_center' => '100.00',
            'rejection_reason_id' => RejectionReason::query()->where('code', 'REJ-ADU')->value('id'),
            'grade_id' => Grade::query()->where('code', 'GRD-A')->value('id'),
        ], $officer);

        $this->assertSame('0.00', (string) $consignment->litres_confirmed);
        $this->assertTrue($consignment->isBatchable(), 'BR-9 lets it batch, which is how the hole opened.');

        try {
            app(BatchService::class)->dispatch($world['centerA'], [$consignment->id], [], $officer);
            $this->fail('A batch with no confirmed volume should be refused.');
        } catch (RuleViolationException $exception) {
            $this->assertSame('BR-9', $exception->ruleId);
        }

        // Belt and braces for the rows that predate the guard: a variance
        // against nothing dispatched is beyond tolerance, not inside it.
        $legacy = new Batch(['litres_dispatched' => '0.00', 'discrepancy_litres' => '5.00']);

        $this->assertTrue($legacy->exceedsTolerance());
        $this->assertNull($legacy->discrepancyPercentage(), 'There is no ratio to report.');
    }

    /**
     * BR-8 / ARCH-6 — the adjustment total is folded in integer centilitres, and
     * the preloaded aggregate agrees with the query it replaces.
     *
     * `adjustmentTotal()` used to run `(int) round(100 * (float) SUM(...))`. The
     * round() kept it right at realistic magnitudes, but this figure is an input
     * to `litres_confirmed` — a stored number BR-8 defines and a payment run will
     * read — and it was the one float left inside the money path. It was also a
     * SUM per call, issued once per table row and again inside every unconfirmed
     * row's confirmation modal, so the list queries now preload it; the two paths
     * have to give the same answer or the screen and the write disagree.
     */
    public function test_br8_the_adjustment_total_is_exact_whether_preloaded_or_queried(): void
    {
        $this->travelTo(Wat::todayAt(10, 0));

        [$world, $officer] = $this->officerAtCenter();
        $consignment = $this->dispatch($world, $officer, ['100.00']);

        foreach (['-2.55', '1.30'] as $delta) {
            app(AdjustmentService::class)->record(
                $consignment,
                $delta,
                AdjustmentReason::query()->where('code', 'ADJ-MEAS')->value('id'),
                'Measurement corrected against the calibrated can.',
                $officer,
            );
        }

        $this->assertSame('-1.25', $consignment->refresh()->adjustmentTotal());

        $preloaded = Consignment::query()
            ->withSum('adjustments', 'litres_delta')
            ->findOrFail($consignment->id);

        $issued = 0;
        DB::listen(function () use (&$issued): void {
            $issued++;
        });

        $this->assertSame('-1.25', $preloaded->adjustmentTotal(), 'The preloaded aggregate must not drift from the query.');
        $this->assertSame(0, $issued, 'A list query that already asked for the sum must not be asked again per row.');

        // BR-8 reads it, so the stored figure carries the same number.
        $this->recordTests($consignment, $officer);
        $confirmed = app(ConsignmentService::class)->confirm($consignment->refresh(), [], $officer);

        $this->assertSame('98.75', (string) $confirmed->litres_confirmed);
    }

    /**
     * BR-12 — "Adjustments are never silent."
     *
     * §17's DEL-0009 traced through application code rather than through the demo
     * seeder's console report: Zainab Idris presents 34 L, 6 L are rejected for
     * adulteration, 28 L are accepted, and a −1 L adjustment for the litre lost
     * decanting leaves 27 L payable. Before this, the adjustment row was written
     * and no column, accessor or query anywhere netted it — the delivery screen
     * offered "Record adjustment" as the way to change a volume and it changed
     * none, so §14's Phase 3 criterion ("a payable volume of 27 L") was not
     * producible by any query the running system could make.
     */
    public function test_br12_a_delivery_adjustment_moves_the_payable_volume(): void
    {
        [$world, $agent] = $this->agentAtPoint();

        $delivery = app(DeliveryService::class)->record($world['pointA'], $world['farmer'], [
            'litres_presented' => '34.00',
            'litres_rejected' => '6.00',
            'rejection_reason_id' => RejectionReason::query()->where('code', 'REJ-ADU')->value('id'),
            'delivered_at' => Wat::todayAt(6, 15),
        ], $agent);

        $this->assertSame('28.00', (string) $delivery->litres_accepted);
        $this->assertSame('28.00', (string) $delivery->litres_payable, 'With no adjustment, payable is the accepted volume.');

        app(AdjustmentService::class)->record(
            $delivery,
            '-1.00',
            AdjustmentReason::query()->where('code', 'ADJ-CONT')->value('id'),
            'One litre lost decanting into the center can.',
            $agent,
        );

        $delivery->refresh();

        $this->assertSame('-1.00', (string) $delivery->litres_adjusted);
        $this->assertSame('27.00', (string) $delivery->litres_payable);

        // DM-1 is untouched: the point recorded 28 L and still says so.
        $this->assertSame('28.00', (string) $delivery->litres_accepted);

        /*
         * The figure a payment run reads is one SUM, not a polymorphic join the
         * caller has to remember to make. This is the assertion that would have
         * failed for the whole of the system's life until now.
         */
        $this->assertSame(
            '27.00',
            Volume::sum([
                Delivery::withoutDataScope()
                    ->where('collection_point_id', $world['pointA']->id)
                    ->sum('litres_payable'),
            ]),
        );
    }

    /**
     * BR-12 — an adjustment cannot invent a negative volume.
     *
     * A 28 L delivery accepted a −100 L adjustment. Under the payable formula
     * that is −72 L, i.e. −₦18,000: a farmer who owes the cooperative money for
     * handing over milk.
     */
    public function test_br12_an_adjustment_cannot_take_a_delivery_below_zero(): void
    {
        [$world, $agent] = $this->agentAtPoint();
        $delivery = $this->record($world, $agent, '28.00');

        try {
            app(AdjustmentService::class)->record(
                $delivery,
                '-100.00',
                AdjustmentReason::query()->where('code', 'ADJ-CONT')->value('id'),
                'Deliberate over-deduction, to prove the guard.',
                $agent,
            );

            $this->fail('An adjustment larger than the delivery should be refused.');
        } catch (RuleViolationException $exception) {
            $this->assertSame('BR-12', $exception->ruleId);
            $this->assertSame('litres_delta', $exception->field);
            $this->assertStringContainsString('28 L', $exception->getMessage());
        }

        $delivery->refresh();

        $this->assertSame(0, $delivery->adjustments()->count(), 'Nothing is written when the magnitude is refused.');
        $this->assertSame('28.00', (string) $delivery->litres_payable);

        // Taking it exactly to zero is a correction, not an overdraw, and stands.
        app(AdjustmentService::class)->record(
            $delivery,
            '-28.00',
            AdjustmentReason::query()->where('code', 'ADJ-CONT')->value('id'),
            'The whole can was spilled before it reached the center.',
            $agent,
        );

        $this->assertSame('0.00', (string) $delivery->refresh()->litres_payable);
    }

    /* ------------------------------------------------------------------ */

    private function agentAtPoint(): array
    {
        $world = $this->makeMilkWorld();
        $agent = $this->makeUser('Volume Agent');
        $this->assignRole($agent, 'Collection Agent', ScopeType::Point, $world['pointA']->id);
        $this->actingAs($agent);

        return [$world, $agent];
    }

    private function officerAtCenter(): array
    {
        $world = $this->makeMilkWorld();
        $officer = $this->makeUser('Volume Officer');
        $this->assignRole($officer, 'Milk Collection Officer', ScopeType::Center, $world['centerA']->id);
        $this->assignRole($officer, 'Milk Collection Supervisor');
        $this->actingAs($officer);

        return [$world, $officer];
    }

    private function record(array $world, $actor, string $presented, string $rejected = '0.00')
    {
        return app(DeliveryService::class)->record($world['pointA'], $world['farmer'], [
            'litres_presented' => $presented,
            'litres_rejected' => $rejected,
            'rejection_reason_id' => $rejected === '0.00'
                ? null
                : RejectionReason::query()->where('code', 'REJ-SPO')->value('id'),
            'delivered_at' => Wat::todayAt(6, 15),
        ], $actor);
    }

    /** @param array<int, string> $volumes */
    private function dispatch(array $world, $actor, array $volumes)
    {
        $ids = array_map(fn (string $litres) => $this->record($world, $actor, $litres)->id, $volumes);

        return app(ConsignmentService::class)->dispatch(
            $world['pointA'],
            $ids,
            ['dispatched_at' => Wat::todayAt(7, 0)],
            $actor,
        );
    }

    private function recordTests(Consignment $consignment, $actor): void
    {
        foreach (QualityTestDefinition::query()->required()->get() as $definition) {
            app(ConsignmentService::class)->recordQualityTest(
                $consignment,
                $definition,
                $definition->code === 'DENSITY' ? '1.030' : ($definition->code === 'TEMPERATURE' ? '17' : '1'),
                $actor,
            );
        }
    }

    /** @param array<int, string> $volumes */
    private function confirmedConsignment(array $world, $actor, array $volumes): Consignment
    {
        $consignment = $this->dispatch($world, $actor, $volumes);
        $this->recordTests($consignment, $actor);

        return app(ConsignmentService::class)->confirm($consignment->refresh(), [
            'grade_id' => Grade::query()->where('code', 'GRD-A')->value('id'),
        ], $actor);
    }

    /** @param array<int, string> $volumes */
    private function batch(array $world, $actor, array $volumes): Batch
    {
        $consignment = $this->confirmedConsignment($world, $actor, $volumes);

        return app(BatchService::class)->dispatch(
            $world['centerA'],
            [$consignment->id],
            ['dispatched_at' => Wat::todayAt(8, 30)],
            $actor,
        );
    }
}
