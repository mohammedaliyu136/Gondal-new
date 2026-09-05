<?php

namespace App\Models;

use App\Authorization\Scope;
use App\Authorization\ScopeSet;
use App\Authorization\ScopeType;
use App\Models\Concerns\RecordsActor;
use App\Support\Wat;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * §6.1 users. The account of a member of STAFF.
 *
 * USER-1 — farmers, cooperative officials, riders, drivers and vendors are not
 * here and never will be in v1.
 *
 * This class owns the permission and scope resolution the whole system depends
 * on (§5). Two rules shape it:
 *
 *   ROLE-2  effective permissions are the UNION of the user's roles. There is
 *           no deny rule; absence of a grant is the denial.
 *   ROLE-6  a role edit takes effect on the user's NEXT REQUEST. Resolution is
 *           therefore memoised per instance only — never cached across requests.
 */
class User extends Authenticatable
{
    use Notifiable;
    use RecordsActor;
    use SoftDeletes;

    protected $fillable = [
        'name', 'email', 'telegram_chat_id', 'telegram_username', 'telegram_onboarding_token',
        'phone', 'department_id', 'position', 'employee_id',
        'status', 'is_test', 'two_factor_enabled', 'created_by_user_id',
    ];

    protected $hidden = ['password_hash', 'remember_token'];

    protected function casts(): array
    {
        return [
            'is_test' => 'boolean',
            'two_factor_enabled' => 'boolean',
            'password_changed_at' => 'datetime',
            'password_reset_at' => 'datetime',
            'password_is_temporary' => 'boolean',
            'email_changed_at' => 'datetime',
            'locked_until' => 'datetime',
            'last_signed_in_at' => 'datetime',
            'deactivated_at' => 'datetime',
        ];
    }

    /**
     * The schema names the column password_hash (§6.1) while the framework's
     * guard asks for getAuthPassword(), so the two are bridged here rather than
     * renaming a column the PRD specifies.
     */
    public function getAuthPassword(): string
    {
        return (string) $this->password_hash;
    }

    public function getAuthPasswordName(): string
    {
        return 'password_hash';
    }

