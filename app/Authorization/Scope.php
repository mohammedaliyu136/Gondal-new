<?php

namespace App\Authorization;

use Illuminate\Contracts\Support\Arrayable;

/**
 * SCOPE-1 — one role assignment's data scope: a type and, where applicable,
 * one or more targets.
 *
 * @implements Arrayable<string, mixed>
 */
final class Scope implements Arrayable
{
    /**
     * @param  array<int, int>  $targetIds
     */
    public function __construct(
        public readonly ScopeType $type,
        public readonly array $targetIds = [],
        public readonly ?string $targetLabel = null,
    ) {}

    public static function network(): self
    {
        return new self(ScopeType::Network);
    }

    public static function own(): self
    {
        return new self(ScopeType::Own);
    }

    public function isNetwork(): bool
    {
        return $this->type === ScopeType::Network;
    }

    /**
     * A targeted scope with no target can never match a record — it is a
     * misconfiguration, and it must fail closed rather than fall back to
     * network-wide access.
     */
    public function isSatisfiable(): bool
    {
        return ! $this->type->requiresTarget() || $this->targetIds !== [];
    }

    public function key(): string
    {
        return $this->type->value.':'.implode(',', $this->targetIds);
    }

    /**
     * SCR-1 — the wording shown in the "Your Data Scope" cell of access-denied.
     */
    public function describe(): string
    {
        if ($this->type === ScopeType::Network) {
            return 'Network-wide (unrestricted)';
        }

        if ($this->type === ScopeType::Own) {
            return 'Own records only';
        }

        if ($this->targetLabel !== null && $this->targetLabel !== '') {
            return $this->targetLabel.' only';
        }

        return $this->type->label().' — no target set';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'label' => $this->type->label(),
            'target_ids' => $this->targetIds,
            'target_label' => $this->targetLabel,
            'description' => $this->describe(),
        ];
    }
}
