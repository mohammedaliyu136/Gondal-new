<?php

use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\PermissionTestController;
use App\Http\Controllers\Admin\PersonaController;
use App\Http\Controllers\Admin\ReferenceDataController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\ApprovalsController;
use App\Http\Controllers\Auth\PasswordChangeController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\SignInController;
use App\Http\Controllers\Community\CommunityController;
use App\Http\Controllers\Community\CooperativeController;
use App\Http\Controllers\Community\ExtensionAgentController;
use App\Http\Controllers\Community\FarmerController;
use App\Http\Controllers\Community\FieldActivityController;
use App\Http\Controllers\Community\ValidationController;
use App\Http\Controllers\Finance\CashFloatController;
use App\Http\Controllers\Finance\PaymentRunController;
use App\Http\Controllers\Finance\TransportPaymentRunController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Hr\DepartmentController;
use App\Http\Controllers\Hr\EmployeeController;
use App\Http\Controllers\Hr\LeaveController;
use App\Http\Controllers\Hr\PayrollController;
use App\Http\Controllers\Hr\PositionController;
use App\Http\Controllers\Milk\BatchController;
use App\Http\Controllers\Milk\CollectionCenterController;
use App\Http\Controllers\Milk\CollectionPointController;
use App\Http\Controllers\Milk\ConsignmentController;
use App\Http\Controllers\Milk\DeliveryController;
use App\Http\Controllers\Milk\FleetController;
use App\Http\Controllers\Milk\LogisticsController;
use App\Http\Controllers\Milk\ReconciliationController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Purchases\RequisitionController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\Shop\InventoryController;
use App\Http\Controllers\Shop\ProductCategoryController;
use App\Http\Controllers\Shop\SaleController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web routes — §4 screen inventory
|--------------------------------------------------------------------------
|
| Every route carries the permission §4 assigns to that screen. This is
| authorisation layer 1 (ARCH-4); layer 2 — the data-scope check on a specific
| record — happens in the controller through Access::authorize() with the record
| in hand, because a route cannot know which record is being asked for.
|
| SCR-1  a 403 from either layer renders access-denied, populated.
| AUTH-8 there is no registration route, by design.
*/

/* ---------------------------------------------------------------------
 | Authentication (no app shell)
 * ------------------------------------------------------------------ */

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [SignInController::class, 'showLogin'])->name('login');

    // NFR-8 — rate-limited per IP; SigninThrottle adds the per-account rule.
    Route::post('/login', [SignInController::class, 'attempt'])
        ->middleware('throttle:10,1')
        ->name('login.attempt');

    Route::get('/login/verify', [SignInController::class, 'showVerify'])->name('login.verify');
    Route::post('/login/verify', [SignInController::class, 'verify'])
        ->middleware('throttle:10,1')
        ->name('login.verify.store');
    Route::post('/login/verify/resend', [SignInController::class, 'resend'])
        ->middleware('throttle:5,5')
        ->name('login.verify.resend');

    Route::get('/password/forgot', [PasswordResetController::class, 'showForgot'])->name('password.forgot');
    Route::post('/password/forgot', [PasswordResetController::class, 'sendCode'])
        ->middleware('throttle:5,5')
        ->name('password.forgot.store');

    Route::get('/password/verify', [PasswordResetController::class, 'showVerify'])->name('password.verify');
    Route::post('/password/verify', [PasswordResetController::class, 'verify'])
        ->middleware('throttle:10,1')
        ->name('password.verify.store');

    // The activation email's signed front door — seeds the reset session so the
    // emailed code is redeemable. `signed` is the whole guard: the URL cannot be
    // forged or outlive its expiry, and the code entry still stands after it.
    Route::get('/activate/{user}', [PasswordResetController::class, 'activate'])
        ->middleware('signed')->name('activate');

    Route::get('/password/reset', [PasswordResetController::class, 'showReset'])->name('password.reset.form');
    Route::post('/password/reset', [PasswordResetController::class, 'store'])->name('password.reset.store');
});

Route::post('/signout', [SignInController::class, 'signOut'])
    ->middleware('auth')
    ->name('auth.signout');

/* ---------------------------------------------------------------------
 | Everything else
 * ------------------------------------------------------------------ */

