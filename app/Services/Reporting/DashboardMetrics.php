<?php

namespace App\Services\Reporting;

use App\Authorization\Access;
use App\Models\Batch;
use App\Models\CollectionPoint;
use App\Models\Consignment;
use App\Models\Delivery;
use App\Models\Farmer;
use App\Models\Grade;
use App\Models\RejectionReason;
use App\Models\User;
use App\Services\Workflow\WorkflowEngine;
use App\Support\Volume;
use App\Support\Wat;
use Illuminate\Support\Collection;

/**
 * The dashboard's figures.
 *
 * SCOPE-4 — "Aggregates respect scope. A collection officer scoped to Kumbotso
 *   who loads the dashboard sees Kumbotso's totals, not the network's — and only
 *   if they hold milk.totals.network.view do they see network figures at all."
 *
 *   Both halves are implemented here: every query below runs through the model's
 *   GLOBAL SCOPE (so it is already narrowed to the viewer), and the network
 *   figures are simply not computed unless the permission is held.
 *
 * BR-35 / TEST-1 — every aggregate excludes test activity.
 * G-6 — no revenue or payroll figure appears here without its permission.
 */
class DashboardMetrics
{
    public function __construct(private readonly Access $access) {}

    /**
     * @return array<string, mixed>
     */
    public function for(User $user): array
    {
        $today = Wat::today()->toDateString();

        return [
            'today' => Wat::today(),
            'scope_label' => $user->overallScopeDescription(),

            // SCOPE-4 — the flag the view uses to decide whether to show a
            // network-wide caption or the viewer's own scope.
            'sees_network_totals' => $this->access->allows($user, 'milk.totals.network.view'),

            'milk' => $this->access->allows($user, 'milk.deliveries.view')
                || $this->access->allows($user, 'milk.consignment.confirm.view')
                    ? $this->milk($today)
                    : null,

            'farmers' => $this->access->allows($user, 'community.farmers.view')
                ? $this->farmers()
                : null,

            'approvals' => $this->access->hasPermission($user, 'purchase.requisitions.view')
                ? $this->approvals($user)
                : null,

            'rejections' => $this->access->allows($user, 'milk.rejection.view')
                || $this->access->allows($user, 'milk.deliveries.view')
                    ? $this->rejections($today)
                    : null,

            'intake_week' => $this->access->allows($user, 'milk.consignment.confirm.view')
                ? $this->intakeLastSevenDays()
                : null,

            'quality' => $this->access->allows($user, 'milk.consignment.confirm.view')
                ? $this->qualityBreakdown($today)
                : null,

            'recent_centers' => $this->access->allows($user, 'milk.consignment.confirm.view')
                ? $this->recentCenters($today)
                : null,
        ];
    }

    /** @return array<string, mixed> */
    private function milk(string $date): array
    {
        /*
         * ARCH-9 — a WAT day out of a UTC column is a RANGE, never whereDate().
         * These three figures are the executive headline; filtering `confirmed_at`
         * by a WAT date string put the first hour of every day on yesterday's tile,
         * so a manager opening the dashboard at 00:30 saw 0 L for today and the
         * change_pct read -100%.
         */
        [$dayStart, $dayEnd] = Wat::dayRange($date);
        [$previousStart, $previousEnd] = Wat::dayRange(Wat::today()->subDay());

        // The global scope has already narrowed these to the viewer (SCOPE-4).
        $confirmed = Consignment::query()
            ->excludingTestData()
            ->whereNotNull('confirmed_at')
            ->where('confirmed_at', '>=', $dayStart)
            ->where('confirmed_at', '<', $dayEnd)
            ->sum('litres_confirmed');

        $yesterday = Consignment::query()
            ->excludingTestData()
            ->whereNotNull('confirmed_at')
            ->where('confirmed_at', '>=', $previousStart)
            ->where('confirmed_at', '<', $previousEnd)
            ->sum('litres_confirmed');

        $deliveries = Delivery::query()
            ->excludingTestData()
            ->where('delivered_at', '>=', $dayStart)
            ->where('delivered_at', '<', $dayEnd)
            ->count();

        $points = CollectionPoint::query()->count();
        $activePoints = CollectionPoint::query()->where('status', 'active')->count();

        return [
            'litres_confirmed' => Volume::fromCentilitres((int) round(100 * (float) $confirmed)),
            'litres_yesterday' => Volume::fromCentilitres((int) round(100 * (float) $yesterday)),
            'change_pct' => $this->changePercentage((float) $yesterday, (float) $confirmed),
            'deliveries' => $deliveries,
            'points_total' => $points,
            'points_active' => $activePoints,
        ];
    }

