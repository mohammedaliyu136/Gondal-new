<?php

namespace App\Services\Audit;

use App\Authorization\PermissionKey;
use App\Models\AuditEntry;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\Sequences;
use App\Support\Wat;
use Illuminate\Database\Eloquent\Model;

/**
 * §12 — the one place audit entries are written.
 *
 * G-7  every permission change, data change and blocked access attempt, logged
 *      immutably.
 * DM-3 append-only: this class only ever inserts.
 * AUDIT-3 permission and role changes record the number of affected users and
 *      the before/after grant sets.
 * AUDIT-5 blocked access records the missing permission key and a quotable
 *      reference.
 */
class AuditLogger
{
    public function __construct(private readonly AuditContext $context) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function write(array $attributes): AuditEntry
    {
        $actor = $attributes['actor'] ?? auth()->user();
        unset($attributes['actor']);

        return AuditEntry::query()->create(array_merge([
            'actor_user_id' => $actor instanceof User ? $actor->getKey() : null,
            'actor_label' => $actor instanceof User ? $actor->name : 'System',
            'actor_role_label' => $actor instanceof User ? $actor->primaryRoleLabel() : null,
            'source' => $this->context->source(),
            'ip' => $this->context->ip(),
            'request_id' => $this->context->requestId(),
            'is_test' => $this->context->isTest($actor instanceof User ? $actor : null),
            'occurred_at' => Wat::now(),
        ], $attributes));
    }

    /* ---------------------------------------------------------------------
     | AUDIT-2 — data changes
     * ------------------------------------------------------------------ */

