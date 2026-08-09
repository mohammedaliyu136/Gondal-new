<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * §6.2 quality_tests — one recorded reading against one configured definition.
 *
 * BR-4 — grading is blocked until every REQUIRED definition has a row here.
 * The definition's name and range are snapshotted so retiring a definition never
 * rewrites a historical result.
 */
class QualityTest extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'consignment_id', 'quality_test_definition_id', 'test_type', 'reading',
        'acceptable_range', 'passed', 'recorded_by_user_id', 'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'passed' => 'boolean',
            'recorded_at' => 'datetime',
        ];
    }

    public function consignment(): BelongsTo
    {
        return $this->belongsTo(Consignment::class);
    }

    public function definition(): BelongsTo
    {
        return $this->belongsTo(QualityTestDefinition::class, 'quality_test_definition_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }
}
