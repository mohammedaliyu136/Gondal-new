<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\ApprovalResource;
use App\Models\WorkflowInstance;
use App\Services\Workflow\WorkflowEngine;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * BR-18 — approving your own submission is a 403 here exactly as it is on the
 * web, because both go through WorkflowEngine.
 *
 * BR-20 / BR-22 — and the outcome now reaches the subject here too. These three
 * endpoints used to skip the subject sync that ApprovalsController did by hand,
 * so an API approval left `requisitions.approved_total_minor` null and an API
 * rejection stranded the requisition in `in_review` with no code path out. The
 * sync moved into WorkflowEngine (see SubjectSynchroniser); nothing is done for
 * it here on purpose, because a step a caller has to remember is a step a caller
 * will forget.
 */
class ApprovalApiController extends ApiController
{
    public function __construct(private readonly WorkflowEngine $engine) {}

    public function index(Request $request): JsonResponse
    {
        $queue = $this->engine->queueFor($this->currentUser())
            ->orderByRaw('case when current_stage_due_at is null then 1 else 0 end')
            ->orderBy('current_stage_due_at')
            ->paginate($this->perPage($request->integer('per_page') ?: null));

        return ApprovalResource::collection($queue)->response();
    }

    public function approve(Request $request, WorkflowInstance $instance): JsonResponse
    {
        $validated = $request->validate([
            // BR-22 — an approver may reduce the amount, never raise it and never
            // take it below zero. The engine refuses both; refusing here too
            // makes it a 422 on the field rather than a rule violation.
            'approved_amount' => ['nullable', 'numeric', 'min:0'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $instance = $this->engine->approve(
            $instance,
            $this->currentUser(),
            ($validated['approved_amount'] ?? null) === null ? null : Money::fromMajor($validated['approved_amount']),
            $validated['comment'] ?? null,
        );

        return ApprovalResource::make($instance->load(['workflow', 'currentStage.approvingRole', 'requester']))->response();
    }

    public function reject(Request $request, WorkflowInstance $instance): JsonResponse
    {
        $validated = $request->validate(['comment' => ['required', 'string', 'max:2000']]);

        $instance = $this->engine->reject($instance, $this->currentUser(), $validated['comment']);

        return ApprovalResource::make($instance->load(['workflow', 'requester']))->response();
    }

    /** BR-21 — records and notifies without advancing or ending. */
    public function requestInfo(Request $request, WorkflowInstance $instance): JsonResponse
    {
        $validated = $request->validate(['comment' => ['required', 'string', 'max:2000']]);

        $this->engine->requestInfo($instance, $this->currentUser(), $validated['comment']);

        return ApprovalResource::make($instance->fresh(['workflow', 'currentStage.approvingRole', 'requester']))->response();
    }
}
