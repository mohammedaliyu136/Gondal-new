<?php

namespace App\Models\Concerns;

use App\Exceptions\RuleViolationException;

/**
 * NFR-4 — "Concurrency: confirming an already-confirmed consignment must fail,
 * not overwrite. Use optimistic locking on consignments and batches."
 *
 * The caller reads a record (capturing lock_version), then saves through
 * saveWithLock(). If another request moved the row in between, the update
 * matches zero rows and we refuse rather than silently clobbering.
 */
trait OptimisticLocking
{
    /**
     * @throws RuleViolationException when the row changed underneath us
     */
    public function saveWithLock(): bool
    {
        if (! $this->exists) {
            return $this->save();
        }

        $expected = (int) $this->getOriginal('lock_version', 0);

        $this->lock_version = $expected + 1;

        $attributes = collect($this->getDirty())
            ->except($this->getKeyName())
            ->all();

        if ($attributes === []) {
            return true;
        }

        $affected = static::query()
            ->withoutGlobalScopes()
            ->whereKey($this->getKey())
            ->where('lock_version', $expected)
            ->update($attributes);

        if ($affected === 0) {
            throw RuleViolationException::make(
                'NFR-4',
                'This record was changed by someone else while you were working on it. Reload and try again.',
                [
                    'model' => class_basename($this),
                    'id' => $this->getKey(),
                    'expected_version' => $expected,
                ],
            );
        }

        $this->syncOriginal();

        return true;
    }
}
