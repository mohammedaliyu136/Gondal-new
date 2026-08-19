<?php

namespace App\Services\Workflow;

use App\Services\Workflow\Contracts\WorkflowStageActionHandler;
use Illuminate\Support\Collection;

/**
 * Registry and Factory for workflow stage action triggers / sub-events.
 */
class StageActionRegistry
{
    /**
     * @var array<string, WorkflowStageActionHandler>
     */
    protected array $handlers = [];

    /**
     * Register a new stage action handler.
     */
    public function register(WorkflowStageActionHandler $handler): self
    {
        $this->handlers[$handler->key()] = $handler;

        return $this;
    }

    /**
     * Retrieve a registered handler by its unique key.
     */
    public function get(?string $key): ?WorkflowStageActionHandler
    {
        if ($key === null || $key === '') {
            return null;
        }

        return $this->handlers[$key] ?? null;
    }

    /**
     * Alias for get() to act as a factory.
     */
    public function factory(?string $key): ?WorkflowStageActionHandler
    {
        return $this->get($key);
    }

    /**
     * Check if a stage action handler is registered.
     */
    public function has(string $key): bool
    {
        return isset($this->handlers[$key]);
    }

    /**
     * Get all registered handlers.
     *
     * @return Collection<string, WorkflowStageActionHandler>
     */
    public function all(): Collection
    {
        return collect($this->handlers);
    }

    /**
     * Get all handlers that apply to a specific workflow target (e.g. 'requisition').
     *
     * @return Collection<string, WorkflowStageActionHandler>
     */
    public function forAppliesTo(string $appliesTo): Collection
    {
        return $this->all()->filter(function (WorkflowStageActionHandler $handler) use ($appliesTo) {
            $targets = $handler->appliesTo();

            return in_array('*', $targets, true) || in_array($appliesTo, $targets, true);
        });
    }
}
