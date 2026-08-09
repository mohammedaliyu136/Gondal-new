<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * §6.9 / §9 — the settings row. Read through App\Support\Settings, never
 * directly, so that REF-1 (before/after auditing) cannot be bypassed.
 */
class Setting extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'key', 'value', 'group', 'label', 'help_text', 'value_type', 'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }
}
