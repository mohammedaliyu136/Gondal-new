<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AUTH-6 / NFR-8 — failed sign-ins are logged and throttled per account AND per
 * IP. Recording them (rather than only counting in the cache) is what lets the
 * lockout survive a cache flush and lets the audit log explain itself.
 */
class FailedSignin extends Model
{
    public $timestamps = false;

    protected $fillable = ['email', 'user_id', 'ip', 'user_agent', 'reason', 'occurred_at'];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime', 'superseded_at' => 'datetime'];
    }

    /**
     * AUTH-6 — the failures that still count towards a lockout.
     *
     * A successful sign-in supersedes everything before it (SigninThrottle::clear),
     * so the run of failures the lock describes is always the current one. The rows
     * stay in the table either way; AUTH-6 requires they stay logged.
     */
    public function scopeStillCounting(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->whereNull('superseded_at');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
