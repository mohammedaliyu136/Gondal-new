<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PaymentBatchItem extends Model
{
    use SoftDeletes;

    public const STATUS_PENDING = 'pending';
    public const STATUS_INITIALIZED = 'initialized';
    public const STATUS_SUCCESSFUL = 'successful';
    public const STATUS_FAILED = 'failed';
    public const STATUS_REVERSED = 'reversed';

    protected $fillable = [
        'payment_batch_id',
        'item_reference',
        'recipient_type',
        'recipient_id',
        'recipient_name',
        'recipient_email',
        'recipient_phone',
        'recipient_bank_code',
        'recipient_bank_name',
        'recipient_account_number',
        'amount_minor',
        'fee_minor',
        'narration',
        'status',
        'gateway_reference',
        'gateway_status',
        'gateway_transfer_code',
        'gateway_response',
        'failure_reason',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'fee_minor' => 'integer',
            'gateway_response' => 'array',
            'paid_at' => 'datetime',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(PaymentBatch::class, 'payment_batch_id');
    }

    public function recipient(): MorphTo
    {
        return $this->morphTo();
    }

    public function markSuccessful(?string $gatewayRef = null, ?int $feeMinor = null, ?array $response = null): void
    {
        $this->forceFill([
            'status' => self::STATUS_SUCCESSFUL,
            'gateway_reference' => $gatewayRef ?? $this->gateway_reference,
            'fee_minor' => $feeMinor ?? $this->fee_minor,
            'gateway_response' => $response ?? $this->gateway_response,
            'paid_at' => now(),
            'failure_reason' => null,
        ])->save();

        $this->batch->recalculateCounts();
    }

    public function markFailed(string $reason, ?array $response = null): void
    {
        $this->forceFill([
            'status' => self::STATUS_FAILED,
            'failure_reason' => $reason,
            'gateway_response' => $response ?? $this->gateway_response,
        ])->save();

        $this->batch->recalculateCounts();
    }
}
