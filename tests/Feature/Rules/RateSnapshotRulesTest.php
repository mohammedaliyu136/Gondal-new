<?php

namespace Tests\Feature\Rules;

use App\Authorization\ScopeType;
use App\Exceptions\RuleViolationException;
use App\Models\AuditEntry;
use App\Models\Consignment;
use App\Models\Grade;
use App\Models\GradeRate;
use App\Models\QualityTestDefinition;
use App\Services\Milk\ConsignmentService;
use App\Services\Milk\DeliveryService;
use App\Support\Money;
use App\Support\Wat;
use Illuminate\Support\Carbon;
use Tests\GondalTestCase;

/** §7.3 — rates and payment integrity. */
class RateSnapshotRulesTest extends GondalTestCase
{
    /**
     * BR-13 — "Rates are effective-dated. grade_rates rows carry effective_from.
     * Changing a rate never alters a historical figure."
     *
     * This is the §14 Phase 2 acceptance criterion: "changing the Grade A rate
     * creates a new effective-dated row, and a delivery confirmed yesterday still
     * reports yesterday's rate."
     */
    public function test_br13_changing_a_rate_never_moves_a_historical_figure(): void
    {
        [$world, $officer] = $this->officerAtCenter();

        $gradeA = Grade::query()->where('code', 'GRD-A')->firstOrFail();
        $originalRate = $gradeA->currentRate();

        $this->assertSame(25_000, (int) $originalRate->rate_per_litre_minor, 'Grade A seeds at ₦250.00/L.');

        // Confirm a consignment today at the current rate.
        $consignment = $this->confirmed($world, $officer, '100.00', $gradeA);

        $this->assertSame(25_000, (int) $consignment->rate_per_litre_minor);
        $this->assertSame($originalRate->id, $consignment->grade_rate_id);

        // The administrator raises the rate from tomorrow. This must INSERT a row.
        $before = GradeRate::query()->where('grade_id', $gradeA->id)->count();

        GradeRate::query()->create([
            'grade_id' => $gradeA->id,
            'rate_per_litre_minor' => 27_500,
            'effective_from' => Wat::today()->addDay()->toDateString(),
        ]);

        $this->assertSame($before + 1, GradeRate::query()->where('grade_id', $gradeA->id)->count());

        // The already-confirmed consignment is untouched.
        $this->assertSame(25_000, (int) $consignment->refresh()->rate_per_litre_minor);

        // And the rate in force yesterday is still the old one.
        $this->assertSame(
            25_000,
            (int) $gradeA->refresh()->rateOn(Wat::today()->subDay())->rate_per_litre_minor,
        );

        // Tomorrow's rate is the new one.
        $this->assertSame(
            27_500,
            (int) $gradeA->rateOn(Wat::today()->addDay())->rate_per_litre_minor,
        );
    }

    /**
     * BR-14 — "When a consignment is confirmed and graded, the applicable
     * grade_rate_id AND the numeric rate_per_litre_minor are snapshotted onto the
     * consignment. Downstream payment reads the snapshot, never a live join."
     */
    public function test_br14_confirmation_snapshots_both_the_row_and_the_number(): void
    {
        [$world, $officer] = $this->officerAtCenter();
        $gradeA = Grade::query()->where('code', 'GRD-A')->firstOrFail();

        $consignment = $this->confirmed($world, $officer, '40.00', $gradeA);

        $this->assertNotNull($consignment->grade_rate_id, 'The rate ROW is snapshotted.');
        $this->assertNotNull($consignment->rate_per_litre_minor, 'The NUMBER is snapshotted too.');

        // Now delete every rate row for the grade. Payment must still work,
        // because it reads the snapshotted number rather than joining.
        GradeRate::query()->where('grade_id', $gradeA->id)->delete();

        $this->assertSame(
            Money::valueVolume('40.00', 25_000),
            $consignment->refresh()->payableValueMinor(),
        );
        $this->assertSame(1_000_000, $consignment->payableValueMinor(), '40 L × ₦250 = ₦10,000.00');
    }

