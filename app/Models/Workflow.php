<?php

namespace App\Models;

use App\Models\Concerns\RecordsActor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * §6.5 / §9 — "who approves what, in what order: configured, not coded".
 *
 * The options panel on settings-workflows.html maps one-to-one onto the
 * `options` JSON, and the engine reads it rather than assuming behaviour:
 *   strict_sequence                (BR-17)
 *   rejection_returns_to_requester (BR-20)
 *   approver_may_reduce_amount     (BR-22)
 *   allow_request_info             (BR-21)
 *   allow_delegation               (BR-24)
 *   auto_escalate_on_sla
 *   requester_may_not_approve_own  (BR-18 — enforced regardless, see the engine)
 *   overdue_reminder               (NOTIF-4)
 */
class Workflow extends Model
{
    use RecordsActor;
    use SoftDeletes;

    public const APPLIES_REQUISITION = 'requisition';

    public const APPLIES_LEAVE = 'leave';

    public const APPLIES_STOCK_ADJUSTMENT = 'stock_adjustment';

    public const APPLIES_PAYROLL_RUN = 'payroll_run';

    public const APPLIES_BATCH_DISCREPANCY = 'batch_discrepancy';

    protected $fillable = [
        'code', 'name', 'description', 'applies_to', 'status', 'options', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return ['options' => 'array'];
    }

    public function stages(): HasMany
    {
        return $this->hasMany(WorkflowStage::class)->orderBy('position');
    }

    public function bands(): HasMany
    {
        return $this->hasMany(WorkflowBand::class)->orderBy('amount_from_minor');
    }

    public function instances(): HasMany
    {
        return $this->hasMany(WorkflowInstance::class);
    }

    public function option(string $key, mixed $default = null): mixed
    {
        return data_get($this->options ?? [], $key, $default);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * BR-19 — the band whose range contains the subject's amount. A workflow with
     * no bands applies every stage.
     */
    public function bandFor(?int $amountMinor): ?WorkflowBand
    {
        if ($this->bands->isEmpty()) {
            return null;
        }

        $amount = $amountMinor ?? 0;

        return $this->bands
            ->sortBy('amount_from_minor')
            ->first(fn (WorkflowBand $band) => $amount >= (int) $band->amount_from_minor
                && ($band->amount_to_minor === null || $amount <= (int) $band->amount_to_minor));
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function scopeFor(Builder $query, string $appliesTo): Builder
    {
        return $query->active()->where('applies_to', $appliesTo);
    }
}
