<?php

namespace App\Services\Workflow\Contracts;

use App\Models\User;
use App\Models\WorkflowInstance;
use App\Models\WorkflowStage;
use Illuminate\Http\Request;

interface WorkflowStageActionHandler
{
    /**
     * Unique identifier key for the stage action (e.g. 'requisition.adjust_items').
     */
    public function key(): string;

    /**
     * User-friendly label displayed in dropdowns and UI.
     */
    public function label(): string;

    /**
     * Detailed description of what this stage action does.
     */
    public function description(): string;

    /**
     * List of workflow applies_to target strings this action supports (e.g. ['requisition']).
     * Use ['*'] to support all workflows.
     */
    public function appliesTo(): array;

    /**
     * Renders the interactive form UI partial for this action inside the approval modal.
     */
    public function renderForm(WorkflowInstance $instance, WorkflowStage $stage): string;

    /**
     * Validate the submitted payload for this stage action.
     *
     * @return array<string, mixed> Validated data
     */
    public function validate(Request $request, WorkflowInstance $instance, WorkflowStage $stage): array;

    /**
     * Execute the stage action on approval submit.
     *
     * @param  array<string, mixed>  $payload
     */
    public function execute(WorkflowInstance $instance, WorkflowStage $stage, User $actor, array $payload): void;
}
