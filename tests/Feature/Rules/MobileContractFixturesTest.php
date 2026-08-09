<?php

namespace Tests\Feature\Rules;

use App\Authorization\ScopeType;
use App\Models\CollectionPoint;
use App\Models\Farmer;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Models\ValidationReason;
use App\Services\Auth\ApiTokenService;
use App\Services\Community\FarmerValidationService;
use App\Support\Wat;
use Illuminate\Support\Facades\File;
use Tests\GondalTestCase;

/**
 * ARCH-2 — the mobile READ contract, written down as files both trees can read.
 *
 * Every mobile read path this project shipped was broken on an envelope or a key
 * name, and both suites stayed green: the server asserted its own output and the
 * app asserted its own input, and neither ever saw the other. `form-options` was
 * never unwrapped from `data`, `oss/catalog` was read from a `products` key the
 * server does not send, and `farmers/search` was read from `farmers`. Every
 * dropdown in the field app was permanently empty and nothing failed.
 *
 * So this test does not assert a shape — it EMITS one. Each endpoint's literal
 * response body is written to `contract-fixtures/`, and
 * `agents_app/test/contract_fixtures_test.dart` parses those same bytes into the
 * models the screens use and asserts each list is non-empty. A server-side rename
 * now breaks the Dart suite on the next run, which is the only place a contract
 * between two repositories can actually be held.
 *
 * The assertions here are the fixtures' own preconditions. A fixture that came
 * out empty would let the Dart side pass vacuously and prove nothing, so an empty
 * one fails HERE rather than quietly weakening the check at the other end.
 */
class MobileContractFixturesTest extends GondalTestCase
{
    /** Written by this test, read by agents_app/test/contract_fixtures_test.dart. */
    private function fixtureDirectory(): string
    {
        return dirname(base_path()).'/contract-fixtures';
    }

    /**
     * ARCH-2 — the five GET endpoints AgentConnect cannot work without.
     */
    public function test_arch2_the_mobile_read_endpoints_emit_fixtures_the_app_can_parse(): void
    {
        $world = $this->makeMilkWorld();
        $agent = $this->makeCollectionAgent();

        File::ensureDirectoryExists($this->fixtureDirectory());

        /* -------- agent/permissions ---------------------------------- */

        $permissions = $this->actingAsMobile($agent)
            ->getJson('/api/v1/agent/permissions')
            ->assertOk();

        $this->assertNotEmpty($permissions->json('data.roles'));
        $this->assertNotEmpty($permissions->json('data.permissions'));

        $this->write('agent-permissions.json', $permissions->getContent());

        /* -------- agent/form-options --------------------------------- */

        // SCOPE-2 — form-options is filtered by what the caller may see, so no
        // single persona receives all five lists: a Collection Agent holds no
        // `community.cooperatives` grant and gets an empty cooperative picker.
        // The fixture is captured from a user holding BOTH field roles, because
        // its job is to exercise every parser against the richest shape the app
        // will ever be handed — not to describe one person's day.
        $formOptions = $this->actingAsMobile($this->makeFieldSupervisor($world['communityA']->getKey()))
            ->getJson('/api/v1/agent/form-options')
            ->assertOk();

        // Every one of these feeds a required dropdown. An empty list is not a
        // thin fixture, it is a form the field worker cannot submit.
        foreach (['communities', 'cooperatives', 'collection_points', 'rejection_reasons', 'activity_types'] as $key) {
            $this->assertNotEmpty(
                $formOptions->json('data.'.$key),
                sprintf('form-options.%s is empty, so the fixture cannot prove the app parses it.', $key),
            );
        }

        // BR-3 — the phone warns before it queues a late delivery, so the point
        // must arrive carrying its own cut-off.
        $this->assertNotEmpty($formOptions->json('data.collection_points.0.cutoff_time'));

        $this->write('form-options.json', $formOptions->getContent());

        /* -------- farmers/search ------------------------------------- */

        $search = $this->actingAsMobile($agent)
            ->getJson('/api/v1/farmers/search?q=Zainab')
            ->assertOk();

        $this->assertNotEmpty($search->json('data'));
        // A scanned farmer card is a CODE, and the picker resolves it here.
        $this->assertSame($world['farmer']->code, $search->json('data.0.code'));

        $this->write('farmers-search.json', $search->getContent());

        /* -------- validations ---------------------------------------- */

        $this->assignValidationTo($agent, $world['farmer']->getKey());

        $validations = $this->actingAsMobile($agent)
            ->getJson('/api/v1/validations')
            ->assertOk();

        $this->assertNotEmpty($validations->json('data.assignments'));
        $this->assertNotEmpty($validations->json('data.outcomes'));

        $this->write('validations.json', $validations->getContent());

        /* -------- oss/catalog ---------------------------------------- */

        // BR-29 — the catalogue is told two ways. The fixture is captured for the
        // Sales Officer, who sees BOTH the price and the on-hand quantity, so the
        // app's parser is exercised against the richest shape it will ever meet.
        $this->seedShopProduct();

        $seller = $this->makeUser('Fatima Sale');
        $this->assignRole($seller, 'Sales Officer', ScopeType::Own, $seller->getKey());

        $catalog = $this->actingAsMobile($seller->fresh())
            ->getJson('/api/v1/oss/catalog')
            ->assertOk();

        $this->assertNotEmpty($catalog->json('data'));
        $this->assertNotEmpty($catalog->json('data.0.price'));
        $this->assertNotNull($catalog->json('data.0.quantity_on_hand'));

        $this->write('oss-catalog.json', $catalog->getContent());
    }

