<?php

namespace App\Services\Community;

use App\Exceptions\RuleViolationException;
use App\Models\ExtensionAgent;
use App\Models\Farmer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * "Everyone in Tudun Wada", "everyone on Jamila's round", "everyone at PT-001".
 *
 * A revalidation round is one act of judgement over many farmers, so the thing
 * M&E picks is a COHORT, not two hundred checkboxes. This class is the only
 * place that turns the name of a cohort into the farmers in it.
 *
 * ARCH-4 layer 2 — every cohort is resolved through `Farmer::query()`, which
 * carries the data scope. An officer holding four communities who asks for a
 * fifth gets an empty cohort rather than a leak, and does not need to be
 * trusted to have filtered correctly on the way in. Nothing here calls
 * `withoutDataScope()`, and nothing here should.
 *
 * The cap is deliberate and REPORTED (see `resolve()`): a silent truncation
 * would read as "the whole community was scheduled" when it was not.
 */
class FarmerCohort
{
    public const BY_COMMUNITY = 'community';

    public const BY_LGA = 'lga';

    public const BY_AGENT = 'agent';

    public const BY_POINT = 'point';

    public const BY_COOPERATIVE = 'cooperative';

    public const TYPES = [
        self::BY_COMMUNITY,
        self::BY_LGA,
        self::BY_AGENT,
        self::BY_POINT,
        self::BY_COOPERATIVE,
    ];

    /**
     * One round should not silently become a thousand household visits nobody
     * has the staff for. Beyond this the caller is told to narrow the cohort.
     */
    public const MAX = 500;

    /** Labels for the picker, and for the round name M&E does not have to type. */
    public static function label(string $type): string
    {
        return match ($type) {
            self::BY_COMMUNITY => 'Community',
            self::BY_LGA => 'LGA',
            self::BY_AGENT => 'Extension agent',
            self::BY_POINT => 'Collection point',
            self::BY_COOPERATIVE => 'Cooperative',
            default => $type,
        };
    }

    /**
     * @param  array<int, int|string>  $targetIds
     * @return Collection<int, Farmer>
     */
    public function resolve(string $type, array $targetIds, bool $overdueOnly = false): Collection
    {
        $ids = array_values(array_filter(array_map('intval', $targetIds)));

        if ($ids === []) {
            throw RuleViolationException::make(
                'BR-36',
                'Choose at least one '.strtolower(self::label($type)).' for the cohort.',
                ['cohort_type' => $type],
                'cohort_target_ids',
            );
        }

        $query = $this->constrain(Farmer::query()->active(), $type, $ids);

        if ($overdueOnly) {
            $query->validationOverdue();
        }

        $total = (clone $query)->count();

        // `static::`, not `self::` — so a deployment that needs a different cap
        // can subclass and bind it, and so the guard can be exercised in a test
        // without creating five hundred farmers.
        if ($total > static::MAX) {
            throw RuleViolationException::make(
                'BR-36',
                sprintf(
                    'That cohort is %d farmers, and a round is capped at %d. Narrow it — one community at a time, or tick "only farmers already overdue".',
                    $total,
                    static::MAX,
                ),
                ['cohort_type' => $type, 'total' => $total, 'max' => static::MAX],
                'cohort_target_ids',
            );
        }

        return $query->with('community')->orderBy('name')->get();
    }

    /**
     * How many farmers the cohort would cover, for the count shown beside the
     * picker before anyone commits. Scoped identically to `resolve()`, so the
     * number on the screen is the number that will be scheduled.
     *
     * @param  array<int, int|string>  $targetIds
     */
    public function count(string $type, array $targetIds, bool $overdueOnly = false): int
    {
        $ids = array_values(array_filter(array_map('intval', $targetIds)));

        if ($ids === []) {
            return 0;
        }

        $query = $this->constrain(Farmer::query()->active(), $type, $ids);

        if ($overdueOnly) {
            $query->validationOverdue();
        }

        return $query->count();
    }

    /**
     * @param  array<int, int>  $ids
     */
    private function constrain(Builder $query, string $type, array $ids): Builder
    {
        return match ($type) {
            self::BY_COMMUNITY => $query->whereIn('community_id', $ids),
            self::BY_LGA => $query->whereIn('lga_id', $ids),
            self::BY_POINT => $query->whereIn('default_collection_point_id', $ids),
            self::BY_COOPERATIVE => $query->whereIn('cooperative_id', $ids),

            /*
             * An agent's caseload is the communities the register assigns them,
             * exactly as their data scope is derived — so "Jamila's farmers"
             * means the same thing here as it does when Jamila signs in. Read
             * from the register rather than from her role assignment, because
             * the register is what the extension team maintains.
             */
            /*
             * Every column is table-qualified. `whereIn('id', …)` read as
             * ambiguous once the join brought `agent_community.id` into scope,
             * and SQLite refused the whole query — so picking an agent as the
             * cohort failed outright with a SQL error rather than returning
             * their farmers. Nothing caught it because the tests only ever
             * exercised the community and LGA resolvers.
             */
            self::BY_AGENT => $query->whereIn(
                'community_id',
                ExtensionAgent::query()
                    ->whereIn('extension_agents.id', $ids)
                    ->join('agent_community', 'agent_community.extension_agent_id', '=', 'extension_agents.id')
                    ->select('agent_community.community_id'),
            ),

            default => throw RuleViolationException::make(
                'BR-36',
                'Unknown cohort type.',
                ['cohort_type' => $type],
                'cohort_type',
            ),
        };
    }
}
