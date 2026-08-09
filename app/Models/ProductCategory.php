<?php

namespace App\Models;

use App\Models\Concerns\RecordsActor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * G-5 / BR-25 — "Product categories are created and retired by users holding
 * shop.categories.create. Retiring a category hides it from new sales but
 * preserves all history. Categories are never deleted."
 *
 * The behaviour flags are columns, not code: a category that requires a
 * prescription (BR-27) or manager approval is configured, never compiled in.
 */
class ProductCategory extends Model
{
    use RecordsActor;
    use SoftDeletes;

    protected $fillable = [
        'code', 'name', 'description', 'default_unit', 'default_reorder_level',
        'requires_prescription', 'track_expiry', 'allow_credit',
        'requires_manager_approval', 'status', 'position', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'requires_prescription' => 'boolean',
            'track_expiry' => 'boolean',
            'allow_credit' => 'boolean',
            'requires_manager_approval' => 'boolean',
            'default_reorder_level' => 'integer',
            'retired_at' => 'datetime',
        ];
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function isRetired(): bool
    {
        return $this->status === 'retired';
    }

    /** BR-25 — retire, never delete. History stays intact. */
    public function retire(): void
    {
        $this->forceFill(['status' => 'retired', 'retired_at' => now()])->save();
    }

    public function reinstate(): void
    {
        $this->forceFill(['status' => 'active', 'retired_at' => null])->save();
    }

    /** BR-25 — "immediately sellable" once created. */
    public function scopeSellable(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }
}
