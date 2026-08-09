<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * BR-15 — a cooperative's deduction percentages as at a date.
 *
 * Modelled on GradeRate deliberately: effective-dated and INSERT-ONLY in
 * practice. Changing a percentage adds a row; it never edits one, so no payable
 * already calculated can move underneath the member it was calculated for.
 */
class CooperativeRate extends Model
{
    protected $fillable = [
        'cooperative_id', 'savings_deduction_pct', 'levy_pct',
        'social_contribution_minor', 'effective_from', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'savings_deduction_pct' => 'decimal:2',
            'levy_pct' => 'decimal:2',
            'social_contribution_minor' => 'integer',
        ];
    }

    public function cooperative(): BelongsTo
    {
        return $this->belongsTo(Cooperative::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
