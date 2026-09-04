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
 * §14 Phase 7 — a batch of money owed to riders and drivers.
 *
 * Deliberately the same shape as PaymentRun and PayrollRun: same states, same
 * workflow, same claim-table guarantee. What stops a trip being paid twice is
 * the UNIQUE on transport_payment_trips.trip_id, not the period on this row —
 * so a trip logged three days late is swept into the next run rather than
 * falling down a gap between two sheets.
 */
class TransportPaymentRun extends Model implements Scopeable
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

    /** Everything unclaimed, including trips whose centre was never recorded. */
    public const SCOPE_NETWORK = 'network';

    public const SCOPE_DRIVER = 'driver';

    public const SCOPE_RIDER = 'rider';

    public const SCOPE_INDIVIDUAL = 'individual';

    public bool $tagsTestActivity = true;

    protected $fillable = [
        'reference', 'scope_type', 'scope_id', 'period_start', 'period_end', 'status',
        'total_minor', 'trip_count', 'driver_count',
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
        return $this->hasMany(TransportPayment::class);
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
        return 'logistics.payments';
    }

    /**
     * A centre-scoped holder sees their centre's runs. A NETWORK run is visible
     * only to a network-scoped holder: it spans every centre by construction, so
     * showing it to one centre would show them somebody else's riders.
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

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_DRAFT, self::STATUS_PROCESSING, self::STATUS_APPROVED]);
    }

    public function scopeExcludingTestData(Builder $query): Builder
    {
        return $query->where('transport_payment_runs.is_test', false);
    }
}
