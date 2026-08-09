<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Api\ApiController;
use App\Models\User;
use App\Services\Mobile\FieldPhotoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `POST /api/v1/attachments` — a field photograph arriving on its own.
 *
 * Deliberately not part of `/sync/batch`: see FieldPhotoService's docblock on
 * why an image does not belong in a five-hundred-record JSON envelope.
 *
 * No permission middleware on the route, for the same reason the sync batch has
 * none — which permission applies depends on what the photo is attached to, and
 * that is not known until the client_uuid has been resolved. The service
 * authorises with the record in hand.
 */
class MobileAttachmentController extends ApiController
{
    public function __construct(private readonly FieldPhotoService $photos) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'client_uuid' => ['required', 'string', 'max:36'],
            'photo' => ['required', 'file'],
            'caption' => ['nullable', 'string', 'max:120'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $attachment = $this->photos->store(
            $user,
            $validated['client_uuid'],
            $request->file('photo'),
            $validated['caption'] ?? null,
        );

        return $this->ok([
            'id' => $attachment->getKey(),
            'client_uuid' => $validated['client_uuid'],
            'filename' => $attachment->filename,
            'size_bytes' => $attachment->size_bytes,
            'mime' => $attachment->mime,
        ], 201);
    }
}
