<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * §6 conventions — "Every table recording an action has created_by_user_id."
 *
 * TEST-4 / BR-35 — a model that declares $tagsTestActivity also inherits the
 * acting user's `is_test` flag, so aggregates can exclude test activity with one
 * indexed predicate instead of a join back to `users` on every report.
 */
trait RecordsActor
{
    public static function bootRecordsActor(): void
    {
        static::creating(function (Model $model): void {
            $actor = auth()->user();

            if ($actor instanceof User) {
                if ($model->getAttribute('created_by_user_id') === null) {
                    $model->setAttribute('created_by_user_id', $actor->getKey());
                }

                if (($model->tagsTestActivity ?? false) && $model->getAttribute('is_test') === null) {
                    $model->setAttribute('is_test', $actor->is_test);
                }
            }

            // A model that tags test activity must never leave the flag null.
            if (($model->tagsTestActivity ?? false) && $model->getAttribute('is_test') === null) {
                $model->setAttribute('is_test', false);
            }
        });
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
