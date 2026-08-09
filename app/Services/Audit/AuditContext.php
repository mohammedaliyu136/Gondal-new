<?php

namespace App\Services\Audit;

use App\Models\User;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Str;

/**
 * AUDIT-4 / NFR-9 — the request facts every audit entry needs: source (web or
 * api), IP, request id, and whether the actor is a test user (TEST-4).
 *
 * Held as a singleton for the request so the request id is stable across every
 * entry written while handling it.
 */
class AuditContext
{
    private ?string $requestId = null;

    public function requestId(): string
    {
        return $this->requestId ??= (string) (Request::header('X-Request-Id') ?: Str::uuid());
    }

    public function setRequestId(string $id): void
    {
        $this->requestId = $id;
    }

    /**
     * AUDIT-4 — "Entries record `source` (`web` or `api`)".
     *
     * Two values, so a console or queue write has to be recorded as one of them;
     * `api` is the honest choice, since what it is NOT is a browser session.
     */
    public function source(): string
    {
        if (! $this->handlingHttpRequest()) {
            return 'api';
        }

        return Request::is('api/*') ? 'api' : 'web';
    }

    public function ip(): ?string
    {
        return $this->handlingHttpRequest() ? Request::ip() : null;
    }

    public function route(): ?string
    {
        return $this->handlingHttpRequest() ? Request::fullUrl() : null;
    }

    /**
     * Whether a real HTTP request is being handled.
     *
     * Deliberately NOT `runningInConsole()`, which reports the SAPI: under PHPUnit
     * that is `cli` even while the kernel is handling a request, so every entry
     * written by a test would be labelled `api` and the web/api distinction would
     * be untested — and untestable. A resolved route is the fact that actually
     * distinguishes the two: an artisan command or a queued job has none.
     */
    private function handlingHttpRequest(): bool
    {
        return app()->bound('request') && Request::route() !== null;
    }

    /** TEST-4 — every action taken as a test user is tagged. */
    public function isTest(?User $actor = null): bool
    {
        $actor ??= auth()->user();

        return $actor instanceof User && $actor->is_test;
    }
}
