<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * §6.6 / §9 — exactly two accounts per cooperative in v1: the general fund and
 * the social fund. NG-1 defers the loan book.
 *
 * The balance is sensitive (community.coop.savings, §5.1) and is stripped from
 * responses for anyone without that permission.
 */
class CooperativeAccount extends Model
{
    use SoftDeletes;

    protected $fillable = ['cooperative_id', 'kind', 'balance_minor'];

    protected function casts(): array
    {
        return ['balance_minor' => 'integer'];
    }

    public function cooperative(): BelongsTo
    {
        return $this->belongsTo(Cooperative::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(CooperativeEntry::class)->orderByDesc('entry_date')->orderByDesc('id');
    }

    public function formattedBalance(): string
    {
        return Money::format($this->balance_minor);
    }
}
