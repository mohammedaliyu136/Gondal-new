<?php

namespace Tests\Feature\Rules;

use App\Authorization\ScopeType;
use App\Models\AuditEntry;
use App\Models\CollectionPoint;
use App\Models\Consignment;
use App\Models\Delivery;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Auth\ApiTokenService;
use App\Services\Reporting\DashboardMetrics;
use App\Services\Shop\SaleService;
use App\Support\Sequences;
use App\Support\Wat;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Tests\GondalTestCase;

/**
 * ARCH-9 — "Timezone Africa/Lagos (WAT). Store UTC, present WAT."
 *
 * ArchitectureRulesTest covers the two halves that were already known to bite:
 * what lands in the column (test_arch9_instants_are_stored_utc_and_presented_wat)
 * and what a naive form value means (test_arch9_a_naive_form_time_is_read_as_west
 * _africa_time). This class covers the third, which is where the rule was actually
 * being broken forty times over: HOW YOU ASK A UTC COLUMN FOR A WAT DAY.
 *
 * Nothing in the suite had ever recorded anything between 00:00 and 00:59 WAT and
 * then asked which day's list and totals it landed on. Every test runs at whatever
 * hour CI happens to start, and for twenty-three hours out of twenty-four a WAT
 * date and a UTC date agree — so `whereDate('delivered_at', Wat::today())` looked
 * correct in green. In the missing hour a WAT day D runs from 23:00 UTC on D-1, so
 * the query returned 01:00 WAT on D through 00:59 WAT on D+1: a record made in the
 * first hour of its own day was filed against the day before, which is the day that
 * had already been reported, batched and reconciled.
 *
 * Every test here freezes the clock inside that hour. They fail against the
 * whereDate() form and pass against Wat::dayRange().
 */
class TimeAndDateRulesTest extends GondalTestCase
{
    /** 00:30 on Thursday 6 August 2026, WAT — i.e. 23:30 UTC on the 5th. */
    private const MIDNIGHT_HOUR_UTC = '2026-08-05 23:30:00';

    /**
     * ARCH-9 — a WAT calendar day is a half-open UTC interval, and the two dates
     * disagree by design for one hour.
     */
    public function test_arch9_a_wat_day_is_a_utc_range_and_not_a_date_literal(): void
    {
        $this->travelTo(Carbon::parse(self::MIDNIGHT_HOUR_UTC, 'UTC'));

        // The premise: it is already the 6th in Kano while it is still the 5th in UTC.
        $this->assertSame('2026-08-06', Wat::today()->toDateString());
        $this->assertSame('2026-08-05', Wat::now()->toDateString());

        [$start, $end] = Wat::dayRange();

        $this->assertSame('UTC', $start->getTimezone()->getName());
        $this->assertSame('UTC', $end->getTimezone()->getName());
        $this->assertSame('2026-08-05 23:00:00', $start->toDateTimeString());
        $this->assertSame('2026-08-06 23:00:00', $end->toDateTimeString());

        // The current instant is inside today's range, which is the whole point.
        $this->assertTrue(Wat::now()->greaterThanOrEqualTo($start));
        $this->assertTrue(Wat::now()->lessThan($end));

        // Half-open: consecutive days abut exactly, so the boundary instant belongs
        // to one day and no record can be counted twice or dropped between them.
        $this->assertTrue($end->equalTo(Wat::dayRange('2026-08-07')[0]));
        $this->assertTrue($start->equalTo(Wat::dayRange('2026-08-05')[1]));

        // An explicit date is read as a WAT day too, not as a UTC one.
        $this->assertSame('2026-07-31 23:00:00', Wat::dayStart('2026-08-01')->toDateTimeString());

        // A span of WAT days is inclusive of the last one.
        [$from, $to] = Wat::daysRange('2026-08-01', '2026-08-31');
        $this->assertSame('2026-07-31 23:00:00', $from->toDateTimeString());
        $this->assertSame('2026-08-31 23:00:00', $to->toDateTimeString());

        // And a WAT month is the same shape.
        $this->assertSame(
            ['2026-07-31 23:00:00', '2026-08-31 23:00:00'],
            array_map(fn (Carbon $edge) => $edge->toDateTimeString(), Wat::monthRange('2026-08-15')),
        );

        $this->travelBack();
    }

