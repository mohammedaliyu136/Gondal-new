<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * NOTIF-2 / NOTIF-3 — the seeded event catalogue.
 *
 * `required_permission` is what makes "a user is never notified about something
 * they could not open" enforceable from data rather than from a switch statement
 * that would drift from the permission catalogue.
 */
class NotificationEvent extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'code', 'name', 'description', 'module', 'required_permission',
        'default_in_app', 'default_email', 'default_sms', 'status', 'position',
    ];

    protected function casts(): array
    {
        return [
            'default_in_app' => 'boolean',
            'default_email' => 'boolean',
            'default_sms' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }
}
