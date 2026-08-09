<?php

namespace Tests\Feature\Rules;

use App\Authorization\ScopeType;
use App\Exceptions\AccessDeniedException;
use App\Models\User;
use App\Services\Milk\DeliveryService;
use App\Services\Reporting\PeriodReports;
use App\Support\Wat;
use Tests\GondalTestCase;

/**
 * §15.5 / NG-7 — the reporting layer.
 *
 * A report is a read of data somebody already holds, so it inherits the rules
 * that govern reading it. The two that would be easiest to lose, and that cost
 * the most if lost, each have a test here:
 *
 *   SCOPE-4 — "aggregates respect scope". A report that totalled the network for
 *   a centre officer would be a way to see, in one number, exactly what every
 *   list in the system is careful to hide.
 *
 *   BR-35 / TEST-1 — a report is the "report, aggregate or payroll" the rule
 *   names, so test activity is excluded from it.
 */
class ReportingRulesTest extends GondalTestCase
{
    /**
     * SCOPE-4 — the same report, two viewers, two answers, and neither is the
     * other's.
     */
    public function test_scope4_a_production_report_totals_only_the_viewers_own_scope(): void
    {
        $world = $this->makeMilkWorld();

        // 20 L at point A, 35 L at point B.
        $agentA = $this->agentAt($world['pointA']->getKey(), 'Tudun Wada Agent');
        $agentB = $this->agentAt($world['pointB']->getKey(), 'Tumfafi Agent');

        $this->recordDelivery($world['pointA'], $world['farmer'], '20.00', $agentA);
        $this->recordDelivery($world['pointB'], $world['farmerB'], '35.00', $agentB);

        $reports = app(PeriodReports::class);
        $from = Wat::today()->toDateString();

        // The point-scoped agent sees their own point and no other.
        $forAgentA = $this->reportAs($agentA, 'production', $from, $from);

        $this->assertCount(1, $forAgentA['rows']);
        $this->assertSame('Tudun Wada', $forAgentA['rows'][0]['Collection point']);
        $this->assertSame('20.00', $forAgentA['totals']['Accepted (L)']);

        // The network-scoped supervisor sees both.
        $supervisor = $this->makeUser('Network Supervisor');
        $this->assignRole($supervisor, 'Milk Collection Supervisor', ScopeType::Network);

        $forSupervisor = $this->reportAs($supervisor, 'production', $from, $from);

        $this->assertCount(2, $forSupervisor['rows']);
        $this->assertSame('55.00', $forSupervisor['totals']['Accepted (L)']);
    }

    /** ARCH-4 — a report the role may not read is refused, not silently empty. */
    public function test_arch4_a_report_is_refused_without_the_permission_that_governs_its_data(): void
    {
        $this->makeMilkWorld();

        // §16 — an Extension Agent gets no volumes and no money.
        $visitor = $this->makeUser('Yusuf Garba');
        $this->assignRole($visitor, 'Extension Agent', ScopeType::Communities);

        $reports = app(PeriodReports::class);
        $available = $reports->availableTo($visitor->fresh());

        $this->assertContains('enrolment', $available, 'They may report on the register they maintain.');
        $this->assertNotContains('production', $available);
        $this->assertNotContains('sales', $available);

        $this->expectException(AccessDeniedException::class);

        $today = Wat::today()->toDateString();
        $this->reportAs($visitor, 'production', $today, $today);
    }

    /**
     * §16 — Monitoring & Evaluation exists to report, and until this layer landed
     * it could read every underlying row while being unable to total any of them.
     */
    public function test_monitoring_and_evaluation_can_run_the_reports_its_job_depends_on(): void
    {
        $this->makeMilkWorld();

        $evaluator = $this->makeUser('Programme Evaluator');
        $this->assignRole($evaluator, 'Monitoring & Evaluation');

        $available = app(PeriodReports::class)->availableTo($evaluator->fresh());

        foreach (['production', 'quality', 'enrolment', 'extension'] as $expected) {
            $this->assertContains($expected, $available, "M&E must be able to run the {$expected} report.");
        }

        // BR-29 / §5 — and still no money. The role holds no shop.revenue.view,
        // and the report list is derived from the grants rather than from a
        // separate idea of what a reporter should see.
        $this->assertNotContains('sales', $available);
    }

    /** BR-35 / TEST-1 — a test account's litres are excluded from the report. */
    public function test_br35_test_activity_is_excluded_from_a_report(): void
    {
        $world = $this->makeMilkWorld();

        $real = $this->agentAt($world['pointA']->getKey(), 'Real Agent');
        $tester = $this->agentAt($world['pointA']->getKey(), 'Test Agent', ['is_test' => true]);

        $this->recordDelivery($world['pointA'], $world['farmer'], '20.00', $real);
        $this->recordDelivery($world['pointA'], $world['farmer'], '99.00', $tester);

        $supervisor = $this->makeUser('Reporting Supervisor');
        $this->assignRole($supervisor, 'Milk Collection Supervisor', ScopeType::Network);

        $today = Wat::today()->toDateString();
        $report = $this->reportAs($supervisor, 'production', $today, $today);

        $this->assertSame('20.00', $report['totals']['Accepted (L)'],
            'The test account\'s 99 L must not reach a report.');
    }

