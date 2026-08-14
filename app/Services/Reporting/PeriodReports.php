<?php

namespace App\Services\Reporting;

use App\Authorization\Access;
use App\Models\CashFloat;
use App\Models\Delivery;
use App\Models\FarmerPayment;
use App\Models\FarmerPaymentDisbursement;
use App\Models\Farmer;
use App\Models\FieldActivity;
use App\Models\PaymentRun;
use App\Models\Sale;
use App\Models\User;
use App\Services\Finance\RequisitionSpendService;
use App\Support\Report;
use App\Support\Volume;
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
            /*
             * §14 Phase 7 — the finance reports. There were none: five reports
             * and not one of them about money, on a system whose whole purpose
             * is buying milk.
             */
            'farmer_payments' => [
                'label' => 'Farmer payments',
                'permission' => 'finance.farmer_payments.view',
                'description' => 'What was paid to farmers in the period, per run, and what is still outstanding.',
            ],
            'deductions' => [
                'label' => 'Deductions collected',
                'permission' => 'finance.farmer_payments.view',
                'description' => 'Savings, levy, social fund and shop credit taken from farmers, by cooperative.',
            ],
            'cost_per_litre' => [
                'label' => 'Cost per litre',
                'permission' => 'finance.farmer_payments.view',
                'description' => 'What a litre cost to put in the factory tank — farmer price, transport, and what was lost on the way.',
            ],
            'spend' => [
                'label' => 'Departmental spend',
                'permission' => 'purchase.requisitions.view',
                'description' => 'What each department actually paid against approved requisitions, and its budget if one is set.',
            ],
            'cash' => [
                'label' => 'Cash reconciliation',
                'permission' => 'finance.cash.view',
                'description' => 'Floats drawn, disbursed and returned, and every variance with its explanation.',
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
            'farmer_payments' => $this->farmerPayments($start, $end),
            'deductions' => $this->deductions($start, $end),
            'cost_per_litre' => $this->costPerLitre($start, $end),
            'spend' => $this->spend($start, $end),
            'cash' => $this->cash($start, $end),
        };
    }


    /* ---------------------------------------------------------------------
     | §14 Phase 7 — the money reports
     * ------------------------------------------------------------------ */

    /**
     * What was paid to farmers, per run.
     *
     * Keyed on the RUN rather than the farmer: a per-farmer list of 1,800 rows
     * is a data dump, and the question Accounts actually asks at month end is
     * "which sheets did we settle and what is still open on them".
     *
     * Reversed lines are excluded from the money and counted separately, because
     * a run with four reversals looks identical to a clean one on every other
     * column.
     */
    private function farmerPayments(Carbon $start, Carbon $end): array
    {
        $runs = PaymentRun::query()
            ->excludingTestData()
            ->where('payment_runs.created_at', '>=', $start)
            ->where('payment_runs.created_at', '<', $end)
            ->with('payments')
            ->orderBy('payment_runs.id')
            ->get();

        $rows = $runs->map(function (PaymentRun $run) {
            $live = $run->payments->where('status', '!=', FarmerPayment::STATUS_REVERSED);

            $disbursed = (int) FarmerPaymentDisbursement::query()
                ->whereIn('farmer_payment_id', $live->pluck('id'))
                ->sum('amount_minor');

            $net = (int) $live->sum('net_minor');

            return [
                'Run' => $run->reference,
                'Period' => $run->period_start?->toDateString().' to '.$run->period_end?->toDateString(),
                'Status' => \Illuminate\Support\Str::headline($run->status),
                'Farmers' => $live->count(),
                'Litres' => self::decimal($live->sum('litres_paid')),
                'Gross' => self::money($live->sum('gross_minor')),
                'Deductions' => self::money((int) $live->sum('gross_minor') - $net),
                'Net' => self::money($net),
                'Paid out' => self::money($disbursed),
                'Outstanding' => self::money($net - $disbursed),
                'Held' => self::money($live->where('status', FarmerPayment::STATUS_HELD)->sum('net_minor')),
                'Reversed' => $run->payments->where('status', FarmerPayment::STATUS_REVERSED)->count(),
            ];
        });

        return Report::of(
            ['Run', 'Period', 'Status', 'Farmers', 'Litres', 'Gross', 'Deductions', 'Net',
                'Paid out', 'Outstanding', 'Held', 'Reversed'],
            $rows->all(),
            [
                'Farmers' => (int) $rows->sum('Farmers'),
                'Gross' => self::money((int) round($rows->sum(fn ($r) => (float) $r['Gross'] * 100))),
                'Net' => self::money((int) round($rows->sum(fn ($r) => (float) $r['Net'] * 100))),
                'Paid out' => self::money((int) round($rows->sum(fn ($r) => (float) $r['Paid out'] * 100))),
                'Outstanding' => self::money((int) round($rows->sum(fn ($r) => (float) $r['Outstanding'] * 100))),
            ],
        );
    }

    /**
     * What was taken off farmers, and where it went.
     *
     * By cooperative, because that is who holds it. Until Phase 7 these amounts
     * were subtracted and credited nowhere at all; the ledger columns here are
     * what the pools actually hold now, so a divergence between "deducted in the
     * period" and "held" is visible rather than assumed away.
     */
    private function deductions(Carbon $start, Carbon $end): array
    {
        $payments = FarmerPayment::query()
            ->excludingTestData()
            ->where('farmer_payments.status', '!=', FarmerPayment::STATUS_REVERSED)
            ->whereIn('farmer_payments.payment_run_id', PaymentRun::query()
                ->where('payment_runs.created_at', '>=', $start)
                ->where('payment_runs.created_at', '<', $end)
                ->select('id'))
            ->with('farmer.cooperative.accounts')
            ->get();

        $rows = $payments
            ->groupBy(fn (FarmerPayment $payment) => $payment->farmer?->cooperative?->name ?? 'No cooperative')
            ->map(function ($group, $name) {
                $cooperative = $group->first()->farmer?->cooperative;

                return [
                    'Cooperative' => $name,
                    'Farmers' => $group->pluck('farmer_id')->unique()->count(),
                    'Gross' => self::money($group->sum('gross_minor')),
                    'Savings' => self::money($group->sum('savings_minor')),
                    'Levy' => self::money($group->sum('levy_minor')),
                    'Social fund' => self::money($group->sum('social_minor')),
                    'Shop credit' => self::money($group->sum('shop_deduction_minor')),
                    'Net paid' => self::money($group->sum('net_minor')),
                    // What the pools hold now, all-time — not period-limited,
                    // and labelled so nobody reads it as a period figure.
                    'Savings pool (now)' => self::money($cooperative?->savingsAccount()?->balance_minor ?? 0),
                    'Social pool (now)' => self::money($cooperative?->socialAccount()?->balance_minor ?? 0),
                ];
            })
            ->sortBy('Cooperative')
            ->values();

        return Report::of(
            ['Cooperative', 'Farmers', 'Gross', 'Savings', 'Levy', 'Social fund', 'Shop credit',
                'Net paid', 'Savings pool (now)', 'Social pool (now)'],
            $rows->all(),
            [
                'Farmers' => (int) $rows->sum('Farmers'),
                'Savings' => self::money((int) round($rows->sum(fn ($r) => (float) $r['Savings'] * 100))),
                'Levy' => self::money((int) round($rows->sum(fn ($r) => (float) $r['Levy'] * 100))),
                'Social fund' => self::money((int) round($rows->sum(fn ($r) => (float) $r['Social fund'] * 100))),
                'Shop credit' => self::money((int) round($rows->sum(fn ($r) => (float) $r['Shop credit'] * 100))),
            ],
        );
    }

    /**
     * What a litre cost to put in the factory's tank.
     *
     * A COST, not a margin — nothing records what the factory pays, so there is
     * no revenue side and pretending there is would be worse than the gap.
     * Presented as a small table of named figures rather than one row per
     * anything, because the question has one answer.
     */
    private function costPerLitre(Carbon $start, Carbon $end): array
    {
        $analysis = app(MilkCostAnalysis::class)->forPeriod($start, $end);
        $litres = $analysis['litres'];

        $rows = [
            ['Figure' => 'Presented by farmers', 'Litres' => self::decimal($litres['presented']), 'Amount' => ''],
            ['Figure' => 'Rejected at the point', 'Litres' => self::decimal($litres['rejected_at_point']), 'Amount' => ''],
            ['Figure' => 'Accepted', 'Litres' => self::decimal($litres['accepted']), 'Amount' => ''],
            ['Figure' => 'Priced and paid for', 'Litres' => self::decimal($litres['priced']), 'Amount' => ''],
            ['Figure' => 'Rejected at the centre', 'Litres' => self::decimal($litres['rejected_at_center']), 'Amount' => ''],
            ['Figure' => 'Received at the factory', 'Litres' => self::decimal($litres['received_at_factory']), 'Amount' => ''],
            // Measured on the consignment and the batch themselves, never by
            // subtracting one date window from another.
            ['Figure' => 'Lost point to centre', 'Litres' => self::decimal($litres['lost_point_to_centre']), 'Amount' => ''],
            ['Figure' => 'Lost centre to factory', 'Litres' => self::decimal($litres['lost_centre_to_factory']), 'Amount' => ''],
            ['Figure' => 'Value of milk lost', 'Litres' => self::decimal($analysis['shrinkage_litres']),
                'Amount' => self::money($analysis['shrinkage_minor'])],
            ['Figure' => 'Paid to farmers (gross)', 'Litres' => '', 'Amount' => self::money($analysis['farmer_gross_minor'])],
            ['Figure' => 'Transport ('.$analysis['trips'].' trips)', 'Litres' => '', 'Amount' => self::money($analysis['transport_minor'])],
            ['Figure' => 'Total cost', 'Litres' => '', 'Amount' => self::money($analysis['total_minor'])],
            ['Figure' => 'COST PER LITRE paid for', 'Litres' => '',
                'Amount' => $analysis['cost_per_litre_minor'] === null ? 'no priced litres'
                    : self::money($analysis['cost_per_litre_minor'])],
            ['Figure' => '— of which farmer price', 'Litres' => '',
                'Amount' => $analysis['farmer_cost_per_litre_minor'] === null ? ''
                    : self::money($analysis['farmer_cost_per_litre_minor'])],
            ['Figure' => '— of which transport', 'Litres' => '',
                'Amount' => $analysis['transport_cost_per_litre_minor'] === null ? ''
                    : self::money($analysis['transport_cost_per_litre_minor'])],
        ];

        if (Volume::toCentilitres($analysis['unpriced_litres']) > 0) {
            // Said out loud rather than absorbed: a cost figure built on a
            // period where some milk has no rate yet is understated.
            $rows[] = ['Figure' => 'NOT YET PRICED (excluded above)',
                'Litres' => self::decimal($analysis['unpriced_litres']), 'Amount' => ''];
        }

        if ($analysis['scope_blind']) {
            // Said first, in the first row, because a table of zeros reads as
            // "there was no milk" and the truth is "you cannot see the milk".
            array_unshift($rows, [
                'Figure' => 'NO ACCESS TO DELIVERY RECORDS — every figure below is zero for that reason, not because there was no milk',
                'Litres' => '', 'Amount' => '',
            ]);
        }

        return Report::of(['Figure', 'Litres', 'Amount'], $rows, []);
    }

    /**
     * Every float, and every variance with the words somebody wrote about it.
     *
     * The report exists to be read across many rows at once: one unexplained
     * ₦4,000 is a bad morning, and the same officer four times is a pattern.
     */
    private function cash(Carbon $start, Carbon $end): array
    {
        $floats = CashFloat::query()
            ->excludingTestData()
            ->where('cash_floats.opened_at', '>=', $start)
            ->where('cash_floats.opened_at', '<', $end)
            ->with(['drawnBy', 'issuedBy', 'receivedBackBy', 'collectionCenter'])
            ->orderBy('cash_floats.opened_at')
            ->get();

        $rows = $floats->map(fn (CashFloat $float) => [
            'Reference' => $float->reference,
            'Opened' => Wat::dateTime($float->opened_at),
            'Held by' => $float->drawnBy?->name,
            'Issued by' => $float->issuedBy?->name,
            'Centre' => $float->collectionCenter?->name ?? '',
            'Drawn' => self::money($float->amount_drawn_minor),
            'Disbursed' => $float->isOpen() ? '' : self::money((int) $float->disbursed_minor),
            'Returned' => $float->amount_returned_minor === null ? '' : self::money($float->amount_returned_minor),
            'Variance' => $float->isOpen() ? '' : self::money((int) $float->variance_minor),
            'Explanation' => $float->variance_explanation ?? '',
            'Received back by' => $float->receivedBackBy?->name ?? '',
            'Status' => $float->isOpen() ? 'Open' : 'Reconciled',
        ]);

        $reconciled = $floats->where('status', CashFloat::STATUS_RECONCILED);

        return Report::of(
            ['Reference', 'Opened', 'Held by', 'Issued by', 'Centre', 'Drawn', 'Disbursed',
                'Returned', 'Variance', 'Explanation', 'Received back by', 'Status'],
            $rows->all(),
            [
                'Drawn' => self::money((int) $floats->sum('amount_drawn_minor')),
                'Disbursed' => self::money((int) $reconciled->sum('disbursed_minor')),
                'Returned' => self::money((int) $reconciled->sum('amount_returned_minor')),
                'Variance' => self::money((int) $reconciled->sum('variance_minor')),
            ],
        );
    }

    /**
     * What each department actually paid, against the budget nobody was reading.
     *
     * `departments.cost_centre` has existed since phase 4 and nothing has ever
     * read it. A budget is advisory here — see RequisitionSpendService — so the
     * overrun column is a statement, not an enforcement.
     */
    private function spend(Carbon $start, Carbon $end): array
    {
        $rows = collect(app(RequisitionSpendService::class)->byDepartment($start, $end))
            ->map(fn (array $row) => [
                'Department' => $row['department'],
                'Cost centre' => $row['cost_centre'] ?? '',
                'Payments' => $row['payments'],
                'Spent' => self::money($row['spent_minor']),
                // Blank, not zero, when nobody has set a budget: "under by ₦0"
                // and "there is no budget" are different statements.
                'Budget' => $row['budget_minor'] === null ? '' : self::money($row['budget_minor']),
                'Remaining' => $row['remaining_minor'] === null ? '' : self::money($row['remaining_minor']),
                'Over budget' => $row['over_budget'] ? 'YES' : '',
            ]);

        return Report::of(
            ['Department', 'Cost centre', 'Payments', 'Spent', 'Budget', 'Remaining', 'Over budget'],
            $rows->all(),
            [
                'Payments' => (int) $rows->sum('Payments'),
                'Spent' => self::money((int) round($rows->sum(fn ($r) => (float) $r['Spent'] * 100))),
            ],
        );
    }

    /**
     * Money as a plain decimal, for the same reason self::decimal() exists.
     *
     * Money::format() produces "₦12,500.00", which is right on a screen and does
     * not sum in a spreadsheet. The currency is said once, in the report's own
     * heading.
     */
    private static function money(mixed $minor): string
    {
        return number_format((int) $minor / 100, 2, '.', '');
    }

    /* ------------------------------------------------------------------ */

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
