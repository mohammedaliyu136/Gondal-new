<?php

use App\Exceptions\RuleViolationException;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\CloseModalAfterWrite;
use App\Http\Middleware\EnsureAccountIsUsable;
use App\Http\Middleware\Idempotency;
use App\Http\Middleware\RequireApprovalQueueAccess;
use App\Http\Middleware\RequirePermission;
use App\Http\Middleware\TouchAuthSession;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\AuthenticateSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        // ARCH-2 — API-first. The web UI consumes the same behaviour; these
        // routes exist from day one so field capture is not a retrofit.
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // NFR-9 — a request id on every request, echoed to the client and
        // attached to every audit entry and log line.
        $middleware->prepend(AssignRequestId::class);

        // ARCH-7 — idempotent writes on both surfaces.
        $middleware->api(append: [Idempotency::class]);
        $middleware->web(append: [Idempotency::class]);

        // Closes the :target modal a write was submitted from — see the class for
        // why the browser reopens it otherwise. Web only; the API has no modals.
        $middleware->web(append: [CloseModalAfterWrite::class]);

        $middleware->alias([
            'permission' => RequirePermission::class,
            // §4 — the approval queue admits any workflow-stage approver, which
            // is read from the stages rather than listed here. See the class.
            'approval-queue' => RequireApprovalQueueAccess::class,
            'account.usable' => EnsureAccountIsUsable::class,
            'session.touch' => TouchAuthSession::class,
            // BR-33 — invalidates other sessions when the password hash changes.
            'session.authenticate' => AuthenticateSession::class,
        ]);

        // NFR-7 — CSRF applies to every web write. No exclusions.
        $middleware->validateCsrfTokens(except: []);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // ARCH-2 — the API always answers in JSON.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        /*
         * SCR-1 / SCOPE-3 — a missing permission and a failed scope check both
         * render the populated access-denied screen, and both were already written
         * to the audit log when the exception was constructed
         * (App\Authorization\Denials). AccessDeniedException renders ITSELF rather
         * than through a callback here — see the note on that class for why a
         * callback arrives too late.
         *
         * ST-1 — an illegal transition or violated rule is a 422 carrying the rule
         * ID. RuleViolationException also renders itself; the callback below is
         * kept only so a violation raised outside a request still formats cleanly.
         */
        $exceptions->render(function (RuleViolationException $exception, Request $request) {
            return $exception->render($request);
        });

        // AUTH-8 — there is no self-registration, so an unauthenticated request
        // always goes to sign-in rather than anywhere else.
        $exceptions->render(function (AuthenticationException $exception, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }

            return redirect()->guest(route('login'));
        });
    })->create();
