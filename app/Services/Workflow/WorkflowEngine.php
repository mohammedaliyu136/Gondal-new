<?php

namespace App\Services\Workflow;

use App\Authorization\Denials;
use App\Authorization\PermissionKey;
use App\Authorization\Scopes\DataScope;
use App\Contracts\Scopeable;
use App\Exceptions\AccessDeniedException;
use App\Exceptions\RuleViolationException;
use App\Models\AuditEntry;
use App\Models\Delegation;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowAction;
use App\Models\WorkflowInstance;
use App\Models\WorkflowStage;
use App\Services\Audit\AuditLogger;
use App\Services\Notifications\NotificationService;
use App\Support\Money;
use App\Support\Wat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * §6.5 / §7.4 — the configurable sequential approval chain (G-3).
 *
 * BR-17 strictly sequential: a stage is actionable only when every prior
 *       applicable stage is approved
 * BR-18 a requester may NEVER approve their own submission at any stage, even
 *       holding the permission — 403, not a 422
 * BR-19 the applicable stages come from the matching band for the amount
 * BR-20 a rejection ends the instance and returns the subject; resubmission
 *       starts a NEW instance and the old one is retained
 * BR-21 request_info records an action and notifies without advancing or ending
 * BR-22 an approver may reduce the amount, never raise it
 * BR-23 stages reference ROLES, not users
 * BR-24 an active delegation routes the queue to the delegate; both users are
 *       recorded on the action
 */
