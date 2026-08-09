<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * §6.9 notifications — the in-app row.
 *
 * Named AppNotification rather than Notification so it cannot be confused with
 * the framework's notification classes, which are what actually deliver email
 * and SMS (NOTIF-5: always queued).
 */
class AppNotification extends Model
{
    use SoftDeletes;

    protected $table = 'notifications';

    protected $fillable = [
        'user_id', 'type', 'title', 'body', 'action_url',
        'channel_flags', 'subject_type', 'subject_id', 'read_at',
    ];

    protected function casts(): array
    {
        return [
            'channel_flags' => 'array',
            'read_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function isUnread(): bool
    {
        return $this->read_at === null;
    }

    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }
}
