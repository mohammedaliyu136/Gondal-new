<?php

namespace App\Models;

use App\Models\Concerns\RecordsActor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** §6.4 comments — the discussion thread on any record. */
class Comment extends Model
{
    use RecordsActor;
    use SoftDeletes;

    protected $fillable = ['commentable_type', 'commentable_id', 'body', 'created_by_user_id'];

    public function commentable(): MorphTo
    {
        return $this->morphTo();
    }
}
