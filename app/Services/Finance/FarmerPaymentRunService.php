<?php

namespace App\Services\Finance;

use App\Authorization\Access;
use App\Exceptions\RuleViolationException;
use App\Models\CollectionCenter;
use App\Models\CollectionPoint;
use App\Models\Cooperative;
use App\Models\Delivery;
use App\Models\Farmer;
use App\Models\FarmerPayment;
use App\Models\FarmerPaymentDelivery;
use App\Models\PaymentRun;
use App\Models\PendingFarmerDeduction;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowInstance;
use App\Services\Audit\AuditLogger;
use App\Services\Workflow\WorkflowEngine;
use App\Support\Sequences;
use App\Support\Wat;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * §14 Phase 7 — generating, approving and closing a farmer payment run.
 *
 * Modelled on PayrollService deliberately. The states are the same
 * (draft -> processing -> approved -> paid), the approval rides the same
 * WorkflowEngine, and BR-18's "a requester may never approve their own
 * submission" is enforced by that engine rather than reimplemented here. The
 * codebase should have one shape for "a batch of money owed to many people".
 *
 * WHAT MAKES THIS SAFE TO RUN TWICE. Nothing about the period. Generation
 * claims deliveries by inserting into `farmer_payment_deliveries`, whose
 * `delivery_id` is UNIQUE, so a second run over the same dates claims nothing
 * and produces an empty run. That is also why a consignment confirmed after its
 * month closed is simply swept into the next run rather than lost: "unpaid" is
 * defined by absence from the claim ledger, never by a date window.
 */
class FarmerPaymentRunService
{
    public function __construct(
        private readonly FarmerPaymentCalculator $calculator,
        private readonly WorkflowEngine $workflow,
        private readonly Access $access,
        private readonly AuditLogger $audit,
        private readonly FarmerDeductionPostingService $postings,
    ) {}