    /**
     * @param  array<string, mixed>  $detail
     */
    public function created(Model $subject, string $summary, string $module, array $detail = [], ?User $actor = null): AuditEntry
    {
        return $this->write([
            'actor' => $actor,
            'event_type' => AuditEntry::EVENT_DATA_CREATE,
            'module' => $module,
            'subject_type' => $subject::class,
            'subject_id' => $subject->getKey(),
            'summary' => $summary,
            'detail' => $detail,
        ]);
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function edited(Model $subject, string $summary, string $module, array $before = [], array $after = [], ?User $actor = null): AuditEntry
    {
        return $this->write([
            'actor' => $actor,
            'event_type' => AuditEntry::EVENT_DATA_EDIT,
            'module' => $module,
            'subject_type' => $subject::class,
            'subject_id' => $subject->getKey(),
            'summary' => $summary,
            'detail' => ['before' => $before, 'after' => $after],
        ]);
    }

    /**
     * ARCH-8 — nothing operational is ever hard-deleted, so this records a soft
     * delete or a retirement.
     *
     * @param  array<string, mixed>  $detail
     */
    public function deleted(Model $subject, string $summary, string $module, array $detail = [], ?User $actor = null): AuditEntry
    {
        return $this->write([
            'actor' => $actor,
            'event_type' => AuditEntry::EVENT_DATA_DELETE,
            'module' => $module,
            'subject_type' => $subject::class,
            'subject_id' => $subject->getKey(),
            'summary' => $summary,
            'detail' => $detail,
        ]);
    }

    /* ---------------------------------------------------------------------
     | AUDIT-3 — permission and role changes
     * ------------------------------------------------------------------ */

    /**
     * AUDIT-3 — "Permission and role changes record the number of affected users
     * and the before/after grant sets."
     *
     * @param  array<int, string>  $before  permission keys granted before
     * @param  array<int, string>  $after  permission keys granted after
     */
    public function rolePermissionsChanged(Role $role, array $before, array $after, ?User $actor = null, ?string $overrideReason = null): AuditEntry
    {
        $granted = array_values(array_diff($after, $before));
        $revoked = array_values(array_diff($before, $after));
        $affectedUsers = $role->users()->count();

        $sensitiveGranted = $granted === [] ? [] : Permission::query()
            ->sensitive()
            ->get()
            ->filter(fn (Permission $permission) => in_array($permission->key_string, $granted, true))
            ->map(fn (Permission $permission) => $permission->key_string)
            ->values()
            ->all();

        return $this->write([
            'actor' => $actor,
            'event_type' => AuditEntry::EVENT_PERMISSION_CHANGE,
            'module' => 'Administration',
            'subject_type' => Role::class,
            'subject_id' => $role->getKey(),
            'summary' => sprintf(
                '%s: %d granted, %d revoked · %d user(s) affected',
                $role->name,
                count($granted),
                count($revoked),
                $affectedUsers,
            ),
            'detail' => [
                'role' => $role->name,
                'affected_users' => $affectedUsers,
                'before' => array_values($before),
                'after' => array_values($after),
                'granted' => $granted,
                'revoked' => $revoked,
                // PERM-2 — granting a sensitive permission is called out explicitly.
                'sensitive_granted' => $sensitiveGranted,
                // TEST-5 — the override, when an administrator saves without a
                // passing test run, is logged rather than silently allowed.
                'test_run_override_reason' => $overrideReason,
            ],
        ]);
    }

    /**
     * AUDIT-2 — role assignment and removal, and role status changes.
     *
     * @param  array<string, mixed>  $detail
     */
    public function roleChanged(Role $role, string $summary, array $detail = [], ?User $actor = null): AuditEntry
    {
        return $this->write([
            'actor' => $actor,
            'event_type' => AuditEntry::EVENT_ROLE_CHANGE,
            'module' => 'Administration',
            'subject_type' => Role::class,
            'subject_id' => $role->getKey(),
            'summary' => $summary,
            'detail' => array_merge(['affected_users' => $role->users()->count()], $detail),
        ]);
    }

    /* ---------------------------------------------------------------------
     | AUDIT-5 / BR-34 — blocked access
     * ------------------------------------------------------------------ */

    /**
     * BR-34 — "Every blocked access attempt writes an audit_entries row with the
     * missing permission."
     *
     * SCOPE-3 — a scope failure is logged identically to a missing permission;
     * only `deny_reason` distinguishes them.
     *
     * @param  array<string, mixed>  $detail
     * @return array{0: AuditEntry, 1: string} the entry and its quotable reference
     */
    public function blockedAccess(
        ?User $actor,
        ?string $permissionKey,
        string $reason,
        ?string $route,
        ?string $attemptedLabel = null,
        array $detail = [],
    ): array {
        // The DENY prefix is a row in `sequences` so an administrator can change
        // it, but it is created on demand here because a denial must never fail
        // for want of reference data.
        $reference = Sequences::next('denials', [
            'label' => 'Blocked access reference',
            'prefix' => 'DENY',
            'digits' => 4,
            'reference_format' => '{prefix}-{number}',
        ]);

        $entry = $this->write([
            'actor' => $actor,
            'event_type' => AuditEntry::EVENT_BLOCKED_ACCESS,
            'module' => $permissionKey === null
                ? 'System'
                : (PermissionKey::tryParse($permissionKey)?->module() ?? 'System'),
            'summary' => $reason === 'scope'
                ? sprintf('Blocked: %s is outside %s\'s data scope', $attemptedLabel ?? ($permissionKey ?? 'record'), $actor?->name ?? 'the user')
                : sprintf('Blocked: %s lacks %s', $actor?->name ?? 'Anonymous', $permissionKey ?? 'the required permission'),
            'reference' => $reference,
            'missing_permission' => $permissionKey,
            'attempted_route' => $route,
            'deny_reason' => $reason,
            'detail' => array_merge([
                'attempted_label' => $attemptedLabel,
                'user_scope' => $actor?->overallScopeDescription(),
                'user_roles' => $actor?->effectiveRoles()->pluck('name')->values()->all(),
            ], $detail),
        ]);

        return [$entry, $reference];
    }

    /* ---------------------------------------------------------------------
     | AUDIT-2 — approvals, sign-ins, settings, test runs
     * ------------------------------------------------------------------ */

    /**
     * @param  array<string, mixed>  $detail
     */
    public function approval(Model $subject, string $summary, array $detail = [], ?User $actor = null): AuditEntry
    {
        return $this->write([
            'actor' => $actor,
            'event_type' => AuditEntry::EVENT_APPROVAL,
            'module' => 'Purchases',
            'subject_type' => $subject::class,
            'subject_id' => $subject->getKey(),
            'summary' => $summary,
            'detail' => $detail,
        ]);
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    public function rejection(Model $subject, string $summary, array $detail = [], ?User $actor = null): AuditEntry
    {
        return $this->write([
            'actor' => $actor,
            'event_type' => AuditEntry::EVENT_REJECTION,
            'module' => 'Purchases',
            'subject_type' => $subject::class,
            'subject_id' => $subject->getKey(),
            'summary' => $summary,
            'detail' => $detail,
        ]);
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    public function signin(User $actor, string $summary, array $detail = []): AuditEntry
    {
        return $this->write([
            'actor' => $actor,
            'event_type' => AuditEntry::EVENT_SIGNIN,
            'module' => 'Account',
            'subject_type' => User::class,
            'subject_id' => $actor->getKey(),
            'summary' => $summary,
            'detail' => $detail,
        ]);
    }

    /**
     * AUTH-6 — failed sign-ins are logged.
     *
     * @param  array<string, mixed>  $detail
     */
    public function failedSignin(string $email, string $reason, ?User $actor = null, array $detail = []): AuditEntry
    {
        return $this->write([
            'actor' => $actor,
            'actor_label' => $actor?->name ?? $email,
            'event_type' => AuditEntry::EVENT_FAILED_SIGNIN,
            'module' => 'Account',
            'subject_type' => $actor === null ? null : User::class,
            'subject_id' => $actor?->getKey(),
            'summary' => sprintf('Failed sign-in for %s (%s)', $email, $reason),
            'detail' => array_merge(['email' => $email, 'reason' => $reason], $detail),
        ]);
    }

    /**
     * REF-1 — "Changing reference data is audited with before and after values."
     *
     * @param  array<int, string>  $keys
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function settingsChanged(array $keys, array $before, array $after, ?User $actor = null): AuditEntry
    {
        return $this->write([
            'actor' => $actor,
            'event_type' => AuditEntry::EVENT_DATA_EDIT,
            'module' => 'Settings',
            'summary' => count($keys) === 1
                ? 'Setting changed: '.$keys[0]
                : count($keys).' settings changed',
            'detail' => [
                'keys' => $keys,
                'before' => array_intersect_key($before, array_flip($keys)),
                'after' => array_intersect_key($after, array_flip($keys)),
            ],
        ]);
    }

    /**
     * TEST-2 / TEST-4 — the test run itself is an audited event.
     *
     * @param  array<string, mixed>  $detail
     */
    public function testRun(Model $subject, string $summary, array $detail = [], ?User $actor = null): AuditEntry
    {
        return $this->write([
            'actor' => $actor,
            'event_type' => AuditEntry::EVENT_TEST_RUN,
            'module' => 'Administration',
            'subject_type' => $subject::class,
            'subject_id' => $subject->getKey(),
            'summary' => $summary,
            'detail' => $detail,
        ]);
    }
}
