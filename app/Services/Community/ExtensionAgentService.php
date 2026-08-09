<?php

namespace App\Services\Community;

use App\Exceptions\RuleViolationException;
use App\Models\Community;
use App\Models\ExtensionAgent;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Wat;
use Illuminate\Support\Facades\DB;

/**
 * §6.6 — the extension agent register and its community coverage.
 *
 * SCOPE-1 — the Extension Agent role is `communities`-scoped, so the
 * agent_community rows ARE the agent's world: an agent with none sees an empty
 * farmer list and an empty activity queue on the web and in AgentConnect alike.
 * Until this existed, only DemoDataSeeder wrote that pivot — a newly hired agent
 * landed in exactly that state and the detail screen said so ("the agent can see
 * nothing until one is") while offering no control to fix it.
 *
 * USER-1 — an extension agent IS staff, so the record is attached to an existing
 * account rather than creating one. BR-31 keeps account creation in
 * administration, where the password never passes through a human.
 */
class ExtensionAgentService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $actor): ExtensionAgent
    {
        $user = User::query()->findOrFail($data['user_id']);

        if (ExtensionAgent::withoutDataScope()->where('user_id', $user->getKey())->exists()) {
            throw RuleViolationException::make(
                'ST-1',
                $user->name.' already has an extension agent record.',
                ['user_id' => $user->getKey()],
                'user_id',
            );
        }

        $agent = DB::transaction(function () use ($data, $user, $actor): ExtensionAgent {
            $agent = ExtensionAgent::query()->create([
                'user_id' => $user->getKey(),
                'code' => $data['code'],
                'reports_to_user_id' => $data['reports_to_user_id'] ?? null,
                'visit_target_monthly' => $data['visit_target_monthly'] ?? null,
                'enrolment_target_monthly' => $data['enrolment_target_monthly'] ?? null,
                'status' => $data['status'] ?? 'active',
                'created_by_user_id' => $actor->getKey(),
            ]);

            $this->syncCommunities($agent, $data['community_ids'] ?? []);

            return $agent;
        });

        $this->audit->created(
            $agent,
            sprintf(
                '%s (%s) added as an extension agent covering %d community/communities',
                $user->name,
                $agent->code,
                $agent->communities()->count(),
            ),
            'Community Engagement',
            [
                'user' => $user->email,
                'visit_target_monthly' => $agent->visit_target_monthly,
                'enrolment_target_monthly' => $agent->enrolment_target_monthly,
                'rule' => 'SCOPE-1',
            ],
            $actor,
        );

        return $agent->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(ExtensionAgent $agent, array $data, User $actor): ExtensionAgent
    {
        $before = $agent->only(['code', 'reports_to_user_id', 'visit_target_monthly', 'enrolment_target_monthly', 'status'])
            + ['communities' => $this->communityNames($agent)];

        DB::transaction(function () use ($agent, $data): void {
            $agent->fill([
                'code' => $data['code'] ?? $agent->code,
                'reports_to_user_id' => $data['reports_to_user_id'] ?? null,
                'visit_target_monthly' => $data['visit_target_monthly'] ?? null,
                'enrolment_target_monthly' => $data['enrolment_target_monthly'] ?? null,
                'status' => $data['status'] ?? $agent->status,
            ])->save();

            /*
             * Only touched when the form actually sent a coverage list. The
             * targets form and the coverage form are separate controls on the
             * screen, and an absent field must not be read as "cover nothing" —
             * that would silently blind the agent.
             */
            if (array_key_exists('community_ids', $data)) {
                $this->syncCommunities($agent, $data['community_ids'] ?? []);
            }
        });

        $agent->load('communities');

        $after = $agent->only(array_keys($before)) + ['communities' => $this->communityNames($agent)];

        $this->audit->edited(
            $agent,
            sprintf('%s (%s) updated — covering %s',
                $agent->user?->name ?? 'Extension agent',
                $agent->code,
                $after['communities'] === '' ? 'no communities' : $after['communities'],
            ),
            'Community Engagement',
            $before,
            $after,
            $actor,
        );

        return $agent;
    }

    /**
     * The coverage list, replaced wholesale.
     *
     * sync() rather than syncWithoutDetaching(): removing a community from an
     * agent is the operation a transfer needs, and an add-only pivot cannot
     * express it. The pivot's assigned_at is preserved for rows that stay, so
     * "since when" survives an unrelated edit.
     *
     * @param  array<int, int|string>  $communityIds
     */
    private function syncCommunities(ExtensionAgent $agent, array $communityIds): void
    {
        $ids = Community::query()
            ->whereIn('id', array_map('intval', $communityIds))
            ->pluck('id');

        $existing = $agent->communities()->pluck('agent_community.assigned_at', 'communities.id');

        $agent->communities()->sync(
            $ids->mapWithKeys(fn (int $id) => [
                $id => ['assigned_at' => $existing[$id] ?? Wat::now()],
            ])->all(),
        );
    }

    private function communityNames(ExtensionAgent $agent): string
    {
        return $agent->communities()->orderBy('name')->pluck('name')->implode(', ');
    }
}