    /**
     * Build a draft run for everything unpaid in a scope.
     *
     * @param  CollectionCenter|Cooperative  $scope
     */
    public function generate(Model $scope, User $actor, ?string $periodStart = null, ?string $periodEnd = null): PaymentRun
    {
        $this->access->authorize($actor, 'finance.farmer_payments.create', null, 'Generate a farmer payment run');

        $scopeType = $scope instanceof CollectionCenter
            ? PaymentRun::SCOPE_CENTER
            : PaymentRun::SCOPE_COOPERATIVE;

        return DB::transaction(function () use ($scope, $scopeType, $actor, $periodStart, $periodEnd): PaymentRun {
            $run = PaymentRun::query()->create([
                'reference' => Sequences::next('payment_runs'),
                'scope_type' => $scopeType,
                'scope_id' => $scope->getKey(),
                // Dates label the run; they do not decide what it may claim.
                'period_start' => $periodStart ?? Wat::today()->startOfMonth()->toDateString(),
                'period_end' => $periodEnd ?? Wat::today()->toDateString(),
                'status' => PaymentRun::STATUS_DRAFT,
                'run_by_user_id' => $actor->getKey(),
            ]);

            $totals = ['gross' => 0, 'deductions' => 0, 'net' => 0, 'held' => 0, 'farmers' => 0, 'heldCount' => 0];

            foreach ($this->farmersIn($scope, $scopeType) as $farmer) {
                $valued = $this->calculator->value($farmer);

                // A farmer with nothing unpaid is not a line. An empty row on a
                // payout sheet is a queue of people expecting money.
                if ($valued['deliveries']->isEmpty()) {
                    continue;
                }

                $held = $valued['held'];

                $payment = FarmerPayment::query()->create([
                    'payment_run_id' => $run->getKey(),
                    'farmer_id' => $farmer->getKey(),
                    'litres_paid' => $valued['litres'],
                    'gross_minor' => $valued['gross_minor'],
                    'savings_minor' => $valued['savings_minor'],
                    'levy_minor' => $valued['levy_minor'],
                    'social_minor' => $valued['social_minor'],
                    'shop_deduction_minor' => $valued['shop_deduction_minor'],
                    'net_minor' => $valued['net_minor'],
                    // BR-15 — the percentages as they were, saved with the
                    // figure they produced.
                    'savings_pct_snapshot' => $valued['snapshots']['savings_pct'],
                    'levy_pct_snapshot' => $valued['snapshots']['levy_pct'],
                    'social_minor_snapshot' => $valued['snapshots']['social_minor'],
                    'status' => $held ? FarmerPayment::STATUS_HELD : FarmerPayment::STATUS_PAYABLE,
                    'hold_reason' => $valued['hold_reason'],
                    'breakdown' => [
                        'lines' => $valued['lines'],
                        'snapshots' => $valued['snapshots'],
                        'settled_deduction_ids' => $valued['settled_deduction_ids'],
                    ],
                ]);

                /*
                 * The claim. This is the insert that can fail on the UNIQUE, and
                 * failing is correct: it means another run took the delivery
                 * first, and the whole transaction rolls back rather than paying
                 * a litre twice.
                 */
                foreach ($valued['lines'] as $line) {
                    FarmerPaymentDelivery::query()->create([
                        'farmer_payment_id' => $payment->getKey(),
                        'delivery_id' => $line['delivery_id'],
                        'litres_payable' => $line['litres_payable'],
                        'rate_per_litre_minor' => $line['rate_per_litre_minor'],
                        'grade_id' => $line['grade_id'],
                        'consignment_id' => $line['consignment_id'],
                        'line_gross_minor' => $line['line_gross_minor'],
                    ]);
                }

                // BR-30 — a shop debt recovered here is settled here. Nothing
                // else in the system ever set settled_at, which is why these
                // rows accumulated forever.
                if ($valued['settled_deduction_ids'] !== []) {
                    PendingFarmerDeduction::query()
                        ->whereIn('id', $valued['settled_deduction_ids'])
                        ->update([
                            'status' => PendingFarmerDeduction::STATUS_SETTLED,
                            'settled_at' => Wat::now(),
                        ]);
                }

                $totals['gross'] += $valued['gross_minor'];
                $totals['deductions'] += $valued['gross_minor'] - $valued['net_minor'];
                $totals['net'] += $valued['net_minor'];
                $totals['farmers']++;

                if ($held) {
                    $totals['held'] += $valued['net_minor'];
                    $totals['heldCount']++;
                }
            }

            $run->forceFill([
                'gross_total_minor' => $totals['gross'],
                'deductions_total_minor' => $totals['deductions'],
                'net_total_minor' => $totals['net'],
                'held_net_minor' => $totals['held'],
                // The number Accounts actually sends to the points. Reading the
                // headline net as "cash to send" would ship a surplus that then
                // sits in a village untracked.
                'cash_required_minor' => $totals['net'] - $totals['held'],
                'farmer_count' => $totals['farmers'],
                'held_count' => $totals['heldCount'],
            ])->save();

            $this->audit->created(
                $run,
                sprintf('%s generated — %d farmers, %s payable now, %s held',
                    $run->reference,
                    $totals['farmers'],
                    \App\Support\Money::format($totals['net'] - $totals['held']),
                    \App\Support\Money::format($totals['held']),
                ),
                'Finance',
                ['scope' => $scopeType.':'.$scope->getKey(), 'held_count' => $totals['heldCount']],
                $actor,
            );

            return $run->refresh();
        });
    }

    /** BR-18 lives in the engine: the preparer cannot approve their own run. */
    public function submitForApproval(PaymentRun $run, User $actor): PaymentRun
    {
        if ($run->status !== PaymentRun::STATUS_DRAFT) {
            throw RuleViolationException::make(
                'ST-1',
                sprintf('Only a draft run can be submitted. %s is %s.', $run->reference, $run->status),
                ['status' => $run->status],
            );
        }

        if ((int) $run->farmer_count === 0) {
            throw RuleViolationException::make(
                'ST-1',
                'There is nothing to pay in this run.',
                ['reference' => $run->reference],
            );
        }

        $instance = $this->workflow->start(
            Workflow::APPLIES_FARMER_PAYMENT_RUN,
            $run,
            $actor,
            // Banded on what actually leaves the building, not on the headline.
            (int) $run->cash_required_minor,
        );

        $run->forceFill([
            'workflow_instance_id' => $instance->getKey(),
            'status' => PaymentRun::STATUS_PROCESSING,
        ])->save();

        return $run->refresh();
    }

