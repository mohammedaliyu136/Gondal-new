<?php

namespace App\Http\Responses;

use App\Authorization\PermissionKey;
use App\Exceptions\AccessDeniedException;
use App\Models\Permission;
use App\Models\User;
use App\Support\Wat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * SCR-1 — "A 403 renders access-denied.html populated with the attempted route,
 * the permission that was missing, the user's role and their data scope — never
 * a generic error page."
 *
 * AUDIT-5 — the page shows the reference the user can quote (DENY-####).
 * SCOPE-3 — a scope failure renders the same page; only the sentence changes.
 */
class AccessDeniedResponse
{
    public function make(AccessDeniedException $exception, Request $request): JsonResponse|Response
    {
        /** @var User|null $user */
        $user = $request->user();

        $permission = $exception->permissionKey === null
            ? null
            : $this->resolvePermission($exception->permissionKey);

        $payload = [
            'reason' => $exception->reason,
            'permission_key' => $exception->permissionKey,
            'permission_label' => $permission?->label,
            'permission_action' => $permission?->action
                ?? PermissionKey::tryParse((string) $exception->permissionKey)?->action,
            'attempted_route' => $exception->attemptedRoute,
            'attempted_label' => $exception->attemptedLabel,
            'reference' => $exception->reference,
            'roles' => $user?->effectiveRoles()->pluck('name')->values()->all() ?? [],
            'primary_role' => $user?->primaryRoleLabel(),
            'data_scope' => $user?->overallScopeDescription(),
            'occurred_at' => Wat::dateTime(Wat::now()),
        ];

        if ($request->is('api/*') || $request->expectsJson()) {
            return response()->json([
                'message' => $exception->isScopeFailure()
                    ? 'This record is outside your data scope.'
                    : 'You do not have permission to do this.',
                'rule' => $exception->isScopeFailure() ? 'SCOPE-3' : 'BR-34',
                'denial' => $payload,
            ], 403);
        }

        return response()->view('errors.access-denied', [
            'denial' => $payload,
            'isScopeFailure' => $exception->isScopeFailure(),
        ], 403);
    }

    /**
     * The permission row gives the human label the screen shows next to the key,
     * so the page reads "hr.payroll (view)" and "Payroll and salaries" rather
     * than only a machine key.
     */
    private function resolvePermission(string $key): ?Permission
    {
        $parsed = PermissionKey::tryParse($key);

        if ($parsed === null) {
            return null;
        }

        return Permission::query()
            ->where('resource_key', $parsed->resourceKey)
            ->where('action', $parsed->action)
            ->first();
    }
}
