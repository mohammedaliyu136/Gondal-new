<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TEST-2 — one expected-versus-actual access check.
 *
 * SCOPE-3 — a permission granted at the wrong scope and a permission not granted
 * at all both surface as `deny`, distinguished by `actual_reason`. The screen
 * shows "Blocked (out of scope)" versus "Blocked" from this column.
 */
class PermissionTestCheck extends Model
{
    public const EXPECT_ALLOW = 'allow';

    public const EXPECT_DENY = 'deny';

    protected $fillable = [
        'permission_test_run_id', 'module', 'area', 'permission_key', 'route',
        'scope_target_id', 'is_scope_probe', 'expected', 'actual',
        'actual_reason', 'passed', 'note', 'position',
    ];

    protected function casts(): array
    {
        return [
            'is_scope_probe' => 'boolean',
            'passed' => 'boolean',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(PermissionTestRun::class, 'permission_test_run_id');
    }

    /** permission-test.html "Actual" column wording. */
    public function describeActual(): string
    {
        if ($this->actual === null) {
            return 'Not run';
        }

        if ($this->actual === self::EXPECT_ALLOW) {
            return 'Allowed';
        }

        return $this->actual_reason === 'scope' ? 'Blocked (out of scope)' : 'Blocked';
    }

    public function describeExpected(): string
    {
        if ($this->expected === self::EXPECT_ALLOW) {
            return 'Allowed';
        }

        return $this->is_scope_probe ? 'Blocked (out of scope)' : 'Blocked';
    }
}