    /** BR-13 — a grade with no rate effective on the confirmation date is refused. */
    public function test_br13_grade_without_an_effective_rate_is_refused(): void
    {
        [$world, $officer] = $this->officerAtCenter();
        $gradeA = Grade::query()->where('code', 'GRD-A')->firstOrFail();

        // Push every rate into the future.
        GradeRate::query()->where('grade_id', $gradeA->id)->update([
            'effective_from' => Wat::today()->addMonth()->toDateString(),
        ]);

        try {
            $this->confirmed($world, $officer, '10.00', $gradeA->refresh());
            $this->fail('Grading with no rate in force should be refused.');
        } catch (RuleViolationException $exception) {
            $this->assertSame('BR-13', $exception->ruleId);
        }
    }

    /**
     * BR-15 — "Cooperative savings_deduction_pct and levy_pct are likewise
     * snapshotted at the point a payable amount is calculated."
     *
     * §15.1 blocks the payment module itself, so what is testable now is that the
     * arithmetic is exact and integral, and that the cooperative's percentages are
     * read at calculation time rather than assumed.
     */
    public function test_br15_deduction_percentages_are_applied_in_integer_arithmetic(): void
    {
        $world = $this->makeMilkWorld();
        $cooperative = $world['cooperative'];

        $this->assertSame('5.00', (string) $cooperative->savings_deduction_pct);
        $this->assertSame('2.00', (string) $cooperative->levy_pct);

        // §17 — 27 L at ₦250/L is ₦6,750.00; a 2% levy leaves ₦6,615.00.
        $gross = Money::valueVolume('27.00', 25_000);
        $this->assertSame(675_000, $gross);

        $levy = Money::percentageOf($gross, $cooperative->levy_pct);
        $this->assertSame(13_500, $levy, 'A 2% levy on ₦6,750.00 is ₦135.00.');
        $this->assertSame(661_500, $gross - $levy, '§17 — ₦6,615.00 net.');

        $savings = Money::percentageOf($gross, $cooperative->savings_deduction_pct);
        $this->assertSame(33_750, $savings, 'A 5% savings deduction is ₦337.50.');

        // NFR-5 — every figure above is an integer number of kobo.
        foreach ([$gross, $levy, $savings] as $figure) {
            $this->assertIsInt($figure);
        }
    }

    /** BR-16 — "Rejected volume is valued at zero." */
    public function test_br16_rejected_volume_is_valued_at_zero(): void
    {
        $rejectedGrade = Grade::query()->where('is_rejection', true)->firstOrFail();

        $this->assertSame(0, (int) $rejectedGrade->currentRate()->rate_per_litre_minor);
        $this->assertSame(0, Money::valueVolume('500.00', 0));

        // And the rejection grade cannot be assigned as a quality outcome, so no
        // volume is ever "paid at the rejected rate" by mistake.
        [$world, $officer] = $this->officerAtCenter();

        try {
            $this->confirmed($world, $officer, '10.00', $rejectedGrade);
            $this->fail('The rejection grade must not be assignable as an outcome.');
        } catch (RuleViolationException $exception) {
            $this->assertSame('BR-4', $exception->ruleId);
        }
    }