    public function syncFromWorkflow(PaymentRun $run): PaymentRun
    {
        $instance = $run->workflowInstance;

        if ($instance === null) {
            return $run;
        }

        if ($instance->status === WorkflowInstance::STATUS_APPROVED && ! $run->isApproved()) {
            $run->forceFill([
                'status' => PaymentRun::STATUS_APPROVED,
                'approved_at' => $instance->completed_at,
                'approved_by_user_id' => $instance->currentStage?->id ? null : null,
            ])->save();

            /*
             * The deductions land in the cooperative's accounts HERE, on
             * approval — the point the figures become committed. Before this
             * existed the run subtracted savings, a levy and a social
             * contribution from every farmer and credited nothing at all.
             */
            $posted = $this->postings->postForRun($run);

            $this->audit->approval(
                $run,
                sprintf('%s approved for disbursement — %s. %d ledger entr(ies): %s',
                    $run->reference,
                    \App\Support\Money::format((int) $run->cash_required_minor),
                    $posted,
                    $this->postings->summaryFor($run)),
                ['farmers' => $run->farmer_count, 'ledger_entries' => $posted],
                null,
                'Finance',
            );
        }

        // A rejected run returns to draft so it can be corrected and resubmitted
        // (BR-20). The claims stay: the deliveries are still this run's.
        if ($instance->status === WorkflowInstance::STATUS_REJECTED) {
            $run->forceFill(['status' => PaymentRun::STATUS_DRAFT])->save();
        }

        return $run->refresh();
    }

    /**
     * Throw the run away and release every delivery it claimed.
     *
     * The claim ledger rows are DELETED rather than flagged, because the UNIQUE
     * on delivery_id is what makes a later run able to pick the milk up again.
     * A settled shop deduction goes back to pending for the same reason.
     */
    public function cancel(PaymentRun $run, User $actor, string $reason): PaymentRun
    {
        if ($run->isApproved()) {
            throw RuleViolationException::make(
                'ST-1',
                sprintf('%s is %s. An approved run is reversed, not cancelled.', $run->reference, $run->status),
                ['status' => $run->status],
            );
        }

        DB::transaction(function () use ($run, $actor, $reason): void {
            foreach ($run->payments()->with('lines')->get() as $payment) {
                $ids = collect($payment->breakdown['settled_deduction_ids'] ?? []);

                if ($ids->isNotEmpty()) {
                    PendingFarmerDeduction::query()->whereIn('id', $ids->all())->update([
                        'status' => PendingFarmerDeduction::STATUS_PENDING,
                        'settled_at' => null,
                    ]);
                }

                $payment->lines()->delete();
                $payment->delete();
            }

            $run->forceFill(['status' => PaymentRun::STATUS_CANCELLED])->save();

            $this->audit->edited(
                $run,
                sprintf('%s cancelled — %s', $run->reference, $reason),
                'Finance',
                ['status' => PaymentRun::STATUS_DRAFT],
                ['status' => PaymentRun::STATUS_CANCELLED, 'reason' => $reason],
                $actor,
            );
        });

        return $run->refresh();
    }

    /**
     * Farmers in scope.
     *
     * A CENTRE run reaches farmers through their default collection point, which
     * is how milk physically arrives. A COOPERATIVE run reaches them by
     * membership. Which of the two is right depends on how many farmers have no
     * cooperative — a number the plan says to go and count before committing.
     *
     * @return \Illuminate\Support\Collection<int, Farmer>
     */
    private function farmersIn(Model $scope, string $scopeType): \Illuminate\Support\Collection
    {
        $query = Farmer::withoutDataScope()->active()->with('cooperative');

        if ($scopeType === PaymentRun::SCOPE_COOPERATIVE) {
            return $query->where('cooperative_id', $scope->getKey())->orderBy('name')->get();
        }

        /*
         * withoutDataScope on the POINT lookup, deliberately.
         *
         * "Which points feed this centre?" is a structural fact about the
         * network, not a question about what the person running payroll may
         * see. Reading it through CollectionPoint's data scope meant an
         * Accounts user — who holds finance.farmer_payments but no milk.points
         * scope — resolved the centre to zero points and generated a run with
         * zero farmers and no error. A payment run that silently pays nobody is
         * the worst possible failure here.
         *
         * The authority that governs this run is finance.farmer_payments.create,
         * checked in generate() before we get here.
         */
        return $query
            ->whereIn(
                'default_collection_point_id',
                CollectionPoint::withoutDataScope()
                    ->where('collection_center_id', $scope->getKey())
                    ->select('id'),
            )
            ->orderBy('name')
            ->get();
    }

    /** Unpaid milk sitting in a scope, for the "should I run this?" figure. */
    public function unpaidDeliveryCount(Model $scope, string $scopeType): int
    {
        $farmerIds = $this->farmersIn($scope, $scopeType)->pluck('id');

        return Delivery::withoutDataScope()
            ->excludingTestData()
            ->whereIn('farmer_id', $farmerIds)
            ->whereHas('consignment', fn ($query) => $query->whereNotNull('rate_per_litre_minor'))
            ->whereNotIn('id', FarmerPaymentDelivery::query()->select('delivery_id'))
            ->count();
    }
}
