<?php

namespace App\Services\Reporting;

use App\Authorization\Access;
use App\Models\Delivery;
use App\Models\Farmer;
use App\Models\FieldActivity;
use App\Models\Sale;
use App\Models\User;
use App\Support\Report;
use App\Support\Wat;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * §15.5 / NG-7 — the reporting layer.
 *
 * Until now nothing in the system aggregated over a period the user chose. Every
 * figure on every screen was "today" or "this week", and the only date-range
 * control in the whole application was on the audit log. The consequence was not
 * subtle: the Monitoring & Evaluation role exists to report on enrolment, visit
 * targets and quality trends, and could read every underlying row while being
 * unable to total any of them over the month it was reporting on. The documented
 * workaround — "it happens in a spreadsheet today" — was not actually possible
 * either, because nothing could be exported.
 *
 * Three properties hold for every report below, and each is a rule the rest of
 * the system already obeys:
 *
 *   SCOPE-4 — "aggregates respect scope". Every query runs through the model's
 *   global scope, so a Milk Collection Officer's production report totals their
 *   own centre and a supervisor's totals the network. Nothing here calls
 *   withoutDataScope, and a report is never a way to see what a list would hide.
 *
 *   BR-35 / TEST-1 — every aggregate excludes test activity, because a report is
 *   exactly the "report, aggregate or payroll" the rule names.
 *
 *   ARCH-9 — a period is a span of WAT calendar days resolved to a half-open UTC
 *   interval through Wat::daysRange(). Comparing a WAT date to a UTC column is
 *   the defect that made every "today" figure wrong for an hour a day; a report
 *   over a month would have been wrong at both ends.
 *
 * The aggregates are GROUP BY, not a query per bucket. The dashboard's
 * per-reason and per-grade figures are issued one statement at a time, which is
 * tolerable for four buckets on one day and is not for a year.
 */
class PeriodReports
{
    public function __construct(private readonly Access $access) {}

    /**
     * Which reports this user may run at all.
     *
     * A report the viewer cannot open is not listed, for the same reason SCR-2
     * omits a nav item rather than showing a locked one: an empty report and a
     * refused one look identical, and only one of them is worth asking an
     * administrator about.
     *
     * @return array<string, array{label: string, permission: string, description: string}>
     */
    public static function catalogue(): array
    {
        return [
            'production' => [
                'label' => 'Milk production',
                'permission' => 'milk.deliveries.view',
                'description' => 'Litres presented, rejected and accepted, by collection point.',
            ],
            'quality' => [
                'label' => 'Quality and rejections',
                'permission' => 'milk.rejection.view',
                'description' => 'Rejected litres by reason, and the farmers they came from.',
            ],
            'enrolment' => [
                'label' => 'Farmer enrolment',
                'permission' => 'community.farmers.view',
                'description' => 'Farmers enrolled in the period, by community and by the agent who enrolled them.',
            ],
            'extension' => [
                'label' => 'Extension activity',
                'permission' => 'community.extension.view',
                'description' => 'Visits and farmers reached, per agent, against their monthly targets.',
            ],
            'sales' => [
                'label' => 'Shop sales',
                'permission' => 'shop.revenue.view',
                'description' => 'Sales and revenue by product category and payment method.',
            ],
        ];
    }

    /** @return array<int, string> the report keys this user may run */
    public function availableTo(User $user): array
    {
        return array_keys(array_filter(
            self::catalogue(),
            fn (array $report) => $this->access->allows($user, $report['permission']),
        ));
    }