Route::middleware(['auth', 'session.authenticate', 'account.usable', 'session.touch'])->group(function (): void {

    // AUTH-5 — reachable even with an expired password (see EnsureAccountIsUsable).
    Route::get('/password/change', [PasswordChangeController::class, 'show'])->name('password.change');
    Route::post('/password/change', [PasswordChangeController::class, 'store'])->name('password.change.store');

    Route::get('/', DashboardController::class)->name('dashboard');

    /*
     * §4 — `/approvals` admits any workflow-stage approver, not only purchasing
     * ones: the queue carries leave and payroll too, and gating it on
     * `purchase.approve.*` locked the HR Manager out of their own items.
     */
    Route::middleware('approval-queue')->group(function (): void {
        Route::get('/approvals', [ApprovalsController::class, 'index'])->name('approvals.index');
        Route::post('/approvals/{instance}/approve', [ApprovalsController::class, 'approve'])->name('approvals.approve');
        Route::post('/approvals/{instance}/reject', [ApprovalsController::class, 'reject'])->name('approvals.reject');
        Route::post('/approvals/{instance}/request-info', [ApprovalsController::class, 'requestInfo'])->name('approvals.request-info');
    });

    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');

    /*
     * §15.5 / NG-7 — reporting. No permission here on purpose: each report in
     * PeriodReports::catalogue() carries its own, the picker lists only the ones
     * the viewer may run, and the service authorises again before it reads a
     * table. A single route permission would have to be the loosest of the five,
     * which puts a Sales Officer one URL away from the revenue report.
     */
    Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('/reports/{report}/export', [ReportController::class, 'export'])->name('reports.export');
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead'])->name('notifications.read');
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.read-all');
    Route::put('/notifications/preferences', [NotificationController::class, 'updatePreferences'])->name('notifications.preferences');

    Route::get('/profile', [ProfileController::class, 'show'])->name('profile');
    Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
    // AUTH-2 — trust is revocable by the user.
    Route::post('/profile/devices/{device}/revoke', [ProfileController::class, 'revokeDevice'])->name('profile.devices.revoke');
    Route::post('/profile/sessions/revoke-others', [ProfileController::class, 'revokeOtherSessions'])->name('profile.sessions.revoke');

    /* ----------------------------- Milk ----------------------------- */

    Route::middleware('permission:milk.points.view')->group(function (): void {
        Route::get('/collection-points', [CollectionPointController::class, 'index'])->name('collection-points.index');
        Route::get('/collection-points/{point}', [CollectionPointController::class, 'show'])->name('collection-points.show');
        Route::get('/collection-centers', [CollectionCenterController::class, 'index'])->name('collection-centers.index');
    });

    Route::post('/collection-points', [CollectionPointController::class, 'store'])
        ->middleware('permission:milk.points.create')->name('collection-points.store');
    Route::put('/collection-points/{point}', [CollectionPointController::class, 'update'])
        ->middleware('permission:milk.points.edit')->name('collection-points.update');

    // Who delivers here — a farmer column, but the point owner's decision.
    Route::get('/collection-points/{point}/farmers', [CollectionPointController::class, 'farmers'])
        ->middleware('permission:milk.points.view')->name('collection-points.farmers');
    Route::post('/collection-points/{point}/farmers', [CollectionPointController::class, 'assignFarmer'])
        ->middleware('permission:community.farmers.edit|milk.points.edit')->name('collection-points.farmers.assign');
    Route::delete('/collection-points/{point}/farmers/{farmer}', [CollectionPointController::class, 'unassignFarmer'])
        ->middleware('permission:community.farmers.edit|milk.points.edit')->name('collection-points.farmers.unassign');

    // A centre is the same class of master data as a point and shares its grant.
    Route::post('/collection-centers', [CollectionCenterController::class, 'store'])
        ->middleware('permission:milk.points.create')->name('collection-centers.store');
    Route::put('/collection-centers/{center}', [CollectionCenterController::class, 'update'])
        ->middleware('permission:milk.points.edit')->name('collection-centers.update');

    /*
     * A collection center is master data, so opening one needs the same permission
     * as listing them. It used to require milk.consignment.confirm.view, which the
     * nav did not — so a Delivery Lead saw the list and got 403 on every row.
     *
     * Either permission opens it, because the two audiences are different and both
     * are legitimate: master-data roles read the center's setup, intake roles work
     * its confirmation queue. Requiring only the first would have locked Accounts
     * out of a screen they could reach before.
     *
     * The queue INSIDE the screen is still gated separately: the controller only
     * loads it, and the view only renders it, for a holder of
     * milk.consignment.confirm.view.
     */
    Route::get('/collection-centers/{center}', [CollectionCenterController::class, 'show'])
        ->middleware('permission:milk.consignment.confirm.view|milk.points.view')
        ->name('collection-centers.show');

    Route::middleware('permission:milk.deliveries.view')->group(function (): void {
        Route::get('/milk-flow/deliveries', [DeliveryController::class, 'index'])->name('deliveries.index');
        Route::get('/milk-flow/deliveries/{delivery}', [DeliveryController::class, 'show'])->name('deliveries.show');
    });

    Route::post('/milk-flow/deliveries', [DeliveryController::class, 'store'])
        ->middleware('permission:milk.deliveries.create')->name('deliveries.store');
    Route::put('/milk-flow/deliveries/{delivery}', [DeliveryController::class, 'update'])
        ->middleware('permission:milk.deliveries.edit')->name('deliveries.update');
    Route::post('/milk-flow/deliveries/{delivery}/adjustments', [DeliveryController::class, 'adjust'])
        ->middleware('permission:milk.adjustment.create')->name('deliveries.adjust');

    Route::get('/milk-flow/consignments', [ConsignmentController::class, 'index'])
        ->middleware('permission:milk.consignment.confirm.view')->name('consignments.index');

    Route::post('/milk-flow/consignments', [ConsignmentController::class, 'store'])
        ->middleware('permission:milk.consignment.confirm.create')->name('consignments.store');
    Route::post('/milk-flow/consignments/{consignment}/quality-tests', [ConsignmentController::class, 'recordQualityTest'])
        ->middleware('permission:milk.grade.create')->name('consignments.quality-test');
    Route::post('/milk-flow/consignments/{consignment}/confirm', [ConsignmentController::class, 'confirm'])
        ->middleware('permission:milk.consignment.confirm.edit')->name('consignments.confirm');
    Route::post('/milk-flow/consignments/{consignment}/adjustments', [ConsignmentController::class, 'adjust'])
        ->middleware('permission:milk.adjustment.create')->name('consignments.adjust');
    Route::post('/milk-flow/consignments/{consignment}/grade', [ConsignmentController::class, 'grade'])
        ->middleware('permission:milk.grade.create')->name('consignments.grade');
    /*
     * BR-4 — the re-grade control break. `milk.grade.edit`, not `.create`:
     * assigning a grade must not wait for a supervisor, changing one must.
     */
    Route::post('/milk-flow/consignments/{consignment}/regrade', [ConsignmentController::class, 'regrade'])
        ->middleware('permission:milk.grade.edit')->name('consignments.regrade');
    Route::get('/milk-flow/regrades', [ConsignmentController::class, 'regrades'])
        ->middleware('permission:milk.grade.edit')->name('consignments.regrades');

    Route::middleware('permission:milk.batch.dispatch.view')->group(function (): void {
        Route::get('/milk-flow/batches', [BatchController::class, 'index'])->name('batches.index');
        Route::get('/milk-flow/batches/{batch}', [BatchController::class, 'show'])->name('batches.show');
    });

    Route::post('/milk-flow/batches', [BatchController::class, 'store'])
        ->middleware('permission:milk.batch.dispatch.create')->name('batches.store');

    Route::get('/logistics', [LogisticsController::class, 'index'])
        ->middleware('permission:logistics.trips.view')->name('logistics.index');
    Route::post('/logistics/trips', [LogisticsController::class, 'storeTrip'])
        ->middleware('permission:logistics.trips.create')->name('logistics.trips.store');

    Route::get('/reconciliation', [ReconciliationController::class, 'index'])
        ->middleware('permission:milk.reconciliation.view')->name('reconciliation.index');
    Route::post('/reconciliation/{batch}', [ReconciliationController::class, 'store'])
        ->middleware('permission:milk.reconciliation.create')->name('reconciliation.store');
    Route::post('/reconciliation/{batch}/release', [ReconciliationController::class, 'release'])
        ->middleware('permission:milk.reconciliation.approve')->name('reconciliation.release');

    /*
     * §9 — the fleet and route register.
     *
     * Without it a fresh install cannot log a trip at all: the trip form's route
     * select is required and the three tables it reads were seeded only by the
     * demo seeder, which NFR-12 keeps off. `trips.fee_minor` — what §15.1's
     * transport payment run will settle from — was therefore never captured.
     */
    Route::get('/fleet', [FleetController::class, 'index'])
        ->middleware('permission:logistics.trips.view')->name('fleet.index');

    Route::middleware('permission:logistics.trips.edit')->group(function (): void {
        Route::post('/fleet/routes', [FleetController::class, 'storeRoute'])->name('fleet.routes.store');
        Route::post('/fleet/routes/generate', [FleetController::class, 'generateCentreRoutes'])->name('fleet.routes.generate');
        Route::put('/fleet/routes/{route}', [FleetController::class, 'updateRoute'])->name('fleet.routes.update');
        Route::post('/fleet/vehicles', [FleetController::class, 'storeVehicle'])->name('fleet.vehicles.store');
        Route::put('/fleet/vehicles/{vehicle}', [FleetController::class, 'updateVehicle'])->name('fleet.vehicles.update');
        Route::post('/fleet/drivers', [FleetController::class, 'storeDriver'])->name('fleet.drivers.store');
        Route::put('/fleet/drivers/{driver}', [FleetController::class, 'updateDriver'])->name('fleet.drivers.update');
    });

    /* -------------------------- Purchases --------------------------- */

    Route::middleware('permission:purchase.requisitions.view')->group(function (): void {
        Route::get('/requisitions', [RequisitionController::class, 'index'])->name('requisitions.index');
        Route::get('/requisitions/{requisition}', [RequisitionController::class, 'show'])->name('requisitions.show');
        Route::post('/requisitions/{requisition}/comments', [RequisitionController::class, 'comment'])->name('requisitions.comment');
    });

    Route::middleware('permission:purchase.requisitions.create')->group(function (): void {
        Route::post('/requisitions', [RequisitionController::class, 'store'])->name('requisitions.store');
        Route::post('/requisitions/{requisition}/submit', [RequisitionController::class, 'submit'])->name('requisitions.submit');
        // BR-20 — resubmission starts a new instance.
        Route::post('/requisitions/{requisition}/resubmit', [RequisitionController::class, 'resubmit'])->name('requisitions.resubmit');
    // §14 Phase 7 — an approval is a permission to spend, not a spend.
    Route::post('/requisitions/{requisition}/spend', [RequisitionController::class, 'spend'])
        ->middleware('permission:purchase.requisitions.spend')->name('requisitions.spend');
    });

    /* -------------------------- Community --------------------------- */

    /*
     * BR-36 — the revalidation queue. Two authorities, deliberately apart:
     * `community.validation.*` schedules and reviews (M&E), while carrying a
     * check OUT needs `community.farmers.validate`, which M&E does not hold.
     * The field app works the same queue through /api/v1/validations.
     */
    /*
     * §14 Phase 7 — farmer payment. The module every other screen said was
     * "not available yet".
     *
     * Note the split of authority: `create` generates and submits, `approve`
     * clears (through WF-007, where BR-18 stops the preparer approving their
     * own), and `disburse` records money handed over — held by the Collection
     * Officer at the centre, deliberately NOT by the agent who recorded the
     * milk.
     */
    Route::get('/payment-runs', [PaymentRunController::class, 'index'])
        ->middleware('permission:finance.farmer_payments.view')->name('payment-runs.index');
    Route::get('/payment-runs/{run}', [PaymentRunController::class, 'show'])
        ->middleware('permission:finance.farmer_payments.view')->name('payment-runs.show');
    Route::post('/payment-runs', [PaymentRunController::class, 'store'])
        ->middleware('permission:finance.farmer_payments.create')->name('payment-runs.store');
    Route::post('/payment-runs/{run}/submit', [PaymentRunController::class, 'submit'])
        ->middleware('permission:finance.farmer_payments.create')->name('payment-runs.submit');
    Route::post('/payment-runs/{run}/cancel', [PaymentRunController::class, 'cancel'])
        ->middleware('permission:finance.farmer_payments.create')->name('payment-runs.cancel');
    Route::post('/farmer-payments/{payment}/disburse', [PaymentRunController::class, 'disburse'])
        ->middleware('permission:finance.farmer_payments.disburse')->name('farmer-payments.disburse');
    Route::post('/payment-runs/{run}/reverse', [PaymentRunController::class, 'reverseRun'])
        ->middleware('permission:finance.farmer_payments.reverse')->name('payment-runs.reverse');
    Route::post('/farmer-payments/{payment}/reverse', [PaymentRunController::class, 'reversePayment'])
        ->middleware('permission:finance.farmer_payments.reverse')->name('farmer-payments.reverse');
    // USER-2 — printed FOR a farmer, never logged into by one. The second
    // authorisation layer (this farmer, this caller's scope) is in the controller.
    Route::get('/farmers/{farmer}/statement', [PaymentRunController::class, 'statement'])
        ->middleware('permission:finance.farmer_payments.view')->name('farmers.statement');
    // Deliberately NOT on community.farmers.edit — see the controller. Changing
    // where money is sent is a finance act, not a register correction.
    Route::put('/farmers/{farmer}/payout-details', [PaymentRunController::class, 'updatePayoutDetails'])
        ->middleware('permission:finance.farmer_payments.create')->name('farmers.payout-details');

    /*
     * §14 Phase 7 — transport payment. `logistics.payments` gated nothing for
     * three phases; these are the screens it was always for.
     */
    Route::get('/transport-payments', [TransportPaymentRunController::class, 'index'])
        ->middleware('permission:logistics.payments.view')->name('transport-payments.index');
    Route::get('/transport-payments/{run}', [TransportPaymentRunController::class, 'show'])
        ->middleware('permission:logistics.payments.view')->name('transport-payments.show');
    Route::post('/transport-payments', [TransportPaymentRunController::class, 'store'])
        ->middleware('permission:logistics.payments.create')->name('transport-payments.store');
    Route::post('/transport-payments/{run}/submit', [TransportPaymentRunController::class, 'submit'])
        ->middleware('permission:logistics.payments.create')->name('transport-payments.submit');
    Route::post('/transport-payments/{run}/cancel', [TransportPaymentRunController::class, 'cancel'])
        ->middleware('permission:logistics.payments.create')->name('transport-payments.cancel');
    Route::post('/transport-payments/{run}/reverse', [TransportPaymentRunController::class, 'reverseRun'])
        ->middleware('permission:logistics.payments.reverse')->name('transport-payments.reverse');
    Route::post('/driver-payments/{payment}/disburse', [TransportPaymentRunController::class, 'disburse'])
        ->middleware('permission:logistics.payments.disburse')->name('driver-payments.disburse');
    Route::post('/driver-payments/{payment}/reverse', [TransportPaymentRunController::class, 'reversePayment'])
        ->middleware('permission:logistics.payments.reverse')->name('driver-payments.reverse');

    // §14 Phase 7 — the cash book. The second leg of every payout.
    Route::get('/cash-floats', [CashFloatController::class, 'index'])
        ->middleware('permission:finance.cash.view')->name('cash-floats.index');
    Route::post('/cash-floats', [CashFloatController::class, 'store'])
        ->middleware('permission:finance.cash.issue')->name('cash-floats.store');
    Route::post('/cash-floats/{float}/reconcile', [CashFloatController::class, 'reconcile'])
        ->middleware('permission:finance.cash.reconcile')->name('cash-floats.reconcile');

    Route::get('/validations', [ValidationController::class, 'index'])
        ->middleware('permission:community.validation.view')->name('validations.index');
    Route::post('/validations', [ValidationController::class, 'store'])
        ->middleware('permission:community.validation.create')->name('validations.store');

    /*
     * Bulk, at both ends of the queue. Same two authorities as the single-record
     * routes above — scheduling a hundred checks is the same KIND of decision as
     * scheduling one, so it needs the same grant and no new permission.
     *
     * Both are fixed two-segment paths, so neither can be shadowed by the
     * `/validations/{validation}/…` routes that follow.
     */
    Route::post('/validations/rounds', [ValidationController::class, 'storeRound'])
        ->middleware('permission:community.validation.create')->name('validations.rounds.store');
    Route::post('/validations/accept-many', [ValidationController::class, 'acceptMany'])
        ->middleware('permission:community.validation.approve')->name('validations.accept-many');

    Route::post('/validations/{validation}/accept', [ValidationController::class, 'accept'])
        ->middleware('permission:community.validation.approve')->name('validations.accept');
    Route::post('/validations/{validation}/return', [ValidationController::class, 'returnToField'])
        ->middleware('permission:community.validation.approve')->name('validations.return');
    Route::post('/validations/{validation}/cancel', [ValidationController::class, 'cancel'])
        ->middleware('permission:community.validation.edit')->name('validations.cancel');

    /*
     * Communities — geography shared by the collection network and the engagement
     * programme, so either grant opens the screen. There was no create path at
     * all before this: a supervisor could add a collection point but not the
     * community it stands in.
     */
    Route::get('/communities', [CommunityController::class, 'index'])
        ->middleware('permission:community.farmers.view|milk.points.view')->name('communities.index');
    Route::post('/communities', [CommunityController::class, 'store'])
        ->middleware('permission:community.cooperatives.create|milk.points.create')->name('communities.store');
    Route::put('/communities/{community}', [CommunityController::class, 'update'])
        ->middleware('permission:community.cooperatives.edit|milk.points.edit')->name('communities.update');

    Route::middleware('permission:community.farmers.view')->group(function (): void {
        Route::get('/farmers', [FarmerController::class, 'index'])->name('farmers.index');
        Route::get('/farmers/{farmer}', [FarmerController::class, 'show'])->name('farmers.show');
    });

    Route::post('/farmers', [FarmerController::class, 'store'])
        ->middleware('permission:community.farmers.create')->name('farmers.store');
    Route::put('/farmers/{farmer}', [FarmerController::class, 'update'])
        ->middleware('permission:community.farmers.edit')->name('farmers.update');

    Route::middleware('permission:community.cooperatives.view')->group(function (): void {
        Route::get('/cooperatives', [CooperativeController::class, 'index'])->name('cooperatives.index');
        Route::get('/cooperatives/{cooperative}', [CooperativeController::class, 'show'])->name('cooperatives.show');
    });

    Route::post('/cooperatives', [CooperativeController::class, 'store'])
        ->middleware('permission:community.cooperatives.create')->name('cooperatives.store');
    Route::put('/cooperatives/{cooperative}', [CooperativeController::class, 'update'])
        ->middleware('permission:community.cooperatives.edit')->name('cooperatives.update');
    // Sensitive (§5.1) — the fund ledger.
    Route::post('/cooperatives/{cooperative}/entries', [CooperativeController::class, 'storeEntry'])
        ->middleware('permission:community.coop.savings.create')->name('cooperatives.entries.store');

    Route::middleware('permission:community.extension.view')->group(function (): void {
        Route::get('/extension-agents', [ExtensionAgentController::class, 'index'])->name('extension-agents.index');
        Route::get('/extension-agents/{agent}', [ExtensionAgentController::class, 'show'])->name('extension-agents.show');

        /*
         * The register was read-only: an agent could not be created and a
         * community could not be assigned to one from any screen, which the
         * agent-detail page said out loud and offered no way out of. The service
         * and both controller actions already existed; only the routes did not.
         */
        Route::post('/extension-agents', [ExtensionAgentController::class, 'store'])
            ->middleware('permission:community.extension.create')->name('extension-agents.store');
        Route::put('/extension-agents/{agent}', [ExtensionAgentController::class, 'update'])
            ->middleware('permission:community.extension.edit')->name('extension-agents.update');
        Route::get('/field-activities', [FieldActivityController::class, 'index'])->name('field-activities.index');
    });

    Route::post('/field-activities', [FieldActivityController::class, 'store'])
        ->middleware('permission:community.extension.create')->name('field-activities.store');

    /* ------------------------ One-Stop Shop ------------------------- */

    Route::middleware('permission:shop.inventory.view')->group(function (): void {
        Route::get('/shop/inventory', [InventoryController::class, 'index'])->name('shop.inventory');
        Route::get('/shop/products/{product}', [InventoryController::class, 'show'])->name('shop.products.show');
    });

    Route::post('/shop/products', [InventoryController::class, 'store'])
        ->middleware('permission:shop.inventory.create')->name('shop.products.store');
    /*
     * A price change is the most routine event in a shop and had no route at
     * all, so the only way to make one was a database edit or a duplicate SKU
     * that splits stock and sales history. `shop.inventory.edit` was being spent
     * entirely on stock adjustments.
     */
    Route::put('/shop/products/{product}', [InventoryController::class, 'update'])
        ->middleware('permission:shop.inventory.edit')->name('shop.products.update');
    Route::post('/shop/products/{product}/stock', [InventoryController::class, 'receiveStock'])
        ->middleware('permission:shop.inventory.create')->name('shop.products.stock');
    Route::post('/shop/products/{product}/adjustments', [InventoryController::class, 'adjustStock'])
        ->middleware('permission:shop.inventory.edit')->name('shop.products.adjust');

    Route::get('/shop/categories', [ProductCategoryController::class, 'index'])
        ->middleware('permission:shop.categories.view')->name('shop.categories.index');
    Route::post('/shop/categories', [ProductCategoryController::class, 'store'])
        ->middleware('permission:shop.categories.create')->name('shop.categories.store');
    Route::put('/shop/categories/{category}', [ProductCategoryController::class, 'update'])
        ->middleware('permission:shop.categories.edit')->name('shop.categories.update');
    // BR-25 — retire, never delete.
    Route::post('/shop/categories/{category}/retire', [ProductCategoryController::class, 'retire'])
        ->middleware('permission:shop.categories.delete')->name('shop.categories.retire');

    Route::get('/shop/sales', [SaleController::class, 'index'])
        ->middleware('permission:shop.sales.view')->name('shop.sales.index');
    Route::post('/shop/sales', [SaleController::class, 'store'])
        ->middleware('permission:shop.sales.create')->name('shop.sales.store');
    Route::get('/shop/sales/{sale}', [SaleController::class, 'show'])
        ->middleware('permission:shop.sales.view')->name('shop.sales.show');
    // Gives shop.sales.edit a real job — it was granted and checked by nothing.
    Route::post('/shop/sales/{sale}/void', [SaleController::class, 'void'])
        ->middleware('permission:shop.sales.edit')->name('shop.sales.void');

    /* ---------------------- Human resources ------------------------- */

    Route::middleware('permission:hr.employees.view')->group(function (): void {
        Route::get('/employees', [EmployeeController::class, 'index'])->name('employees.index');
        Route::get('/employees/{employee}', [EmployeeController::class, 'show'])->name('employees.show');
    });

    /*
     * hr.employees.create and .edit were live permissions that nothing checked —
     * the register was read-only and an employee could only arrive via the seeder,
     * so HR could not add the person they had just hired.
     */
    Route::post('/employees', [EmployeeController::class, 'store'])
        ->middleware('permission:hr.employees.create')->name('employees.store');
    Route::put('/employees/{employee}', [EmployeeController::class, 'update'])
        ->middleware('permission:hr.employees.edit')->name('employees.update');

    Route::middleware('permission:hr.employees.view')->group(function (): void {
        Route::get('/departments', [DepartmentController::class, 'index'])->name('departments.index');
        Route::get('/positions', [PositionController::class, 'index'])->name('positions.index');
    });

    // HR master data. Same grant as the employee register — the catalogue already
    // describes hr.employees as covering staff records AND departments.
    Route::middleware('permission:hr.employees.create')->group(function (): void {
        Route::post('/departments', [DepartmentController::class, 'store'])->name('departments.store');
        Route::post('/positions', [PositionController::class, 'store'])->name('positions.store');
    });

    Route::middleware('permission:hr.employees.edit')->group(function (): void {
        Route::put('/departments/{department}', [DepartmentController::class, 'update'])->name('departments.update');
        Route::put('/positions/{position}', [PositionController::class, 'update'])->name('positions.update');
    });

    Route::middleware('permission:hr.employees.view')->group(function (): void {});

    // §4 — `hr.leave.view` OR `hr.leave.own.view`.
    Route::get('/leave', [LeaveController::class, 'index'])
        ->middleware('permission:hr.leave.view|hr.leave.own.view')->name('leave.index');

    Route::post('/leave', [LeaveController::class, 'store'])
        ->middleware('permission:hr.leave.own.create|hr.leave.create')->name('leave.store');
    Route::post('/leave/{leaveRequest}/submit', [LeaveController::class, 'submit'])
        ->middleware('permission:hr.leave.own.create|hr.leave.create')->name('leave.submit');

    Route::get('/payroll', [PayrollController::class, 'index'])
        ->middleware('permission:hr.payroll.view')->name('payroll.index');
    Route::post('/payroll', [PayrollController::class, 'store'])
        ->middleware('permission:hr.payroll.create')->name('payroll.store');
    Route::post('/payroll/{payrollRun}/submit', [PayrollController::class, 'submit'])
        ->middleware('permission:hr.payroll.create')->name('payroll.submit');

    // §4 — `hr.payroll.view` OR own.
    Route::get('/payroll/payslips/{payslip}', [PayrollController::class, 'payslip'])
        ->middleware('permission:hr.payroll.view|hr.payslip.own.view')->name('payroll.payslips.show');

    /* -------------------------- Administration ---------------------- */

    Route::prefix('admin')->name('admin.')->group(function (): void {
        Route::middleware('permission:admin.users.view')->group(function (): void {
            Route::get('/users', [UserController::class, 'index'])->name('users.index');
            Route::get('/users/{user}', [UserController::class, 'show'])->name('users.show');
        });

        // BR-31 — creation sends a code; no password field exists anywhere here.
        Route::post('/users', [UserController::class, 'store'])
            ->middleware('permission:admin.users.create')->name('users.store');
        Route::put('/users/{user}', [UserController::class, 'update'])
            ->middleware('permission:admin.users.edit')->name('users.update');
        // BR-32 — deactivate, never delete.
        Route::post('/users/{user}/deactivate', [UserController::class, 'deactivate'])
            ->middleware('permission:admin.users.delete')->name('users.deactivate');
        Route::post('/users/{user}/reactivate', [UserController::class, 'reactivate'])
            ->middleware('permission:admin.users.edit')->name('users.reactivate');
        // AUTH-6 — the lock's counterpart: the administrator the lock email tells
        // the user to call needs a lever that is not deactivate-then-reactivate.
        Route::post('/users/{user}/unlock', [UserController::class, 'unlock'])
            ->middleware('permission:admin.users.edit')->name('users.unlock');
        Route::post('/users/{user}/devices/{device}/revoke', [UserController::class, 'revokeDevice'])
            ->middleware('permission:admin.users.edit')->name('users.devices.revoke');
        Route::post('/users/{user}/sign-out-everywhere', [UserController::class, 'signOutEverywhere'])
            ->middleware('permission:admin.users.edit')->name('users.sign-out-everywhere');
        Route::post('/users/{user}/send-activation', [UserController::class, 'sendActivation'])
            ->middleware('permission:admin.users.edit')->name('users.send-activation');
        /*
         * BR-31 — reset ANY user's password, not just a new hire's. The
         * administrator sets nothing: the old password stops working and the
         * holder is emailed a code to choose their own. Same permission as the
         * other credential levers on this screen (unlock, resend activation,
         * sign out everywhere), because it is the same job.
         */
        Route::post('/users/{user}/reset-password', [UserController::class, 'resetPassword'])
            ->middleware('permission:admin.users.edit')->name('users.reset-password');
        /*
         * BR-31, qualified — the administrator types a TEMPORARY password, for the
         * case an emailed code cannot answer: a user who cannot reach their mailbox
         * from where they are standing. The user must replace it at their next
         * sign-in (EnsureAccountIsUsable enforces that).
         *
         * This is the most dangerous route on the screen, and it is worth knowing
         * why it is not behind its own permission: `admin.users.edit` already
         * carries "change this account's e-mail address", and whoever holds that
         * plus send-activation could already take an unactivated account. A
         * separate grant would suggest a boundary the permission set does not
         * actually draw. If that changes — if a role appears that may edit users
         * but must never hold a colleague's password — split it then, and the
         * service's guards will not need touching.
         */
        Route::post('/users/{user}/set-password', [UserController::class, 'setPassword'])
            ->middleware('permission:admin.users.edit')->name('users.set-password');

        Route::post('/users/{user}/roles', [UserController::class, 'assignRole'])
            ->middleware('permission:admin.roles.edit')->name('users.roles.store');
        Route::delete('/users/{user}/roles/{assignment}', [UserController::class, 'removeRole'])
            ->middleware('permission:admin.roles.edit')->name('users.roles.destroy');

        Route::middleware('permission:admin.roles.view')->group(function (): void {
            Route::get('/roles', [RoleController::class, 'index'])->name('roles.index');
            Route::get('/roles/{role}', [RoleController::class, 'show'])->name('roles.show');
            Route::get('/personas', PersonaController::class)->name('personas');
        });

        Route::post('/roles', [RoleController::class, 'store'])
            ->middleware('permission:admin.roles.create')->name('roles.store');
        Route::put('/roles/{role}', [RoleController::class, 'update'])
            ->middleware('permission:admin.roles.edit')->name('roles.update');
        // TEST-5 — saving grants prompts for a passing test run; overrides are logged.
        Route::put('/roles/{role}/permissions', [RoleController::class, 'updatePermissions'])
            ->middleware('permission:admin.roles.edit')->name('roles.permissions');
        // ROLE-7 — a role with users can only be disabled.
        Route::post('/roles/{role}/disable', [RoleController::class, 'disable'])
            ->middleware('permission:admin.roles.delete')->name('roles.disable');

        Route::middleware('permission:admin.roles.edit')->group(function (): void {
            Route::get('/permission-tests', [PermissionTestController::class, 'index'])->name('permission-tests.index');
            Route::post('/permission-tests', [PermissionTestController::class, 'store'])->name('permission-tests.store');
            Route::post('/permission-tests/{run}/run', [PermissionTestController::class, 'run'])->name('permission-tests.run');
            Route::post('/permission-tests/{run}/complete', [PermissionTestController::class, 'complete'])->name('permission-tests.complete');
        });

        Route::get('/audit-log', AuditLogController::class)
            ->middleware('permission:admin.audit.view')->name('audit-log');

        Route::middleware('permission:admin.settings.edit')->group(function (): void {
            Route::get('/settings', [SettingsController::class, 'index'])->name('settings');
            Route::get('/settings/reference', [ReferenceDataController::class, 'index'])->name('reference.index');
            Route::put('/settings', [SettingsController::class, 'update'])->name('settings.update');
            Route::post('/settings/grades', [SettingsController::class, 'storeGradeRate'])->name('settings.grades.store');
            Route::put('/settings/rejection-reasons/{reason}', [SettingsController::class, 'updateRejectionReason'])->name('settings.reasons.update');
            Route::put('/settings/sequences/{sequence}', [SettingsController::class, 'updateSequence'])->name('settings.sequences.update');
            /*
             * §9 — "every value is a ROW an administrator edits through
             * Settings". Six registers rendered read-only, so adding a visit
             * type, an adjustment reason, a quality test or an LGA needed a
             * developer with database access. One screen, one registry.
             */
            Route::post('/settings/reference/{register}', [ReferenceDataController::class, 'store'])->name('reference.store');
            Route::put('/settings/reference/{register}/{id}', [ReferenceDataController::class, 'update'])->name('reference.update');

            Route::get('/settings/workflows', [SettingsController::class, 'workflows'])->name('settings.workflows');
            Route::post('/settings/workflows', [SettingsController::class, 'storeWorkflow'])->name('settings.workflows.store');
            Route::put('/settings/workflows/{workflow}', [SettingsController::class, 'updateWorkflow'])->name('settings.workflows.update');
            Route::post('/settings/workflows/{workflow}/stages', [SettingsController::class, 'storeWorkflowStage'])->name('settings.workflows.stages.store');
            Route::put('/settings/workflows/{workflow}/stages/{stage}', [SettingsController::class, 'updateWorkflowStage'])->name('settings.workflows.stages.update');
            Route::delete('/settings/workflows/{workflow}/stages/{stage}', [SettingsController::class, 'destroyWorkflowStage'])->name('settings.workflows.stages.destroy');
            Route::post('/settings/workflows/{workflow}/bands', [SettingsController::class, 'storeWorkflowBand'])->name('settings.workflows.bands.store');
            Route::put('/settings/workflows/{workflow}/bands/{band}', [SettingsController::class, 'updateWorkflowBand'])->name('settings.workflows.bands.update');
            Route::delete('/settings/workflows/{workflow}/bands/{band}', [SettingsController::class, 'destroyWorkflowBand'])->name('settings.workflows.bands.destroy');
        });
    });
});
