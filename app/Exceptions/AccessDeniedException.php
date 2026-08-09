<?php

namespace App\Exceptions;

use App\Http\Responses\AccessDeniedResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * SCR-1 / SCOPE-3 / BR-34 / AUDIT-5.
 *
 * One exception type covers both authorisation layers (ARCH-4): a missing
 * permission and a failed data-scope check produce the same 403, the same
 * populated access-denied screen, and the same audit entry. The only difference
 * is the human explanation.
 *
 * Never construct this directly — go through App\Authorization\Denials so the
 * audit entry and the quotable reference (DENY-####) are always written.
 */
class AccessDeniedException extends AuthorizationException
{
    public const REASON_PERMISSION = 'permission';

    public const REASON_SCOPE = 'scope';

    /**
     * @param  self::REASON_*  $reason
     * @param  array<string, mixed>  $detail
     */
    public function __construct(
        public readonly string $reason,
        public readonly ?string $permissionKey,
        public readonly string $reference,
        public readonly ?string $attemptedRoute = null,
        public readonly ?string $attemptedLabel = null,
        public readonly array $detail = [],
    ) {
        parent::__construct($reason === self::REASON_SCOPE
            ? 'Outside your data scope.'
            : 'Missing permission.');
    }

    public function isScopeFailure(): bool
    {
        return $this->reason === self::REASON_SCOPE;
    }

    /**
     * SCR-1 — "A 403 renders access-denied.html populated ... never a generic
     * error page."
     *
     * The exception renders itself rather than relying on a handler callback,
     * because the framework flattens an AuthorizationException into a plain
     * HttpException(403) BEFORE render callbacks are consulted — which would lose
     * the permission key, the scope and the quotable reference, and give exactly
     * the generic page SCR-1 forbids.
     */
    public function render(Request $request): JsonResponse|Response
    {
        return app(AccessDeniedResponse::class)->make($this, $request);
    }
}
