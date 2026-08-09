<?php

namespace App\Models;

use App\Support\Wat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

/**
 * §6.5 / §8 — one journey of one subject through one workflow.
 *
 * BR-20 — a rejection ends the instance. Resubmission starts a NEW instance and
 * the old one is retained, which is why nothing here is ever reopened.
 */
class WorkflowInstance extends Model
{
    use SoftDeletes;

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'workflow_id', 'workflow_band_id', 'subject_type', 'subject_id',
        'current_stage_id', 'status', 'requester_user_id', 'amount_minor',
        'approved_amount_minor', 'started_at', 'completed_at',
        'current_stage_due_at', 'is_test',
    ];

    /**
     * NFR-2 — memoised because applicableStages() is not free and the screens
     * ask for it repeatedly. `{{ $instance->stageNumber() }} of
     * {{ $instance->stageCount() }}` on the requisitions table is two calls per
     * row, each lazy-loading `band` and the morph `subject`: 55 queries for a
     * seven-row page, roughly 150 at 25 rows.
     *
     * What it derives from — the band, the subject's department and the
     * requested amount — is fixed for the life of the instance by BR-19, which
     * chooses the band at start() and never re-routes. The one thing that does
     * move, current_stage_id, is not an input to it. refresh() clears it anyway,
     * so a reload cannot serve a stale list.
     *
     * @var Collection<int, WorkflowStage>|null
     */
    private ?Collection $applicableStages = null;

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'current_stage_due_at' => 'datetime',
            'amount_minor' => 'integer',
            'approved_amount_minor' => 'integer',
            'is_test' => 'boolean',
        ];
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    public function band(): BelongsTo
    {
        return $this->belongsTo(WorkflowBand::class, 'workflow_band_id');
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function currentStage(): BelongsTo
    {
        return $this->belongsTo(WorkflowStage::class, 'current_stage_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_user_id');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(WorkflowAction::class)->orderBy('acted_at');
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_IN_PROGRESS;
    }

    /** NOTIF-4 — overdue reminders follow the stage SLA. */
    public function isOverdue(): bool
    {
        return $this->isOpen()
            && $this->current_stage_due_at !== null
            && $this->current_stage_due_at->isPast();
    }

    public function hoursRemaining(): ?int
    {
        if ($this->current_stage_due_at === null) {
            return null;
        }

        return (int) Wat::now()->diffInHours($this->current_stage_due_at, false);
    }

    /**
     * BR-19 — the stages that actually apply to this instance, in order. Band
     * membership decides which stages exist at all; each stage's own condition
     * then decides whether it is reached.
     *
     * @return Collection<int, WorkflowStage>
     */
    public function applicableStages(): Collection
    {
        if ($this->applicableStages !== null) {
            return $this->applicableStages;
        }

        $stages = $this->band !== null
            ? $this->band->stages
            : $this->workflow->stages;

        $departmentId = data_get($this->subject, 'department_id');
        $category = data_get($this->subject, 'category');

        return $this->applicableStages = $stages
            ->filter(fn (WorkflowStage $stage) => $stage->conditionHolds(
                $this->amount_minor,
                $departmentId === null ? null : (int) $departmentId,
                $category === null ? null : (string) $category,
            ))
            ->sortBy('position')
            ->values();
    }

    /** The memo above must not outlive the row it was derived from. */
    public function refresh(): static
    {
        $this->applicableStages = null;

        return parent::refresh();
    }

    /** BR-17 — the next stage after the current one, in strict sequence. */
    public function nextStage(): ?WorkflowStage
    {
        $stages = $this->applicableStages();
        $index = $stages->search(fn (WorkflowStage $stage) => $stage->id === $this->current_stage_id);

        return $index === false ? $stages->first() : $stages->get($index + 1);
    }

    public function stageNumber(): ?int
    {
        $stages = $this->applicableStages();
        $index = $stages->search(fn (WorkflowStage $stage) => $stage->id === $this->current_stage_id);

        return $index === false ? null : $index + 1;
    }

    public function stageCount(): int
    {
        return $this->applicableStages()->count();
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_IN_PROGRESS);
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->open()->whereNotNull('current_stage_due_at')->where('current_stage_due_at', '<', now());
    }
}
