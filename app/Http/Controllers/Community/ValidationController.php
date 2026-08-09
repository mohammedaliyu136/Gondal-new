<?php

namespace App\Http\Controllers\Community;

use App\Http\Controllers\Controller;
use App\Models\CollectionPoint;
use App\Models\Community;
use App\Models\Cooperative;
use App\Models\ExtensionAgent;
use App\Models\Farmer;
use App\Models\FarmerValidation;
use App\Models\Lga;
use App\Models\User;
use App\Models\ValidationReason;
use App\Services\Community\FarmerCohort;
use App\Services\Community\FarmerValidationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * BR-36 — the revalidation queue, on the web.
 *
 * The feature shipped with a field app and no browser screen, which left
 * Monitoring & Evaluation holding a write grant they could not exercise: they
 * decide who needs checking and who checks them, and had nowhere to say so. The
 * queue existed, the API served it to phones, and the only way to put anything
 * into it was a database insert.
 *
 * The separation the feature exists for is enforced by the PERMISSIONS, not by
 * this controller: `community.validation.create` assigns, and
 * `community.farmers.validate` carries a check out. M&E holds the first and not
 * the second, so nothing here lets the person who scheduled a check also declare
 * it passed.
 */
class ValidationController extends Controller
{
    public function __construct(
        private readonly FarmerValidationService $validations,
        private readonly FarmerCohort $cohort,
    ) {}

    /**
     * A round name M&E does not have to invent. "Tudun Wada — 38 farmers"
     * reads better in the round list than whatever gets typed at 7am.
     *
     * @param  Collection<int, Farmer>  $farmers
     */
    private function describeCohort(string $type, Collection $farmers): string
    {
        $where = match ($type) {
            FarmerCohort::BY_COMMUNITY => $farmers->pluck('community.name')->filter()->unique()->join(', '),
            default => FarmerCohort::label($type),
        };

        return sprintf('%s — %d farmers', $where ?: FarmerCohort::label($type), $farmers->count());
    }

    public function index(Request $request): View
    {
        $this->authorizeAccess('community.validation.view', null, 'Revalidation queue');

        /*
         * `$request->string()` returns a Stringable OBJECT, which is always
         * truthy — so `?: 'open'` never fired, the filter became the empty
         * string, and the queue rendered "Nothing here" for everyone. Read the
         * raw input and default it there.
         */
        $status = (string) $request->input('status', 'open');

        /*
         * Cohort filters, so a reviewer can narrow the queue to the thing they
         * are actually reviewing — one community, one agent's round — before
         * accepting it in bulk. Filtering on the FARMER's attributes keeps the
         * vocabulary identical to FarmerCohort's, so "accept everything shown"
         * and "schedule this cohort" mean the same set.
         */
        $community = $request->integer('community_id') ?: null;
        $lga = $request->integer('lga_id') ?: null;
        $point = $request->integer('collection_point_id') ?: null;
        $assignee = $request->integer('assigned_to_user_id') ?: null;

        $validations = FarmerValidation::query()
            ->with(['farmer.community', 'reason', 'assignedTo', 'assignedBy', 'round'])
            ->when($status === 'open', fn ($query) => $query->open())
            ->when($status === 'review', fn ($query) => $query->awaitingReview())
            ->when($status === 'overdue', fn ($query) => $query->overdue())
            ->when(
                ! in_array($status, ['open', 'review', 'overdue'], true),
                fn ($query) => $query->where('status', $status),
            )
            ->when($assignee, fn ($query, $id) => $query->where('assigned_to_user_id', $id))
            ->when(
                $community || $lga || $point,
                fn ($query) => $query->whereHas('farmer', fn ($farmer) => $farmer
                    ->when($community, fn ($inner, $id) => $inner->where('community_id', $id))
                    ->when($lga, fn ($inner, $id) => $inner->where('lga_id', $id))
                    ->when($point, fn ($inner, $id) => $inner->where('default_collection_point_id', $id)),
                ),
            )
            ->latest('assigned_at')
            ->paginate($this->perPage($request->integer('per_page') ?: null))
            ->withQueryString();

        return view('community.validation.index', [
            'validations' => $validations,
            'status' => $status,
            'reasons' => ValidationReason::query()->active()->orderBy('position')->get(),
            /*
             * Only people who can actually do the work. Assigning to somebody
             * without `community.farmers.validate` produces a task that sits in
             * their queue forever — the service refuses it, and offering the name
             * in the picker only to refuse the submission is a worse way to say so.
             */
            'assignees' => $this->eligibleAssignees(),
            // §16 — the farmers whose details are past their revalidation date.
            // This is the list M&E is deciding FROM.
            'overdueFarmers' => Farmer::query()->active()->validationOverdue()
                ->with('community')->orderBy('name')->limit(200)->get(),
            'counts' => [
                'open' => FarmerValidation::query()->open()->count(),
                'review' => FarmerValidation::query()->awaitingReview()->count(),
                'overdue' => FarmerValidation::query()->overdue()->count(),
            ],
            'canAssign' => $this->allows('community.validation.create'),
            'canReview' => $this->allows('community.validation.approve'),

            /*
             * The cohort pickers. Each list is read through its own scoped
             * query, so an officer is only ever offered communities, points and
             * agents they actually hold — the picker cannot suggest a cohort
             * that would then resolve to nothing.
             */
            'cohortOptions' => $this->cohortOptions(),
            'cohortMax' => FarmerCohort::MAX,
            'filters' => [
                'community_id' => $community,
                'lga_id' => $lga,
                'collection_point_id' => $point,
                'assigned_to_user_id' => $assignee,
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'farmer_id' => ['required', 'exists:farmers,id'],
            'validation_reason_id' => ['required', 'exists:validation_reasons,id'],
            'assigned_to_user_id' => ['nullable', 'exists:users,id'],
            'due_on' => ['nullable', 'date', 'after_or_equal:today'],
        ]);

        $farmer = Farmer::query()->findOrFail($validated['farmer_id']);

        // ARCH-4 layer 2 — the farmer decides. An officer scoped to four
        // communities cannot schedule a check in a fifth.
        $this->authorizeAccess('community.validation.create', $farmer, 'Assign a revalidation for '.$farmer->name);

        $validation = $this->validations->assign(
            $farmer,
            ValidationReason::query()->findOrFail($validated['validation_reason_id']),
            $this->currentUser(),
            [
                'assigned_to_user_id' => $validated['assigned_to_user_id'] ?? null,
                'due_on' => $validated['due_on'] ?? null,
            ],
        );

        return back()->with('success', $validation->reference.' assigned.');
    }

    /**
     * One act of judgement over a whole cohort: "revalidate Tudun Wada",
     * "revalidate Jamila's round".
     *
     * The cohort is resolved through the scoped farmer query rather than from a
     * list of ids the browser sent, so an officer cannot widen their own reach
     * by editing the form — a community they do not hold simply yields no
     * farmers. `authorizeAccess` is still called per farmer inside `assign()`'s
     * caller path for the same reason the single-farmer route calls it: the
     * cohort narrows, the permission decides.
     */
    public function storeRound(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'cohort_type' => ['required', 'string', 'in:'.implode(',', FarmerCohort::TYPES)],
            'cohort_target_ids' => ['required', 'array', 'min:1'],
            'cohort_target_ids.*' => ['integer'],
            'validation_reason_id' => ['required', 'exists:validation_reasons,id'],
            'assigned_to_user_id' => ['nullable', 'exists:users,id'],
            'due_on' => ['nullable', 'date', 'after_or_equal:today'],
            'overdue_only' => ['nullable', 'boolean'],
            'name' => ['nullable', 'string', 'max:120'],
            'auto_approve' => ['nullable', 'boolean'],
        ]);

