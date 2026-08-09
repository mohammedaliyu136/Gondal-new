<?php

namespace Tests\Feature\Rules;

use App\Authorization\ScopeType;
use App\Models\AuditEntry;
use App\Models\ExtensionAgent;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Tests\GondalTestCase;

/**
 * The records that could be created and never corrected.
 *
 * Several tables had a service method, and in some cases a route, with no form
 * anywhere that posted to them — so the only way to fix a wrong figure was a
 * database write, which is exactly the path that bypasses the audit trail
 * AUDIT-* exists to keep. Each test below is the same shape: change the thing,
 * prove it changed, and prove the change is answerable for.
 */
class RecordEditingRulesTest extends GondalTestCase
{
    /**
     * A price change is the most routine event in a shop and had no route at
     * all. Because a sale is priced from `selling_price_minor` at the moment it
     * is rung up, the audit entry is the only answer to "why was this bag one
     * price in March and another in April".
     */
    public function test_a_products_price_can_be_changed_and_the_change_is_answerable_for(): void
    {
        $manager = $this->shopManager();
        $this->actingAs($manager);

        $product = $this->makeProduct();

        $this->put(route('shop.products.update', $product), [
            'name' => $product->name,
            'product_category_id' => $product->product_category_id,
            'unit' => $product->unit,
            'cost_price' => '3600.00',
            'selling_price' => '4800.00',
            'reorder_level' => '25',
            'status' => 'active',
        ])->assertRedirect();

        $product->refresh();

        $this->assertSame(480_000, (int) $product->selling_price_minor);
        $this->assertSame(360_000, (int) $product->cost_price_minor);

        $entry = AuditEntry::query()
            ->where('subject_type', Product::class)
            ->where('event_type', AuditEntry::EVENT_DATA_EDIT)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame(125_000, (int) $entry->detail['before']['selling_price_minor']);
        $this->assertSame(480_000, (int) $entry->detail['after']['selling_price_minor']);
    }

    /**
     * The SKU is what stock movements, sale lines and batches reconcile by, so
     * renaming it silently re-labels history. It is not on the form and is not
     * accepted from one.
     */
    public function test_the_sku_and_the_quantity_are_not_editable_through_the_product_form(): void
    {
        $manager = $this->shopManager();
        $this->actingAs($manager);

        $product = $this->makeProduct();
        $originalSku = $product->sku;

        $this->put(route('shop.products.update', $product), [
            'name' => 'Renamed',
            'product_category_id' => $product->product_category_id,
            'selling_price' => '1250.00',
            'status' => 'active',
            // Sent, and deliberately ignored.
            'sku' => 'HACKED-SKU',
            'quantity_on_hand' => '9999',
        ])->assertRedirect();

        $product->refresh();

        $this->assertSame($originalSku, $product->sku);
        $this->assertSame('0.00', (string) $product->quantity_on_hand);
        $this->assertSame('Renamed', $product->name, 'What the form does offer still works.');
    }

    /** BR-25 — a retired category cannot be a destination, by any door. */
    public function test_br25_a_product_cannot_be_moved_into_a_retired_category(): void
    {
        $manager = $this->shopManager();
        $this->actingAs($manager);

        $product = $this->makeProduct();

        $retired = $this->asSystem(fn () => ProductCategory::query()->create([
            'code' => 'RET', 'name' => 'Retired feed', 'status' => 'retired', 'position' => 99,
        ]));

        $this->put(route('shop.products.update', $product), [
            'name' => $product->name,
            'product_category_id' => $retired->getKey(),
            'selling_price' => '1250.00',
            'status' => 'active',
        ])->assertSessionHasErrors('product_category_id');

        $this->assertNotSame($retired->getKey(), $product->refresh()->product_category_id);
    }

    /**
     * §16 — "Assigns communities to agents". The register was read-only: an
     * agent could not be created and a community could not be assigned from any
     * screen, which the agent-detail page said out loud and offered no way out
     * of. The service existed; only the routes did not.
     */
    public function test_an_extension_agent_can_be_created_from_the_screen(): void
    {
        $world = $this->makeMilkWorld();

        $officer = $this->makeUser('Fatima Aliyu');
        $this->assignRole($officer, 'Community Engagement Officer', ScopeType::Communities, $world['communityA']->getKey());
        $this->actingAs($officer->fresh());

        $agentUser = $this->makeUser('Yusuf Garba');
        $this->assignRole($agentUser, 'Extension Agent', ScopeType::Communities, $world['communityA']->getKey());

        $this->post(route('extension-agents.store'), [
            'user_id' => $agentUser->getKey(),
            'code' => 'EXT-010',
            'visit_target_monthly' => 40,
            'enrolment_target_monthly' => 10,
            'status' => 'active',
            'community_ids' => [$world['communityA']->getKey()],
        ])->assertRedirect();

        $agent = ExtensionAgent::withoutDataScope()->where('code', 'EXT-010')->firstOrFail();

        $this->assertSame($agentUser->getKey(), $agent->user_id);
        $this->assertSame(40, (int) $agent->visit_target_monthly);
    }

    /** ARCH-4 — none of these forms widens who may use them. */
    public function test_arch4_editing_a_record_needs_the_edit_grant_not_the_view_one(): void
    {
        $product = $this->makeProduct();

        // A Sales Officer sees the catalogue and may not re-price it. BR-29's
        // sibling: reading a price and setting one are different authorities.
        $seller = $this->makeUser('Hauwa Ibrahim');
        $this->assignRole($seller, 'Sales Officer', ScopeType::Own);
        $this->actingAs($seller->fresh());

        $this->put(route('shop.products.update', $product), [
            'name' => 'Cheap', 'product_category_id' => $product->product_category_id,
            'selling_price' => '1.00', 'status' => 'active',
        ])->assertStatus(403);

        $this->assertSame(125_000, (int) $product->refresh()->selling_price_minor);
    }

    /* ------------------------------------------------------------------ */

    private function shopManager(): User
    {
        $user = $this->makeUser('Amina Kabir');
        $this->assignRole($user, 'One-Stop Shop Manager', ScopeType::Network);

        return $user->fresh();
    }

    private function makeProduct(): Product
    {
        return $this->asSystem(function (): Product {
            $category = ProductCategory::query()->first()
                ?? ProductCategory::query()->create([
                    'code' => 'FEED', 'name' => 'Feed', 'status' => 'active', 'position' => 1,
                ]);

            return Product::query()->create([
                'sku' => 'SKU-0001',
                'name' => 'Aluminium milk can 40L',
                'product_category_id' => $category->getKey(),
                'unit' => 'unit',
                'cost_price_minor' => 100_000,
                'selling_price_minor' => 125_000,
                'quantity_on_hand' => 0,
                'status' => 'active',
            ]);
        });
    }
}
