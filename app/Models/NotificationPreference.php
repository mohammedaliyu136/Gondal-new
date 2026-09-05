<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** NOTIF-1 — per-user, per-event channel preferences. */
class NotificationPreference extends Model
{
    protected $fillable = ['user_id', 'event_type', 'in_app', 'email', 'sms', 'telegram'];

    protected function casts(): array
    {
        return [
            'in_app' => 'boolean',
            'email' => 'boolean',
            'sms' => 'boolean',
            'telegram' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
