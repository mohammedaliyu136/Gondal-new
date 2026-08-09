<?php

namespace App\Http\Controllers\Milk;

use App\Http\Controllers\Controller;
use App\Models\AdjustmentReason;
use App\Models\CollectionCenter;
use App\Models\CollectionPoint;
use App\Models\Consignment;
use App\Models\Grade;
use App\Models\QualityTestDefinition;
use App\Models\RejectionReason;
use App\Services\Milk\AdjustmentService;
use App\Services\Milk\ConsignmentService;
use App\Support\Money;
use App\Support\Volume;
use App\Support\Wat;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** consignments.html — dispatch from a point, confirm at a center. */
class ConsignmentController extends Controller
{
    public function __construct(
        private readonly ConsignmentService $consignments,
        private readonly AdjustmentService $adjustments,
    ) {}

    public function index(Request $request): View
    {
        $consignments = Consignment::query()
            ->with([
                'collectionPoint', 'collectionCenter', 'grade', 'rejectionReason', 'confirmedBy', 'batch',
                // The confirmation modal renders one row per test for every
                // unconfirmed consignment; without this it loaded them one
                // consignment at a time from inside the loop.
                'qualityTests',
            ])
            // BR-8's adjustment total, once for the page rather than once per
            // row and again per confirmation modal. Consignment::adjustmentTotal
            // reads this alias when it is present.
            ->withSum('adjustments', 'litres_delta')
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('center'), fn ($query) => $query->where('collection_center_id', $request->integer('center')))
            ->latest('dispatched_at')
            ->paginate($this->perPage($request->integer('per_page') ?: null))
            ->withQueryString();

