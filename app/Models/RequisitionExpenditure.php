<?php

namespace App\Models;

use App\Models\Concerns\RecordsActor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Money that actually left, against a requisition somebody approved.
 *
 * An approval is a permission to spend, not a spend. Without this row a
 * requisition approved at ₦400,000 and settled at ₦520,000 looks identical to
 * one settled at ₦380,000, and both look identical to one nobody ever bought
 * anything against.
 */
class RequisitionExpenditure extends Model
{
    use RecordsActor;
    use SoftDeletes;

    /** @var array<int, string> */
    public const METHODS = ['bank', 'cash', 'cheque', 'transfer', 'gateway'];

    public bool $tagsTestActivity = true;

    protected $fillable = [
        'requisition_id', 'department_id', 'cost_centre', 'amount_minor',
        'vendor', 'invoice_reference', 'method', 'spent_on',
        'recorded_by_user_id', 'notes', 'is_test', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'spent_on' => 'date',
            'is_test' => 'boolean',
        ];
    }

    public function requisition(): BelongsTo
    {
        return $this->belongsTo(Requisition::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    public function scopeExcludingTestData(Builder $query): Builder
    {
        return $query->where('requisition_expenditures.is_test', false);
    }
}
