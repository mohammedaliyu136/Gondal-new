<?php

namespace App\Contracts;

use App\Models\WorkflowInstance;

/**
 * Standardized interface for any model or business entity that can undergo
 * an approval workflow journey.
 *
 * When all stages are approved in /approvals, onWorkflowApproved() is automatically
 * invoked to trigger downstream business logic (e.g., initializing a payment run,
 * updating status, disbursement, etc.).
 */
interface WorkflowSubjectInterface
{
    /**
     * Display title for the approval queue, notifications, and audit records.
     * E.g. "Milk Collection Batch #2026-004" or "Purchase Requisition"
     */
    public function getApprovalTitle(): string;

    /**
     * Unique business reference code. E.g. 'MC-BATCH-2026-004' or 'REQ-0042'
     */
    public function getApprovalReference(): string;

    /**
     * Monetary amount involved in minor units (kobo), or null if non-monetary.
     */
    public function getApprovalAmountMinor(): ?int;

    /**
     * Detail/Show URL for approvers to review the full record.
     */
    public function getApprovalUrl(): ?string;

    /**
     * Callback executed automatically when all workflow stages are approved.
     * Use this hook to transition states, mark as payable, or schedule payment runs.
     */
    public function onWorkflowApproved(WorkflowInstance $instance): void;

    /**
     * Callback executed automatically if the workflow is rejected at any stage.
     */
    public function onWorkflowRejected(WorkflowInstance $instance, string $reason): void;
}
