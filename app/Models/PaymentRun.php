<?php

namespace App\Models;

use App\Contracts\Scopeable;
use App\Models\Concerns\AppliesDataScope;
use App\Models\Concerns\RecordsActor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * §14 Phase 7 — a batch of money owed to farmers.
 *
 * States and lifecycle mirror PayrollRun deliberately: the codebase already has
 * one shape for "a batch of money owed to many people", and Accounts staff
 * should not have to learn a second one. draft -> processing -> approved -> paid,
 * plus cancelled, with the approval riding the same WorkflowEngine.
 *
 * The PERIOD ON A RUN IS A LABEL. What stops a delivery being paid twice is the
 * UNIQUE on farmer_payment_deliveries.delivery_id, not the dates here — which is
 * why a consignment confirmed after its month closed is simply swept into the
 * next run instead of falling down a gap.
 */
class PaymentRun extends Model implements Scopeable
{
    use AppliesDataScope;
    use RecordsActor;
    use SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_PAID = 'paid';

    public const STATUS_CANCELLED = 'cancelled';

    public const SCOPE_CENTER = 'collection_center';

    public const SCOPE_COOPERATIVE = 'cooperative';

    public bool $tagsTestActivity = true;

    protected $fillable = [
        'reference', 'scope_type', 'scope_id', 'period_start', 'period_end', 'status',
        'gross_total_minor', 'deductions_total_minor', 'net_total_minor',
        'held_net_minor', 'cash_required_minor', 'farmer_count', 'held_count',
        'workflow_instance_id', 'run_by_user_id', 'approved_by_user_id',
        'approved_at', 'paid_at', 'is_test', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'approved_at' => 'datetime',
            'paid_at' => 'datetime',
            'is_test' => 'boolean',
        ];
    }

    public function payments(): HasMany
    {
        return $this->hasMany(FarmerPayment::class);
    }

    public function runBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'run_by_user_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function workflowInstance(): BelongsTo
    {
        return $this->belongsTo(WorkflowInstance::class);
    }

    public function workflowInstances(): MorphMany
    {
        return $this->morphMany(WorkflowInstance::class, 'subject');
    }

    /* ---------------------------------------------------------------------
     | Scope — SCOPE-2
     * ------------------------------------------------------------------ */

    public function scopeResourceKey(): string
    {
        return 'finance.farmer_payments';
    }

    /**
     * A run is scoped by the thing it was generated for.
     *
     * `own` is deliberately absent: a payment run is nobody's personal record,
     * and an `own`-scoped holder should see nothing rather than the runs they
     * happened to press the button on.
     *
     * @return array<string, callable>
     */
    public function scopeConstraints(): array
    {
        return [
            'center' => fn (Builder $query, array $ids) => $query
                ->where('scope_type', self::SCOPE_CENTER)
                ->whereIn('scope_id', $ids),
            'network' => fn (Builder $query) => $query,
        ];
    }

    /* ---------------------------------------------------------------------
     | State
     * ------------------------------------------------------------------ */

    public function isEditable(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_PROCESSING], true);
    }

    public function isApproved(): bool
    {
        return in_array($this->status, [self::STATUS_APPROVED, self::STATUS_PAID], true);
    }

    /** Money that may actually be handed over — net less anything BR-36 holds. */
    public function payableNowMinor(): int
    {
        return (int) $this->cash_required_minor;
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_DRAFT, self::STATUS_PROCESSING, self::STATUS_APPROVED]);
    }
}
