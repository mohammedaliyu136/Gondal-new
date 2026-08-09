<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * §6.1 `sessions` — the auditable session register, not the session driver's
 * payload store (that is `http_sessions`).
 *
 * BR-32 — deactivating a user revokes sessions through this table.
 * BR-33 — changing a password revokes all OTHER sessions the same way.
 */
class AuthSession extends Model
{
    protected $table = 'sessions';

    protected $fillable = [
        'user_id', 'device_id', 'http_session_id', 'ip', 'user_agent',
        'started_at', 'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'ended_at' => 'datetime',
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

    public function scopeLive(Builder $query): Builder
    {
        return $query->whereNull('ended_at');
    }

    public function end(string $reason): void
    {
        if ($this->ended_at !== null) {
            return;
        }

        $this->forceFill(['ended_at' => now(), 'ended_reason' => $reason])->save();
    }
}
