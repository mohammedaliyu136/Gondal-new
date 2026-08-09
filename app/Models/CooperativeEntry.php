<?php

namespace App\Models;

use App\Models\Concerns\RecordsActor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * §6.6 — the cooperative fund ledger. Every row carries the balance it produced,
 * so the statement can be read without recomputing history.
 */
class CooperativeEntry extends Model
{
    use RecordsActor;
    use SoftDeletes;

    public const DIRECTION_IN = 'in';

    public const DIRECTION_OUT = 'out';

    protected $fillable = [
        'cooperative_account_id', 'entry_date', 'description', 'direction',
        'amount_minor', 'balance_after_minor', 'source_type', 'source_id',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'entry_date' => 'date',
            'amount_minor' => 'integer',
            'balance_after_minor' => 'integer',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(CooperativeAccount::class, 'cooperative_account_id');
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }
}