    /* ---------------------------------------------------------------------
     | Relationships
     * ------------------------------------------------------------------ */

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_user')
            ->using(RoleAssignment::class)
            ->withPivot(['id', 'scope_type', 'scope_target_id', 'assigned_by_user_id', 'assigned_at', 'valid_until', 'deleted_at'])
            ->wherePivotNull('deleted_at')
            ->withTimestamps();
    }

    public function roleAssignments(): HasMany
    {
        return $this->hasMany(RoleAssignment::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    public function authSessions(): HasMany
    {
        return $this->hasMany(AuthSession::class);
    }

    /**
     * ARCH-2 — the bearer credentials this user's phones hold.
     *
     * A mobile sign-in writes no session register row, so without this relation
     * a live token appeared on no screen: the profile page listed devices and
     * sessions, the administrator's page listed the same two, and a token with no
     * device behind it was invisible and unrevocable for its whole 30-day life.
     * You cannot revoke what nobody can see.
     */
    public function apiTokens(): HasMany
    {
        return $this->hasMany(ApiToken::class);
    }

    /** AUTH-8 — who last moved this account's sign-in address, if anybody did. */
    public function emailChangedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'email_changed_by_user_id');
    }

    /** BR-31 — the administrator who cleared this account's password, if one did. */
    public function passwordResetBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'password_reset_by_user_id');
    }

    public function loginCodes(): HasMany
    {
        return $this->hasMany(LoginCode::class);
    }

    public function passwordHistories(): HasMany
    {
        return $this->hasMany(PasswordHistory::class);
    }

    public function notificationPreferences(): HasMany
    {
        return $this->hasMany(NotificationPreference::class);
    }

    public function appNotifications(): HasMany
    {
        return $this->hasMany(AppNotification::class, 'user_id')->latest();
    }

    /** BR-24 — delegations this user has given away. */
    public function delegationsGiven(): HasMany
    {
        return $this->hasMany(Delegation::class, 'from_user_id');
    }

    /** BR-24 — delegations this user is currently covering. */
    public function delegationsReceived(): HasMany
    {
        return $this->hasMany(Delegation::class, 'to_user_id');
    }

    public function extensionAgentRecords(): HasMany
    {
        return $this->hasMany(ExtensionAgent::class);
    }

    /* ---------------------------------------------------------------------
     | Status
     * ------------------------------------------------------------------ */

    public function isActive(): bool
    {
        return $this->status === 'active' && $this->deactivated_at === null;
    }

    /** AUTH-6 */
    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    /** AUTH-5 — maximum password age of 90 days. */
    public function passwordHasExpired(): bool
    {
        /*
         * A password somebody else chose is expired the moment it is set,
         * whatever the configured age — including when the age check is switched
         * off entirely, which is why this sits above the `$maxAge <= 0` return.
         * EnsureAccountIsUsable turns this into a redirect to the change screen
         * before the user may reach anything else, and that redirect is the whole
         * reason an administrator is allowed to type a password at all: it makes
         * their knowledge of it last one sign-in instead of forever.
         */
        if ($this->passwordIsTemporary()) {
            return true;
        }

        $maxAge = (int) config('gondal.auth.password_max_age_days', 90);

        if ($maxAge <= 0) {
            return false;
        }

        if ($this->password_changed_at === null) {
            return true;
        }

        return $this->password_changed_at->addDays($maxAge)->isPast();
    }

    /**
     * BR-31 — an administrator reset this password and it has not yet been
     * replaced by one the holder chose.
     *
     * Both flavours: a cleared password awaiting an emailed code, and a temporary
     * one the administrator typed. PasswordPolicy::apply() clears it the instant
     * the user chooses their own, which is what makes it safe to read as "waiting
     * on the user" rather than as a historical fact.
     */
    public function awaitingPasswordReset(): bool
    {
        return $this->password_reset_at !== null;
    }

    /**
     * BR-31, qualified — the password in force is one an administrator typed, and
     * the user must replace it before they may do anything else.
     */
    public function passwordIsTemporary(): bool
    {
        return (bool) $this->password_is_temporary;
    }

    /**
     * The stored hash is a random value NOBODY knows — not the administrator, not
     * the user — so no input opens the account and the emailed code is the only
     * way in.
     *
     * Distinct from awaitingPasswordReset() because a temporary password is a real
     * password: it belongs in AUTH-5's history, and the placeholder does not.
     */
    public function passwordIsUnknowable(): bool
    {
        return $this->awaitingPasswordReset() && ! $this->passwordIsTemporary();
    }

    public function initials(): string
    {
        return collect(preg_split('/\s+/', trim((string) $this->name)) ?: [])
            ->filter()
            ->take(2)
            ->map(fn (string $part) => mb_strtoupper(mb_substr($part, 0, 1)))
            ->implode('');
    }

    /** The role label the topbar and the audit log show alongside the name. */
    public function primaryRoleLabel(): string
    {
        return $this->effectiveRoles()
            ->reject(fn (Role $role) => (bool) $role->is_automatic)
            ->sortByDesc(fn (Role $role) => $role->permissions()->count())
            ->first()?->name
            ?? 'Staff (self-service)';
    }

    /* ---------------------------------------------------------------------
     | §5.2 — effective roles
     * ------------------------------------------------------------------ */

    /** @var array<string, mixed> */
    private array $accessMemo = [];

    /**
     * ROLE-2 — the roles that actually count: assigned, not soft-deleted, and
     * the role itself active.
     *
     * ROLE-3 — every user automatically holds the role flagged is_automatic
     * ("Staff (self-service)"), whether or not a row exists in role_user. The
     * assignment is also written on creation so it is visible on the user
     * screen, but resolution does not depend on that row existing.
     *
     * @return Collection<int, Role>
     */
    public function effectiveRoles(): Collection
    {
        return $this->accessMemo['roles'] ??= (function (): Collection {
            // An assignment past its `valid_until` grants nothing. Checked here
            // rather than at revocation time so access ends on the agreed date
            // whether or not anybody remembers to act on it.
            $assigned = $this->roles()
                ->where('roles.status', 'active')
                ->where(fn ($query) => $query
                    ->whereNull('role_user.valid_until')
                    ->orWhere('role_user.valid_until', '>', Wat::now()))
                ->get();

            $automatic = Role::query()
                ->where('is_automatic', true)
                ->where('status', 'active')
                ->get();

            return $assigned->concat($automatic)->unique('id')->values();
        })();
    }

    /**
     * ROLE-2 — effective permission keys are the union across effective roles.
     * PERM-3 — retired permissions are excluded, but their history survives.
     *
     * @return array<int, string>
     */
    public function effectivePermissionKeys(): array
    {
        return $this->accessMemo['permissions'] ??= DB::table('permission_role')
            ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
            ->whereIn('permission_role.role_id', $this->effectiveRoles()->pluck('id'))
            ->whereNull('permissions.retired_at')
            ->select('permissions.resource_key', 'permissions.action')
            ->distinct()
            ->get()
            ->map(fn ($row) => $row->resource_key.'.'.$row->action)
            ->all();
    }

    public function hasPermission(string $key): bool
    {
        return in_array($key, $this->effectivePermissionKeys(), true);
    }

    /** SCR-2 — "any of these" tests, used by nav and by multi-permission routes. */
    public function hasAnyPermission(string ...$keys): bool
    {
        foreach ($keys as $key) {
            if ($this->hasPermission($key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * §4 — `/approvals` requires "any purchase.approve.*". Prefix matching keeps
     * the route definition from having to list every stage permission.
     */
    public function hasPermissionMatching(string $prefix): bool
    {
        foreach ($this->effectivePermissionKeys() as $key) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /* ---------------------------------------------------------------------
     | §5.3 — data scope
     * ------------------------------------------------------------------ */

    /**
     * SCOPE-1 — the scopes that apply to one permission: the scope of every
     * assignment whose role grants it, unioned.
     *
     * This is why scope cannot be a column on `users`. Two roles may grant the
     * same permission at different scopes, and the wider one wins for that
     * permission only.
     */
    public function scopeSetFor(string $permissionKey): ScopeSet
    {
        $memoKey = 'scope:'.$permissionKey;

        if (isset($this->accessMemo[$memoKey])) {
            return $this->accessMemo[$memoKey];
        }

        $position = strrpos($permissionKey, '.');

        if ($position === false) {
            return $this->accessMemo[$memoKey] = ScopeSet::empty();
        }

        $resourceKey = substr($permissionKey, 0, $position);
        $action = substr($permissionKey, $position + 1);

        $assignments = DB::table('role_user')
            ->join('roles', 'roles.id', '=', 'role_user.role_id')
            ->join('permission_role', 'permission_role.role_id', '=', 'roles.id')
            ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
            ->where('role_user.user_id', $this->getKey())
            ->whereNull('role_user.deleted_at')
            ->where(fn ($query) => $query
                ->whereNull('role_user.valid_until')
                ->orWhere('role_user.valid_until', '>', Wat::now()))
            ->whereNull('roles.deleted_at')
            ->where('roles.status', 'active')
            ->whereNull('permissions.retired_at')
            ->where('permissions.resource_key', $resourceKey)
            ->where('permissions.action', $action)
            ->select('role_user.id', 'role_user.scope_type', 'role_user.scope_target_id')
            ->distinct()
            ->get();

        // ROLE-3 — the automatic role grants hr.leave.own and hr.payslip.own,
        // which are inherently `own`-scoped whether or not an assignment row
        // exists for them.
        $automaticGrants = DB::table('permission_role')
            ->join('roles', 'roles.id', '=', 'permission_role.role_id')
            ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
            ->where('roles.is_automatic', true)
            ->where('roles.status', 'active')
            ->whereNull('roles.deleted_at')
            ->whereNull('permissions.retired_at')
            ->where('permissions.resource_key', $resourceKey)
            ->where('permissions.action', $action)
            ->exists();

        $scopes = [];

        if ($automaticGrants) {
            $scopes[] = Scope::own();
        }

        $multiTargets = $this->multiScopeTargets($assignments->pluck('id')->all());

        foreach ($assignments as $assignment) {
            $type = ScopeType::tryFrom((string) $assignment->scope_type);

            if ($type === null) {
                continue;
            }

            $targetIds = self::unionTargets(
                $assignment->scope_target_id,
                $multiTargets[$assignment->id] ?? [],
            );

            $scopes[] = new Scope($type, $targetIds, $this->describeScopeTarget($type, $targetIds));
        }

        return $this->accessMemo[$memoKey] = new ScopeSet(...$scopes);
    }

    /**
     * SCOPE-1 — the two places a scope's targets are kept, read as one list.
     *
     * A single target sits in `role_user.scope_target_id` and a list in
     * `role_user_scope_targets`. Reading the union means an assignment written
     * before multi-target scopes existed resolves exactly as it did, and one
     * written after resolves to all of its targets, with no data migration and
     * no moment where a live assignment means something different.
     *
     * @param  array<int, int>  $listed
     * @return array<int, int>
     */
    private static function unionTargets(mixed $single, array $listed): array
    {
        $ids = $listed;

        if ($single !== null) {
            $ids[] = (int) $single;
        }

        sort($ids);

        return array_values(array_unique($ids));
    }

    /**
     * SCOPE-1 — the listed targets of an assignment, keyed by assignment id.
     *
     * @param  array<int, int>  $assignmentIds
     * @return array<int, array<int, int>>
     */
    private function multiScopeTargets(array $assignmentIds): array
    {
        if ($assignmentIds === []) {
            return [];
        }

        return DB::table('role_user_scope_targets')
            ->whereIn('role_user_id', $assignmentIds)
            ->get()
            ->groupBy('role_user_id')
            ->map(fn (Collection $rows) => $rows->pluck('target_id')->map(fn ($id) => (int) $id)->all())
            ->all();
    }

    /**
     * SCR-1 — "Your Data Scope: Kumbotso Center only". The label is resolved
     * from the target table the scope type names.
     *
     * @param  array<int, int>  $targetIds
     */
    private function describeScopeTarget(ScopeType $type, array $targetIds): ?string
    {
        $table = $type->targetTable();

        if ($table === null || $targetIds === []) {
            return null;
        }

        $names = DB::table($table)->whereIn('id', $targetIds)->pluck('name')->all();

        if ($names === []) {
            return null;
        }

        $suffix = match ($type) {
            ScopeType::Center => ' Center',
            ScopeType::Point => ' Point',
            ScopeType::Lga => ' LGA',
            default => '',
        };

        return implode(', ', array_map(static fn ($name) => $name.$suffix, $names));
    }

    /**
     * SCR-1 — the widest scope the user holds anywhere, shown on the
     * access-denied page and in their profile.
     */
    public function overallScopeDescription(): string
    {
        $descriptions = $this->roles
            ->map(function (Role $role) {
                $type = ScopeType::tryFrom((string) $role->pivot->scope_type);

                if ($type === null) {
                    return null;
                }

                $pivotId = (int) $role->pivot->id;

                $targetIds = self::unionTargets(
                    $role->pivot->scope_target_id,
                    $this->multiScopeTargets([$pivotId])[$pivotId] ?? [],
                );

                return (new Scope($type, $targetIds, $this->describeScopeTarget($type, $targetIds)))->describe();
            })
            ->filter()
            ->unique()
            ->values();

        return $descriptions->isEmpty() ? 'Own records only' : $descriptions->implode(' · ');
    }

    /**
     * BR-24 — role ids this user may act under by virtue of an active
     * delegation. Kept separate from effectiveRoles() on purpose: a delegation
     * routes an approval queue, it does not silently widen someone's permissions
     * across the whole system.
     *
     * @return array<int, int>
     */
    public function delegatedRoleIds(): array
    {
        return $this->accessMemo['delegated_roles'] ??= Delegation::query()
            ->where('to_user_id', $this->getKey())
            ->whereNull('revoked_at')
            ->whereDate('starts_on', '<=', Wat::today()->toDateString())
            ->whereDate('ends_on', '>=', Wat::today()->toDateString())
            ->pluck('role_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /** Drop the per-instance memo after a role or permission change. */
    public function forgetAccessMemo(): void
    {
        $this->accessMemo = [];
        $this->unsetRelation('roles');
    }

    public function hasTelegram(): bool
    {
        return ! empty($this->telegram_chat_id);
    }

    public function generateTelegramOnboardingToken(): string
    {
        if (empty($this->telegram_onboarding_token)) {
            $this->forceFill([
                'telegram_onboarding_token' => 'gondal_usr_' . bin2hex(random_bytes(16)),
            ])->save();
        }

        return $this->telegram_onboarding_token;
    }
}

