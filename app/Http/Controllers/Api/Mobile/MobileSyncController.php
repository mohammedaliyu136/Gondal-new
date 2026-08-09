<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Api\ApiController;
use App\Models\User;
use App\Services\Mobile\MobileSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `POST /api/v1/sync/batch` — the offline queue arriving.
 *
 * The response is shaped for a client that must reconcile row by row:
 * `results.<type>[]` carries `client_uuid` → `db_id` for what landed, and
 * `results.errors[]` carries `{type, client_uuid, error}` for what did not. A
 * record that is neither is a bug, not a silence — every record the client sent
 * appears in exactly one of the two lists.
 *
 * Note there is no per-type permission middleware on the route. A batch is
 * mixed by nature, and gating the whole request on one permission would reject
 * five valid records because a sixth was not allowed. Authorisation is per
 * record, inside the service, with the record in hand — ARCH-4 layer 2.
 */
class MobileSyncController extends ApiController
{
    public function __construct(private readonly MobileSyncService $sync) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'farmer_registrations' => ['sometimes', 'array', 'max:500'],
            'farmer_validations' => ['sometimes', 'array', 'max:500'],
            'milk_collections' => ['sometimes', 'array', 'max:500'],
            'oss_sales' => ['sometimes', 'array', 'max:500'],
            'field_visits' => ['sometimes', 'array', 'max:500'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $outcome = $this->sync->handle($user, $validated, $request);

        return response()->json([
            'is_success' => $outcome['rejected'] === 0,
            'accepted' => $outcome['accepted'],
            'rejected' => $outcome['rejected'],
            'results' => $outcome['results'],
        ]);
    }
}
