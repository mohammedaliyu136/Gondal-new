<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * ARCH-7 — the ledger of what each `client_uuid` from a phone became.
 */
class MobileSyncRecord extends Model
{
    protected $fillable = ['user_id', 'client_uuid', 'record_type', 'subject_type', 'subject_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
