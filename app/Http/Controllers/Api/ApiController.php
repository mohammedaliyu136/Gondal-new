<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * ARCH-2 — "API-first. Controllers return JSON via API resources; the web UI
 * consumes them. Field data capture is a near-term requirement, and retrofitting
 * an API is more expensive than starting with one."
 *
 * Every endpoint under this namespace shares three properties with its web
 * counterpart, because both call the same services:
 *
 *   · the same business rules (a 422 with the rule ID, ST-1)
 *   · the same authorisation, both layers (a 403 with the missing permission and
 *     the quotable reference, BR-34 / SCR-1)
 *   · the same audit trail (AUDIT-4 tags `source` as `api`)
 *
 * ARCH-7 — every write accepts Idempotency-Key; the middleware is global.
 *
 * NG-3 — mobile applications are deferred. The API is authenticated by the
 * session guard, which is what the web UI uses. When mobile arrives, a token
 * guard is added to config/auth.php and these routes gain it — no controller or
 * service here has to change, which is the point of ARCH-2.
 */
abstract class ApiController extends Controller
{
    /**
     * @param  array<string, mixed>  $extra
     */
    protected function ok(mixed $data, int $status = 200, array $extra = []): JsonResponse
    {
        return response()->json(array_merge(['data' => $data], $extra), $status);
    }
}
