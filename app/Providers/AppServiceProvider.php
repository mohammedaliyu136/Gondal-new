<?php

namespace App\Providers;

use App\Services\Auth\ApiTokenService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(\App\Services\Workflow\StageActionRegistry::class, function ($app) {
            $registry = new \App\Services\Workflow\StageActionRegistry();
            $registry->register($app->make(\App\Services\Workflow\Actions\RequisitionAdjustItemsAction::class));
            $registry->register($app->make(\App\Services\Workflow\Actions\RequisitionApprovePricingAction::class));
            $registry->register($app->make(\App\Services\Workflow\Actions\RequisitionAssignServiceProviderAction::class));

            return $registry;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Schema::defaultStringLength(191);
        /*
         * ARCH-2 — "when [mobile] arrive a token guard is added to
         * config/auth.php and [the routes gain] it".
         *
         * The guard does one thing: turn a bearer token into the User the rest
         * of §5 already knows how to authorise. It grants nothing. A request
         * that arrives on this guard passes through the same RequirePermission
         * middleware, the same Access::authorize() and the same scope layer as
         * the browser, because by the time any of those run the two surfaces are
         * indistinguishable — which is the point.
         */
        Auth::viaRequest('api-token', function (Request $request) {
            $token = app(ApiTokenService::class)->resolve($request->bearerToken(), $request);

            return $token?->user;
        });
    }
}
