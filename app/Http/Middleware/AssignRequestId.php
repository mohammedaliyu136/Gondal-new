<?php

namespace App\Http\Middleware;

use App\Services\Audit\AuditContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * NFR-9 — "Structured logging with request IDs. No credentials, codes or tokens
 * in logs." The id is echoed back on the response so a user can quote it, and it
 * lands on every audit entry written while handling the request.
 */
class AssignRequestId
{
    public function __construct(private readonly AuditContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $requestId = (string) ($request->header('X-Request-Id') ?: Str::uuid());

        $this->context->setRequestId($requestId);
        $request->headers->set('X-Request-Id', $requestId);

        Log::shareContext([
            'request_id' => $requestId,
            'user_id' => $request->user()?->getKey(),
        ]);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }
}
