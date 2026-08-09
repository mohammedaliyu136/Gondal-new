<?php

namespace App\Providers;

use App\Authorization\Access;
use App\Authorization\Denials;
use App\Authorization\PermissionKey;
use App\Models\Batch;
use App\Models\CollectionCenter;
use App\Models\CollectionPoint;
use App\Models\Consignment;
use App\Models\Cooperative;
use App\Models\Delivery;
use App\Models\Employee;
use App\Models\ExtensionAgent;
use App\Models\Farmer;
use App\Models\FieldActivity;
use App\Models\LeaveRequest;
use App\Models\Payslip;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Requisition;
use App\Models\Role;
use App\Models\Sale;
use App\Models\Trip;
use App\Models\User;
use App\Policies\BatchPolicy;
use App\Policies\CollectionCenterPolicy;
use App\Policies\CollectionPointPolicy;
use App\Policies\ConsignmentPolicy;
use App\Policies\CooperativePolicy;
use App\Policies\DeliveryPolicy;
use App\Policies\EmployeePolicy;
use App\Policies\ExtensionAgentPolicy;
use App\Policies\FarmerPolicy;
use App\Policies\FieldActivityPolicy;
use App\Policies\LeaveRequestPolicy;
use App\Policies\PayslipPolicy;
use App\Policies\ProductCategoryPolicy;
use App\Policies\ProductPolicy;
use App\Policies\RequisitionPolicy;
use App\Policies\RolePolicy;
use App\Policies\SalePolicy;
use App\Policies\TripPolicy;
use App\Policies\UserPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * §5 — wires the two authorisation layers into the framework.
 *
 * ARCH-5 — "Do not adopt an off-the-shelf roles package without verifying it
 * supports §5.3 data scope. spatie/laravel-permission covers permissions but NOT
 * scope; scope must be built." No such package is installed. The permission
 * layer is the Gate below; the scope layer is App\Authorization plus the
 * Scopeable models.
 */
class AuthorizationServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    private const POLICIES = [
        CollectionPoint::class => CollectionPointPolicy::class,
        CollectionCenter::class => CollectionCenterPolicy::class,
        Delivery::class => DeliveryPolicy::class,
        Consignment::class => ConsignmentPolicy::class,
        Batch::class => BatchPolicy::class,
        Trip::class => TripPolicy::class,
        Farmer::class => FarmerPolicy::class,
        Cooperative::class => CooperativePolicy::class,
        ExtensionAgent::class => ExtensionAgentPolicy::class,
        FieldActivity::class => FieldActivityPolicy::class,
        Requisition::class => RequisitionPolicy::class,
        Product::class => ProductPolicy::class,
        ProductCategory::class => ProductCategoryPolicy::class,
        Sale::class => SalePolicy::class,
        Employee::class => EmployeePolicy::class,
        LeaveRequest::class => LeaveRequestPolicy::class,
        Payslip::class => PayslipPolicy::class,
        User::class => UserPolicy::class,
        Role::class => RolePolicy::class,
    ];

    public function register(): void
    {
        $this->app->singleton(Denials::class);
        $this->app->singleton(Access::class);
    }

    public function boot(): void
    {
        foreach (self::POLICIES as $model => $policy) {
            Gate::policy($model, $policy);
        }

        $this->registerPermissionGate();
    }

    /**
     * PERM-1 / ROLE-2 — `$user->can('hr.payroll.view')` resolves against the
     * permissions table.
     *
     * The callback deliberately declines two cases so they fall through to the
     * policy layer instead:
     *
     *   - abilities that are not shaped like a permission key ('view',
     *     'update', 'approve'), which are policy method names;
     *   - any check that was given arguments, i.e. a RECORD-level check, which
     *     is layer 2's job (ARCH-4).
     */
    private function registerPermissionGate(): void
    {
        Gate::before(function (?User $user, string $ability, array $arguments = []): ?bool {
            if ($arguments !== []) {
                return null;
            }

            if (! PermissionKey::looksLikeOne($ability)) {
                return null;
            }

            // ROLE-2 — there is no deny rule and no super-user bypass. Even the
            // System Administrator role passes only because it holds the grant.
            return $this->app->make(Access::class)->allows($user, $ability);
        });
    }
}
