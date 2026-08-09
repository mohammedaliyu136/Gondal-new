<?php

namespace Tests\Feature\Browser;

use App\Authorization\ScopeType;
use App\Models\AdjustmentReason;
use App\Models\Consignment;
use App\Models\Grade;
use App\Models\GradeRate;
use App\Models\QualityTestDefinition;
use App\Models\User;
use App\Support\Wat;
use Tests\GondalTestCase;

/**
 * Two journey repairs on the centre officer's intake.
 *
 * The consignment adjustment route existed from day one and no screen posted to
 * it — the capability was built and unreachable. And a consignment confirmed
 * without a grade could never be graded, because confirm() was the only writer of
 * grade_id and confirmation is one-shot — so "grade it later" quietly meant
 * "never batch it, never pay it".
 */
class ConsignmentCorrectionsTest extends GondalTestCase
{
    /** An awaiting consignment can be adjusted from the screen. */
    public function test_an_awaiting_consignment_can_be_adjusted_from_the_form(): void
    {
        $world = $this->makeMilkWorld();
        $officer = $this->officerAt($world);

        $consignment = $this->awaitingConsignment($world, '100.00');

        // The control is on the screen.
        $this->get(route('consignments.index'))
            ->assertOk()
            ->assertSee('modal-adjust-'.$consignment->id);

        $reason = $this->asSystem(fn () => AdjustmentReason::query()
            ->where('applies_to', 'consignment')->orWhere('applies_to', 'any')->firstOrFail());

        $this->post(route('consignments.adjust', $consignment), [
            'litres_delta' => '-2.50',
            'adjustment_reason_id' => $reason->id,
            'explanation' => 'Spillage while decanting at the center.',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame('-2.50', $this->asSystem(fn () => $consignment->adjustmentTotal()));

        // And it lands in the confirmed figure (BR-8), exactly as promised.
        $this->post(route('consignments.confirm', $consignment), [
            'litres_rejected_at_center' => '0',
        ])->assertSessionHasNoErrors();

        $this->assertSame('97.50', (string) $consignment->refresh()->litres_confirmed);
    }

    /** Adjusting after confirmation is refused with a reason, not recorded inertly. */
    public function test_adjusting_a_confirmed_consignment_is_refused(): void
    {
        $world = $this->makeMilkWorld();
        $officer = $this->officerAt($world);

        $consignment = $this->awaitingConsignment($world, '80.00');

        $this->post(route('consignments.confirm', $consignment), [
            'litres_rejected_at_center' => '0',
        ])->assertSessionHasNoErrors();

        $reason = $this->asSystem(fn () => AdjustmentReason::query()
            ->where('applies_to', 'consignment')->orWhere('applies_to', 'any')->firstOrFail());

        $this->post(route('consignments.adjust', $consignment->refresh()), [
            'litres_delta' => '-5.00',
            'adjustment_reason_id' => $reason->id,
            'explanation' => 'Too late — must be refused.',
        ])->assertSessionHasErrors();

        // Nothing recorded, nothing changed.
        $this->assertSame('0.00', $this->asSystem(fn () => $consignment->adjustmentTotal()));
        $this->assertSame('80.00', (string) $consignment->refresh()->litres_confirmed);
    }

    /**
     * A consignment confirmed without a grade can be graded afterwards — and the
     * rate snapshotted is the one in force on the CONFIRMATION day, so grading
     * three days late cannot move what the farmer is owed.
     */
    public function test_a_confirmed_ungraded_consignment_can_be_graded_at_the_confirmation_day_rate(): void
    {
        $world = $this->makeMilkWorld();
        $officer = $this->officerAt($world);

        $consignment = $this->awaitingConsignment($world, '60.00');

        // Record every required quality test, then confirm WITHOUT a grade.
        foreach ($this->asSystem(fn () => QualityTestDefinition::query()->required()->get()) as $definition) {
            $this->post(route('consignments.quality-test', $consignment), [
                'quality_test_definition_id' => (string) $definition->getKey(),
                'reading' => $definition->kind === 'boolean' ? '1' : '1.030',
            ])->assertSessionHasNoErrors();
        }

        $this->post(route('consignments.confirm', $consignment), [
            'litres_rejected_at_center' => '0',
        ])->assertSessionHasNoErrors();

        $consignment->refresh();
        $this->assertNull($consignment->grade_id);
        $this->assertFalse($consignment->isBatchable(), 'Ungraded consignments must stay out of batches.');

        $grade = $this->asSystem(fn () => Grade::query()->assignable()->orderBy('position')->firstOrFail());
        $rateOnConfirmationDay = $this->asSystem(fn () => $grade->rateOn($consignment->confirmed_at));

        /*
         * A NEW rate takes effect after confirmation. If the snapshot anchored to
         * "today" instead of the confirmation day, this is the case that would
         * silently change what the farmer is owed.
         */
        $this->asSystem(fn () => GradeRate::query()->create([
            'grade_id' => $grade->getKey(),
            'rate_per_litre_minor' => $rateOnConfirmationDay->rate_per_litre_minor + 5_000,
            'effective_from' => Wat::today()->addDay()->toDateString(),
            'status' => 'active',
        ]));

        $this->post(route('consignments.grade', $consignment), [
            'grade_id' => $grade->getKey(),
        ])->assertSessionHasNoErrors()->assertRedirect();

        $consignment->refresh();

        $this->assertSame($grade->getKey(), $consignment->grade_id);
        $this->assertSame(
            (int) $rateOnConfirmationDay->rate_per_litre_minor,
            (int) $consignment->rate_per_litre_minor,
            'The snapshot must anchor to the confirmation day, not the grading day.',
        );
        $this->assertTrue($consignment->isBatchable(), 'Graded, it can now join a batch.');
    }

    /** Grading twice is refused; changing a grade is a supervisor act, not this one. */
    public function test_regrading_through_this_route_is_refused(): void
    {
        $world = $this->makeMilkWorld();
        $officer = $this->officerAt($world);

        $consignment = $this->awaitingConsignment($world, '40.00');

        foreach ($this->asSystem(fn () => QualityTestDefinition::query()->required()->get()) as $definition) {
            $this->post(route('consignments.quality-test', $consignment), [
                'quality_test_definition_id' => (string) $definition->getKey(),
                'reading' => $definition->kind === 'boolean' ? '1' : '1.030',
            ]);
        }

        $grades = $this->asSystem(fn () => Grade::query()->assignable()->orderBy('position')->limit(2)->get());

        $this->post(route('consignments.confirm', $consignment), [
            'litres_rejected_at_center' => '0',
            'grade_id' => $grades[0]->getKey(),
        ])->assertSessionHasNoErrors();

        $this->post(route('consignments.grade', $consignment->refresh()), [
            'grade_id' => $grades[1]->getKey(),
        ])->assertSessionHasErrors();

        $this->assertSame($grades[0]->getKey(), $consignment->refresh()->grade_id);
    }

    /** An unconfirmed consignment cannot be graded through the late route. */
    public function test_grading_before_confirmation_is_refused(): void
    {
        $world = $this->makeMilkWorld();
        $officer = $this->officerAt($world);

        $consignment = $this->awaitingConsignment($world, '30.00');
        $grade = $this->asSystem(fn () => Grade::query()->assignable()->firstOrFail());

        $this->post(route('consignments.grade', $consignment), [
            'grade_id' => $grade->getKey(),
        ])->assertSessionHasErrors();

        $this->assertNull($consignment->refresh()->grade_id);
    }

    /* ------------------------------------------------------------------ */

    private function officerAt(array $world): User
    {
        $officer = $this->makeUser('Corrections Officer');
        $this->assignRole($officer, 'Milk Collection Officer', ScopeType::Center, $world['centerA']->id);
        $this->actingAs($officer->fresh());

        return $officer;
    }

    private function awaitingConsignment(array $world, string $litres): Consignment
    {
        return $this->asSystem(fn () => Consignment::query()->create([
            'reference' => 'CNS-'.random_int(8000, 8999),
            'collection_point_id' => $world['pointA']->id,
            'collection_center_id' => $world['centerA']->id,
            'dispatched_at' => Wat::now(),
            'litres_dispatched' => $litres,
            'status' => Consignment::STATUS_AWAITING,
        ]));
    }
}
