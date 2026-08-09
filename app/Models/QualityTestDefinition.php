<?php

namespace App\Models;

use App\Models\Concerns\RecordsActor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * BR-4 — "Grade is assigned ... only after all configured quality tests are
 * recorded." The quality-test thresholds shown on settings.html are these rows,
 * so "configured" genuinely means configured, not compiled in.
 */
class QualityTestDefinition extends Model
{
    use RecordsActor;
    use SoftDeletes;

    public const KIND_RANGE = 'range';

    public const KIND_MAXIMUM = 'maximum';

    public const KIND_MINIMUM = 'minimum';

    public const KIND_BOOLEAN = 'boolean';

    protected $fillable = [
        'code', 'name', 'kind', 'min_value', 'max_value', 'unit',
        'expected_boolean_label', 'is_required', 'status', 'position', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
            'min_value' => 'decimal:4',
            'max_value' => 'decimal:4',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    /** BR-4 — the tests that must be present before a grade may be assigned. */
    public function scopeRequired(Builder $query): Builder
    {
        return $query->active()->where('is_required', true);
    }

    /** The "Acceptable range" text snapshotted onto each recorded test. */
    public function describeRange(): string
    {
        return match ($this->kind) {
            self::KIND_RANGE => trim(sprintf('%s–%s %s', $this->trim($this->min_value), $this->trim($this->max_value), (string) $this->unit)),
            self::KIND_MAXIMUM => trim(sprintf('max %s %s', $this->trim($this->max_value), (string) $this->unit)),
            self::KIND_MINIMUM => trim(sprintf('min %s %s', $this->trim($this->min_value), (string) $this->unit)),
            self::KIND_BOOLEAN => (string) ($this->expected_boolean_label ?? 'pass'),
            default => '',
        };
    }

    /**
     * Whether a recorded reading satisfies this definition. Boolean tests take
     * "1"/"0"; numeric tests are compared as decimal strings.
     */
    public function accepts(?string $reading): bool
    {
        if ($reading === null || $reading === '') {
            return false;
        }

        if ($this->kind === self::KIND_BOOLEAN) {
            return in_array(strtolower($reading), ['1', 'true', 'yes', 'pass', 'negative'], true);
        }

        $value = (float) $reading;

        return match ($this->kind) {
            self::KIND_RANGE => $this->min_value !== null && $this->max_value !== null
                && $value >= (float) $this->min_value && $value <= (float) $this->max_value,
            self::KIND_MAXIMUM => $this->max_value !== null && $value <= (float) $this->max_value,
            self::KIND_MINIMUM => $this->min_value !== null && $value >= (float) $this->min_value,
            default => false,
        };
    }

    private function trim(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        return rtrim(rtrim($value, '0'), '.') ?: '0';
    }
}
