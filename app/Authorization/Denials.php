<?php

namespace App\Authorization;

use App\Exceptions\AccessDeniedException;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Model;

/**
 * ARCH-4 / SCOPE-3 / BR-34 / AUDIT-5 — the single place a 403 is produced.
 *
 * Routing every denial through here is what makes SCOPE-3 true by construction:
 * "Passing the permission check but failing the scope check produces the same
 * 403 and the same access-denied.html as a missing permission, and is logged
 * identically."
 */
class Denials
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $detail
     */
    public function permission(
        ?User $user,
        string $permissionKey,
        ?string $attemptedLabel = null,
        array $detail = [],
    ): AccessDeniedException {
        return $this->record(
            AccessDeniedException::REASON_PERMISSION,
            $user,
            $permissionKey,
            $attemptedLabel,
            $detail,
        );
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    public function scope(
        ?User $user,
        string $permissionKey,
        ?Model $record = null,
        ?string $attemptedLabel = null,
        array $detail = [],
    ): AccessDeniedException {
        if ($record !== null) {
            $detail['record'] = class_basename($record).'#'.$record->getKey();
        }

        return $this->record(
            AccessDeniedException::REASON_SCOPE,
            $user,
            $permissionKey,
            $attemptedLabel,
            $detail,
        );
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    private function record(
        string $reason,
        ?User $user,
        ?string $permissionKey,
        ?string $attemptedLabel,
        array $detail,
    ): AccessDeniedException {
        $route = request()->route()?->uri();
        $attempted = $attemptedLabel ?? $this->labelForRoute();

        [, $reference] = $this->audit->blockedAccess(
            $user,
            $permissionKey,
            $reason,
            $route,
            $attempted,
            $detail,
        );

        return new AccessDeniedException(
            reason: $reason,
            permissionKey: $permissionKey,
            reference: $reference,
            attemptedRoute: $route,
            attemptedLabel: $attempted,
            detail: $detail,
        );
    }

    /**
     * SCR-1 — the "Page" cell of access-denied.html. The route's own name is the
     * most honest label available without a hardcoded route-to-title map.
     */
    private function labelForRoute(): ?string
    {
        $name = request()->route()?->getName();

        if ($name === null) {
            return request()->path();
        }

        return str($name)->replace('.', ' → ')->headline()->toString();
    }
}