    /**
     * REF-1 — "Changing reference data is audited with before and after values."
     * REF-2 — "Rate changes take effect prospectively only."
     */
    public function test_ref1_and_ref2_rate_change_is_audited_and_prospective(): void
    {
        $admin = $this->makeUser('Settings Admin');
        $this->assignRole($admin, 'System Administrator');
        $this->actingAs($admin);

        $gradeA = Grade::query()->where('code', 'GRD-A')->firstOrFail();

        $response = $this->put(route('admin.settings.update'), [
            'milk_delivery_cutoff_default' => '07:00',
            'milk_delivery_cutoff_latest_override' => '08:30',
            'milk_batch_discrepancy_tolerance_pct' => '1.5',
            'cooperative_default_savings_deduction_pct' => '5',
            'cooperative_default_levy_pct' => '2',
            'cooperative_default_social_contribution' => '250.00',
        ]);

        $response->assertRedirect();

        // REF-1 — before and after are both recorded.
        $entry = AuditEntry::query()
            ->where('module', 'Settings')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('1.0', $entry->detail['before']['milk.batch_discrepancy_tolerance_pct']);
        $this->assertSame('1.5', $entry->detail['after']['milk.batch_discrepancy_tolerance_pct']);

        // REF-2 — a rate change is prospective, and the audit entry says so.
        $this->post(route('admin.settings.grades.store'), [
            'grade_id' => $gradeA->id,
            'rate_per_litre' => '265.00',
            'effective_from' => Wat::today()->addDays(3)->toDateString(),
        ])->assertRedirect();

        $rateEntry = AuditEntry::query()
            ->where('subject_type', Grade::class)
            ->where('subject_id', $gradeA->id)
            ->latest('id')
            ->firstOrFail();

        $this->assertContains('BR-13', $rateEntry->detail['after']['rules']);
        $this->assertSame(26_500, $rateEntry->detail['after']['rate_per_litre_minor']);
        // Today's rate has not moved.
        $this->assertSame(25_000, (int) $gradeA->refresh()->currentRate()->rate_per_litre_minor);
    }

    /**
     * BR-14 — "the applicable grade_rate_id AND the numeric
     * rate_per_litre_minor are snapshotted onto the consignment."
     *
     * Which rate is applicable is not the confirming officer's to choose. The
     * snapshot used to be anchored to `confirmed_at`, and `confirmed_at` arrives
     * from the request validated as nothing more than a date. With Grade A cut
     * to ₦200/L today, an officer holding only `milk.consignment.confirm.edit`
     * posted a confirmation dated before the cut and a 100 L consignment
     * snapshotted ₦250/L — ₦25,000 instead of ₦20,000, a 25% overpayment chosen
     * by the person keying the record, needing no supervisor and producing no
     * exception entry. Reversing the dates underpays the farmer just as quietly.
     *
     * BR-14 was satisfied throughout, which is why the suite stayed green: the
     * snapshot was faithful, it was the anchor that was forged.
     */
    public function test_br14_the_confirming_officer_cannot_choose_which_rate_is_snapshotted(): void
    {
        $this->travelTo(Wat::todayAt(10, 0));

        [$world, $officer] = $this->officerAtCenter();
        $gradeA = Grade::query()->where('code', 'GRD-A')->firstOrFail();

        // The rate is cut today. Yesterday's rate is still ₦250/L.
        GradeRate::query()->create([
            'grade_id' => $gradeA->id,
            'rate_per_litre_minor' => 20_000,
            'effective_from' => Wat::today()->toDateString(),
        ]);

        $gradeA->refresh();
        $this->assertSame(20_000, (int) $gradeA->rateOn(Wat::today())->rate_per_litre_minor);
        $this->assertSame(25_000, (int) $gradeA->rateOn(Wat::today()->subDays(3))->rate_per_litre_minor);

        $consignment = $this->awaitingConfirmation($world, $officer, '100.00', Wat::now()->subDays(3));

        $confirmed = app(ConsignmentService::class)->confirm($consignment, [
            'grade_id' => $gradeA->id,
            // The forged anchor: three days back, before the cut.
            'confirmed_at' => Wat::now()->subDays(3),
        ], $officer);

        $this->assertSame(20_000, (int) $confirmed->rate_per_litre_minor,
            'The rate in force NOW, not the rate on the date the officer typed.');
        $this->assertSame(2_000_000, $confirmed->payableValueMinor(), '100 L × ₦200 = ₦20,000.00');

        // The stated confirmation time is still recorded — late data entry is
        // real — it simply is not money any more.
        $this->assertSame(
            Wat::today()->toDateString(),
            Wat::of($confirmed->rate_anchored_at)->toDateString(),
        );
        $this->assertSame(
            Wat::today()->subDays(3)->toDateString(),
            Wat::of($confirmed->confirmed_at)->toDateString(),
        );
    }

