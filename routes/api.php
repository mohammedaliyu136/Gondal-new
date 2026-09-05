<?php

use App\Http\Controllers\Api\ApprovalApiController;
use App\Http\Controllers\Api\ConsignmentApiController;
use App\Http\Controllers\Api\DeliveryApiController;
use App\Http\Controllers\Api\Mobile\MobileAccessController;
use App\Http\Controllers\Api\Mobile\MobileAttachmentController;
use App\Http\Controllers\Api\Mobile\MobileAuthController;
use App\Http\Controllers\Api\Mobile\MobileFieldDataController;
use App\Http\Controllers\Api\Mobile\MobileSyncController;
use App\Http\Controllers\Api\Mobile\MobileValidationController;
use App\Http\Controllers\Api\ReferenceDataApiController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API routes — ARCH-2
|--------------------------------------------------------------------------
|
| "API-first. Controllers return JSON via API resources; the web UI consumes
|  them. Field data capture is a near-term requirement, and retrofitting an API
|  is more expensive than starting with one."
|
| These endpoints share their services, business rules, authorisation and audit
| trail with the web screens. The only differences are the representation (API
| resources) and `source: api` on every audit entry (AUDIT-4).
|
| Authentication: the `web` group is applied so the session cookie authenticates,
| which is what the web UI uses and keeps NFR-7 (CSRF on every write) intact.
|
| The mobile client arrived, and the promise held. The `/v1` group at the bottom
| of this file authenticates with the `api` token guard now listed in
| config/auth.php, and NOTHING above it changed — same services, same permission
| and scope layers, same audit trail. That was the whole point of ARCH-2.
|
| ARCH-7 — every write accepts `Idempotency-Key`; the middleware is global.
| NFR-8 — auth-adjacent and write endpoints are rate limited.
*/

Route::middleware(['web', 'auth', 'account.usable'])->group(function (): void {

    // §9 — reference data, so a client never ships its own copy of grades,
    // reasons or thresholds that would drift from Settings (§18.7).
    Route::get('/reference-data', ReferenceDataApiController::class)
        ->middleware('permission:milk.deliveries.view|shop.inventory.view|community.extension.view')
        ->name('api.reference-data');

    /* --------------------------- Milk flow --------------------------- */

    Route::get('/deliveries', [DeliveryApiController::class, 'index'])
        ->middleware('permission:milk.deliveries.view')->name('api.deliveries.index');
    Route::get('/deliveries/{delivery}', [DeliveryApiController::class, 'show'])
        ->middleware('permission:milk.deliveries.view')->name('api.deliveries.show');

    Route::get('/capture-context', [DeliveryApiController::class, 'captureContext'])
        ->middleware('permission:milk.deliveries.create')->name('api.capture-context');

    Route::post('/deliveries', [DeliveryApiController::class, 'store'])
        ->middleware(['permission:milk.deliveries.create', 'throttle:120,1'])
        ->name('api.deliveries.store');

    Route::get('/consignments', [ConsignmentApiController::class, 'index'])
        ->middleware('permission:milk.consignment.confirm.view')->name('api.consignments.index');
    Route::post('/consignments', [ConsignmentApiController::class, 'store'])
        ->middleware(['permission:milk.consignment.confirm.create', 'throttle:60,1'])
        ->name('api.consignments.store');
    Route::post('/consignments/{consignment}/quality-tests', [ConsignmentApiController::class, 'storeQualityTest'])
        ->middleware('permission:milk.grade.create')->name('api.consignments.quality-tests');
    Route::post('/consignments/{consignment}/confirm', [ConsignmentApiController::class, 'confirm'])
        ->middleware('permission:milk.consignment.confirm.edit')->name('api.consignments.confirm');

    /* --------------------------- Approvals --------------------------- */

    // §4 — any workflow-stage approver, exactly as the web route decides it.
    // The API must not admit a different set of people from the screen.
    Route::middleware('approval-queue')->group(function (): void {
        Route::get('/approvals', [ApprovalApiController::class, 'index'])->name('api.approvals.index');
        Route::post('/approvals/{instance}/approve', [ApprovalApiController::class, 'approve'])->name('api.approvals.approve');
        Route::post('/approvals/{instance}/reject', [ApprovalApiController::class, 'reject'])->name('api.approvals.reject');
        Route::post('/approvals/{instance}/request-info', [ApprovalApiController::class, 'requestInfo'])->name('api.approvals.request-info');
    });
});