    /**
     * Run one report over an inclusive span of WAT days.
     *
     * Deliberately takes NO user. SCOPE-4's narrowing is done by the models'
     * global scope, which reads Auth::user() — so a method that also accepted a
     * user would have two answers to "who is asking", and the one it authorised
     * against would not be the one it filtered by. Passing an out-of-scope user
     * while authenticated as somebody else would then produce a report that
     * passed its permission check and returned the wrong person's figures. There
     * is one viewer, and it is the authenticated one.
     *
     * @return array{columns: array<int, string>, rows: array<int, array<string, mixed>>, totals: array<string, mixed>}
     */
    public function run(string $key, string $from, string $to): array
    {
        $report = self::catalogue()[$key] ?? null;

        if ($report === null) {
            abort(404);
        }

        $user = auth()->user();

        /*
         * Refused rather than run unscoped. An unauthenticated caller would get
         * the models' unfiltered querysets — the global scope enforces nothing
         * when there is nobody to enforce it for — so a console or queue context
         * reaching this by accident would silently export the whole network.
         */
        if (! $user instanceof User) {
            abort(403);
        }

        // ARCH-4 — the same gate as a screen. A report is a read of the data, so
        // it is refused the same way and audited the same way (BR-34).
        app(Access::class)->authorize($user, $report['permission'], null, 'Report: '.$report['label']);

        [$start, $end] = Wat::daysRange($from, $to);

        return match ($key) {
            'production' => $this->production($start, $end),
            'quality' => $this->quality($start, $end),
            'enrolment' => $this->enrolment($from, $to),
            'extension' => $this->extension($from, $to),
            'sales' => $this->sales($start, $end),
        };
    }

