<?php

namespace App\Policies;

use App\Authorization\Access;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * SCOPE-2, second layer — "plus a policy check on every single-record read and
 * write. Both layers are required: the global scope prevents accidental leakage
 * in lists, the policy prevents direct-ID access."
 *
 * Policies answer questions (they return bool so `@can` works in Blade); they do
 * not throw. The throwing gate is Access::authorize(), which controllers call so
 * the denial is audited and the access-denied page is populated.
 *
 * Every policy is a two-line subclass naming its resource key, because the
 * action mapping is identical everywhere: view→view, create→create,
 * update→edit, delete→delete, approve→approve.
 */
abstract class BasePolicy
{
    public function __construct(protected readonly Access $access) {}

    /** The §5.1 resource key this policy governs, e.g. 'milk.deliveries'. */
    abstract protected function resourceKey(): string;

    public function viewAny(User $user): bool
    {
        return $this->access->allows($user, $this->permission('view'));
    }

    public function view(User $user, Model $model): bool
    {
        return $this->access->allows($user, $this->permission('view'), $model);
    }

    public function create(User $user): bool
    {
        return $this->access->allows($user, $this->permission('create'));
    }

    public function update(User $user, Model $model): bool
    {
        return $this->access->allows($user, $this->permission('edit'), $model);
    }

    /** ARCH-8 — a "delete" is always a soft delete. */
    public function delete(User $user, Model $model): bool
    {
        return $this->access->allows($user, $this->permission('delete'), $model);
    }

    public function restore(User $user, Model $model): bool
    {
        return $this->access->allows($user, $this->permission('delete'), $model);
    }

    /** ARCH-8 — nothing operational is ever hard-deleted. */
    public function forceDelete(User $user, Model $model): bool
    {
        return false;
    }

    public function approve(User $user, Model $model): bool
    {
        return $this->access->allows($user, $this->permission('approve'), $model);
    }

    protected function permission(string $action): string
    {
        return $this->resourceKey().'.'.$action;
    }
}