    /**
     * ARCH-9 / BR-35 — a delivery recorded at 00:30 WAT is on ITS OWN day's list
     * and in its own day's litre totals, and on neither the day before.
     */
    public function test_arch9_a_delivery_recorded_in_the_first_hour_is_on_its_own_wat_day(): void
    {
        $this->travelTo(Carbon::parse(self::MIDNIGHT_HOUR_UTC, 'UTC'));

        $world = $this->makeMilkWorld();
        $agent = $this->makeCollectionAgent($world['pointA']);
        $this->actingAs($agent);

        $this->post(route('deliveries.store'), [
            'collection_point_id' => (string) $world['pointA']->getKey(),
            'farmer_id' => (string) $world['farmer']->getKey(),
            'litres_presented' => '18.00',
        ])->assertSessionHasNoErrors();

        $reference = Delivery::withoutDataScope()->latest('id')->firstOrFail()->reference;

        // Today — the WAT day the agent believes they are working in.
        $today = $this->get(route('deliveries.index'))->assertOk();

        $this->assertTrue(
            $today->viewData('deliveries')->contains('reference', $reference),
            'A delivery recorded at 00:30 WAT vanished from its own day\'s list.',
        );
        $this->assertSame(18.0, (float) $today->viewData('totals')->presented);
        $this->assertSame(1, (int) $today->viewData('totals')->deliveries);

        // And the refusal half: yesterday must not have gained it.
        $yesterday = $this->get(route('deliveries.index', ['date' => '2026-08-05']))->assertOk();

        $this->assertFalse(
            $yesterday->viewData('deliveries')->contains('reference', $reference),
            'The delivery was also counted against the previous day, which is the day already reported.',
        );
        $this->assertNull($yesterday->viewData('totals')->presented);

        $this->travelBack();
    }

    /**
     * ARCH-9 / BR-35 — the collection point's own card agrees with the day sheet,
     * and a test agent's intake moves neither the litres nor the farmer count.
     */
    public function test_arch9_and_br35_a_points_today_figures_are_a_wat_day_excluding_test_activity(): void
    {
        $this->travelTo(Carbon::parse(self::MIDNIGHT_HOUR_UTC, 'UTC'));

        $world = $this->makeMilkWorld();
        $point = $world['pointA'];

        $agent = $this->makeCollectionAgent($point);
        $this->actingAs($agent);
        $this->post(route('deliveries.store'), [
            'collection_point_id' => (string) $point->getKey(),
            'farmer_id' => (string) $world['farmer']->getKey(),
            'litres_presented' => '12.00',
        ])->assertSessionHasNoErrors();

        // BR-35 — a test account delivering at the same point, in the same hour.
        $tester = $this->makeCollectionAgent($point, 'Test Agent', ['is_test' => true]);
        $this->actingAs($tester);
        $this->post(route('deliveries.store'), [
            'collection_point_id' => (string) $point->getKey(),
            'farmer_id' => (string) $world['farmerB']->getKey(),
            'litres_presented' => '30.00',
        ])->assertSessionHasNoErrors();

        $officer = $this->makeUser('Point Officer');
        $this->assignRole($officer, 'Milk Collection Officer');
        $this->actingAs($officer->fresh());

        $show = $this->get(route('collection-points.show', $point))->assertOk();

        $this->assertSame(
            12.0,
            (float) $show->viewData('todayTotals')->presented,
            'The point\'s totals lost the 00:30 delivery, or counted the test one.',
        );
        $this->assertSame(
            1,
            (int) $show->viewData('farmersDeliveredToday'),
            'BR-35 — a test agent\'s farmer inflated the participation figure beside litres that excluded it.',
        );

        $index = $this->get(route('collection-points.index'))->assertOk();

        $this->assertSame(
            12.0,
            (float) $index->viewData('todayByPoint')->get($point->getKey())->litres,
        );

        $this->travelBack();
    }