    /**
     * BR-13 / BR-14 — grading after the fact reads the same server-stamped
     * anchor, so closing the confirmation route does not leave the grading one
     * open.
     *
     * Confirming without a grade is legitimate (the lab may be busy) and
     * `grade()` prices against the confirmation day, which is right. But it read
     * `confirmed_at` to find that day — the same caller-supplied field — so
     * confirming ungraded with a backdated date and grading a minute later
     * bought the old rate by another door.
     */
    public function test_br13_a_later_grade_prices_at_the_server_anchor_not_the_stated_date(): void
    {
        $this->travelTo(Wat::todayAt(10, 0));

        [$world, $officer] = $this->officerAtCenter();
        $gradeA = Grade::query()->where('code', 'GRD-A')->firstOrFail();

        GradeRate::query()->create([
            'grade_id' => $gradeA->id,
            'rate_per_litre_minor' => 20_000,
            'effective_from' => Wat::today()->toDateString(),
        ]);

        $consignment = $this->awaitingConfirmation($world, $officer, '100.00', Wat::now()->subDays(3));

        // Confirmed with no grade, dated before the cut.
        $confirmed = app(ConsignmentService::class)->confirm($consignment, [
            'confirmed_at' => Wat::now()->subDays(3),
        ], $officer);

        $this->assertNull($confirmed->grade_id);

        $graded = app(ConsignmentService::class)->grade($confirmed->refresh(), $gradeA->refresh(), $officer);

        $this->assertSame(20_000, (int) $graded->rate_per_litre_minor);
    }

    /**
     * ST-1 — a consignment cannot be confirmed before it was dispatched.
     *
     * `confirmed_at` no longer prices anything, but it is still the column every
     * day aggregate on the centre and dashboard screens filters on, so a
     * confirmation stamped before its own dispatch files litres into a day that
     * has already been reported. Refused in the service, with the record in
     * hand, so the API cannot go round the screen's validation.
     */
    public function test_st1_a_confirmation_cannot_predate_its_own_dispatch(): void
    {
        $this->travelTo(Wat::todayAt(10, 0));

        [$world, $officer] = $this->officerAtCenter();
        $consignment = $this->awaitingConfirmation($world, $officer, '50.00', Wat::now()->subHours(2));

        try {
            app(ConsignmentService::class)->confirm($consignment, [
                'confirmed_at' => Wat::now()->subDays(2),
            ], $officer);
            $this->fail('A confirmation dated before its dispatch should be refused.');
        } catch (RuleViolationException $exception) {
            $this->assertSame('ST-1', $exception->ruleId);
            $this->assertSame('confirmed_at', $exception->field);
        }

        $this->assertFalse($consignment->refresh()->isConfirmed());
    }

    /** BR-14 — and the screen refuses a confirmation that has not happened yet. */
    public function test_br14_a_future_confirmation_time_is_refused_on_the_screen(): void
    {
        $this->travelTo(Wat::todayAt(10, 0));

        [$world, $officer] = $this->officerAtCenter();
        $consignment = $this->awaitingConfirmation($world, $officer, '50.00', Wat::now()->subHours(2));

        $this->post(route('consignments.confirm', $consignment), [
            'confirmed_at' => Wat::local()->addDays(2)->format('Y-m-d\TH:i'),
        ])->assertSessionHasErrors('confirmed_at');

        $this->assertFalse($consignment->refresh()->isConfirmed());
    }