    /** @return array<string, mixed> */
    private function farmers(): array
    {
        return [
            'active' => Farmer::query()->active()->count(),
            'enrolled_this_week' => Farmer::query()
                ->where('enrolled_on', '>=', Wat::today()->startOfWeek()->toDateString())
                ->count(),
        ];
    }

    /**
     * BR-18 — the queue deliberately excludes the viewer's own submissions, so
     * this count matches what /approvals actually shows.
     *
     * @return array<string, mixed>
     */
    private function approvals(User $user): array
    {
        $queue = app(WorkflowEngine::class)->queueFor($user);

        $byStage = (clone $queue)
            ->get()
            ->groupBy(fn ($instance) => $instance->currentStage?->name ?? 'Unassigned')
            ->map->count();

        return [
            'awaiting' => (clone $queue)->count(),
            'by_stage' => $byStage,
        ];
    }

    /** @return array<string, mixed> */
    private function rejections(string $date): array
    {
        // ARCH-9 — the WAT day as a UTC instant range, not a date literal.
        [$dayStart, $dayEnd] = Wat::dayRange($date);

        $atPoints = Delivery::query()
            ->excludingTestData()
            ->where('delivered_at', '>=', $dayStart)
            ->where('delivered_at', '<', $dayEnd)
            ->sum('litres_rejected');

        $atCenters = Consignment::query()
            ->excludingTestData()
            ->whereNotNull('confirmed_at')
            ->where('confirmed_at', '>=', $dayStart)
            ->where('confirmed_at', '<', $dayEnd)
            ->sum('litres_rejected_at_center');

        $atFactory = Batch::query()
            ->excludingTestData()
            ->whereNotNull('reconciled_at')
            ->where('reconciled_at', '>=', $dayStart)
            ->where('reconciled_at', '<', $dayEnd)
            ->sum('litres_rejected_at_factory');

        // BR-1 — the breakdown is by configured reason, never by a hardcoded list.
        $byReason = RejectionReason::query()
            ->active()
            ->orderBy('position')
            ->get()
            ->map(function (RejectionReason $reason) use ($dayStart, $dayEnd) {
                $litres = Delivery::query()
                    ->excludingTestData()
                    ->where('delivered_at', '>=', $dayStart)
                    ->where('delivered_at', '<', $dayEnd)
                    ->where('rejection_reason_id', $reason->getKey())
                    ->sum('litres_rejected');

                return [
                    'reason' => $reason->name,
                    'code' => $reason->code,
                    'litres' => Volume::fromCentilitres((int) round(100 * (float) $litres)),
                ];
            })
            ->filter(fn (array $row) => Volume::toCentilitres($row['litres']) > 0)
            ->values();

        $total = Volume::sum([
            Volume::fromCentilitres((int) round(100 * (float) $atPoints)),
            Volume::fromCentilitres((int) round(100 * (float) $atCenters)),
            Volume::fromCentilitres((int) round(100 * (float) $atFactory)),
        ]);

        return [
            'total' => $total,
            'at_points' => Volume::fromCentilitres((int) round(100 * (float) $atPoints)),
            'at_centers' => Volume::fromCentilitres((int) round(100 * (float) $atCenters)),
            'at_factory' => Volume::fromCentilitres((int) round(100 * (float) $atFactory)),
            'by_reason' => $byReason,
        ];
    }

