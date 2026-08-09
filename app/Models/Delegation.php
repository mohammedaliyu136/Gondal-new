<?php

namespace App\Models;

use App\Models\Concerns\RecordsActor;
use App\Support\Wat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * BR-24 — "An active delegation routes the delegator's queue to the delegate for
 * the period. Delegated actions record both users."
 *
 * A delegation is deliberately narrow: it names ONE role. It does not hand over
 * the delegator's whole account, and it does not widen the delegate's
 * permissions outside the approval queue.
 */
class Delegation extends Model
{
    use RecordsActor;
    use SoftDeletes;

    protected $fillable = [
        'from_user_id', 'to_user_id', 'role_id', 'starts_on', 'ends_on',
        'reason', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'revoked_at' => 'datetime',
        ];
    }

    public function fromUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_user_id');
    }

    public function toUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_user_id');
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function isActive(): bool
    {
        $today = Wat::today()->toDateString();

        return $this->revoked_at === null
            && $this->starts_on->toDateString() <= $today
            && $this->ends_on->toDateString() >= $today;
    }

    public function scopeActive(Builder $query): Builder
    {
        $today = Wat::today()->toDateString();

        return $query->whereNull('revoked_at')
            ->whereDate('starts_on', '<=', $today)
            ->whereDate('ends_on', '>=', $today);
    }
}
