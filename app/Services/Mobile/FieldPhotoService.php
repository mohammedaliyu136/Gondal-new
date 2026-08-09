<?php

namespace App\Services\Mobile;

use App\Authorization\Access;
use App\Exceptions\RuleViolationException;
use App\Models\Attachment;
use App\Models\FarmerValidation;
use App\Models\FieldActivity;
use App\Models\MobileSyncRecord;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Wat;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The photograph a field worker took, arriving separately from the record.
 *
 * WHY SEPARATELY. The sync batch is JSON and may carry five hundred records; a
 * photo is a megabyte or two over a connection that exists for ninety seconds
 * at the top of a hill. Base64-ing images into that batch would mean one large
 * upload that either wholly succeeds or wholly fails, and a phone that cannot
 * sync twenty text records because one photo keeps timing out. So the record
 * syncs first and cheaply, and each photo follows as its own request that can
 * fail and be retried on its own.
 *
 * HOW A PHOTO FINDS ITS RECORD. By `client_uuid` — the id the PHONE generated
 * before either was sent. `mobile_sync_records` already maps
 * (user, client_uuid) → the row that was written, precisely so a client can
 * reconcile after the fact. A photo for a record that has not synced yet is
 * refused with a distinct, retryable answer rather than an error: the phone
 * simply has its queue in the wrong order, and it should send the record and
 * come back.
 *
 * SCOPE. Resolution is keyed on the uploading user's OWN sync records, so a
 * client_uuid guessed or copied from another agent resolves to nothing. The
 * subject is then authorised a second time with the record in hand (ARCH-4
 * layer 2), because a sync record proves who wrote a row, not that they may
 * still touch it.
 */
class FieldPhotoService
{
    /**
     * What a phone camera produces, at the quality this is worth storing at.
     * A field photo is evidence that a visit happened and what was seen — not
     * a print. The client is expected to downscale before sending; this is the
     * backstop, not the target.
     */
    public const MAX_BYTES = 8 * 1024 * 1024;

    public const ACCEPTED_MIME = ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif'];

    /**
     * The record types a photo may be attached to, and the permission that
     * governs each. Anything not on this list is refused — an allow-list, so
     * adding a new attachable type is a deliberate act rather than a
     * consequence of some other model gaining a sync handler.
     */
    private const ATTACHABLE = [
        FieldActivity::class => 'community.extension.create',
        FarmerValidation::class => 'community.farmers.validate',
    ];

    public function __construct(
        private readonly Access $access,
        private readonly AuditLogger $audit,
    ) {}

    public function store(User $user, string $clientUuid, UploadedFile $photo, ?string $caption = null): Attachment
    {
        $subject = $this->resolveSubject($user, $clientUuid);

        $this->access->authorize(
            $user,
            self::ATTACHABLE[$subject::class],
            $subject,
            'Mobile: attach a field photo',
        );

        $this->guardFile($photo);

        /*
         * The name the row will carry. Resolved ONCE, because it is both what
         * gets stored and what the retry check matches on.
         *
         * These were two different expressions before: the row stored the
         * caption when one was given, while the lookup compared the uploaded
         * file's original name. For every captioned upload — which is every
         * upload the app makes — they could not match, so the "already have
         * this" branch never fired and each retry stored another copy. A live
         * retry returned a second id; the unit test missed it because it
         * uploaded without a caption, which is the one case where the two
         * expressions agree.
         */
        $displayName = $caption ?: ($photo->getClientOriginalName() ?: 'field-photo.jpg');

        /*
         * ARCH-7 — the phone retries. A second upload of the same photo for the
         * same record answers with the first rather than storing a duplicate;
         * matching on size as well as name is what distinguishes a retry from
         * two genuinely different pictures that happen to share a name.
         */
        $existing = Attachment::query()
            ->where('attachable_type', $subject::class)
            ->where('attachable_id', $subject->getKey())
            ->where('filename', $displayName)
            ->where('size_bytes', $photo->getSize())
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $today = Wat::today();

        // Private disk. A field photo can show a household and its herd, and
        // nothing about it belongs on a public URL.
        $path = $photo->storeAs(
            sprintf('field-photos/%s/%s', $today->format('Y'), $today->format('m')),
            Str::uuid()->toString().'.'.($photo->guessExtension() ?: 'jpg'),
            'local',
        );

        $attachment = Attachment::query()->create([
            'attachable_type' => $subject::class,
            'attachable_id' => $subject->getKey(),
            'filename' => $displayName,
            'path' => $path,
            'size_bytes' => $photo->getSize(),
            'mime' => $photo->getMimeType(),
            'uploaded_by_user_id' => $user->getKey(),
        ]);

        $this->audit->created(
            $attachment,
            sprintf('Field photo attached to %s from AgentConnect',
                $subject->reference ?? ($subject::class.' #'.$subject->getKey())),
            'Community Engagement',
            ['client_uuid' => $clientUuid, 'bytes' => $photo->getSize(), 'source' => 'mobile'],
            $user,
        );

        return $attachment;
    }

    /**
     * @throws RuleViolationException when the record has not synced yet
     */
    private function resolveSubject(User $user, string $clientUuid): Model
    {
        $record = MobileSyncRecord::query()
            ->where('user_id', $user->getKey())
            ->where('client_uuid', $clientUuid)
            ->first();

        if ($record === null || $record->subject_type === null || $record->subject_id === null) {
            throw RuleViolationException::make(
                'ARCH-7',
                'That record has not reached the server yet. Sync it first, then send the photo.',
                ['client_uuid' => $clientUuid],
                'client_uuid',
            );
        }

        if (! array_key_exists((string) $record->subject_type, self::ATTACHABLE)) {
            throw RuleViolationException::make(
                'ARCH-7',
                'Photographs cannot be attached to that kind of record.',
                ['client_uuid' => $clientUuid, 'type' => $record->subject_type],
                'client_uuid',
            );
        }

        $subject = ($record->subject_type)::query()->find($record->subject_id);

        if ($subject === null) {
            throw RuleViolationException::make(
                'ARCH-7',
                'The record this photo belongs to no longer exists.',
                ['client_uuid' => $clientUuid],
                'client_uuid',
            );
        }

        return $subject;
    }

    private function guardFile(UploadedFile $photo): void
    {
        if ($photo->getSize() > self::MAX_BYTES) {
            throw RuleViolationException::make(
                'NFR-2',
                sprintf('That photo is larger than %d MB. The app should shrink it before sending.',
                    (int) (self::MAX_BYTES / 1024 / 1024)),
                ['bytes' => $photo->getSize()],
                'photo',
            );
        }

        /*
         * The declared MIME is checked against what the FILE actually is, not
         * against its extension — an upload endpoint that trusts the name it
         * was given is how a .jpg becomes a .php.
         */
        if (! in_array((string) $photo->getMimeType(), self::ACCEPTED_MIME, true)) {
            throw RuleViolationException::make(
                'NFR-2',
                'That file is not a photograph.',
                ['mime' => $photo->getMimeType()],
                'photo',
            );
        }
    }
}