    /**
     * ARCH-9 / BR-29 / BR-35 — a sale rung up at 00:30 WAT is on the day's list,
     * in the day's revenue, and counted once — and a test sale is counted not at
     * all.
     */
    public function test_arch9_and_br35_a_sale_in_the_first_hour_reaches_its_own_days_revenue(): void
    {
        $this->travelTo(Carbon::parse(self::MIDNIGHT_HOUR_UTC, 'UTC'));

        [$manager, $product] = $this->shopWorld();

        $this->actingAs($manager);
        $sale = app(SaleService::class)->record([
            'customer_type' => Sale::CUSTOMER_WALKIN,
            'payment_method' => Sale::PAYMENT_CASH,
        ], [['product_id' => $product->getKey(), 'quantity' => 2.0]], $manager);

        // BR-35 — the same sale from a test account must move nothing.
        $tester = $this->makeUser('Test Shop Manager', ['is_test' => true]);
        $this->assignRole($tester, 'One-Stop Shop Manager');
        $this->actingAs($tester->fresh());
        app(SaleService::class)->record([
            'customer_type' => Sale::CUSTOMER_WALKIN,
            'payment_method' => Sale::PAYMENT_CASH,
        ], [['product_id' => $product->fresh()->getKey(), 'quantity' => 5.0]], $tester->fresh());

        $this->actingAs($manager);
        $response = $this->get(route('shop.sales.index'))->assertOk();

        $this->assertTrue(
            $response->viewData('sales')->contains('receipt_no', $sale->receipt_no),
            'A sale rung up at 00:30 WAT was missing from the day it was rung up on.',
        );
        $this->assertSame(
            2 * 12_500_00,
            (int) $response->viewData('revenueTodayMinor'),
            'The day\'s revenue lost the 00:30 sale, or gained the test one.',
        );
        $this->assertSame(1, (int) $response->viewData('saleCountToday'));

        // The refusal half — the previous WAT day is untouched.
        $yesterday = $this->get(route('shop.sales.index', ['date' => '2026-08-05']))->assertOk();

        $this->assertSame(0, (int) $yesterday->viewData('revenueTodayMinor'));
        $this->assertSame(0, (int) $yesterday->viewData('saleCountToday'));

        $this->travelBack();
    }

    /**
     * ARCH-9 / SCOPE-4 — the executive litre figures are a WAT day. Confirmed at
     * 00:30, it belongs to today's tile and not to the "yesterday" comparison the
     * change percentage is computed against.
     */
    public function test_arch9_the_dashboard_counts_a_confirmation_made_in_the_first_hour(): void
    {
        $this->travelTo(Carbon::parse(self::MIDNIGHT_HOUR_UTC, 'UTC'));

        $world = $this->makeMilkWorld();

        $director = $this->makeUser('Executive Director User');
        $this->assignRole($director, 'Executive Director');

        // Yesterday's confirmed intake, at a WAT hour both calendars agree on.
        $this->makeConfirmedConsignment($world, 'CNS-Y', '40.00', Wat::instant('2026-08-05 09:00'));
        // And this morning's, inside the hour they do not.
        $this->makeConfirmedConsignment($world, 'CNS-T', '25.00', Wat::now());

        $metrics = app(DashboardMetrics::class)->for($director->fresh());

        $this->assertSame('25.00', $metrics['milk']['litres_confirmed']);
        $this->assertSame('40.00', $metrics['milk']['litres_yesterday']);
        // -37.5%, not the -100% a manager saw at 00:30 while the milk was in.
        $this->assertSame(-37.5, $metrics['milk']['change_pct']);

        // The 7-day chart labels each bar with a WAT date, so it must sum one.
        $bars = collect($metrics['intake_week']['days'])->keyBy('date');

        $this->assertSame('25.00', $bars['2026-08-06']['litres']);
        $this->assertSame('40.00', $bars['2026-08-05']['litres']);

        $this->travelBack();
    }

    /**
     * §9 / ARCH-9 — a daily-reset reference must not repeat when the WAT day turns
     * over an hour before the UTC one.
     *
     * Sequences::next() resets on Wat::today() (WAT) while Sequence::render()
     * stamped {day} from Wat::now() (UTC). For that one hour the counter went back
     * to 1 while the reference still carried the previous date, so DEL-20260805-0001
     * was issued twice. `deliveries.reference` is unique: the first intake of the
     * day threw a 500 and the agent could not record milk at all.
     */
    public function test_arch9_a_daily_reference_does_not_repeat_across_the_wat_midnight_hour(): void
    {
        // 21:00 WAT on the 5th — both calendars say the 5th.
        $this->travelTo(Carbon::parse('2026-08-05 20:00:00', 'UTC'));

        $evening = Sequences::next('deliveries');
        $this->assertSame('DEL-20260805-0001', $evening);

        // 00:30 WAT on the 6th. It is a new WAT day, so the counter resets — and
        // the reference must carry the new date or it collides with the evening's.
        $this->travelTo(Carbon::parse(self::MIDNIGHT_HOUR_UTC, 'UTC'));

        $afterMidnight = Sequences::next('deliveries');

        $this->assertSame('DEL-20260806-0001', $afterMidnight);
        $this->assertNotSame($evening, $afterMidnight, 'The unique reference repeated itself.');

        // The yearly sequence has the same hazard at 00:30 on 1 January.
        $this->travelTo(Carbon::parse('2026-12-31 23:30:00', 'UTC'));

        $this->assertSame('REQ-2027-0001', Sequences::next('requisitions'));

        $this->travelBack();
    }

