<?php

namespace App\Models;

use App\Authorization\ScopeType;
use App\Contracts\Scopeable;
use App\Models\Concerns\AppliesDataScope;
use App\Models\Concerns\RecordsActor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * §6.8 leave requests.
 *
 * §5.1 — two permissions govern this record: `hr.leave` (all requests) and
 * `hr.leave.own` (own only, granted automatically by ROLE-3). The scope
 * constraint resolves both: `own` reaches the employee record behind the user.
 */
class LeaveRequest extends Model implements Scopeable
{
    use AppliesDataScope;
    use RecordsActor;
    use SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_IN_REVIEW = 'in_review';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'employee_id', 'leave_type_id', 'starts_on', 'ends_on', 'days', 'reason',
        'workflow_instance_id', 'status', 'submitted_at', 'decided_at', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'days' => 'integer',
            'submitted_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    public function scopeResourceKey(): string
    {
        return 'hr.leave';
    }

    /**
     * Leave is reachable two ways: HR and line managers hold `hr.leave`, while
     * every member of staff holds `hr.leave.own` through the automatic role. Both
     * must be consulted, or the ordinary employee — the majority of users — files
     * a request and cannot see it.
     */
    public function scopeResourceKeys(): array
    {
        return ['hr.leave', 'hr.leave.own'];
    }

    public function scopeConstraints(): array
    {
        return [
            ScopeType::Department->value => fn (Builder $q, array $ids) => $q->whereHas(
                'employee',
                fn (Builder $inner) => $inner->whereIn('employees.department_id', $ids),
            ),
            ScopeType::Own->value => fn (Builder $q, array $ids) => $q->whereIn(
                'leave_requests.employee_id',
                User::query()->whereIn('id', $ids)->whereNotNull('employee_id')->select('employee_id'),
            ),
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function workflowInstance(): BelongsTo
    {
        return $this->belongsTo(WorkflowInstance::class);
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    public function workflowInstances(): MorphMany
    {
        return $this->morphMany(WorkflowInstance::class, 'subject')->latest('id');
    }

    public function scopeAwaitingDecision(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_IN_REVIEW);
    }
}
