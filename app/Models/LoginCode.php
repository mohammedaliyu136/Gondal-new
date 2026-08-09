<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AUTH-3 — codes are 6 digits, expire in 10 minutes (15 for a reset, AUTH-4),
 * single-use, rate-limited to 5 attempts before invalidation, and STORED HASHED.
 */
class LoginCode extends Model
{
    public const PURPOSE_SIGNIN = 'signin';

    public const PURPOSE_RESET = 'reset';

    protected $fillable = [
        'user_id', 'purpose', 'code_hash', 'expires_at', 'ip',
    ];

    protected $hidden = ['code_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'invalidated_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isUsable(): bool
    {
        return $this->consumed_at === null
            && $this->invalidated_at === null
            && $this->expires_at->isFuture();
    }

    public function scopeUsable(Builder $query): Builder
    {
        return $query->whereNull('consumed_at')
            ->whereNull('invalidated_at')
            ->where('expires_at', '>', now());
    }

    public function scopeForPurpose(Builder $query, string $purpose): Builder
    {
        return $query->where('purpose', $purpose);
    }
}
