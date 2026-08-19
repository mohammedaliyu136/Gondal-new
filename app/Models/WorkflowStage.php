<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * §6.5 workflow stages.
 *
 * BR-23 — "Stages reference ROLES, not users." Reassigning staff therefore never
 * breaks a workflow, and anyone holding the role (and satisfying scope) sees the
 * item in /approvals.
 */
class WorkflowStage extends Model
{
    use SoftDeletes;

    public const CONDITION_ALWAYS = 'always';

    public const CONDITION_AMOUNT_ABOVE = 'amount_above';

    public const CONDITION_DEPARTMENT = 'department';

    public const CONDITION_CATEGORY = 'category';

    protected $fillable = [
        'workflow_id', 'position', 'name', 'approving_role_id', 'required_permission',
        'condition_type', 'condition_value', 'sla_hours', 'can_reject', 'is_submission',
        'stage_action', 'stage_action_config',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'sla_hours' => 'integer',
            'can_reject' => 'boolean',
            'is_submission' => 'boolean',
            'stage_action_config' => 'array',
        ];
    }

    public function hasStageAction(): bool
    {
        return ! empty($this->stage_action);
    }

    public function stageActionHandler(): ?\App\Services\Workflow\Contracts\WorkflowStageActionHandler
    {
        if (! $this->hasStageAction()) {
            return null;
        }

        return app(\App\Services\Workflow\StageActionRegistry::class)->get($this->stage_action);
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    public function approvingRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'approving_role_id');
    }

    public function bands(): BelongsToMany
    {
        return $this->belongsToMany(WorkflowBand::class, 'workflow_band_stage');
    }

    /** settings-workflows.html "Applies When". */
    public function describeCondition(): string
    {
        return match ($this->condition_type) {
            self::CONDITION_AMOUNT_ABOVE => 'Over '.Money::format((int) $this->condition_value),
            self::CONDITION_DEPARTMENT => 'Department: '.(string) $this->condition_value,
            self::CONDITION_CATEGORY => 'Category: '.(string) $this->condition_value,
            default => 'Always',
        };
    }

    /**
     * BR-19 — a stage applies when its own condition holds. Band membership is
     * checked separately by the engine so the two mechanisms compose.
     */
    public function conditionHolds(?int $amountMinor, ?int $departmentId, ?string $category): bool
    {
        return match ($this->condition_type) {
            self::CONDITION_AMOUNT_ABOVE => ($amountMinor ?? 0) > (int) $this->condition_value,
            self::CONDITION_DEPARTMENT => (string) $departmentId === (string) $this->condition_value,
            self::CONDITION_CATEGORY => $category !== null && $category === $this->condition_value,
            default => true,
        };
    }
}
