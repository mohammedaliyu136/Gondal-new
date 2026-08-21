<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PaymentBatch extends Model
{
    use SoftDeletes;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_INITIALIZED = 'initialized';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_PENDING_OTP = 'pending_otp';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_PARTIALLY_COMPLETED = 'partially_completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'batch_reference',
        'source_module',
        'source_type',
        'source_id',
        'gateway',
        'currency',
        'total_amount_minor',
        'total_fee_minor',
        'total_items_count',
        'successful_items_count',
        'failed_items_count',
        'status',
        'gateway_batch_reference',
        'gateway_status',
        'notes',
        'meta',
        'initiated_by_user_id',
        'authorized_by_user_id',
        'disbursed_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'total_amount_minor' => 'integer',
            'total_fee_minor' => 'integer',
            'total_items_count' => 'integer',
            'successful_items_count' => 'integer',
            'failed_items_count' => 'integer',
            'meta' => 'array',
            'disbursed_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function items(): HasMany
    {
        return $this->hasMany(PaymentBatchItem::class, 'payment_batch_id');
    }

    public function initiatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by_user_id');
    }

    public function authorizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authorized_by_user_id');
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isProcessing(): bool
    {
        return in_array($this->status, [self::STATUS_INITIALIZED, self::STATUS_PROCESSING], true);
    }

    public function recalculateCounts(): void
    {
        $successful = $this->items()->where('status', PaymentBatchItem::STATUS_SUCCESSFUL)->count();
        $failed = $this->items()->where('status', PaymentBatchItem::STATUS_FAILED)->count();
        $total = $this->items()->count();
        $fees = (int) $this->items()->sum('fee_minor');

        $status = $this->status;
        if ($total > 0) {
            if ($successful === $total) {
                $status = self::STATUS_COMPLETED;
            } elseif ($failed === $total) {
                $status = self::STATUS_FAILED;
            } elseif ($successful > 0 && ($successful + $failed === $total)) {
                $status = self::STATUS_PARTIALLY_COMPLETED;
            }
        }

        $this->forceFill([
            'total_items_count' => $total,
            'successful_items_count' => $successful,
            'failed_items_count' => $failed,
            'total_fee_minor' => $fees,
            'status' => $status,
            'completed_at' => in_array($status, [self::STATUS_COMPLETED, self::STATUS_PARTIALLY_COMPLETED, self::STATUS_FAILED], true) ? ($this->completed_at ?? now()) : null,
        ])->save();
    }

    public function scopeForModule(Builder $query, string $module): Builder
    {
        return $query->where('source_module', $module);
    }
}