    /**
     * ARCH-9 — a period is a span of WAT days. A delivery recorded in the first
     * hour of a WAT day belongs to that day, and the report is the place where
     * getting that wrong moves litres between reporting months.
     */
    public function test_arch9_a_period_report_counts_the_first_hour_of_its_own_wat_day(): void
    {
        $world = $this->makeMilkWorld();
        $agent = $this->agentAt($world['pointA']->getKey(), 'Early Agent');

        // 00:30 WAT — 23:30 UTC on the previous calendar day.
        $this->travelTo(Wat::todayAt(0, 30));

        $this->recordDelivery($world['pointA'], $world['farmer'], '12.00', $agent);

        $supervisor = $this->makeUser('Boundary Supervisor');
        $this->assignRole($supervisor, 'Milk Collection Supervisor', ScopeType::Network);

        $today = Wat::today()->toDateString();
        $report = $this->reportAs($supervisor, 'production', $today, $today);

        $this->assertSame('12.00', $report['totals']['Accepted (L)'],
            'A WAT day starts at 00:00 WAT, not at 00:00 UTC.');

        // And it is not also counted against yesterday.
        $yesterday = Wat::today()->subDay()->toDateString();
        $before = $this->reportAs($supervisor, 'production', $yesterday, $yesterday);

        $this->assertSame([], $before['rows']);
    }

    /** The export is the workaround §15.5 assumed existed. */
    public function test_a_report_can_be_taken_away_as_a_file(): void
    {
        $world = $this->makeMilkWorld();
        $agent = $this->agentAt($world['pointA']->getKey(), 'Exporting Agent');
        $this->recordDelivery($world['pointA'], $world['farmer'], '20.00', $agent);

        $supervisor = $this->makeUser('Exporting Supervisor');
        $this->assignRole($supervisor, 'Milk Collection Supervisor', ScopeType::Network);
        $this->actingAs($supervisor->fresh());

        $today = Wat::today()->toDateString();

        $response = $this->get(route('reports.export', [
            'report' => 'production', 'from' => $today, 'to' => $today,
        ]));

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $csv = $response->streamedContent();

        $this->assertStringContainsString('Collection point', $csv);
        $this->assertStringContainsString('Tudun Wada', $csv);
        $this->assertStringContainsString('20.00', $csv);
        $this->assertStringContainsString('Total', $csv);
    }

    /** And the export is gated exactly as the screen is. */
    public function test_an_export_cannot_reach_data_the_screen_refuses(): void
    {
        $this->makeMilkWorld();

        $visitor = $this->makeUser('Yusuf Garba');
        $this->assignRole($visitor, 'Extension Agent', ScopeType::Communities);
        $this->actingAs($visitor->fresh());

        $today = Wat::today()->toDateString();

        $this->get(route('reports.export', [
            'report' => 'production', 'from' => $today, 'to' => $today,
        ]))->assertStatus(403);
    }

    /* ------------------------------------------------------------------ */

    /**
     * Runs a report AS the given user.
     *
     * SCOPE-4's narrowing is the models' global scope, which reads the
     * authenticated user — so a report is meaningless unless somebody is signed
     * in as the viewer, and RecordsActor stamps `is_test` from the same place.
     * Passing a user as an argument, as an earlier draft of this test did, gated
     * on one person and filtered by nobody.
     *
     * @return array{columns: array<int, string>, rows: array<int, array<string, mixed>>, totals: array<string, mixed>}
     */
    private function reportAs(User $user, string $key, string $from, string $to): array
    {
        $this->actingAs($user->fresh());

        return app(PeriodReports::class)->run($key, $from, $to);
    }

    private function agentAt(int $pointId, string $name, array $attributes = []): User
    {
        $user = $this->makeUser($name, $attributes);
        $this->assignRole($user, 'Collection Agent', ScopeType::Point, $pointId);

        return $user->fresh();
    }

    /**
     * BR-3 — milk arrives before the 07:00 cut-off. The suite's clock is frozen
     * mid-morning, so a delivery stamped "now" would be a late one and rightly
     * refused; these tests are about totals, not about the cut-off.
     */
    private function recordDelivery($point, $farmer, string $litres, User $actor): void
    {
        // Acting as the recorder, because that is the only state the service is
        // ever called in — and TEST-1's `is_test` is stamped by RecordsActor from
        // the AUTHENTICATED user, not from the actor argument. Recording without
        // signing in left a test account's litres untagged and therefore inside
        // every aggregate, which is precisely the rule this test exists to prove.
        $this->actingAs($actor->fresh());

        app(DeliveryService::class)->record($point, $farmer, [
            'litres_presented' => $litres,
            'delivered_at' => Wat::todayAt(6, 0),
        ], $actor);
    }
}
