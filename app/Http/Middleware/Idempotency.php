<?php

namespace App\Http\Middleware;

use App\Models\IdempotencyKey;
use App\Support\Wat;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ARCH-7 — "All write endpoints accept an optional Idempotency-Key header;
 * replays return the original result. Required for unreliable-connectivity
 * capture."
 *
 * ARCH-2 / NG-3 — this is what lets a field client retry a delivery it is not
 * sure landed, without creating a second one. Mobile is out of scope for v1, but
 * the API must not preclude it.
 *
 * A replay with the SAME key but a DIFFERENT body is a client bug, not a retry,
 * and is refused with 422 rather than silently returning someone else's result.
 */
class Idempotency
{
    private const WRITE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function handle(Request $request, Closure $next): Response
    {
        $header = (string) config('gondal.idempotency.header', 'Idempotency-Key');
        $key = $request->header($header);

        if ($key === null || $key === '' || ! in_array($request->method(), self::WRITE_METHODS, true)) {
            return $next($request);
        }

        $fingerprint = hash('sha256', $request->method().'|'.$request->path().'|'.$request->getContent());

        $record = IdempotencyKey::query()->firstOrCreate(
            ['key' => $key, 'user_id' => $request->user()?->getKey()],
            [
                'method' => $request->method(),
                'path' => $request->path(),
                'request_fingerprint' => $fingerprint,
            ],
        );

        if ($record->request_fingerprint !== $fingerprint) {
            return response()->json([
                'message' => 'This Idempotency-Key was already used for a different request.',
                'rule' => 'ARCH-7',
            ], 422);
        }

        // The replay of a completed write returns the original result verbatim.
        if ($record->isComplete()) {
            return response(
                (string) $record->response_body,
                (int) $record->response_status,
            )->header('Content-Type', 'application/json')
                ->header('Idempotent-Replay', 'true');
        }

        $response = $next($request);

        if ($response->getStatusCode() < 500) {
            $record->forceFill([
                'response_status' => $response->getStatusCode(),
                'response_body' => $response->getContent(),
                'completed_at' => Wat::now(),
            ])->save();
        }

        return $response;
    }
}
