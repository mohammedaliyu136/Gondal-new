<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AUTH-2 — "Trust this device for 30 days" issues a device token that skips the
 * emailed-code step. Trust is revocable by the user AND by an administrator.
 *
 * NFR-9 — only the token hash is stored. The plaintext token exists in the
 * user's cookie and nowhere else, so it can never appear in a log or a dump.
 */
class Device extends Model
{
    protected $fillable = [
        'user_id', 'label', 'user_agent', 'last_ip', 'token_hash',
        'trusted_until', 'last_seen_at',
    ];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'trusted_until' => 'datetime',
            'last_seen_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by_user_id');
    }

    /** AUTH-2 — a trusted device is one that is neither revoked nor expired. */
    public function isTrusted(): bool
    {
        return $this->revoked_at === null
            && $this->trusted_until !== null
            && $this->trusted_until->isFuture();
    }

    public function scopeTrusted(Builder $query): Builder
    {
        return $query->whereNull('revoked_at')->where('trusted_until', '>', now());
    }

    public function revoke(?User $by = null): void
    {
        $this->forceFill([
            'revoked_at' => now(),
            'revoked_by_user_id' => $by?->getKey(),
        ])->save();
    }
}
