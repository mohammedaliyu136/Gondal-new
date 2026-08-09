<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Authorization\MobileCapabilities;
use App\Authorization\ScopeType;
use App\Http\Controllers\Api\ApiController;
use App\Models\Cooperative;
use App\Models\Delivery;
use App\Models\ExtensionAgent;
use App\Models\Farmer;
use App\Models\FieldActivity;
use App\Models\Role;
use App\Models\Sale;
use App\Models\User;
use App\Support\Money;
use App\Support\Volume;
use App\Support\Wat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * "Who am I, what is my job, and what may I do with this phone?"
 *
 * §16 — the personas screen calls itself the authoritative reference for "who
 * actually uses the system, what they do in it, and what they must never see".
 * Everything on this endpoint is that reference, resolved for one signed-in
 * user and served as data:
 *
 *   roles[].responsibilities   what this job IS — the "Their day" list
 *   roles[].restrictions       the boundary, in words
 *   capabilities               the same boundary, as questions the UI can gate on
 *   permission_keys            the underlying truth, for a client that wants it
 *   scope                      SCR-1's "Your Data Scope" line
 *
 * The client renders the responsibilities rather than composing its own copy,
 * so a role reshaped in the ERP reshapes the app on the next refresh. ROLE-6
 * says a role edit takes effect on the next request; this is what makes that
 * true on a phone as well as in a browser.
 */
