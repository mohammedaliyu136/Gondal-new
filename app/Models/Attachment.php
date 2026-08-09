<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** §6.4 attachments — shared by requisitions, leave requests, activities. */
class Attachment extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'attachable_type', 'attachable_id', 'filename', 'path',
        'size_bytes', 'mime', 'uploaded_by_user_id',
    ];

    protected function casts(): array
    {
        return ['size_bytes' => 'integer'];
    }

    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    public function humanSize(): string
    {
        $bytes = (int) $this->size_bytes;

        return match (true) {
            $bytes >= 1_048_576 => round($bytes / 1_048_576, 1).' MB',
            $bytes >= 1_024 => round($bytes / 1_024).' KB',
            default => $bytes.' B',
        };
    }
}
