<?php

namespace App\Http\Controllers\Purchases;

use App\Exceptions\RuleViolationException;
use App\Http\Controllers\Controller;
use App\Models\Comment;
use App\Models\Department;
use App\Models\Requisition;
use App\Services\Finance\RequisitionSpendService;
use App\Models\Workflow;
use App\Services\Purchases\RequisitionService;
use App\Services\Workflow\WorkflowEngine;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** requisitions.html and requisition-detail.html. */
class RequisitionController extends Controller
{
    public function __construct(
        private readonly RequisitionService $requisitions,
        private readonly WorkflowEngine $engine,
    ) {}

    public function index(Request $request): View
    {
        $requisitions = Requisition::query()
            // NFR-2 — the table renders "stage N of M", which needs the band's
            // stages and the instance's subject. Loading only the current stage
            // left those two to be lazy-loaded once per row.
            ->with([
                'requester', 'department',
                'workflowInstance.currentStage.approvingRole',
                'workflowInstance.band.stages',
                'workflowInstance.subject',
            ])
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('department'), fn ($query) => $query->where('department_id', $request->integer('department')))
            ->when($request->filled('q'), fn ($query) => $query->where(function ($inner) use ($request) {
                $term = '%'.$request->string('q').'%';
                $inner->where('reference', 'like', $term)->orWhere('title', 'like', $term);
            }))
            ->latest('id')
            ->paginate($this->perPage($request->integer('per_page') ?: null))
            ->withQueryString();

