<?php

namespace App\Models;

use App\Support\Wat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ARCH-2 — the bearer credential a mobile client holds.
 *
 * A token carries no permissions of its own (see the migration). It identifies
 * the user; §5 answers everything else.
 */
class ApiToken extends Model
{
    protected $fillable = [
        'user_id', 'name', 'token_hash', 'device_id', 'platform', 'app_version',
        'last_used_at', 'last_ip', 'expires_at',
    ];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function isUsable(): bool
    {
        return $this->revoked_at === null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function scopeUsable(Builder $query): Builder
    {
        return $query->whereNull('revoked_at')
            ->where(fn (Builder $inner) => $inner
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>', Wat::now()));
    }

    public function revoke(string $reason = 'signout'): void
    {
        $this->forceFill([
            'revoked_at' => Wat::now(),
            'revoked_reason' => $reason,
        ])->save();
    }
}