    /**
     * The 7-day bar chart. Returns litres per day plus the peak, so the view can
     * compute bar heights without inventing figures.
     *
     * @return array<string, mixed>
     */
    private function intakeLastSevenDays(): array
    {
        $days = collect(range(6, 0))->map(function (int $offset) {
            $date = Wat::today()->subDays($offset);

            // ARCH-9 — each bar is labelled with a WAT date, so it must sum a WAT
            // day. Summing the UTC day of the same name shifted every bar an hour.
            [$dayStart, $dayEnd] = Wat::dayRange($date);

            $litres = Consignment::query()
                ->excludingTestData()
                ->whereNotNull('confirmed_at')
                ->where('confirmed_at', '>=', $dayStart)
                ->where('confirmed_at', '<', $dayEnd)
                ->sum('litres_confirmed');

            return [
                'label' => $date->format('D'),
                'date' => $date->toDateString(),
                'litres' => Volume::fromCentilitres((int) round(100 * (float) $litres)),
                'centilitres' => (int) round(100 * (float) $litres),
            ];
        });

        return [
            'days' => $days,
            'peak_centilitres' => max(1, (int) $days->max('centilitres')),
        ];
    }

    /**
     * Today's intake by grade. Percentages are computed from the figures, never
     * assumed.
     *
     * @return array<string, mixed>
     */
    private function qualityBreakdown(string $date): array
    {
        // ARCH-9 — the WAT day as a UTC instant range, not a date literal.
        [$dayStart, $dayEnd] = Wat::dayRange($date);

        $rows = Grade::query()->active()->orderBy('position')->get()->map(function (Grade $grade) use ($dayStart, $dayEnd) {
            $litres = Consignment::query()
                ->excludingTestData()
                ->whereNotNull('confirmed_at')
                ->where('confirmed_at', '>=', $dayStart)
                ->where('confirmed_at', '<', $dayEnd)
                ->where('grade_id', $grade->getKey())
                ->sum('litres_confirmed');

            return [
                'grade' => $grade->name,
                'code' => $grade->code,
                'is_rejection' => (bool) $grade->is_rejection,
                'centilitres' => (int) round(100 * (float) $litres),
            ];
        });

        $rejectedCl = (int) round(100 * (float) Consignment::query()
            ->excludingTestData()
            ->whereNotNull('confirmed_at')
            ->where('confirmed_at', '>=', $dayStart)
            ->where('confirmed_at', '<', $dayEnd)
            ->sum('litres_rejected_at_center'));

        // The rejected row is volume rejected, not volume graded "Rejected".
        $rows = $rows->map(fn (array $row) => $row['is_rejection']
            ? array_merge($row, ['centilitres' => $rejectedCl])
            : $row);

        $total = max(1, (int) $rows->sum('centilitres'));

        return [
            'rows' => $rows->map(fn (array $row) => array_merge($row, [
                'litres' => Volume::fromCentilitres($row['centilitres']),
                'percentage' => round($row['centilitres'] / $total * 100, 1),
            ]))->values(),
            'total' => Volume::fromCentilitres((int) $rows->sum('centilitres')),
        ];
    }

    /**
     * "Latest Collections" — centers with today's confirmed volume.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function recentCenters(string $date): Collection
    {
        // ARCH-9 — the WAT day as a UTC instant range, not a date literal.
        [$dayStart, $dayEnd] = Wat::dayRange($date);

        return Consignment::query()
            ->excludingTestData()
            ->whereNotNull('confirmed_at')
            ->where('confirmed_at', '>=', $dayStart)
            ->where('confirmed_at', '<', $dayEnd)
            ->with(['collectionCenter', 'grade'])
            ->get()
            ->groupBy('collection_center_id')
            ->map(function (Collection $group) {
                $first = $group->first();

                return [
                    'center' => $first->collectionCenter,
                    'litres' => Volume::sum($group->pluck('litres_confirmed')->all()),
                    'farmers' => Delivery::query()
                        ->excludingTestData()
                        ->whereIn('consignment_id', $group->pluck('id'))
                        ->distinct()
                        ->count('farmer_id'),
                    'dominant_grade' => $group
                        ->groupBy('grade_id')
                        ->sortByDesc(fn (Collection $byGrade) => $byGrade->count())
                        ->first()?->first()?->grade,
                ];
            })
            ->sortByDesc(fn (array $row) => Volume::toCentilitres($row['litres']))
            ->take(4)
            ->values();
    }

    private function changePercentage(float $previous, float $current): ?float
    {
        if ($previous <= 0.0) {
            return null;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }
}
