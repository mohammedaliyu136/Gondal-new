<?php

namespace App\Models;

use App\Authorization\ScopeType;
use App\Contracts\Scopeable;
use App\Models\Concerns\AppliesDataScope;
use App\Models\Concerns\RecordsActor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * §6.4 requisitions.
 *
 * NG-6 — no vendor registry, purchase orders or GRNs in v1; `suggested_vendor`
 *   is free text by design (§15.5).
 * BR-22 — approved_total_minor may only ever be reduced below total_minor.
 */
class Requisition extends Model implements Scopeable
{
    use AppliesDataScope;
    use RecordsActor;
    use SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_IN_REVIEW = 'in_review';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    public bool $tagsTestActivity = true;

    protected $fillable = [
        'reference', 'requester_user_id', 'department_id', 'title', 'category',
        'urgency', 'needed_by', 'suggested_vendor', 'service_provider_id', 'total_minor',
        'approved_total_minor', 'workflow_instance_id', 'status',
        'submitted_at', 'decided_at', 'revises_requisition_id',
        'is_test', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'needed_by' => 'date',
            'submitted_at' => 'datetime',
            'decided_at' => 'datetime',
            'total_minor' => 'integer',
            'approved_total_minor' => 'integer',
            'is_test' => 'boolean',
        ];
    }

    public function scopeResourceKey(): string
    {
        return 'purchase.requisitions';
    }

    /**
     * SCOPE-1 — a Department Head scoped to their department sees their
     * department's requisitions; everyone sees their own.
     */
    public function scopeConstraints(): array
    {
        return [
            ScopeType::Department->value => fn (Builder $q, array $ids) => $q->whereIn('requisitions.department_id', $ids),
            ScopeType::Own->value => fn (Builder $q, array $ids) => $q->whereIn('requisitions.requester_user_id', $ids),
        ];
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_user_id');
    }

    public function serviceProvider(): BelongsTo
    {
        return $this->belongsTo(ServiceProvider::class);
    }

    /**
     * Money that actually left against this requisition.
     *
     * An approval is a permission to spend, not a spend. Until Phase 7 nothing
     * referred to `approved_total_minor` again once the workflow cleared.
     */
    public function expenditures(): HasMany
    {
        return $this->hasMany(RequisitionExpenditure::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(RequisitionItem::class)->orderBy('position');
    }

    public function workflowInstance(): BelongsTo
    {
        return $this->belongsTo(WorkflowInstance::class);
    }

    /** BR-20 — the requisition this one revises, if it was a resubmission. */
    public function revises(): BelongsTo
    {
        return $this->belongsTo(self::class, 'revises_requisition_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(self::class, 'revises_requisition_id');
    }

    /** BR-20 — every instance this subject has ever had, newest first. */
    public function workflowInstances(): MorphMany
    {
        return $this->morphMany(WorkflowInstance::class, 'subject')->latest('id');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    public function isEditableByRequester(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_REJECTED], true);
    }

    public function scopeAwaitingDecision(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_IN_REVIEW);
    }
}