/*
|--------------------------------------------------------------------------
| /api/v1 — the mobile surface (AgentConnect)
|--------------------------------------------------------------------------
|
| Same rules, different credential. Every route below resolves a bearer token to
| a User through the `api` guard and then meets the identical RequirePermission
| middleware, Access checks and services as the web screens.
|
| AUTH-8 — there is no register endpoint, and there never will be.
| NFR-8 — the sign-in pair is rate limited per IP; SigninThrottle holds the
|         per-account rule (AUTH-6) inside the service.
*/
Route::prefix('v1')->group(function (): void {

    /* ------------------------------ §10 sign-in ------------------------------ */

    Route::post('/auth/login', [MobileAuthController::class, 'login'])
        ->middleware('throttle:10,1')->name('api.mobile.login');

    // AUTH-1 step 2. Throttled harder than the password step: this one is a
    // 6-digit number, and AUTH-3's five-attempt rule is per code, not per minute.
    Route::post('/auth/verify', [MobileAuthController::class, 'verify'])
        ->middleware('throttle:10,1')->name('api.mobile.verify');

    Route::middleware(['auth:api', 'account.usable'])->group(function (): void {

        Route::post('/auth/logout', [MobileAuthController::class, 'logout'])->name('api.mobile.logout');

        /*
         * §16 — who the signed-in user is, what their job is, and what they may
         * do. The client's home screen and every gate in it are built from this.
         *
         * No permission middleware: every authenticated user may read their OWN
         * roles and responsibilities. That is not a privileged fact — it is the
         * one thing they most need to know, and a user who cannot see their own
         * job description is the previous system's problem, not a safeguard.
         */
        Route::get('/agent/permissions', MobileAccessController::class)->name('api.mobile.access');
        Route::get('/me', MobileAccessController::class)->name('api.mobile.me');

        Route::get('/agent/form-options', [MobileFieldDataController::class, 'formOptions'])
            ->name('api.mobile.form-options');

        Route::get('/farmers/search', [MobileFieldDataController::class, 'searchFarmers'])
            ->middleware('permission:community.farmers.view')->name('api.mobile.farmers.search');

        /*
         * BR-36 — the revalidation queue M&E assigned to this field worker.
         * Gated on the act, not on the queue: the people who DO validations
         * hold `community.farmers.validate`, while `community.validation.view`
         * belongs to the people who manage them.
         */
        Route::get('/validations', MobileValidationController::class)
            ->middleware('permission:community.farmers.validate')->name('api.mobile.validations');

        Route::get('/oss/catalog', [MobileFieldDataController::class, 'catalog'])
            ->middleware('permission:shop.inventory.view|shop.sales.view')->name('api.mobile.oss.catalog');

        /*
         * The offline queue. Deliberately NOT gated on a single permission — a
         * batch is mixed, and each record is authorised individually inside the
         * service with the record in hand (ARCH-4 layer 2). Gating here would
         * reject a valid delivery because the same batch carried a sale.
         */
        Route::post('/sync/batch', MobileSyncController::class)
            ->middleware('throttle:60,1')->name('api.mobile.sync');

        /*
         * A field photograph, following the record it belongs to. Separate from
         * the batch above because an image is a megabyte over a connection that
         * exists for ninety seconds, and one photo timing out must not hold up
         * twenty text records. Throttled lower than the batch: each request is
         * far heavier, and a phone with a day's photos should trickle them.
         */
        Route::post('/attachments', MobileAttachmentController::class)
            ->middleware('throttle:30,1')->name('api.mobile.attachments');
    });
});

/* -------------------- Public Webhook Endpoints -------------------- */
Route::post('/telegram/webhook', [\App\Http\Controllers\Api\TelegramWebhookController::class, 'handle'])
    ->name('api.telegram.webhook');