    /**
     * A figure a spreadsheet can add up.
     *
     * Volume::format() appends the unit ("12 L") and Money::decimal() inserts a
     * thousands separator ("12,500.00"). Both are right for a screen and wrong
     * here: the whole point of the export is that somebody sums the column in
     * Excel, and neither of those parses as a number. The unit lives in the
     * column heading instead, where it is said once.
     */
    private static function decimal(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    /* ------------------------------------------------------------------ */

    /**
     * Litres by collection point. One GROUP BY, not one query per point.
     */
    private function production(Carbon $start, Carbon $end): array
    {
        $rows = Delivery::query()
            ->excludingTestData()
            ->where('deliveries.delivered_at', '>=', $start)
            ->where('deliveries.delivered_at', '<', $end)
            ->join('collection_points', 'collection_points.id', '=', 'deliveries.collection_point_id')
            ->groupBy('collection_points.id', 'collection_points.name')
            ->orderBy('collection_points.name')
            ->get([
                'collection_points.name as point',
                DB::raw('count(*) as deliveries'),
                DB::raw('count(distinct deliveries.farmer_id) as farmers'),
                DB::raw('sum(deliveries.litres_presented) as presented'),
                DB::raw('sum(deliveries.litres_rejected) as rejected'),
                DB::raw('sum(deliveries.litres_accepted) as accepted'),
            ]);

        return Report::of(
            ['Collection point', 'Deliveries', 'Farmers', 'Presented (L)', 'Rejected (L)', 'Accepted (L)'],
            $rows->map(fn ($row) => [
                'Collection point' => $row->point,
                'Deliveries' => (int) $row->deliveries,
                'Farmers' => (int) $row->farmers,
                'Presented (L)' => self::decimal($row->presented),
                'Rejected (L)' => self::decimal($row->rejected),
                'Accepted (L)' => self::decimal($row->accepted),
            ])->all(),
            [
                'Deliveries' => (int) $rows->sum('deliveries'),
                'Presented (L)' => self::decimal($rows->sum('presented')),
                'Rejected (L)' => self::decimal($rows->sum('rejected')),
                'Accepted (L)' => self::decimal($rows->sum('accepted')),
            ],
        );
    }

    /** BR-1 — rejected litres by the configured reason, never by a hardcoded list. */
    private function quality(Carbon $start, Carbon $end): array
    {
        $rows = Delivery::query()
            ->excludingTestData()
            ->where('deliveries.delivered_at', '>=', $start)
            ->where('deliveries.delivered_at', '<', $end)
            ->whereNotNull('deliveries.rejection_reason_id')
            ->join('rejection_reasons', 'rejection_reasons.id', '=', 'deliveries.rejection_reason_id')
            ->groupBy('rejection_reasons.id', 'rejection_reasons.name')
            ->orderByDesc(DB::raw('sum(deliveries.litres_rejected)'))
            ->get([
                'rejection_reasons.name as reason',
                DB::raw('count(*) as occurrences'),
                DB::raw('count(distinct deliveries.farmer_id) as farmers'),
                DB::raw('sum(deliveries.litres_rejected) as rejected'),
            ]);

        return Report::of(
            ['Reason', 'Occurrences', 'Farmers affected', 'Rejected (L)'],
            $rows->map(fn ($row) => [
                'Reason' => $row->reason,
                'Occurrences' => (int) $row->occurrences,
                'Farmers affected' => (int) $row->farmers,
                'Rejected (L)' => self::decimal($row->rejected),
            ])->all(),
            [
                'Occurrences' => (int) $rows->sum('occurrences'),
                'Rejected (L)' => self::decimal($rows->sum('rejected')),
            ],
        );
    }

    /**
     * Enrolment by community. `enrolled_on` is a genuine date column, so it is
     * compared against WAT calendar dates — not the UTC range the instant
     * columns need.
     */
    private function enrolment(string $from, string $to): array
    {
        $rows = Farmer::query()
            ->whereBetween('farmers.enrolled_on', [
                Wat::of($from)->toDateString(),
                Wat::of($to)->toDateString(),
            ])
            ->join('communities', 'communities.id', '=', 'farmers.community_id')
            ->leftJoin('users', 'users.id', '=', 'farmers.enrolled_by_user_id')
            ->groupBy('communities.id', 'communities.name', 'users.id', 'users.name')
            ->orderBy('communities.name')
            ->get([
                'communities.name as community',
                DB::raw("coalesce(users.name, 'Unattributed') as enrolled_by"),
                DB::raw('count(*) as farmers'),
            ]);

        return Report::of(
            ['Community', 'Enrolled by', 'Farmers'],
            $rows->map(fn ($row) => [
                'Community' => $row->community,
                'Enrolled by' => $row->enrolled_by,
                'Farmers' => (int) $row->farmers,
            ])->all(),
            ['Farmers' => (int) $rows->sum('farmers')],
        );
    }

    /**
     * §16 — the Community Engagement Officer "tracks visit and enrolment targets",
     * and M&E reports on them. The target lives on the agent record, so
     * actual-against-target is a join rather than a second trip.
     */
    private function extension(string $from, string $to): array
    {
        $rows = FieldActivity::query()
            ->excludingTestData()
            ->whereBetween('field_activities.activity_date', [
                Wat::of($from)->toDateString(),
                Wat::of($to)->toDateString(),
            ])
            ->join('extension_agents', 'extension_agents.id', '=', 'field_activities.extension_agent_id')
            ->leftJoin('users', 'users.id', '=', 'extension_agents.user_id')
            ->groupBy('extension_agents.id', 'extension_agents.code', 'users.name', 'extension_agents.visit_target_monthly')
            ->orderBy('extension_agents.code')
            ->get([
                'extension_agents.code as code',
                DB::raw('coalesce(users.name, extension_agents.code) as agent'),
                'extension_agents.visit_target_monthly as target',
                DB::raw('count(*) as visits'),
                DB::raw('sum(field_activities.farmers_reached) as reached'),
            ]);

        return Report::of(
            ['Agent', 'Code', 'Visits', 'Monthly target', 'Farmers reached'],
            $rows->map(fn ($row) => [
                'Agent' => $row->agent,
                'Code' => $row->code,
                'Visits' => (int) $row->visits,
                // Null, not zero, for an agent nobody has set a target for — the
                // two mean different things and a report that conflates them
                // makes every unconfigured agent look like a failing one.
                'Monthly target' => $row->target === null ? '—' : (int) $row->target,
                'Farmers reached' => (int) $row->reached,
            ])->all(),
            [
                'Visits' => (int) $rows->sum('visits'),
                'Farmers reached' => (int) $rows->sum('reached'),
            ],
        );
    }

    /** BR-29 — gated on shop.revenue.view, so money never reaches a Sales Officer. */
    private function sales(Carbon $start, Carbon $end): array
    {
        $rows = Sale::query()
            ->excludingTestData()
            ->notVoided()
            ->where('sales.sold_at', '>=', $start)
            ->where('sales.sold_at', '<', $end)
            ->groupBy('sales.payment_method')
            ->orderBy('sales.payment_method')
            ->get([
                'sales.payment_method as method',
                DB::raw('count(*) as sales'),
                DB::raw('sum(sales.total_minor) as total_minor'),
            ]);

        return Report::of(
            ['Payment method', 'Sales', 'Total (₦)'],
            $rows->map(fn ($row) => [
                'Payment method' => $row->method,
                'Sales' => (int) $row->sales,
                'Total (₦)' => self::decimal(((int) $row->total_minor) / 100),
            ])->all(),
            [
                'Sales' => (int) $rows->sum('sales'),
                'Total (₦)' => self::decimal(((int) $rows->sum('total_minor')) / 100),
            ],
        );
    }
}
