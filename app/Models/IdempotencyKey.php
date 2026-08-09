<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ARCH-7 — "All write endpoints accept an optional Idempotency-Key header;
 * replays return the original result. Required for unreliable-connectivity
 * capture."
 */
class IdempotencyKey extends Model
{
    protected $fillable = [
        'key', 'user_id', 'method', 'path', 'request_fingerprint',
        'response_status', 'response_body', 'completed_at',
    ];

    protected function casts(): array
    {
        return ['completed_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isComplete(): bool
    {
        return $this->completed_at !== null;
    }
}
