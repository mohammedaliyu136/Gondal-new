<?php

namespace App\Models;

use App\Exceptions\RuleViolationException;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * BR-13 / REF-2 — rates are effective-dated and INSERT-ONLY in practice.
 * Changing a rate adds a row with a future effective_from; it never updates an
 * existing row, so no historical figure can move.
 */
class GradeRate extends Model
{
    protected $fillable = [
        'grade_id', 'rate_per_litre_minor', 'effective_from', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'rate_per_litre_minor' => 'integer',
        ];
    }

    /**
     * BR-13 / REF-2 — the docblock above said "insert-only in practice" and
     * practice was the only thing enforcing it.
     *
     * The one UI path that changes what a litre is worth calls
     * `GradeRate::updateOrCreate(['grade_id' => …, 'effective_from' => …], …)`.
     * On PostgreSQL — ARCH-1's database — the literal matches and the historical
     * row is UPDATED in place: what Grade A was worth on 1 April becomes
     * unrecoverable, with no second row and no record of the old value.
     * Already-confirmed consignments survive it because BR-14 snapshotted them,
     * but a consignment confirmed and not yet graded, and every later grade()
     * and regrade(), read the rewritten row.
     *
     * So the model refuses it. Changing a rate adds a row; the rate that was in
     * force on a day is a fact about that day and cannot be edited afterwards.
     * A save that touches neither the money nor the date still passes, which is
     * what keeps ReferenceDataSeeder's idempotent re-run working.
     */
    protected static function booted(): void
    {
        static::updating(function (self $rate): void {
            if (! $rate->isDirty(['rate_per_litre_minor', 'effective_from'])) {
                return;
            }

            throw RuleViolationException::make(
                'BR-13',
                sprintf(
                    'The %s rate effective %s is history and cannot be edited. Add a new effective-dated rate instead.',
                    $rate->grade?->name ?? 'grade',
                    $rate->getOriginal('effective_from') instanceof \DateTimeInterface
                        ? $rate->getOriginal('effective_from')->format('Y-m-d')
                        : (string) $rate->getOriginal('effective_from'),
                ),
                [
                    'grade_rate_id' => $rate->getKey(),
                    'existing_rate_per_litre_minor' => (int) $rate->getOriginal('rate_per_litre_minor'),
                ],
                'rate_per_litre',
            );
        });
    }

    public function grade(): BelongsTo
    {
        return $this->belongsTo(Grade::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function formattedRate(): string
    {
        return Money::format($this->rate_per_litre_minor);
    }
}