class MobileAccessController extends ApiController
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $roles = $user->effectiveRoles()->sortByDesc(
            fn (Role $role) => $role->livePermissions()->count(),
        )->values();

        return $this->ok([
            'user' => [
                'id' => $user->getKey(),
                'name' => $user->name,
                'email' => $user->email,
                'initials' => $user->initials(),
                'role' => $user->primaryRoleLabel(),
                'department' => $user->department?->name,
                'position' => $user->position,
                // TEST-1 — a test account is excluded from every report and
                // payroll figure. The client says so on screen, because an agent
                // handed a test phone should never wonder why totals differ.
                'is_test' => (bool) $user->is_test,
            ],

            'roles' => $roles->map(fn (Role $role) => $this->describeRole($user, $role))->all(),

            // Where this user's app should open. The widest role wins; a user
            // with no mobile-facing role lands on their own records, which is
            // the one thing every user has (ROLE-3).
            'home' => $roles->pluck('mobile_home')->filter()->first() ?? 'self_service',

            // SCR-1 — "Your Data Scope: Kumbotso Center only".
            'scope' => $user->overallScopeDescription(),

            'permissions' => MobileCapabilities::for($user),

            // PERM-1 — the catalogue lives in the database, so the client is
            // told the real keys rather than shipping a copy of them.
            'permission_keys' => $user->effectivePermissionKeys(),

            /*
             * USER-2 — holding `community.extension.create` is not the same as
             * having an extension-agent record, and a field visit belongs to the
             * record: the register, the targets and the follow-up trail all hang
             * off it. A Community Engagement Officer, or an agent whose register
             * entry has not been created yet, saw the 'New visit report' action,
             * captured visits all morning offline, and had every one refused at
             * sync time hours later with nothing they could do about it.
             *
             * Nobody should see a button that will refuse them at sync time, so
             * the fact is answered here rather than discovered in the field.
             */
            'has_extension_agent_record' => ExtensionAgent::withoutDataScope()
                ->where('user_id', $user->getKey())
                ->exists(),

            'assigned_communities' => $this->scopeTargetNames($user, ScopeType::Communities),
            'assigned_points' => $this->scopeTargetNames($user, ScopeType::Point),
            'assigned_centers' => $this->scopeTargetNames($user, ScopeType::Center),

            'metrics' => $this->metrics($user),

            // ARCH-9 — the phone's clock is not trusted for the BR-3 cut-off.
            'server_time_wat' => Wat::now()->toIso8601String(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function describeRole(User $user, Role $role): array
    {
        return [
            'name' => $role->name,
            'description' => $role->description,
            'scope_type' => $role->scope_type,
            'scope' => $this->roleScopeDescription($user, $role),
            'accent' => $role->accent,
            'is_automatic' => (bool) $role->is_automatic,
            'mobile_home' => $role->mobile_home,
            // §16 — empty for a role nobody has described yet (ROLE-5). The
            // client shows the role with no list rather than inventing one.
            'responsibilities' => $role->responsibilityList(),
            'restrictions' => $role->restrictionList(),
        ];
    }

    /**
     * The scope of THIS role's assignment, which is not always the user's widest
     * scope: an officer covering one center may also hold a network-wide read.
     * SCOPE-1 puts the scope on the assignment, so it is read from there.
     */
    private function roleScopeDescription(User $user, Role $role): string
    {
        $assignment = $user->roles->firstWhere('id', $role->getKey());

        if ($assignment === null) {
            // ROLE-3 — the automatic role has no assignment row to read.
            return $role->defaultScopeType() === ScopeType::Own
                ? 'Own records only'
                : $role->defaultScopeType()->label();
        }

        $type = ScopeType::tryFrom((string) $assignment->pivot->scope_type) ?? $role->defaultScopeType();

        if (! $type->requiresTarget()) {
            return $type === ScopeType::Network ? 'Network-wide' : 'Own records only';
        }

        $names = $this->assignmentTargetNames($type, (int) $assignment->pivot->id, $assignment->pivot->scope_target_id);

        if ($names === []) {
            return $type->label().' — no target set';
        }

        // SCR-1 — the same wording the web uses ("Kumbotso Center"), so the two
        // surfaces do not describe one scope two ways.
        $suffix = match ($type) {
            ScopeType::Center => ' Center',
            ScopeType::Point => ' Point',
            ScopeType::Lga => ' LGA',
            default => '',
        };

        return implode(', ', array_map(static fn (string $name) => $name.$suffix, $names));
    }

    /**
     * Every target the user holds for a scope type, across all their roles —
     * "the 4 communities you cover", which the app shows on the home screen.
     *
     * @return array<int, string>
     */
    private function scopeTargetNames(User $user, ScopeType $type): array
    {
        $table = $type->targetTable();

        if ($table === null) {
            return [];
        }

        $assignments = DB::table('role_user')
            ->join('roles', 'roles.id', '=', 'role_user.role_id')
            ->where('role_user.user_id', $user->getKey())
            ->whereNull('role_user.deleted_at')
            ->whereNull('roles.deleted_at')
            ->where('roles.status', Role::STATUS_ACTIVE)
            ->where(fn ($query) => $query
                ->whereNull('role_user.valid_until')
                ->orWhere('role_user.valid_until', '>', Wat::now()))
            ->where('role_user.scope_type', $type->value)
            ->select('role_user.id', 'role_user.scope_target_id')
            ->get();

        $names = [];

        foreach ($assignments as $assignment) {
            foreach ($this->assignmentTargetNames($type, (int) $assignment->id, $assignment->scope_target_id) as $name) {
                $names[$name] = true;
            }
        }

        return array_keys($names);
    }

    /**
     * SCOPE-1 — a scope's targets live in two places, and both are read.
     *
     * @return array<int, string>
     */
    private function assignmentTargetNames(ScopeType $type, int $assignmentId, mixed $singleTargetId): array
    {
        $table = $type->targetTable();

        if ($table === null) {
            return [];
        }

        $ids = DB::table('role_user_scope_targets')
            ->where('role_user_id', $assignmentId)
            ->pluck('target_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($singleTargetId !== null) {
            $ids[] = (int) $singleTargetId;
        }

        if ($ids === []) {
            return [];
        }

        return DB::table($table)->whereIn('id', array_unique($ids))->orderBy('name')->pluck('name')->all();
    }

    /**
     * The home-screen tiles.
     *
     * Each figure is gated on the permission that governs it, and absent — not
     * zero — when the user may not see it. An Extension Agent's phone must not
     * show a litre total for the farmers they visit (§16), and "0 L" is a
     * statement about production, not a refusal; the client renders a dash for a
     * missing key and a number for a present one.
     *
     * @return array<string, float|int|string>
     */
    private function metrics(User $user): array
    {
        $since = Wat::now()->startOfMonth();
        $metrics = [];

        if ($user->hasPermission('milk.deliveries.view')) {
            // Delivery carries the scope global (SCOPE-2), so this is already
            // narrowed to the caller's points or centers.
            $centilitres = (int) round(100 * (float) Delivery::query()
                ->excludingTestData()
                ->where('delivered_at', '>=', $since)
                ->sum('litres_accepted'));

            $metrics['volume_collected'] = (float) Volume::fromCentilitres($centilitres);
        }

        // A farmer is a record, not an activity — TEST-1 tags the work a test
        // account produces, and the farmer register carries no is_test column
        // to filter on.
        if ($user->hasPermission('community.farmers.view')) {
            $metrics['farmers_under_care'] = Farmer::query()->active()->count();
        }

        if ($user->hasPermission('community.extension.view')) {
            $metrics['producers_visited'] = FieldActivity::query()
                ->excludingTestData()
                ->where('activity_date', '>=', $since->toDateString())
                ->count();
        }

        // BR-29 — a Sales Officer records sales but is never shown a total.
        if ($user->hasPermission('shop.revenue.view')) {
            /*
             * BR-25 — CREDIT issued, which is what the tile is labelled. Without
             * the payment_method filter this summed cash, transfer, credit and
             * milk_deduction together, so a supervisor reading credit exposure on
             * the phone saw several times the real figure while the web screen
             * (SaleController) showed the right one under the same word.
             *
             * ARCH-6 / NFR-5 — and it goes out through Money, not a hardcoded
             * /100 emitting a binary float. Money::minorUnits() is configuration;
             * every other figure in this API is a Money::decimal string.
             */
            $metrics['oss_credit_issued'] = Money::decimal((int) Sale::query()
                ->excludingTestData()
                ->notVoided()
                ->where('payment_method', Sale::PAYMENT_CREDIT)
                ->where('sold_at', '>=', $since)
                ->sum('total_minor'));
        }

        if ($user->hasPermission('community.cooperatives.view')) {
            $metrics['cooperatives'] = Cooperative::query()->count();
        }

        return $metrics;
    }
}
