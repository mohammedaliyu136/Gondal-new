<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * §6.8 payroll runs.
 *
 * G-6 — the whole record is sensitive; hr.payroll.view gates it.
 * BR-35 / TEST-1 — test accounts are excluded when the run is GENERATED, not
 *   filtered out of the report afterwards, so a test employee can never appear
 *   in a total that was already computed.
 */
class PayrollRun extends Model
{
    use SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_PAID = 'paid';

    protected $fillable = [
        'period_month', 'period_year', 'workflow_instance_id', 'status',
        'run_by_user_id', 'gross_total_minor', 'deductions_total_minor',
        'net_total_minor', 'employee_count', 'approved_at', 'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'period_month' => 'integer',
            'period_year' => 'integer',
            'gross_total_minor' => 'integer',
            'deductions_total_minor' => 'integer',
            'net_total_minor' => 'integer',
            'employee_count' => 'integer',
            'approved_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class);
    }

    public function runBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'run_by_user_id');
    }

    public function workflowInstance(): BelongsTo
    {
        return $this->belongsTo(WorkflowInstance::class);
    }

    public function workflowInstances(): MorphMany
    {
        return $this->morphMany(WorkflowInstance::class, 'subject')->latest('id');
    }

    public function periodLabel(): string
    {
        return Carbon::create($this->period_year, $this->period_month, 1)
            ->format('F Y');
    }

    public function scopeForPeriod(Builder $query, int $year, int $month): Builder
    {
        return $query->where('period_year', $year)->where('period_month', $month);
    }

    /**
     * Runs whose payslips are real money for year-to-date purposes.
     *
     * A rejection returns the run to `draft` (PayrollService::syncFromWorkflow),
     * which makes it indistinguishable by status from a run that has simply not
     * been submitted yet — the workflow instance is the only thing that tells
     * them apart. Counting a rejected run would have the next month's payslip
     * report pay the employee never received.
     */
    public function scopeCountsTowardYearToDate(Builder $query): Builder
    {
        return $query->whereDoesntHave(
            'workflowInstance',
            fn (Builder $instance) => $instance->where('status', WorkflowInstance::STATUS_REJECTED),
        );
    }
}
