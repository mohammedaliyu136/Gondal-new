<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * §6.5 workflow_actions — the immutable trail of who did what at which stage.
 *
 * BR-24 — a delegated action records BOTH users: actor_user_id is who clicked,
 * on_behalf_of_user_id is whose queue it came from.
 */
class WorkflowAction extends Model
{
    public const ACTION_SUBMIT = 'submit';

    public const ACTION_APPROVE = 'approve';

    public const ACTION_REJECT = 'reject';

    public const ACTION_REQUEST_INFO = 'request_info';

    public const ACTION_DELEGATE = 'delegate';

    public const ACTION_CANCEL = 'cancel';

    protected $fillable = [
        'workflow_instance_id', 'workflow_stage_id', 'actor_user_id',
        'on_behalf_of_user_id', 'delegation_id', 'action', 'amount_minor',
        'reason_id', 'comment', 'acted_at',
    ];

    protected function casts(): array
    {
        return [
            'acted_at' => 'datetime',
            'amount_minor' => 'integer',
        ];
    }

    public function instance(): BelongsTo
    {
        return $this->belongsTo(WorkflowInstance::class, 'workflow_instance_id');
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(WorkflowStage::class, 'workflow_stage_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function onBehalfOf(): BelongsTo
    {
        return $this->belongsTo(User::class, 'on_behalf_of_user_id');
    }

    public function delegation(): BelongsTo
    {
        return $this->belongsTo(Delegation::class);
    }
}
