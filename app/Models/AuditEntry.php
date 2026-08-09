<?php

namespace App\Models;

use App\Exceptions\RuleViolationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * §12 the audit log.
 *
 * DM-3 / AUDIT-1 / AUDIT-6 — APPEND-ONLY. The database refuses updates and
 * deletes outright (see the migration's triggers); these model guards exist so
 * the failure is a clear rule violation rather than a driver error, and so that
 * nobody can even write the call in application code by accident.
 */
class AuditEntry extends Model
{
    public const EVENT_PERMISSION_CHANGE = 'permission_change';

    public const EVENT_ROLE_CHANGE = 'role_change';

    public const EVENT_DATA_CREATE = 'data_create';

    public const EVENT_DATA_EDIT = 'data_edit';

    public const EVENT_DATA_DELETE = 'data_delete';

    public const EVENT_APPROVAL = 'approval';

    public const EVENT_REJECTION = 'rejection';

    public const EVENT_BLOCKED_ACCESS = 'blocked_access';

    public const EVENT_SIGNIN = 'signin';

    public const EVENT_FAILED_SIGNIN = 'failed_signin';

    public const EVENT_TEST_RUN = 'test_run';

    /** AUDIT-2 — the captured vocabulary, in the order audit-log.html filters it. */
    public const EVENTS = [
        self::EVENT_PERMISSION_CHANGE,
        self::EVENT_ROLE_CHANGE,
        self::EVENT_DATA_CREATE,
        self::EVENT_DATA_EDIT,
        self::EVENT_DATA_DELETE,
        self::EVENT_APPROVAL,
        self::EVENT_REJECTION,
        self::EVENT_BLOCKED_ACCESS,
        self::EVENT_SIGNIN,
        self::EVENT_FAILED_SIGNIN,
        self::EVENT_TEST_RUN,
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'detail' => 'array',
            'is_test' => 'boolean',
            'occurred_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // DM-3 — there is no update route and no delete route.
        static::updating(function (): never {
            throw RuleViolationException::make(
                'DM-3',
                'The audit log is append-only. Entries can never be modified.',
            );
        });

        static::deleting(function (): never {
            throw RuleViolationException::make(
                'DM-3',
                'The audit log is append-only. Entries can never be deleted.',
            );
        });
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /* ---------------------------------------------------------------------
     | Query scopes — the audit-log.html filter bar
     * ------------------------------------------------------------------ */

    public function scopeOfEvent(Builder $query, ?string $eventType): Builder
    {
        return $eventType === null || $eventType === ''
            ? $query
            : $query->where('event_type', $eventType);
    }

    public function scopeOfModule(Builder $query, ?string $module): Builder
    {
        return $module === null || $module === ''
            ? $query
            : $query->where('module', $module);
    }

    /** AUDIT-4 / TEST-4 */
    public function scopeExcludingTestData(Builder $query): Builder
    {
        return $query->where('is_test', false);
    }

    public function scopeForSubject(Builder $query, Model $subject): Builder
    {
        return $query
            ->where('subject_type', $subject::class)
            ->where('subject_id', $subject->getKey());
    }
}
