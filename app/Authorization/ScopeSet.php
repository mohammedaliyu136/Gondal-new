<?php

namespace App\Authorization;

use Illuminate\Contracts\Support\Arrayable;
use IteratorAggregate;
use Traversable;

/**
 * ROLE-2 + SCOPE-1 — a user may hold several roles, each with its own scope.
 * The scopes that apply to a given permission are the scopes of every
 * assignment whose role grants that permission. They combine as a union:
 * a record is in scope if ANY of them admits it.
 *
 * An empty set means "no grant at all" and must deny everything — absence of a
 * grant is the denial (ROLE-2). It never degrades to network access.
 *
 * @implements IteratorAggregate<int, Scope>
 * @implements Arrayable<int, array<string, mixed>>
 */
final class ScopeSet implements Arrayable, IteratorAggregate
{
    /** @var array<int, Scope> */
    private array $scopes;

    public function __construct(Scope ...$scopes)
    {
        $unique = [];

        foreach ($scopes as $scope) {
            $unique[$scope->key()] = $scope;
        }

        $this->scopes = array_values($unique);
    }

    public static function empty(): self
    {
        return new self;
    }

    public static function network(): self
    {
        return new self(Scope::network());
    }

    /** @return array<int, Scope> */
    public function all(): array
    {
        return $this->scopes;
    }

    public function isEmpty(): bool
    {
        return $this->scopes === [];
    }

    /**
     * SCOPE-4 — only an unrestricted scope may see network-wide aggregates.
     */
    public function isNetwork(): bool
    {
        foreach ($this->scopes as $scope) {
            if ($scope->isNetwork()) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int, Scope> */
    public function satisfiable(): array
    {
        return array_values(array_filter(
            $this->scopes,
            static fn (Scope $scope) => $scope->isSatisfiable(),
        ));
    }

    public function of(ScopeType $type): ?Scope
    {
        foreach ($this->scopes as $scope) {
            if ($scope->type === $type) {
                return $scope;
            }
        }

        return null;
    }

    /**
     * Every target id held for a scope type, merged across assignments.
     *
     * @return array<int, int>
     */
    public function targetIdsFor(ScopeType $type): array
    {
        $ids = [];

        foreach ($this->scopes as $scope) {
            if ($scope->type === $type) {
                $ids = array_merge($ids, $scope->targetIds);
            }
        }

        return array_values(array_unique($ids));
    }

    /** SCR-1 — one line for the access-denied screen and the audit detail. */
    public function describe(): string
    {
        if ($this->isEmpty()) {
            return 'No scope (no role grants this permission)';
        }

        if ($this->isNetwork()) {
            return 'Network-wide (unrestricted)';
        }

        return implode(', ', array_map(
            static fn (Scope $scope) => $scope->describe(),
            $this->scopes,
        ));
    }

    public function merge(self $other): self
    {
        return new self(...$this->scopes, ...$other->all());
    }

    public function getIterator(): Traversable
    {
        return new \ArrayIterator($this->scopes);
    }

    /** @return array<int, array<string, mixed>> */
    public function toArray(): array
    {
        return array_map(static fn (Scope $scope) => $scope->toArray(), $this->scopes);
    }
}
