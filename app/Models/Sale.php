<?php

namespace App\Models;

use App\Authorization\Scopes\DataScope;
use App\Authorization\ScopeType;
use App\Contracts\Scopeable;
use App\Models\Concerns\AppliesDataScope;
use App\Models\Concerns\RecordsActor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * §6.7 sales.
 *
 * BR-29 — "Users holding shop.sales but not shop.revenue see their own
 * transactions and no aggregate revenue, margin or stock-value figure — in API
 * responses as well as UI." The `own` scope constraint below is how the list
 * narrows; the resource layer strips the money aggregates.
 */
class Sale extends Model implements Scopeable
{
    use AppliesDataScope;
    use RecordsActor;
    use SoftDeletes;

    public const CUSTOMER_FARMER = 'farmer';

    public const CUSTOMER_COOPERATIVE = 'cooperative';

    public const CUSTOMER_WALKIN = 'walkin';

    public const CUSTOMER_INTERNAL = 'internal';

    public const PAYMENT_CASH = 'cash';

    public const PAYMENT_TRANSFER = 'transfer';

    public const PAYMENT_CREDIT = 'credit';

    public const PAYMENT_MILK_DEDUCTION = 'milk_deduction';

    /**
     * ARCH-2 — "the API enforces the same rules as the web UI."
     *
     * The web controller constrained both columns with an `in:` rule and the
     * mobile sync path did not, so the phone's own literal — 'walk_in', which
     * nothing else in the system recognises — persisted into a bare string(16).
     * The sale then never appeared under the Walk-in filter and was miscounted in
     * every breakdown by customer type, while `customerLabel()` fell through to
     * its default branch and rendered "Walk-in" anyway, so nothing on screen ever
     * revealed it.
     *
     * One list, read by both callers and by SaleService's own guard, is what stops
     * the two drifting again. These are not §9 reference data: they are the shape
     * of the sale record itself (§6.7), which is why they are constants and grades
     * are rows.
     *
     * @var array<int, string>
     */
    public const CUSTOMER_TYPES = [
        self::CUSTOMER_FARMER,
        self::CUSTOMER_COOPERATIVE,
        self::CUSTOMER_WALKIN,
        self::CUSTOMER_INTERNAL,
    ];

    /** @var array<int, string> */
    public const PAYMENT_METHODS = [
        self::PAYMENT_CASH,
        self::PAYMENT_TRANSFER,
        self::PAYMENT_CREDIT,
        self::PAYMENT_MILK_DEDUCTION,
    ];

    public bool $tagsTestActivity = true;

    protected $fillable = [
        'receipt_no', 'customer_type', 'farmer_id', 'cooperative_id', 'customer_name',
        'payment_method', 'total_minor', 'amount_received_minor',
        'prescription_reference', 'sales_officer_user_id', 'notes', 'sold_at',
        'is_test', 'created_by_user_id', 'voided_at', 'voided_by_user_id', 'void_reason',
    ];

    protected function casts(): array
    {
        return [
            'voided_at' => 'datetime',
            'sold_at' => 'datetime',
            'total_minor' => 'integer',
            'amount_received_minor' => 'integer',
            'is_test' => 'boolean',
        ];
    }

    public function scopeResourceKey(): string
    {
        return 'shop.sales';
    }

    public function scopeConstraints(): array
    {
        return [
            ScopeType::Own->value => fn (Builder $q, array $ids) => $q->whereIn('sales.sales_officer_user_id', $ids),
            ScopeType::Communities->value => fn (Builder $q, array $ids) => $q->whereHas(
                'farmer',
                fn (Builder $inner) => $inner->whereIn('farmers.community_id', $ids),
            ),
            ScopeType::Lga->value => fn (Builder $q, array $ids) => $q->whereHas(
                'farmer',
                fn (Builder $inner) => $inner->whereIn('farmers.lga_id', $ids),
            ),
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    /**
     * Deliberately unscoped. A sale the viewer is entitled to see must carry the
     * identity of its farmer, whatever the viewer's own FARMER scope says — that
     * scope governs browsing the farmer register, not knowing who this record
     * belongs to. Left scoped, the relation resolved to null whenever the
     * farmer's default point fell outside the viewer's assignment: names went
     * blank on the day sheet, and the detail page crashed building a link from a
     * null farmer. Opening the farmer's own record is still guarded by the
     * farmers.show route and policy.
     */
    public function farmer(): BelongsTo
    {
        return $this->belongsTo(Farmer::class)
            ->withoutGlobalScope(DataScope::class);
    }

    public function cooperative(): BelongsTo
    {
        return $this->belongsTo(Cooperative::class);
    }

    public function salesOfficer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sales_officer_user_id');
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function pendingDeduction(): HasMany
    {
        return $this->hasMany(PendingFarmerDeduction::class);
    }

    public function customerLabel(): string
    {
        return match ($this->customer_type) {
            self::CUSTOMER_FARMER => $this->farmer?->name ?? 'Farmer',
            self::CUSTOMER_COOPERATIVE => $this->cooperative?->name ?? 'Cooperative',
            self::CUSTOMER_INTERNAL => $this->customer_name ?? 'Internal',
            default => $this->customer_name ?? 'Walk-in',
        };
    }

    /** BR-29 — margin is a shop.revenue figure, computed from snapshots. */
    public function marginMinor(): int
    {
        return $this->items->sum(
            fn (SaleItem $item) => (int) $item->amount_minor
                - (int) round((float) $item->quantity * (int) ($item->unit_cost_minor_snapshot ?? 0)),
        );
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by_user_id');
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    /**
     * Sales that still count.
     *
     * Every revenue, margin and credit figure must use this. A voided sale keeps
     * its row — the customer holds the receipt — but it is not income.
     */
    public function scopeNotVoided(Builder $query): Builder
    {
        return $query->whereNull('voided_at');
    }

    public function scopeOnDate(Builder $query, string $date): Builder
    {
        return $query->whereDate('sold_at', $date);
    }
}