class WorkflowEngine
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly NotificationService $notifications,
        private readonly SubjectSynchroniser $subjects,
    ) {}

    /* ---------------------------------------------------------------------
     | Starting
     * ------------------------------------------------------------------ */

    /**
     * BR-19 / BR-20 — always creates a NEW instance. Resubmitting after a
     * rejection therefore never reopens the old one.
     */
    public function start(string $appliesTo, Model $subject, User $requester, ?int $amountMinor): WorkflowInstance
    {
        $workflow = Workflow::query()->for($appliesTo)->with(['stages', 'bands.stages'])->first();

        if ($workflow === null) {
            throw RuleViolationException::make(
                'BR-19',
                "No active workflow is configured for {$appliesTo}. Configure one in Settings → Approval Workflows.",
                ['applies_to' => $appliesTo],
            );
        }

        $band = $workflow->bandFor($amountMinor);

        $instance = DB::transaction(function () use ($workflow, $band, $subject, $requester, $amountMinor): WorkflowInstance {
            $instance = WorkflowInstance::query()->create([
                'workflow_id' => $workflow->getKey(),
                'workflow_band_id' => $band?->getKey(),
                'subject_type' => $subject->getMorphClass(),
                'subject_id' => $subject->getKey(),
                'status' => WorkflowInstance::STATUS_IN_PROGRESS,
                'requester_user_id' => $requester->getKey(),
                'amount_minor' => $amountMinor,
                'approved_amount_minor' => $amountMinor,
                'started_at' => Wat::now(),
                'is_test' => $requester->is_test,
            ]);

            $instance->setRelation('subject', $subject);

            $stages = $instance->applicableStages();
            $submission = $stages->first(fn (WorkflowStage $stage) => $stage->is_submission);

            // The submission stage is satisfied by the act of submitting.
            if ($submission !== null) {
                $submissionPayload = null;
                if ($subject instanceof \App\Models\Requisition) {
                    $submissionPayload = [
                        'items' => $subject->items->map(fn ($it) => [
                            'item' => $it->item,
                            'purpose' => $it->purpose,
                            'quantity' => (float) $it->quantity,
                            'unit' => $it->unit,
                            'unit_price_minor' => (int) $it->unit_price_minor,
                            'amount_minor' => (int) $it->amount_minor,
                            'status' => 'requested',
                        ])->all(),
                    ];
                }

                WorkflowAction::query()->create([
                    'workflow_instance_id' => $instance->getKey(),
                    'workflow_stage_id' => $submission->getKey(),
                    'actor_user_id' => $requester->getKey(),
                    'action' => WorkflowAction::ACTION_SUBMIT,
                    'amount_minor' => $amountMinor,
                    'action_payload' => $submissionPayload,
                    'acted_at' => Wat::now(),
                ]);
            }

            $next = $stages->first(fn (WorkflowStage $stage) => ! $stage->is_submission);

            $instance->forceFill([
                'current_stage_id' => $next?->getKey(),
                'current_stage_due_at' => $this->dueAt($next),
            ])->save();

            // §18.5 / AUDIT-2 — written inside the transaction, so an audit
            // failure rolls the submission back instead of orphaning it. See
            // auditAction() for the incident that made the ordering matter.
            $this->audit->approval(
                $subject,
                sprintf(
                    '%s submitted into %s%s',
                    $this->describe($subject),
                    $workflow->name,
                    $band === null ? '' : ' ('.$band->name.' band, '.$stages->count().' stages)',
                ),
                [
                    'workflow' => $workflow->code,
                    'band' => $band?->name,
                    'amount_minor' => $amountMinor,
                    'stages' => $stages->pluck('name')->all(),
                    'rules' => ['BR-19'],
                ],
                $requester,
            );

            return $instance;
        });

        $this->notifyCurrentStage($instance);

        return $instance;
    }

    /* ---------------------------------------------------------------------
     | Acting
     * ------------------------------------------------------------------ */

    /**
     * BR-17 / BR-18 / BR-22 — approve the current stage.
     */
    public function approve(
        WorkflowInstance $instance,
        User $actor,
        ?int $amountMinor = null,
        ?string $comment = null,
        ?array $actionPayload = null,
    ): WorkflowInstance {
        $stage = $this->guardActionable($instance, $actor);
        $subject = $this->subjectOf($instance);

        // BR-22 — "An approver may reduce amount_minor but never increase it
        // above the requested total."
        $approved = (int) ($instance->approved_amount_minor ?? $instance->amount_minor);

        if ($amountMinor !== null) {
            if (! $instance->workflow->option('approver_may_reduce_amount', true)) {
                throw RuleViolationException::make(
                    'BR-22',
                    'This workflow does not allow approvers to change the amount.',
                    ['workflow' => $instance->workflow->code],
                    'amount_minor',
                );
            }

            /*
             * BR-22 bounds one end of the range and says nothing about the other,
             * so a negative approved amount used to sail through and reach
             * `requisitions.approved_total_minor` — a figure Accounts pays
             * against — and `payroll_runs` by the same path. An approval below
             * zero is not a reduction; it is a different kind of mistake.
             */
            if ($amountMinor < 0) {
                throw RuleViolationException::make(
                    'BR-22',
                    'An approved amount cannot be negative.',
                    ['attempted_minor' => $amountMinor],
                    'amount_minor',
                );
            }

            /*
             * The ceiling is what the chain has ALREADY settled on, not what was
             * originally requested. Comparing against `amount_minor` let a later
             * stage quietly undo an earlier one: requested ₦500,000, Department
             * Head approves it down to ₦100,000, Internal Audit approves at
             * ₦500,000 and the reduction is gone. BR-19 fixes the band at start(),
             * so nothing re-routes and no stage is added to notice. A reduction is
             * monotonic; restoring the original figure means rejecting under BR-20
             * and having it resubmitted, which leaves a trail.
             */
            if ($amountMinor > $approved) {
                throw RuleViolationException::make(
                    'BR-22',
                    sprintf(
                        'An approver may reduce the amount but never raise it above the %s %s.',
                        $approved === (int) $instance->amount_minor ? 'requested' : 'already approved',
                        Money::format($approved),
                    ),
                    [
                        'requested_minor' => (int) $instance->amount_minor,
                        'ceiling_minor' => $approved,
                        'attempted_minor' => $amountMinor,
                    ],
                    'amount_minor',
                );
            }

            $approved = $amountMinor;
        }

        $delegation = $this->activeDelegationFor($actor, $stage);

        DB::transaction(function () use ($instance, $subject, $stage, $actor, $approved, $comment, $delegation, $actionPayload): void {
            WorkflowAction::query()->create([
                'workflow_instance_id' => $instance->getKey(),
                'workflow_stage_id' => $stage->getKey(),
                'actor_user_id' => $actor->getKey(),
                // BR-24 — a delegated action records both users.
                'on_behalf_of_user_id' => $delegation?->from_user_id,
                'delegation_id' => $delegation?->getKey(),
                'action' => WorkflowAction::ACTION_APPROVE,
                'amount_minor' => $approved,
                'comment' => $comment,
                'action_payload' => $actionPayload,
                'acted_at' => Wat::now(),
            ]);

            $instance->approved_amount_minor = $approved;

            // BR-17 — advance one stage, in order.
            $next = $instance->nextStage();

            if ($next === null) {
                $instance->forceFill([
                    'status' => WorkflowInstance::STATUS_APPROVED,
                    'current_stage_id' => null,
                    'current_stage_due_at' => null,
                    'completed_at' => Wat::now(),
                    'approved_amount_minor' => $approved,
                ])->save();
            } else {
                $instance->forceFill([
                    'current_stage_id' => $next->getKey(),
                    'current_stage_due_at' => $this->dueAt($next),
                    'approved_amount_minor' => $approved,
                ])->save();
            }

            $this->auditAction(
                AuditEntry::EVENT_APPROVAL,
                $instance,
                $subject,
                sprintf(
                    '%s approved at %s by %s%s',
                    $this->describe($subject),
                    $stage->name,
                    $actor->name,
                    $delegation === null ? '' : ' on behalf of '.($delegation->fromUser?->name ?? 'a delegator'),
                ),
                [
                    'stage' => $stage->name,
                    'approved_amount_minor' => $approved,
                    'next_stage' => $next?->name,
                    'status' => $instance->status,
                    'delegated' => $delegation !== null,
                    'rules' => ['BR-17', 'BR-22', 'BR-24'],
                ],
                $actor,
            );

            // BR-20 / BR-22 — the subject mirrors the instance in the same
            // transaction, so it can never be left behind by a caller that
            // forgot to ask.
            $this->subjects->sync($subject);
        });

        $instance->refresh()->setRelation('subject', $subject);

        if ($instance->status === WorkflowInstance::STATUS_APPROVED) {
            $this->notifyRequester($instance, 'approved');
        } else {
            $this->notifyCurrentStage($instance);
        }

        return $instance;
    }

    /**
     * BR-20 — "A rejection at any stage sets the instance to rejected and returns
     * the subject to the requester, who may revise and resubmit."
     */
    public function reject(WorkflowInstance $instance, User $actor, string $comment): WorkflowInstance
    {
        $stage = $this->guardActionable($instance, $actor);
        $subject = $this->subjectOf($instance);

        if (! $stage->can_reject) {
            throw RuleViolationException::make(
                'BR-20',
                "The {$stage->name} stage is not configured to reject. Request more information instead.",
                ['stage' => $stage->name],
            );
        }

        if (trim($comment) === '') {
            throw RuleViolationException::make(
                'BR-20',
                'A rejection needs a reason the requester can act on.',
                [],
                'comment',
            );
        }

        $delegation = $this->activeDelegationFor($actor, $stage);

        DB::transaction(function () use ($instance, $subject, $stage, $actor, $comment, $delegation): void {
            WorkflowAction::query()->create([
                'workflow_instance_id' => $instance->getKey(),
                'workflow_stage_id' => $stage->getKey(),
                'actor_user_id' => $actor->getKey(),
                'on_behalf_of_user_id' => $delegation?->from_user_id,
                'delegation_id' => $delegation?->getKey(),
                'action' => WorkflowAction::ACTION_REJECT,
                'comment' => $comment,
                'acted_at' => Wat::now(),
            ]);

            $instance->forceFill([
                'status' => WorkflowInstance::STATUS_REJECTED,
                'current_stage_id' => null,
                'current_stage_due_at' => null,
                'completed_at' => Wat::now(),
            ])->save();

            $this->auditAction(
                AuditEntry::EVENT_REJECTION,
                $instance,
                $subject,
                sprintf('%s rejected at %s by %s', $this->describe($subject), $stage->name, $actor->name),
                ['stage' => $stage->name, 'comment' => $comment, 'rule' => 'BR-20'],
                $actor,
            );

            // BR-20 — "returns the subject to the requester, who may revise and
            // resubmit". Without this the subject stays `in_review` and neither
            // resubmit() nor submit() will accept it ever again.
            $this->subjects->sync($subject);
        });

        $instance->refresh()->setRelation('subject', $subject);

        $this->notifyRequester($instance, 'rejected');

        return $instance;
    }

    /**
     * BR-21 — "request_info records an action and notifies the requester without
     * advancing or ending the instance."
     */
    public function requestInfo(WorkflowInstance $instance, User $actor, string $comment): WorkflowAction
    {
        $stage = $this->guardActionable($instance, $actor);
        $subject = $this->subjectOf($instance);

        if (! $instance->workflow->option('allow_request_info', true)) {
            throw RuleViolationException::make(
                'BR-21',
                'This workflow does not allow requesting more information.',
                ['workflow' => $instance->workflow->code],
            );
        }

        if (trim($comment) === '') {
            throw RuleViolationException::make(
                'BR-21',
                'Say what information you need.',
                [],
                'comment',
            );
        }

        $action = DB::transaction(function () use ($instance, $subject, $stage, $actor, $comment): WorkflowAction {
            $action = WorkflowAction::query()->create([
                'workflow_instance_id' => $instance->getKey(),
                'workflow_stage_id' => $stage->getKey(),
                'actor_user_id' => $actor->getKey(),
                'action' => WorkflowAction::ACTION_REQUEST_INFO,
                'comment' => $comment,
                'acted_at' => Wat::now(),
            ]);

            // Deliberately no status or stage change (BR-21), and so no subject
            // sync either — nothing about the subject has been decided.
            $this->auditAction(
                AuditEntry::EVENT_APPROVAL,
                $instance,
                $subject,
                sprintf('%s — more information requested at %s', $this->describe($subject), $stage->name),
                ['stage' => $stage->name, 'comment' => $comment, 'rule' => 'BR-21'],
                $actor,
            );

            return $action;
        });

        $instance->setRelation('subject', $subject);

        $this->notifyRequester($instance, 'info_requested', $comment);

        return $action;
    }

    /**
     * §8 — `in_progress → cancelled`. The requester withdrawing an item whose
     * need has passed, rather than leaving it in an approver's queue accruing
     * NOTIF-4 reminders until somebody rejects it (which under BR-20 then forces
     * a whole new instance if it is ever wanted again).
     *
     * WHO may cancel is the caller's business — the controller restricts it to
     * the requester, the same way it restricts submit().
     */
    public function cancel(WorkflowInstance $instance, User $actor, ?string $comment = null): WorkflowInstance
    {
        if (! $instance->isOpen()) {
            throw RuleViolationException::make(
                'ST-1',
                'That approval is already '.$instance->status.'.',
                ['status' => $instance->status],
            );
        }

        $subject = $this->subjectOf($instance);
        $stage = $instance->currentStage;

        DB::transaction(function () use ($instance, $subject, $actor, $comment): void {
            WorkflowAction::query()->create([
                'workflow_instance_id' => $instance->getKey(),
                'workflow_stage_id' => $instance->current_stage_id,
                'actor_user_id' => $actor->getKey(),
                'action' => WorkflowAction::ACTION_CANCEL,
                'comment' => $comment,
                'acted_at' => Wat::now(),
            ]);

            $instance->forceFill([
                'status' => WorkflowInstance::STATUS_CANCELLED,
                'current_stage_id' => null,
                'current_stage_due_at' => null,
                'completed_at' => Wat::now(),
            ])->save();

            $this->auditAction(
                AuditEntry::EVENT_APPROVAL,
                $instance,
                $subject,
                sprintf('%s withdrawn by %s', $this->describe($subject), $actor->name),
                ['comment' => $comment, 'rules' => ['ST-1']],
                $actor,
            );

            $this->subjects->sync($subject);
        });

        $instance->refresh()->setRelation('subject', $subject);

        // NOTIF-3 in reverse — whoever was holding it needs to see it leave
        // their queue, not merely find it gone.
        $this->notifyWithdrawal($instance, $stage, $actor);

        return $instance;
    }

    /* ---------------------------------------------------------------------
     | The queue
     * ------------------------------------------------------------------ */

    /**
     * BR-23 — "Any user holding the stage's role and satisfying scope sees the
     * item in /approvals."
     * BR-24 — plus anything delegated to them.
     * BR-18 — minus their own submissions, which they may never approve.
     *
     * @return Builder<WorkflowInstance>
     */
    public function queueFor(User $user): Builder
    {
        $roleIds = array_merge(
            $user->effectiveRoles()->pluck('id')->all(),
            $user->delegatedRoleIds(),
        );

        return WorkflowInstance::query()
            ->open()
            ->whereHas('currentStage', fn (Builder $query) => $query->whereIn('approving_role_id', $roleIds))
            // BR-18 — never your own.
            ->where(function (Builder $query) use ($user) {
                $query->whereNull('requester_user_id')
                    ->orWhere('requester_user_id', '!=', $user->getKey());
            })
            // BR-23 — "and satisfying scope". Without this half a Department Head
            // was shown every other department's requisitions.
            ->where(fn (Builder $query) => $this->constrainToScope($query, $user))
            ->with(['subject', 'currentStage.approvingRole', 'workflow', 'requester']);
    }

    /**
     * BR-23's scope half, at the list level.
     *
     * WorkflowInstance is deliberately not Scopeable — it is a journey, not a
     * record with a department or a centre of its own — so the narrowing has to
     * reach through the morph to the SUBJECT, and the scope that applies is the
     * one attached to the permission the queue's own stage requires (SCOPE-2:
     * the scope belongs to the permission being exercised, not to the model).
     *
     * That is why this groups by stage rather than filtering once. Stages are
     * grouped by their required permission, and each group is constrained with
     * the scope set for exactly that permission: a user who is a Department Head
     * for Logistics and an Internal Auditor network-wide sees Logistics items at
     * the Department Head stage and everything at the Internal Audit stage, which
     * is what guardActionable() will also conclude, one record at a time.
     *
     * BR-24 — a delegated stage is constrained by the DELEGATOR's scope, because
     * that is the authority the delegate is exercising. Constraining it by the
     * delegate's own scope would empty the delegated queue, which is the whole
     * point of a delegation.
     */
    private function constrainToScope(Builder $query, User $user): void
    {
        $groups = [];

        foreach ($this->stagesApprovedBy($user->effectiveRoles()->pluck('id')->all()) as $permission => $stageIds) {
            $groups[] = [$stageIds, $user, $user->scopeSetFor((string) $permission)];
        }

        /** @var Delegation $delegation */
        foreach ($user->delegationsReceived()->active()->with('fromUser')->get() as $delegation) {
            $from = $delegation->fromUser;

            if ($from === null) {
                continue;
            }

            foreach ($this->stagesApprovedBy([(int) $delegation->role_id]) as $permission => $stageIds) {
                $groups[] = [$stageIds, $from, $from->scopeSetFor((string) $permission)];
            }
        }

        if ($groups === []) {
            return;
        }

        foreach ($groups as [$stageIds, $holder, $scopes]) {
            $query->orWhere(function (Builder $inner) use ($stageIds, $holder, $scopes): void {
                $inner->whereIn('current_stage_id', $stageIds)
                    ->whereHasMorph('subject', '*', function (Builder $subject) use ($holder, $scopes): void {
                        $model = $subject->getModel();

                        // A subject with no scope of its own (a payroll run is a
                        // network-wide artefact) is admitted by the role alone.
                        if (! $model instanceof Scopeable) {
                            return;
                        }

                        $subject->withoutGlobalScope(DataScope::class);

                        DataScope::constrain($subject, $model, $scopes, $holder);
                    });
            });
        }
    }

    /**
     * The stages a set of roles approves at, keyed by the permission each one
     * requires.
     *
     * A stage that requires no permission cannot have a scope resolved for it, so
     * it is keyed under the empty string and gets an empty ScopeSet — which fails
     * closed (ROLE-2). Every seeded approval stage names a permission; a stage
     * that does not is a configuration error, and an empty queue is the right way
     * for it to show up.
     *
     * @param  array<int, int>  $roleIds
     * @return array<string, array<int, int>>
     */
    private function stagesApprovedBy(array $roleIds): array
    {
        if ($roleIds === []) {
            return [];
        }

        $grouped = [];

        $stages = WorkflowStage::query()
            ->whereIn('approving_role_id', $roleIds)
            ->where('is_submission', false)
            ->get(['id', 'required_permission']);

        foreach ($stages as $stage) {
            $grouped[(string) $stage->required_permission][] = (int) $stage->getKey();
        }

        return $grouped;
    }

    /**
     * Can this user act on this instance right now? Used by the UI to decide
     * whether to render the action buttons.
     */
    public function canAct(WorkflowInstance $instance, User $user): bool
    {
        try {
            $this->guardActionable($instance, $user);

            return true;
        } catch (RuleViolationException|AccessDeniedException) {
            return false;
        }
    }

    /* ---------------------------------------------------------------------
     | Guards
     * ------------------------------------------------------------------ */

    /**
     * BR-17 / BR-18 / BR-23 — everything that must be true before an action.
     *
     * @throws RuleViolationException
     * @throws AccessDeniedException
     */
    private function guardActionable(WorkflowInstance $instance, User $actor): WorkflowStage
    {
        if (! $instance->isOpen()) {
            throw RuleViolationException::make(
                'ST-1',
                'That approval is already '.$instance->status.'.',
                ['status' => $instance->status],
            );
        }

        $stage = $instance->currentStage;

        if ($stage === null) {
            throw RuleViolationException::make(
                'BR-17',
                'That approval has no current stage.',
                ['instance' => $instance->getKey()],
            );
        }

        /*
         * BR-18 — "A requester may never approve their own submission at any
         * stage, even if they hold the permission. Reject with 403."
         *
         * 403 rather than 422 is deliberate and comes straight from the rule, so
         * this is a denial (audited via Denials) rather than a rule violation.
         */
        if ($instance->requester_user_id === $actor->getKey()) {
            throw app(Denials::class)->permission(
                $actor,
                $stage->required_permission ?? 'purchase.requisitions.approve',
                'Approve own submission at '.$stage->name,
                ['rule' => 'BR-18', 'instance' => $instance->getKey()],
            );
        }

        // BR-17 — every prior applicable stage must be approved.
        $stages = $instance->applicableStages();
        $index = $stages->search(fn (WorkflowStage $candidate) => $candidate->id === $stage->id);

        if ($index === false) {
            throw RuleViolationException::make(
                'BR-19',
                'That stage does not apply to this item under its amount band.',
                ['stage' => $stage->name],
            );
        }

        $approvedStageIds = $instance->actions()
            ->whereIn('action', [WorkflowAction::ACTION_APPROVE, WorkflowAction::ACTION_SUBMIT])
            ->pluck('workflow_stage_id')
            ->all();

        foreach ($stages->take($index) as $prior) {
            if (! in_array($prior->id, $approvedStageIds, true)) {
                throw RuleViolationException::make(
                    'BR-17',
                    "Approval is sequential: {$prior->name} has not approved yet.",
                    ['blocking_stage' => $prior->name],
                );
            }
        }

        // BR-23 — the stage's role, or an active delegation of it (BR-24).
        $holdsRoleDirectly = $stage->approving_role_id === null
            || $actor->effectiveRoles()->contains('id', $stage->approving_role_id);

        $holdsRoleByDelegation = $stage->approving_role_id !== null
            && in_array((int) $stage->approving_role_id, $actor->delegatedRoleIds(), true);

        if (! $holdsRoleDirectly && ! $holdsRoleByDelegation) {
            throw app(Denials::class)->permission(
                $actor,
                $stage->required_permission ?? 'purchase.requisitions.approve',
                'Act at the '.$stage->name.' stage',
                ['rule' => 'BR-23', 'required_role_id' => $stage->approving_role_id],
            );
        }

        /*
         * The stage's own permission, on top of the role.
         *
         * BR-24 — a delegate holds the DELEGATED ROLE's authority for this stage,
         * not their own. Checking the actor's effective permissions here would make
         * every delegation useless, because a delegate by definition does not hold
         * the delegator's role. So the permission is looked for on the delegated
         * role itself, which keeps the delegation narrow: it conveys exactly what
         * that one role grants at that one stage, and nothing else.
         */
        if ($stage->required_permission !== null) {
            $satisfied = $holdsRoleDirectly
                ? $actor->hasPermission($stage->required_permission)
                : $this->roleGrants((int) $stage->approving_role_id, $stage->required_permission);

            if (! $satisfied) {
                throw app(Denials::class)->permission(
                    $actor,
                    $stage->required_permission,
                    'Act at the '.$stage->name.' stage',
                    [
                        'rule' => 'BR-23',
                        'via_delegation' => $holdsRoleByDelegation,
                        'required_role_id' => $stage->approving_role_id,
                    ],
                );
            }
        }

        /*
         * ARCH-4 layer 2 — BR-23's other half: "any user holding the stage's role
         * AND SATISFYING SCOPE".
         *
         * Everything above this point is layer 1. It asks whether the actor may
         * approve at THIS STAGE and never whether they may approve THIS RECORD,
         * and Department Head is a Department-scoped role with six holders, each
         * pinned to a different department. So any one of them could approve —
         * and under BR-22 re-price — any other department's requisition: a
         * ₦3,400,000 Logistics purchase cut to ₦1.00 by the head of Finance, and
         * the requisition was never even visible to them in a list.
         *
         * The subject is resolved with the data scope suspended (subjectOf) on
         * purpose. Loading it scoped returns null for exactly the actor this check
         * exists to refuse, and a null record reads as "nothing to check" — which
         * is how the hole survived. SCOPE-4's "never a workaround for a denial"
         * holds: the denial is decided here, with the record in hand.
         */
        $subject = $this->subjectOf($instance);

        if ($subject instanceof Scopeable) {
            $permission = $stage->required_permission ?? $subject->scopeResourceKey().'.approve';

            /*
             * BR-24 — a delegate exercises the DELEGATOR's authority, so it is the
             * delegator's scope the subject must fall inside. Asking about the
             * delegate's own scope would refuse every delegated action, because a
             * delegate by definition does not hold the delegated role and so has
             * no scope for its permissions at all.
             */
            $holder = $holdsRoleDirectly
                ? $actor
                : ($this->activeDelegationFor($actor, $stage)?->fromUser ?? $actor);

            if (! $subject->isWithinScopeFor($holder, $permission)) {
                throw app(Denials::class)->scope(
                    $actor,
                    $permission,
                    $subject,
                    'Act at the '.$stage->name.' stage',
                    [
                        'rule' => 'BR-23',
                        'via_delegation' => ! $holdsRoleDirectly,
                        'instance' => $instance->getKey(),
                    ],
                );
            }
        }

        return $stage;
    }

    /**
     * The instance's subject, resolved with the data scope suspended.
     *
     * `subject` is a morphTo, and every scopeable subject narrows itself to the
     * signed-in user's scope as it loads. For an approver acting outside that
     * scope it therefore resolved to null — precisely when the code most needs
     * the record: to run the layer-2 check above, and to name the subject in the
     * audit entry. The old ordering turned that null into a TypeError inside
     * AuditLogger::approval() AFTER the transaction had committed, so the stage
     * advanced, the approved amount changed, nothing was audited (AUDIT-2, BR-34)
     * and the approver saw a 500 and believed nothing had happened.
     */
    private function subjectOf(WorkflowInstance $instance): ?Model
    {
        $loaded = $instance->relationLoaded('subject') ? $instance->getRelation('subject') : null;

        if ($loaded instanceof Model) {
            return $loaded;
        }

        $instance->unsetRelation('subject');

        $subject = DataScope::asSystem(
            static fn (): mixed => $instance->load('subject')->getRelation('subject'),
        );

        return $subject instanceof Model ? $subject : null;
    }

    /**
     * §18.5 / AUDIT-2 — the audit entry for a workflow action.
     *
     * Two things about it are deliberate. It is called INSIDE the caller's
     * transaction, so an audit failure rolls the state change back rather than
     * orphaning it — DM-3's append-only triggers protect history that was
     * written and can do nothing for history that was never written, and an
     * INSERT inside the transaction is fine because those triggers only block
     * UPDATE and DELETE. And a subject that cannot be loaded at all degrades to
     * a thin entry naming what it was, never to no entry: Internal Audit's whole
     * job is reading this log, and a silent gap in it is worse than a terse row.
     *
     * @param  array<string, mixed>  $detail
     */
    private function auditAction(
        string $eventType,
        WorkflowInstance $instance,
        ?Model $subject,
        string $summary,
        array $detail,
        User $actor,
    ): void {
        if ($subject !== null) {
            $eventType === AuditEntry::EVENT_REJECTION
                ? $this->audit->rejection($subject, $summary, $detail, $actor)
                : $this->audit->approval($subject, $summary, $detail, $actor);

            return;
        }

        $this->audit->write([
            'actor' => $actor,
            'event_type' => $eventType,
            'module' => 'Purchases',
            'subject_type' => $instance->subject_type,
            'subject_id' => $instance->subject_id,
            'summary' => $summary,
            'detail' => array_merge($detail, ['subject_unavailable' => true]),
        ]);
    }

    /**
     * PERM-3 — does a role grant this permission, ignoring retired ones?
     * Used for the delegated-authority check above.
     */
    private function roleGrants(int $roleId, string $permissionKey): bool
    {
        $parsed = PermissionKey::tryParse($permissionKey);

        if ($parsed === null) {
            return false;
        }

        return DB::table('permission_role')
            ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
            ->where('permission_role.role_id', $roleId)
            ->whereNull('permissions.retired_at')
            ->where('permissions.resource_key', $parsed->resourceKey)
            ->where('permissions.action', $parsed->action)
            ->exists();
    }

    /** BR-24 */
    private function activeDelegationFor(User $actor, WorkflowStage $stage): ?Delegation
    {
        if ($stage->approving_role_id === null) {
            return null;
        }

        // A delegation only matters when the actor does NOT hold the role
        // themselves; otherwise they are acting in their own right.
        if ($actor->effectiveRoles()->contains('id', $stage->approving_role_id)) {
            return null;
        }

        return Delegation::query()
            ->active()
            ->where('to_user_id', $actor->getKey())
            ->where('role_id', $stage->approving_role_id)
            ->with('fromUser')
            ->first();
    }

    /** NOTIF-4 — the stage SLA sets the due time. */
    private function dueAt(?WorkflowStage $stage): ?Carbon
    {
        if ($stage === null || $stage->sla_hours === null) {
            return null;
        }

        return Wat::now()->addHours((int) $stage->sla_hours);
    }

    /* ---------------------------------------------------------------------
     | Notifications
     * ------------------------------------------------------------------ */

    /** NOTIF-3 — "item enters my approval queue". */
    private function notifyCurrentStage(WorkflowInstance $instance): void
    {
        $stage = $instance->currentStage;

        if ($stage === null || $stage->approving_role_id === null) {
            return;
        }

        $recipients = $this->notifications
            ->usersHoldingRole((int) $stage->approving_role_id)
            // BR-18 — never notify the requester that they must approve it.
            ->reject(fn (User $user) => $user->getKey() === $instance->requester_user_id)
            ->values();

        $this->notifications->send(
            eventCode: 'approval.queued',
            recipients: $recipients,
            title: $this->describe($instance->subject).' needs your approval',
            body: sprintf(
                '%s at %s%s.',
                $instance->workflow->name,
                $stage->name,
                $instance->amount_minor === null ? '' : ' · '.Money::format((int) $instance->amount_minor),
            ),
            actionUrl: route('approvals.index'),
            subject: $instance->subject,
        );
    }

    /**
     * §8 — the queue holder is told the item has gone, so it disappearing reads
     * as a decision rather than as a bug.
     *
     * It rides on `approval.queued` because that is the seeded event for "my
     * approval queue changed", and §9 keeps the event catalogue in the database:
     * inventing an `approval.withdrawn` code here would be a §18.7 violation and
     * NotificationService would drop it as unknown. A dedicated row belongs in
     * ReferenceDataSeeder — see the report accompanying this change.
     */
    private function notifyWithdrawal(WorkflowInstance $instance, ?WorkflowStage $stage, User $actor): void
    {
        if ($stage === null || $stage->approving_role_id === null) {
            return;
        }

        $this->notifications->send(
            eventCode: 'approval.queued',
            recipients: $this->notifications->usersHoldingRole((int) $stage->approving_role_id),
            title: $this->describe($instance->subject).' was withdrawn',
            body: sprintf('%s withdrew it before %s acted. No action is needed.', $actor->name, $stage->name),
            actionUrl: route('approvals.index'),
            subject: $instance->subject,
        );
    }

    private function notifyRequester(WorkflowInstance $instance, string $outcome, ?string $comment = null): void
    {
        $requester = $instance->requester;

        if ($requester === null) {
            return;
        }

        $subject = $instance->subject;
        $isLeave = $instance->workflow->applies_to === Workflow::APPLIES_LEAVE;

        $this->notifications->send(
            eventCode: $isLeave ? 'leave.decided' : 'requisition.decided',
            recipients: [$requester],
            title: match ($outcome) {
                'approved' => $this->describe($subject).' was approved',
                'rejected' => $this->describe($subject).' was rejected',
                default => 'More information needed on '.$this->describe($subject),
            },
            body: $comment,
            actionUrl: $this->subjectUrl($subject),
            subject: $subject,
        );
    }

    private function describe(?Model $subject): string
    {
        if ($subject === null) {
            return 'An item';
        }

        return (string) ($subject->reference ?? (class_basename($subject).' #'.$subject->getKey()));
    }

    private function subjectUrl(?Model $subject): ?string
    {
        return match ($subject === null ? null : class_basename($subject)) {
            'Requisition' => route('requisitions.show', $subject),
            'LeaveRequest' => route('leave.index'),
            'PayrollRun' => route('payroll.index'),
            'Batch' => route('reconciliation.index'),
            default => null,
        };
    }

    /**
     * NOTIF-4 — "Overdue reminders follow the stage SLA and the workflow's
     * reminder setting." Called by the scheduler.
     *
     * @return int reminders sent
     */
    public function sendOverdueReminders(): int
    {
        $sent = 0;

        /** @var Collection<int, WorkflowInstance> $overdue */
        $overdue = WorkflowInstance::query()
            ->overdue()
            ->with(['currentStage', 'workflow', 'subject', 'requester'])
            ->get();

        foreach ($overdue as $instance) {
            if ($instance->workflow->option('overdue_reminder', 'daily') === 'never') {
                continue;
            }

            $stage = $instance->currentStage;

            if ($stage === null || $stage->approving_role_id === null) {
                continue;
            }

            $sent += $this->notifications->send(
                eventCode: 'approval.overdue',
                recipients: $this->notifications->usersHoldingRole((int) $stage->approving_role_id),
                title: $this->describe($instance->subject).' is overdue in your queue',
                body: sprintf(
                    'The %s stage SLA of %dh lapsed on %s.',
                    $stage->name,
                    (int) $stage->sla_hours,
                    Wat::dateTime($instance->current_stage_due_at),
                ),
                actionUrl: route('approvals.index'),
                subject: $instance->subject,
            );
        }

        return $sent;
    }
}