        return view('purchases.requisitions.index', [
            'requisitions' => $requisitions,
            'departments' => Department::query()->active()->orderBy('name')->get(),
            'awaitingCount' => Requisition::query()->awaitingDecision()->count(),
            // BR-19 — the bands are shown so a requester can see where their
            // total will route before they submit.
            'workflow' => Workflow::query()->for(Workflow::APPLIES_REQUISITION)->with('bands.stages')->first(),
            'canCreate' => $this->allows('purchase.requisitions.create'),
        ]);
    }

    public function show(Requisition $requisition): View
    {
        $this->authorizeAccess('purchase.requisitions.view', $requisition, 'Requisition → '.$requisition->reference);

        $instance = $requisition->workflowInstance;

        return view('purchases.requisitions.show', [
            'requisition' => $requisition->load([
                'requester.department', 'department', 'items', 'comments.createdBy',
                'attachments', 'revises',
            ]),
            'instance' => $instance?->load(['workflow', 'band', 'currentStage.approvingRole', 'actions.actor', 'actions.stage', 'actions.onBehalfOf']),
            'stages' => $instance?->applicableStages() ?? collect(),
            // BR-20 — every previous attempt is retained and visible.
            'previousInstances' => $requisition->workflowInstances()->with('actions.actor')->get(),
            'canAct' => $instance !== null && $this->engine->canAct($instance, $this->currentUser()),
            'canResubmit' => $requisition->status === Requisition::STATUS_REJECTED
                && $requisition->requester_user_id === $this->currentUser()?->getKey(),
            // BR-18 — spelled out on screen rather than only enforced silently.
            'isOwnSubmission' => $requisition->requester_user_id === $this->currentUser()?->getKey(),
            /*
             * §14 Phase 7 — the other half of a purchase. An approval is a
             * permission to spend; until this existed nothing ever referred to
             * `approved_total_minor` again once the workflow cleared.
             */
            'expenditures' => $requisition->expenditures()->with('recordedBy')->latest('spent_on')->get(),
            'authorisedMinor' => app(RequisitionSpendService::class)->authorisedMinor($requisition),
            'spentMinor' => app(RequisitionSpendService::class)->spentMinor($requisition),
            'remainingMinor' => app(RequisitionSpendService::class)->remainingMinor($requisition),
            'canSpend' => $this->allows('purchase.requisitions.spend', $requisition)
                && $requisition->status === Requisition::STATUS_APPROVED,
        ]);
    }


    /**
     * Record that an approved requisition was actually paid.
     *
     * Deliberately not the requester's action: the person who asked for the
     * money is not the person who confirms it left. Same separation BR-18 makes
     * on approvals and the cash book makes on floats.
     */
    public function spend(Request $request, Requisition $requisition): RedirectResponse
    {
        $validated = $request->validate([
            'amount_minor' => ['required', 'integer', 'min:1'],
            'vendor' => ['nullable', 'string', 'max:255'],
            'invoice_reference' => ['nullable', 'string', 'max:255'],
            'method' => ['required', 'string', 'max:24'],
            'spent_on' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        app(RequisitionSpendService::class)->record($requisition, $validated, $this->currentUser());

        return back()->with('success', 'Payment recorded against '.$requisition->reference.'.');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validatePayload($request);

        $requisition = $this->requisitions->create(
            $validated,
            $this->normaliseItems($validated['items']),
            $this->currentUser(),
        );

        if ($request->boolean('submit')) {
            $this->requisitions->submit($requisition, $this->currentUser());
        }

        return redirect()->route('requisitions.show', $requisition)->with(
            'success',
            sprintf('%s created — %s.', $requisition->reference, Money::format((int) $requisition->total_minor)),
        );
    }

    /** BR-19 — submission is where the band is chosen. */
    public function submit(Requisition $requisition): RedirectResponse
    {
        $this->authorizeAccess('purchase.requisitions.create', $requisition, 'Submit '.$requisition->reference);

        abort_unless($requisition->requester_user_id === $this->currentUser()?->getKey(), 403);

        $this->requisitions->submit($requisition, $this->currentUser());

        $instance = $requisition->fresh()->workflowInstance;

        return back()->with('success', sprintf(
            '%s submitted — %s band, %d stage(s).',
            $requisition->reference,
            $instance?->band?->name ?? 'default',
            $instance?->stageCount() ?? 0,
        ));
    }

    /** BR-20 — a new requisition and a NEW instance; the old one is retained. */
    public function resubmit(Request $request, Requisition $requisition): RedirectResponse
    {
        $this->authorizeAccess('purchase.requisitions.create', $requisition, 'Resubmit '.$requisition->reference);

        abort_unless($requisition->requester_user_id === $this->currentUser()?->getKey(), 403);

        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:64'],
            'urgency' => ['nullable', 'in:low,normal,high'],
            'needed_by' => ['nullable', 'date'],
            'suggested_vendor' => ['nullable', 'string', 'max:255'],
            'items' => ['nullable', 'array'],
            'items.*.item' => ['required_with:items', 'string', 'max:255'],
            'items.*.purpose' => ['nullable', 'string', 'max:255'],
            'items.*.quantity' => ['required_with:items', 'numeric', 'min:0.01'],
            'items.*.unit' => ['nullable', 'string', 'max:24'],
            // BR-19 — see validatePayload() for why a minus sign here is an
            // authorisation problem and not a typo.
            'items.*.unit_price' => ['required_with:items', 'numeric', 'min:0'],
        ]);

        $revision = $this->requisitions->resubmit(
            $requisition,
            array_filter($validated, fn ($value, $key) => $key !== 'items', ARRAY_FILTER_USE_BOTH),
            $this->normaliseItems($validated['items'] ?? []),
            $this->currentUser(),
        );

        return redirect()->route('requisitions.show', $revision)->with(
            'success',
            sprintf('%s submitted as a revision of %s. The original chain is retained.', $revision->reference, $requisition->reference),
        );
    }

    /**
     * §8 — `in_review → cancelled`, the transition the screens have always
     * offered as a filter and the system could not produce.
     *
     * Only the requester may withdraw, and only while the chain is open. Anyone
     * else wanting it stopped rejects it under BR-20, which leaves a reason on
     * the record; a withdrawal is the requester saying the need has passed.
     */
    public function cancel(Request $request, Requisition $requisition): RedirectResponse
    {
        $this->authorizeAccess('purchase.requisitions.create', $requisition, 'Withdraw '.$requisition->reference);

        abort_unless($requisition->requester_user_id === $this->currentUser()?->getKey(), 403);

        $validated = $request->validate(['comment' => ['nullable', 'string', 'max:2000']]);

        $instance = $requisition->workflowInstance;

        if ($instance === null) {
            throw RuleViolationException::make(
                'ST-1',
                sprintf('%s has never been submitted, so there is nothing to withdraw.', $requisition->reference),
                ['status' => $requisition->status],
            );
        }

        $this->engine->cancel($instance, $this->currentUser(), $validated['comment'] ?? null);

        return back()->with('success', sprintf('%s withdrawn.', $requisition->reference));
    }

    public function comment(Request $request, Requisition $requisition): RedirectResponse
    {
        $this->authorizeAccess('purchase.requisitions.view', $requisition, 'Comment on '.$requisition->reference);

        $validated = $request->validate(['body' => ['required', 'string', 'max:2000']]);

        Comment::query()->create([
            'commentable_type' => $requisition->getMorphClass(),
            'commentable_id' => $requisition->getKey(),
            'body' => $validated['body'],
        ]);

        return back()->with('success', 'Comment added.');
    }

    /** @return array<string, mixed> */
    private function validatePayload(Request $request): array
    {
        if ($request->has('items') && is_array($request->input('items'))) {
            $filteredItems = array_values(array_filter($request->input('items'), function ($row) {
                return is_array($row) && trim($row['item'] ?? '') !== '';
            }));
            $request->merge(['items' => $filteredItems]);
        }

        return $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'department_id' => ['nullable', 'exists:departments,id'],
            'category' => ['nullable', 'string', 'max:64'],
            'urgency' => ['required', 'in:low,normal,high'],
            'needed_by' => ['nullable', 'date'],
            // NG-6 — free text; there is no vendor registry in v1 (§15.5).
            'suggested_vendor' => ['nullable', 'string', 'max:255'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.item' => ['required', 'string', 'max:255'],
            'items.*.purpose' => ['nullable', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.01'],
            'items.*.unit' => ['nullable', 'string', 'max:24'],
            /*
             * BR-19 — `numeric, min:0`, not a bare string.
             *
             * Money::fromMajor honours a leading minus, and the band is chosen
             * once from the total and never recomputed. A requester wanting a
             * ₦2,000,000 purchase could therefore file it as a ₦2,000,000 line
             * plus a −₦1,600,000 "discount" line, band at ₦400,000 and route
             * past the Executive Director and the General Manager entirely — a
             * permission boundary defeated by arithmetic. The hint under this
             * field already asks for plain naira and kobo ("1500.50"), so
             * `numeric` costs the operator nothing.
             */
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
        ]);
    }

    /**
     * ARCH-6 — operator input becomes integer kobo at the boundary and stays
     * integral from there.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function normaliseItems(array $items): array
    {
        return array_map(fn (array $item) => [
            'item' => $item['item'],
            'purpose' => $item['purpose'] ?? null,
            'quantity' => (float) $item['quantity'],
            'unit' => $item['unit'] ?? null,
            'unit_price_minor' => Money::fromMajor($item['unit_price'] ?? '0') ?? 0,
        ], $items);
    }
}
