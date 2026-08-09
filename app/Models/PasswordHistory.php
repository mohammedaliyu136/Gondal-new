<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AUTH-5 — "not among the user's last 3 passwords".
 */
class PasswordHistory extends Model
{
    public $timestamps = false;

    protected $fillable = ['user_id', 'password_hash', 'created_at'];

    protected $hidden = ['password_hash'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
