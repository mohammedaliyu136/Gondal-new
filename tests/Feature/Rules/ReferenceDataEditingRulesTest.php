<?php

namespace Tests\Feature\Rules;

use App\Authorization\ScopeType;
use App\Models\ActivityType;
use App\Models\AdjustmentReason;
use App\Models\AuditEntry;
use App\Models\Community;
use App\Models\Lga;
use App\Models\QualityTestDefinition;
use App\Models\User;
use Tests\GondalTestCase;

/**
 * §9 — "every value is a ROW an administrator edits through Settings."
 *
 * §18.7's test proves no reference value is hardcoded. It could not notice the
 * other half: that six of these registers rendered read-only, so the values
 * were rows nobody could change without database access. The rule was true of
 * the schema and false of the system.
 */
class ReferenceDataEditingRulesTest extends GondalTestCase
{
    /** A new visit type reaches the pickers that read it, without a release. */
    public function test_an_administrator_can_add_a_reference_row(): void
    {
        $this->actingAs($this->administrator());

        $this->get(route('admin.reference.index', ['register' => 'activity-types']))->assertOk();

        $this->post(route('admin.reference.store', ['register' => 'activity-types']), [
            'code' => 'FLOOD',
            'name' => 'Post-flood verification',
            'help_text' => 'Confirm the household is still there.',
            'closes_quality_followup' => '1',
            'status' => 'active',
            'position' => 9,
        ])->assertRedirect();

        $type = ActivityType::query()->where('code', 'FLOOD')->firstOrFail();

        $this->assertSame('Post-flood verification', $type->name);
        $this->assertTrue((bool) $type->closes_quality_followup);
    }

    /**
     * BR-4 — a quality test's threshold decides which milk is gradeable, so
     * changing one is as consequential as changing a rate, and is recorded with
     * both sides.
     */
    public function test_br4_changing_a_quality_threshold_is_recorded_with_both_sides(): void
    {
        $this->actingAs($this->administrator());

        // A test that carries a threshold — the seeded vocabulary calls this
        // `range`, which is precisely why the controller reads the vocabulary
        // from the rows instead of restating it.
        $test = QualityTestDefinition::query()->whereNotNull('min_value')->firstOrFail();
        $originalMin = $test->min_value;

        $this->put(route('admin.reference.update', ['register' => 'quality-tests', 'id' => $test->getKey()]), [
            'code' => $test->code,
            'name' => $test->name,
            'kind' => $test->kind,
            'min_value' => '1.030',
            'max_value' => $test->max_value,
            'unit' => $test->unit,
            'is_required' => '1',
            'status' => 'active',
            'position' => $test->position,
        ])->assertRedirect();

        // The column is decimal:4, so the stored value reads back at its own
        // precision. Compared numerically because the point is the threshold,
        // not its formatting.
        $this->assertSame(1.0300, (float) $test->refresh()->min_value);

        $entry = AuditEntry::query()
            ->where('subject_type', QualityTestDefinition::class)
            ->where('event_type', AuditEntry::EVENT_DATA_EDIT)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame((string) $originalMin, (string) $entry->detail['before']['min_value']);
        $this->assertSame(1.0300, (float) $entry->detail['after']['min_value']);
    }

    /**
     * ARCH-6 — the field app refuses to enrol into a community that is not on
     * this register, so being able to add one is what unblocks a new village.
     */
    public function test_a_community_can_be_added_to_the_register(): void
    {
        $this->actingAs($this->administrator());

        $lga = Lga::query()->firstOrFail();

        $this->post(route('admin.reference.store', ['register' => 'communities']), [
            'name' => 'Sabon Gari',
            'lga_id' => $lga->getKey(),
        ])->assertRedirect();

        $this->assertSame(
            $lga->getKey(),
            Community::query()->where('name', 'Sabon Gari')->firstOrFail()->lga_id,
        );
    }

    /**
     * REF-1 — a row is retired, never deleted, because records already pointing
     * at it must keep resolving. There is no delete route at all.
     */
    public function test_ref1_a_reference_row_is_retired_rather_than_deleted(): void
    {
        $this->actingAs($this->administrator());

        $reason = AdjustmentReason::query()->firstOrFail();

        $this->put(route('admin.reference.update', ['register' => 'adjustment-reasons', 'id' => $reason->getKey()]), [
            'code' => $reason->code,
            'name' => $reason->name,
            'help_text' => $reason->help_text,
            'applies_to' => $reason->applies_to,
            'status' => 'retired',
            'position' => $reason->position,
        ])->assertRedirect();

        $this->assertSame('retired', $reason->refresh()->status);
        // Still there, so an adjustment that already cites it still resolves.
        $this->assertDatabaseHas('adjustment_reasons', ['id' => $reason->getKey()]);
    }

    /** ARCH-4 — the registers are the administrator's, and nobody else's. */
    public function test_arch4_reference_data_is_not_editable_by_the_people_who_read_it(): void
    {
        $this->makeMilkWorld();

        $officer = $this->makeUser('Halima Yusuf');
        $this->assignRole($officer, 'Milk Collection Officer', ScopeType::Network);
        $this->actingAs($officer->fresh());

        $this->get(route('admin.reference.index'))->assertStatus(403);

        $this->post(route('admin.reference.store', ['register' => 'activity-types']), [
            'code' => 'NOPE', 'name' => 'Should not exist', 'status' => 'active',
        ])->assertStatus(403);

        $this->assertNull(ActivityType::query()->where('code', 'NOPE')->first());
    }

    /** An unknown register is a 404, not an unguarded write into any table. */
    public function test_an_unknown_register_is_refused(): void
    {
        $this->actingAs($this->administrator());

        $this->post(route('admin.reference.store', ['register' => 'users']), [
            'name' => 'Nope',
        ])->assertStatus(404);
    }

    /* ------------------------------------------------------------------ */

    private function administrator(): User
    {
        $admin = $this->makeUser('Sadiq Ahmed');
        $this->assignRole($admin, 'System Administrator');

        return $admin->fresh();
    }
}