    /**
     * BR-13 — "changing a rate never alters a historical figure." The model
     * enforces it rather than describing it.
     *
     * `GradeRate`'s own docblock has always said the table is insert-only, and
     * nothing made that true: `SettingsController::storeGradeRate` reaches it
     * through `updateOrCreate` keyed on (grade_id, effective_from), which on
     * PostgreSQL matches the existing row and overwrites what a litre was worth
     * that day, leaving no second row and no record of the old value.
     */
    public function test_br13_a_historical_rate_row_cannot_be_rewritten_in_place(): void
    {
        $gradeA = Grade::query()->where('code', 'GRD-A')->firstOrFail();
        $existing = $gradeA->currentRate();

        try {
            $existing->rate_per_litre_minor = 40_000;
            $existing->save();
            $this->fail('Editing a historical rate row should be refused.');
        } catch (RuleViolationException $exception) {
            $this->assertSame('BR-13', $exception->ruleId);
        }

        $this->assertSame(25_000, (int) $existing->fresh()->rate_per_litre_minor);

        // A save that touches neither the money nor the date is still fine, so
        // the reference-data seeder stays re-runnable.
        $existing->refresh()->touch();

        // And the sanctioned way to change a rate still works: another row.
        $before = GradeRate::query()->where('grade_id', $gradeA->id)->count();

        GradeRate::query()->create([
            'grade_id' => $gradeA->id,
            'rate_per_litre_minor' => 40_000,
            'effective_from' => Wat::today()->addDay()->toDateString(),
        ]);

        $this->assertSame($before + 1, GradeRate::query()->where('grade_id', $gradeA->id)->count());
    }

    /* ------------------------------------------------------------------ */

    /**
     * A consignment sitting at the centre awaiting confirmation, dispatched at a
     * given instant. Built directly rather than through DeliveryService so the
     * test can place the dispatch in the past without arguing with BR-3's
     * cut-off, which is a different rule with its own tests.
     */
    private function awaitingConfirmation(array $world, $actor, string $litres, Carbon $dispatchedAt)
    {
        $consignment = $this->asSystem(fn () => Consignment::query()->create([
            'reference' => 'CNS-ANCHOR-'.random_int(1000, 9999),
            'collection_point_id' => $world['pointA']->id,
            'collection_center_id' => $world['centerA']->id,
            'dispatched_by_user_id' => $actor->id,
            'dispatched_at' => $dispatchedAt,
            'litres_dispatched' => $litres,
            'status' => Consignment::STATUS_AWAITING,
        ]));

        foreach (QualityTestDefinition::query()->required()->get() as $definition) {
            app(ConsignmentService::class)->recordQualityTest(
                $consignment,
                $definition,
                $definition->code === 'DENSITY' ? '1.030' : ($definition->code === 'TEMPERATURE' ? '18' : '1'),
                $actor,
            );
        }

        return $consignment->refresh();
    }

    private function officerAtCenter(): array
    {
        $world = $this->makeMilkWorld();
        $officer = $this->makeUser('Rate Officer');
        $this->assignRole($officer, 'Milk Collection Officer', ScopeType::Center, $world['centerA']->id);
        $this->actingAs($officer);

        return [$world, $officer];
    }

    private function confirmed(array $world, $actor, string $litres, Grade $grade)
    {
        $delivery = app(DeliveryService::class)->record($world['pointA'], $world['farmer'], [
            'litres_presented' => $litres,
            'delivered_at' => Wat::todayAt(6, 10),
        ], $actor);

        $consignment = app(ConsignmentService::class)->dispatch(
            $world['pointA'],
            [$delivery->id],
            ['dispatched_at' => Wat::todayAt(7, 0)],
            $actor,
        );

        foreach (QualityTestDefinition::query()->required()->get() as $definition) {
            app(ConsignmentService::class)->recordQualityTest(
                $consignment,
                $definition,
                $definition->code === 'DENSITY' ? '1.030' : ($definition->code === 'TEMPERATURE' ? '18' : '1'),
                $actor,
            );
        }

        return app(ConsignmentService::class)->confirm($consignment->refresh(), [
            'grade_id' => $grade->id,
            'confirmed_at' => Wat::todayAt(8, 0),
        ], $actor);
    }
}