    /**
     * ARCH-9 / AUDIT-5 — an entry raised at 00:30 WAT is found on the day the user
     * says it happened.
     *
     * A refused user is shown a reference and told to quote it. If the auditor
     * filters by the date on the user's screenshot and the entry is filed a day
     * earlier, the quotable reference is not doing its job.
     */
    public function test_arch9_the_audit_day_filter_finds_an_entry_raised_in_the_first_hour(): void
    {
        $this->travelTo(Carbon::parse(self::MIDNIGHT_HOUR_UTC, 'UTC'));

        $auditor = $this->makeUser('Audit Reader');
        $this->assignRole($auditor, 'System Administrator');

        $entry = $this->asSystem(fn () => AuditEntry::query()->create([
            'event_type' => AuditEntry::EVENT_BLOCKED_ACCESS,
            'summary' => 'Blocked in the small hours',
            'reference' => 'DENY-9001',
            'occurred_at' => Wat::now(),
            'actor_user_id' => $auditor->getKey(),
            'actor_label' => $auditor->name,
        ]));

        $this->actingAs($auditor->fresh());

        $onItsOwnDay = $this->get(route('admin.audit-log', [
            'from' => '2026-08-06', 'to' => '2026-08-06',
        ]))->assertOk();

        $this->assertTrue(
            $onItsOwnDay->viewData('entries')->contains('id', $entry->getKey()),
            'An entry raised at 00:30 WAT was not on the WAT day it was raised.',
        );

        // The refusal half — it is not also on the day before.
        $theDayBefore = $this->get(route('admin.audit-log', [
            'from' => '2026-08-05', 'to' => '2026-08-05',
        ]))->assertOk();

        $this->assertFalse($theDayBefore->viewData('entries')->contains('id', $entry->getKey()));

        $this->travelBack();
    }

    /**
     * ARCH-9 — a synced record keeps the instant it was captured, never midnight.
     *
     * The phone posts a bare `date` alongside `queued_at`, and the server preferred
     * the date: Wat::instant('2026-08-06') is 00:00 WAT, so every mobile delivery
     * and sale was stored at midnight. The time of the intake was gone from the row
     * and BR-3's cut-off, which compares the WAT wall clock against the point's
     * cut-off, judged all of them at 00:00 and could never fire.
     */
    public function test_arch9_a_synced_delivery_keeps_its_capture_instant_not_midnight(): void
    {
        // 06:15 WAT, comfortably inside a normal collection morning.
        $this->travelTo(Carbon::parse('2026-08-06 05:20:00', 'UTC'));

        $world = $this->makeMilkWorld();
        $agent = $this->makeCollectionAgent($world['pointA']);

        $response = $this->actingAsMobile($agent)->postJson('/api/v1/sync/batch', [
            'milk_collections' => [[
                'client_uuid' => 'f0f0f0f0-0000-4000-8000-000000000001',
                'farmer_db_id' => $world['farmer']->getKey(),
                'collection_point_id' => $world['pointA']->getKey(),
                'volume' => '14.0',
                // Exactly what MilkCollectionModel.toPayload() and the sync queue send.
                'date' => '2026-08-06',
                'queued_at' => '2026-08-06T05:15:00.000Z',
            ]],
        ])->assertOk();

        $this->assertSame(1, $response->json('accepted'));

        $delivery = Delivery::withoutDataScope()->latest('id')->firstOrFail();

        $this->assertSame('06:15', Wat::time($delivery->delivered_at));
        $this->assertNotSame('00:00', Wat::time($delivery->delivered_at));
        $this->assertSame('2026-08-06', Wat::of($delivery->delivered_at)->toDateString());

        // And with no instant at all, the fallback is the sync receipt — still on
        // the WAT day the client claimed, still not midnight.
        $this->actingAs($agent);
        $this->postJson('/api/v1/sync/batch', [
            'milk_collections' => [[
                'client_uuid' => 'f0f0f0f0-0000-4000-8000-000000000002',
                'farmer_db_id' => $world['farmer']->getKey(),
                'collection_point_id' => $world['pointA']->getKey(),
                'volume' => '9.0',
                'date' => '2026-08-06',
            ]],
        ])->assertOk();

        $fallback = Delivery::withoutDataScope()->latest('id')->firstOrFail();

        $this->assertSame('06:20', Wat::time($fallback->delivered_at));
        $this->assertSame('2026-08-06', Wat::of($fallback->delivered_at)->toDateString());

        $this->travelBack();
    }

