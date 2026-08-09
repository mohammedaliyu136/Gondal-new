<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * BR-19 — "Which stages apply is determined by the matching workflow_band for
 * the subject's amount." Seeded: up to ₦500,000 → User, Dept Head, Internal
 * Audit, Accounts. Above ₦500,000 → all six stages.
 */
class WorkflowBand extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'workflow_id', 'name', 'amount_from_minor', 'amount_to_minor', 'position',
    ];

    protected function casts(): array
    {
        return [
            'amount_from_minor' => 'integer',
            'amount_to_minor' => 'integer',
        ];
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    public function stages(): BelongsToMany
    {
        return $this->belongsToMany(WorkflowStage::class, 'workflow_band_stage')
            ->orderBy('workflow_stages.position');
    }

    public function describeRange(): string
    {
        if ($this->amount_to_minor === null) {
            return 'Above '.Money::format((int) $this->amount_from_minor - 1);
        }

        return 'Up to '.Money::format((int) $this->amount_to_minor);
    }
}
