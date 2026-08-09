<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SCOPE-1 — the `communities` scope type takes a LIST of community ids, which a
 * single scope_target_id cannot hold.
 */
class RoleUserScopeTarget extends Model
{
    protected $table = 'role_user_scope_targets';

    protected $fillable = ['role_user_id', 'target_id'];

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(RoleAssignment::class, 'role_user_id');
    }
}