    /**
     * §18.7 applies to the client too — a vocabulary the phone carries as a
     * constant drifts from the one the server enforces, and the drift is silent.
     *
     * `payment_method` was the proof: the app offered 'Cooperative Credit', the
     * ERP recognises `credit`, and the mismatch skipped BR-25's category check
     * and left the cooperative's ledger unposted. The catalogue now serves the
     * vocabulary, so the picker and the guard cannot disagree.
     */
    public function test_the_catalogue_serves_the_sale_vocabulary_rather_than_leaving_the_phone_to_guess(): void
    {
        $this->seedShopProduct();

        $seller = $this->makeUser('Fatima Sale');
        $this->assignRole($seller, 'Sales Officer', ScopeType::Own, $seller->getKey());

        $catalog = $this->actingAsMobile($seller->fresh())
            ->getJson('/api/v1/oss/catalog')
            ->assertOk();

        $methods = collect($catalog->json('payment_methods'));

        $this->assertNotEmpty($methods, 'The phone must be told which payment methods the ERP accepts.');
        $this->assertContains('credit', $methods->pluck('code')->all());
        $this->assertContains('milk_deduction', $methods->pluck('code')->all(),
            'BR-30 — buying feed against a milk payment is a field transaction; the picker must offer it.');

        $this->assertContains('walkin', collect($catalog->json('customer_types'))->pluck('code')->all());
    }

    /* ------------------------------------------------------------------ */

    private function write(string $name, string $json): void
    {
        // Pretty-printed and re-encoded so a diff on this directory reads as a
        // contract change rather than as one long line.
        File::put(
            $this->fixtureDirectory().'/'.$name,
            json_encode(json_decode($json, true), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n",
        );
    }

    private function seedShopProduct(): void
    {
        $this->asSystem(function (): void {
            $category = ProductCategory::query()->create([
                'code' => 'FEED',
                'name' => 'Animal feed',
                'default_unit' => 'bag',
                'default_reorder_level' => 10,
                'requires_prescription' => false,
                'track_expiry' => false,
                'allow_credit' => true,
                'requires_manager_approval' => false,
                'status' => 'active',
            ]);

            Product::query()->create([
                'sku' => 'FEED-050',
                'name' => 'Dairy concentrate 50kg',
                'product_category_id' => $category->getKey(),
                'unit' => 'bag',
                'cost_price_minor' => 9_800_00,
                'selling_price_minor' => 12_500_00,
                'reorder_level' => 10,
                'quantity_on_hand' => 40,
                'status' => 'active',
            ]);
        });
    }

    private function assignValidationTo(User $agent, int $farmerId): void
    {
        $evaluator = $this->makeUser('Programme Evaluator');
        $this->assignRole($evaluator, 'Monitoring & Evaluation');

        $farmer = Farmer::withoutDataScope()->findOrFail($farmerId);
        $this->asSystem(fn () => $farmer->forceFill([
            'enrolled_on' => Wat::today()->subYears(2)->toDateString(),
            'last_validated_on' => null,
        ])->save());

        app(FarmerValidationService::class)->assign(
            $farmer,
            ValidationReason::query()->where('code', 'PERIODIC')->firstOrFail(),
            $evaluator->fresh(),
            ['assigned_to_user_id' => $agent->getKey(), 'due_on' => Wat::today()->subDay()->toDateString()],
        );
    }

    /** Both field roles at once — see the note at the form-options capture. */
    private function makeFieldSupervisor(int $communityId): User
    {
        $point = CollectionPoint::query()->where('code', 'PT-001')->firstOrFail();

        $user = $this->makeUser('Halima Fixture');
        $this->assignRole($user, 'Community Engagement Officer', ScopeType::Communities, $communityId);
        $this->assignRole($user, 'Collection Agent', ScopeType::Point, $point->getKey());

        return $user->fresh();
    }

    private function makeCollectionAgent(): User
    {
        $point = CollectionPoint::query()->where('code', 'PT-001')->firstOrFail();

        $agent = $this->makeUser('Sani Bello');
        $this->assignRole($agent, 'Collection Agent', ScopeType::Point, $point->getKey());

        return $agent->fresh();
    }

    private function actingAsMobile(User $user): static
    {
        $token = app(ApiTokenService::class)->issue($user, request(), null)['token'];

        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }
}
