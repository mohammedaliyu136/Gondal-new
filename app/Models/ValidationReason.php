<?php

namespace App\Models;

use App\Models\Concerns\RecordsActor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * §9 — why a farmer was put on the revalidation list. Reference data, so the
 * list of reasons is M&E's to extend, not a developer's.
 */
class ValidationReason extends Model
{
    use RecordsActor;
    use SoftDeletes;

    protected $fillable = [
        'code', 'name', 'help_text', 'is_automatic', 'status', 'position', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return ['is_automatic' => 'boolean'];
    }

    public function validations(): HasMany
    {
        return $this->hasMany(FarmerValidation::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }
}
