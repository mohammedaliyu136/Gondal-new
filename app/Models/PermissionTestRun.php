<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * §5.4 — a permission test run.
 *
 * TEST-2 — records role under test, test user, simulated scope, environment and
 *   the expected-versus-actual checks.
 * TEST-3 — PRODUCTION IS NOT OFFERABLE. The allowed environments come from
 *   config('gondal.permission_test_environments'); see the form request.
 */
class PermissionTestRun extends Model
{
    use SoftDeletes;

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_PASSED = 'passed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_APPROVED_FOR_LIVE = 'approved_for_live';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_ABANDONED = 'abandoned';

    protected $fillable = [
        'reference', 'role_id', 'test_user_id', 'run_by_user_id',
        'scope_type', 'scope_target_id', 'scope_target_ids', 'environment', 'signin_result',
        'status', 'passed_count', 'failed_count', 'notes',
        'started_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'scope_target_ids' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'passed_count' => 'integer',
            'failed_count' => 'integer',
        ];
    }

    /**
     * SCOPE-1 — every target this run simulates, however it was recorded.
     *
     * @return array<int, int>
     */
    public function simulatedTargetIds(): array
    {
        $ids = array_map('intval', $this->scope_target_ids ?? []);

        if ($this->scope_target_id !== null) {
            $ids[] = (int) $this->scope_target_id;
        }

        $ids = array_values(array_unique(array_filter($ids)));
        sort($ids);

        return $ids;
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function testUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'test_user_id');
    }

    public function runBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'run_by_user_id');
    }

    public function checks(): HasMany
    {
        return $this->hasMany(PermissionTestCheck::class)->orderBy('position');
    }

    /** TEST-5 — only a run with zero failures may bless a role change. */
    public function hasPassed(): bool
    {
        return $this->failed_count === 0
            && $this->passed_count > 0
            && $this->completed_at !== null;
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->whereNotNull('completed_at');
    }
}
