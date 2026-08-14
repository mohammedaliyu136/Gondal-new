<?php

namespace App\Models;

use App\Models\Concerns\RecordsActor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Money actually handed to a rider, with who handed it over and when. */
class TransportPaymentDisbursement extends Model
{
    use RecordsActor;
    use SoftDeletes;

    public bool $tagsTestActivity = true;

    protected $fillable = [
        'transport_payment_id', 'amount_minor', 'method', 'external_reference',
        'paid_by_user_id', 'received_by', 'disbursed_at', 'is_test', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'disbursed_at' => 'datetime',
            'is_test' => 'boolean',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(TransportPayment::class, 'transport_payment_id');
    }

    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by_user_id');
    }

    public function scopeExcludingTestData(Builder $query): Builder
    {
        return $query->where('transport_payment_disbursements.is_test', false);
    }
}