        return view('milk.consignments.index', [
            'consignments' => $consignments,
            'centers' => CollectionCenter::query()->orderBy('name')->get(),
            'awaitingCount' => Consignment::query()->awaitingConfirmation()->count(),
            'points' => CollectionPoint::query()->orderBy('name')->get(),
            // BR-13 — the grade modals price each option against the
            // consignment's own anchor date, so the rate history travels with
            // the grade instead of being re-queried per grade per row.
            'grades' => Grade::query()->assignable()->with('rates')->orderBy('position')->get(),
            'centerReasons' => RejectionReason::query()->availableAt(RejectionReason::STAGE_CENTER)->orderBy('position')->get(),
            'qualityTests' => QualityTestDefinition::query()->active()->orderBy('position')->get(),
            'adjustmentReasons' => AdjustmentReason::query()->for('consignment')->orderBy('position')->get(),
            'canDispatch' => $this->allows('milk.consignment.confirm.create'),
            'canConfirm' => $this->allows('milk.consignment.confirm.edit'),
            'canAdjust' => $this->allows('milk.adjustment.create'),
            'canGrade' => $this->allows('milk.grade.create'),
            // BR-4 — changing an assigned grade is a separate, tighter grant.
            'canRegrade' => $this->allows('milk.grade.edit'),
        ]);
    }

    /** BR-7 — the consignment's volume is the sum of its deliveries. */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'collection_point_id' => ['required', 'exists:collection_points,id'],
            'delivery_ids' => ['required', 'array', 'min:1'],
            'delivery_ids.*' => ['integer', 'exists:deliveries,id'],
            'containers' => ['nullable', 'integer', 'min:0'],
            'trip_id' => ['nullable', 'exists:trips,id'],
            'dispatched_at' => ['nullable', 'date'],
        ]);

        $point = CollectionPoint::query()->findOrFail($validated['collection_point_id']);

        $this->authorizeAccess('milk.consignment.confirm.create', $point, 'Dispatch from '.$point->name);

        $consignment = $this->consignments->dispatch(
            $point,
            array_map('intval', $validated['delivery_ids']),
            $validated,
            $this->currentUser(),
        );

        return back()->with(
            'success',
            sprintf('%s dispatched — %s.', $consignment->reference, Volume::format($consignment->litres_dispatched)),
        );
    }

    /** BR-4 — a required test must be recorded before a grade can be assigned. */
    public function recordQualityTest(Request $request, Consignment $consignment): RedirectResponse
    {
        $this->authorizeAccess('milk.grade.create', $consignment, 'Quality test on '.$consignment->reference);

        /*
         * Two request shapes reach here, and both are legitimate.
         *
         * The API and the tests post a flat pair: quality_test_definition_id and
         * reading. The confirmation screen cannot, because it renders one row per
         * test inside a form it does not own, so it posts every row's value keyed
         * by test id — readings[7] — and identifies the row through the submit
         * button that was actually clicked.
         *
         * Normalising here keeps one route, one permission check and one service
         * call for both, rather than a second endpoint that could drift.
         */
        if (! $request->filled('reading') && $request->filled('quality_test_definition_id')) {
            $keyed = $request->input('readings', []);
            $request->merge([
                'reading' => is_array($keyed)
                    ? ($keyed[$request->input('quality_test_definition_id')] ?? null)
                    : null,
            ]);
        }

        $validated = $request->validate([
            'quality_test_definition_id' => ['required', 'exists:quality_test_definitions,id'],
            'reading' => ['required', 'string', 'max:32'],
        ]);

        $definition = QualityTestDefinition::query()->findOrFail($validated['quality_test_definition_id']);

        $test = $this->consignments->recordQualityTest(
            $consignment,
            $definition,
            $validated['reading'],
            $this->currentUser(),
        );

        return back()->with(
            $test->passed ? 'success' : 'warning',
            sprintf('%s recorded: %s (%s).', $definition->name, $validated['reading'], $test->passed ? 'pass' : 'fail'),
        );
    }

    /** BR-8 / BR-13 / BR-14 / NFR-4 */
    public function confirm(Request $request, Consignment $consignment): RedirectResponse
    {
        $this->authorizeAccess('milk.consignment.confirm.edit', $consignment, 'Confirm '.$consignment->reference);

        $validated = $request->validate([
            'litres_rejected_at_center' => ['nullable', 'numeric', 'min:0'],
            'rejection_reason_id' => ['nullable', 'exists:rejection_reasons,id'],
            'grade_id' => ['nullable', 'exists:grades,id'],
            'intake_temperature_c' => ['nullable', 'numeric'],
            'officer_notes' => ['nullable', 'string', 'max:2000'],
            /*
             * BR-14 — this is the record of when confirmation happened, not the
             * price. The service anchors the rate snapshot to its own clock,
             * because this field used to do both and an officer could post a
             * date a week back to pick the rate the farmer was paid at. The
             * bound stops the remaining lie: a confirmation cannot have
             * happened later than now. The service refuses one earlier than
             * dispatch, which needs the record in hand.
             */
            'confirmed_at' => ['nullable', 'date', 'before_or_equal:now'],
        ]);

        $consignment = $this->consignments->confirm($consignment, $validated, $this->currentUser());

        return back()->with(
            'success',
            sprintf(
                '%s confirmed at %s%s.',
                $consignment->reference,
                Volume::format($consignment->litres_confirmed),
                $consignment->grade === null ? '' : ' — '.$consignment->grade->name,
            ),
        );
    }

    /** BR-4 / BR-13 — grade a consignment that was confirmed without one. */
    public function grade(Request $request, Consignment $consignment): RedirectResponse
    {
        $this->authorizeAccess('milk.grade.create', $consignment, 'Grade '.$consignment->reference);

        $validated = $request->validate([
            'grade_id' => ['required', 'exists:grades,id'],
        ]);

        $grade = Grade::query()->findOrFail($validated['grade_id']);

        $consignment = $this->consignments->grade($consignment, $grade, $this->currentUser());

        return back()->with('success', sprintf(
            '%s graded %s — %s/L, the rate in force on its confirmation day.',
            $consignment->reference,
            $grade->name,
            Money::format($consignment->rate_per_litre_minor),
        ));
    }

    /**
     * BR-4 — change a grade that has already been assigned.
     *
     * Held by `milk.grade.edit` rather than `milk.grade.create`: assigning a grade
     * must not wait for a supervisor, but changing one moves money for milk
     * already accepted, so it does.
     */
    public function regrade(Request $request, Consignment $consignment): RedirectResponse
    {
        $validated = $request->validate([
            'grade_id' => ['required', 'exists:grades,id'],
            'regrade_reason' => ['required', 'string', 'max:255'],
        ]);

        $grade = Grade::query()->findOrFail($validated['grade_id']);
        $previous = $consignment->grade?->name;

        // The service authorises — scoped, so a supervisor at another center
        // cannot re-grade this one even holding the permission.
        $consignment = $this->consignments->regrade(
            $consignment, $grade, $validated['regrade_reason'], $this->currentUser(),
        );

        return back()->with('success', sprintf(
            '%s re-graded %s → %s at %s/L. It is on the re-grade exceptions list.',
            $consignment->reference,
            $previous ?? 'ungraded',
            $grade->name,
            Money::format($consignment->rate_per_litre_minor),
        ));
    }

    /**
     * BR-4 — the re-grade exceptions list.
     *
     * A control that is recorded but never read is not a control. Every re-grade
     * lands here with who did it, what changed, and why, so a supervisor reviewing
     * the week has one screen to read rather than an audit log to search.
     */
    public function regrades(Request $request): View
    {
        $days = max(1, min(90, (int) $request->integer('days', 7)));

        $consignments = Consignment::query()
            ->whereNotNull('regraded_at')
            ->where('regraded_at', '>=', Wat::now()->subDays($days))
            ->with(['grade', 'regradedBy', 'collectionPoint', 'collectionCenter'])
            ->orderByDesc('regraded_at')
            ->paginate(25)
            ->withQueryString();

        return view('milk.consignments.regrades', [
            'consignments' => $consignments,
            'days' => $days,
        ]);
    }

    /** BR-8 / BR-12 — an adjustment at the center, with reason and explanation. */
    public function adjust(Request $request, Consignment $consignment): RedirectResponse
    {
        $this->authorizeAccess('milk.adjustment.create', $consignment, 'Adjust '.$consignment->reference);

        $validated = $request->validate([
            'litres_delta' => ['required', 'numeric'],
            'adjustment_reason_id' => ['required', 'exists:adjustment_reasons,id'],
            'explanation' => ['required', 'string', 'max:2000'],
        ]);

        $this->adjustments->record(
            $consignment,
            (string) $validated['litres_delta'],
            (int) $validated['adjustment_reason_id'],
            $validated['explanation'],
            $this->currentUser(),
        );

        return back()->with('success', 'Adjustment recorded. It takes effect when the consignment is confirmed.');
    }
}