        $this->authorizeAccess('community.validation.create', null, 'Open a revalidation round');

        $farmers = $this->cohort->resolve(
            $validated['cohort_type'],
            $validated['cohort_target_ids'],
            (bool) ($validated['overdue_only'] ?? false),
        );

        if ($farmers->isEmpty()) {
            return back()->withInput()->withErrors([
                'cohort_target_ids' => 'That cohort has no farmers you can schedule. '
                    .'Either it is empty, or it lies outside your data scope.',
            ]);
        }

        $result = $this->validations->openRound(
            ($validated['name'] ?? null) ?: $this->describeCohort($validated['cohort_type'], $farmers),
            $farmers,
            ValidationReason::query()->findOrFail($validated['validation_reason_id']),
            $this->currentUser(),
            [
                'assigned_to_user_id' => $validated['assigned_to_user_id'] ?? null,
                'due_on' => $validated['due_on'] ?? null,
                'auto_approve' => $request->boolean('auto_approve'),
                'criteria' => sprintf('%s cohort, %d farmers%s',
                    FarmerCohort::label($validated['cohort_type']),
                    $farmers->count(),
                    ($validated['overdue_only'] ?? false) ? ', overdue only' : '',
                ),
            ],
        );

        return back()->with('success', sprintf(
            '%s opened — %d farmers assigned%s.',
            $result['round']->reference,
            $result['assigned'],
            $result['skipped'] === [] ? '' : sprintf(', %d skipped (already in the queue)', count($result['skipped'])),
        ))->with('round_skipped', $result['skipped']);
    }

    /**
     * Accept a reviewed batch in one go.
     *
     * The ids are re-read through the SCOPED query before anything is accepted,
     * so a posted id outside the reviewer's scope is dropped rather than
     * honoured — the same reasoning as `storeRound()`.
     */
    public function acceptMany(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'validation_ids' => ['required', 'array', 'min:1'],
            'validation_ids.*' => ['integer'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $this->authorizeAccess('community.validation.approve', null, 'Accept revalidations in bulk');

        $validations = FarmerValidation::query()
            ->with('farmer')
            ->whereIn('id', $validated['validation_ids'])
            ->awaitingReview()
            ->get();

        if ($validations->isEmpty()) {
            return back()->withErrors([
                'validation_ids' => 'None of those are awaiting review. Someone may have reviewed them already — reload the queue.',
            ]);
        }

        $result = $this->validations->acceptMany(
            $validations,
            $this->currentUser(),
            $validated['note'] ?? null,
        );

        return back()->with('success', sprintf(
            '%d revalidation%s accepted%s.',
            $result['accepted'],
            $result['accepted'] === 1 ? '' : 's',
            $result['skipped'] === [] ? '' : sprintf(', %d skipped', count($result['skipped'])),
        ));
    }

    /** M&E accepts what came back. */
    public function accept(Request $request, FarmerValidation $validation): RedirectResponse
    {
        $this->authorizeAccess('community.validation.approve', $validation, 'Accept '.$validation->reference);

        $this->validations->accept(
            $validation,
            $this->currentUser(),
            $request->string('note')->toString() ?: null,
        );

        return back()->with('success', $validation->reference.' accepted.');
    }

    /** Or sends it back, with the reason the field worker will read. */
    public function returnToField(Request $request, FarmerValidation $validation): RedirectResponse
    {
        $validated = $request->validate([
            // A return with no reason is a task the field worker cannot act on.
            'note' => ['required', 'string', 'max:500'],
        ]);

        $this->authorizeAccess('community.validation.approve', $validation, 'Return '.$validation->reference);

        $this->validations->returnToField($validation, $this->currentUser(), $validated['note']);

        return back()->with('success', $validation->reference.' sent back to the field.');
    }

    public function cancel(Request $request, FarmerValidation $validation): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        $this->authorizeAccess('community.validation.edit', $validation, 'Cancel '.$validation->reference);

        $this->validations->cancel($validation, $this->currentUser(), $validated['reason']);

        return back()->with('success', $validation->reference.' cancelled.');
    }

    /**
     * The users who hold `community.farmers.validate`.
     *
     * Resolved through Access so a role edit changes the picker on the next
     * request (ROLE-6) rather than needing a list maintained beside the grants.
     *
     * @return Collection<int, User>
     */
    /**
     * The cohort pickers, each option carrying how many farmers it covers.
     *
     * The counts come from the SCOPED farmer table in one grouped query per
     * dimension, so the number beside "Tudun Wada" is the number of farmers
     * this officer would actually schedule — not the number that exist. An
     * option showing 0 is honest: the place is real, the officer just holds
     * none of it.
     *
     * @return array<string, \Illuminate\Support\Collection<int, object>>
     */
    private function cohortOptions(): array
    {
        $countBy = fn (string $column) => Farmer::query()->active()
            ->selectRaw($column.' as key, count(*) as total')
            ->groupBy($column)
            ->pluck('total', 'key');

        $byCommunity = $countBy('community_id');
        $byLga = $countBy('lga_id');
        $byPoint = $countBy('default_collection_point_id');
        $byCooperative = $countBy('cooperative_id');

        $decorate = fn (Collection $rows, $counts) => $rows
            ->map(fn ($row) => (object) [
                'id' => $row->id,
                'name' => $row->name,
                'farmers' => (int) ($counts[$row->id] ?? 0),
            ])
            ->values();

        /*
         * An agent's cohort is the union of their communities, so their count is
         * the sum of those communities' counts rather than a sixth query.
         */
        $agents = ExtensionAgent::query()->with(['user', 'communities'])->get()
            ->map(fn (ExtensionAgent $agent) => (object) [
                'id' => $agent->getKey(),
                'name' => $agent->user?->name ?? $agent->code,
                'farmers' => (int) $agent->communities
                    ->sum(fn ($community) => $byCommunity[$community->getKey()] ?? 0),
            ])
            ->sortBy('name')->values();

        return [
            FarmerCohort::BY_COMMUNITY => $decorate(Community::query()->orderBy('name')->get(['id', 'name']), $byCommunity),
            FarmerCohort::BY_LGA => $decorate(Lga::query()->orderBy('name')->get(['id', 'name']), $byLga),
            FarmerCohort::BY_AGENT => $agents,
            FarmerCohort::BY_POINT => $decorate(CollectionPoint::query()->orderBy('name')->get(['id', 'name']), $byPoint),
            FarmerCohort::BY_COOPERATIVE => $decorate(Cooperative::query()->orderBy('name')->get(['id', 'name']), $byCooperative),
        ];
    }

    private function eligibleAssignees(): Collection
    {
        return User::query()
            ->where('status', 'active')
            ->where('is_test', false)
            ->orderBy('name')
            ->get()
            ->filter(fn (User $user) => $user->hasPermission('community.farmers.validate'))
            ->values();
    }
}