    /**
     * NFR-3 / NFR-1 — the instant columns the day queries filter are indexed.
     *
     * InfrastructureRulesTest::test_nfr3_the_named_composite_indexes_exist checks
     * the three composites the requirement names by hand, and
     * test_nfr3_every_foreign_key_is_indexed checks the foreign keys. Neither can
     * notice a HOT FILTER COLUMN with no index at all, which is what `confirmed_at`
     * and `reconciled_at` were: fifteen of the dashboard's date-filtered queries hit
     * confirmed_at, and every one of them scanned. This asserts against the columns
     * the application actually filters, which is the gap that let it happen.
     */
    public function test_nfr3_the_columns_the_day_queries_filter_lead_an_index(): void
    {
        $hotColumns = [
            'consignments' => 'confirmed_at',
            'batches' => 'reconciled_at',
            'deliveries' => 'delivered_at',
            'audit_entries' => 'occurred_at',
        ];

        $unindexed = [];

        foreach ($hotColumns as $table => $column) {
            $leadsAnIndex = collect(Schema::getIndexes($table))
                ->contains(fn (array $index) => ($index['columns'][0] ?? null) === $column);

            if (! $leadsAnIndex) {
                $unindexed[] = sprintf(
                    '%s.%s is filtered by every day query and leads no index — the range scans',
                    $table,
                    $column,
                );
            }
        }

        $this->assertSame([], $unindexed, implode("\n", $unindexed));
    }

    /* ------------------------------------------------------------------ */

    private function makeCollectionAgent(
        CollectionPoint $point,
        string $name = 'Boundary Agent',
        array $attributes = [],
    ): User {
        $agent = $this->makeUser($name, $attributes);
        $this->assignRole($agent, 'Collection Agent', ScopeType::Point, $point->getKey());

        return $agent->fresh();
    }

    private function makeConfirmedConsignment(
        array $world,
        string $reference,
        string $litres,
        Carbon $confirmedAt,
    ): Consignment {
        return $this->asSystem(fn () => Consignment::query()->create([
            'reference' => $reference,
            'collection_point_id' => $world['pointA']->getKey(),
            'collection_center_id' => $world['centerA']->getKey(),
            'dispatched_at' => $confirmedAt->copy()->subHours(2),
            'litres_dispatched' => $litres,
            'confirmed_at' => $confirmedAt,
            'litres_confirmed' => $litres,
            'status' => Consignment::STATUS_CONFIRMED,
        ]));
    }

    /** @return array{0: User, 1: Product} */
    private function shopWorld(int $quantity = 50): array
    {
        $manager = $this->makeUser('Boundary Shop Manager');
        $this->assignRole($manager, 'One-Stop Shop Manager');

        $product = $this->asSystem(function () use ($quantity): Product {
            $category = ProductCategory::query()->create([
                'code' => 'BND'.random_int(1000, 9999),
                'name' => 'Boundary category',
                'default_unit' => 'bag',
                'default_reorder_level' => 5,
                'requires_prescription' => false,
                'track_expiry' => false,
                'allow_credit' => true,
                'requires_manager_approval' => false,
                'status' => 'active',
            ]);

            $product = Product::query()->create([
                'sku' => 'BND'.random_int(1000, 9999),
                'name' => 'Boundary product',
                'product_category_id' => $category->getKey(),
                'unit' => 'bag',
                'cost_price_minor' => 9_800_00,
                'selling_price_minor' => 12_500_00,
                'reorder_level' => 5,
                'quantity_on_hand' => $quantity,
                'status' => 'active',
            ]);

            StockMovement::query()->create([
                'product_id' => $product->getKey(),
                'movement_type' => StockMovement::TYPE_STOCK_IN,
                'reference' => 'opening',
                'quantity_in' => $quantity,
                'quantity_out' => 0,
                'balance_after' => $quantity,
            ]);

            return $product;
        });

        return [$manager->fresh(), $product];
    }

    private function actingAsMobile(User $user): static
    {
        $token = app(ApiTokenService::class)->issue($user, request(), null)['token'];

        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => 'Bearer '.$token]);
    }
}
