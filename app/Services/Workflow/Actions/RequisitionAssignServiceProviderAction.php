<?php

namespace App\Services\Workflow\Actions;

use App\Models\Requisition;
use App\Models\ServiceProvider;
use App\Models\User;
use App\Models\WorkflowInstance;
use App\Models\WorkflowStage;
use App\Services\Audit\AuditLogger;
use App\Services\Purchases\RequisitionService;
use App\Services\Workflow\Contracts\WorkflowStageActionHandler;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RequisitionAssignServiceProviderAction implements WorkflowStageActionHandler
{
    public function __construct(
        private readonly RequisitionService $requisitionService,
        private readonly AuditLogger $audit,
    ) {}

    public function key(): string
    {
        return 'requisition.assign_service_provider';
    }

    public function label(): string
    {
        return 'Assign Service Provider & Bank Account (Accounts)';
    }

    public function description(): string
    {
        return 'Allows Accounts / approvers to select or assign the authorized service provider/vendor and review disbursement bank account details.';
    }

    public function appliesTo(): array
    {
        return ['requisition'];
    }

    public function renderForm(WorkflowInstance $instance, WorkflowStage $stage): string
    {
        $requisition = $instance->subject;
        if (! ($requisition instanceof Requisition)) {
            return '';
        }

        $serviceProviders = ServiceProvider::query()->active()->orderBy('name')->get();

        return view('workflows.actions.requisition-assign-service-provider', [
            'instance' => $instance,
            'stage' => $stage,
            'requisition' => $requisition,
            'serviceProviders' => $serviceProviders,
            'selectedProvider' => $requisition->serviceProvider,
        ])->render();
    }

    public function validate(Request $request, WorkflowInstance $instance, WorkflowStage $stage): array
    {
        if (! $request->has('service_provider_id') && ! $request->has('suggested_vendor')) {
            return [];
        }

        $validated = $request->validate([
            'service_provider_id' => ['nullable', 'exists:service_providers,id'],
            'custom_vendor_name' => ['nullable', 'string', 'max:191'],
            'account_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $providerId = $validated['service_provider_id'] ?? null;
        $provider = $providerId ? ServiceProvider::query()->find($providerId) : null;

        return [
            'service_provider_id' => $provider?->id,
            'service_provider_name' => $provider?->name ?? $validated['custom_vendor_name'] ?? null,
            'bank_name' => $provider?->bank_name,
            'bank_account' => $provider?->bank_account,
            'bank_code' => $provider?->bank_code,
            'account_name' => $provider?->account_name,
            'notes' => $validated['account_notes'] ?? null,
        ];
    }

    public function execute(WorkflowInstance $instance, WorkflowStage $stage, User $actor, array $payload): void
    {
        $requisition = $instance->subject;
        if (! ($requisition instanceof Requisition)) {
            return;
        }

        $providerId = $payload['service_provider_id'] ?? null;
        $vendorName = $payload['service_provider_name'] ?? null;

        DB::transaction(function () use ($requisition, $instance, $stage, $actor, $providerId, $vendorName, $payload): void {
            if ($providerId !== null) {
                $requisition->service_provider_id = $providerId;
            }
            if ($vendorName !== null && $vendorName !== '') {
                $requisition->suggested_vendor = $vendorName;
            }
            $requisition->save();

            $this->audit->approval(
                $requisition,
                sprintf(
                    'Service Provider assigned at %s by %s — %s (%s)',
                    $stage->name,
                    $actor->name,
                    $vendorName ?: 'Registered Provider',
                    $payload['bank_name'] ? "{$payload['bank_name']} {$payload['bank_account']}" : 'No bank set'
                ),
                [
                    'stage' => $stage->name,
                    'stage_action' => $this->key(),
                    'service_provider_id' => $providerId,
                    'service_provider_name' => $vendorName,
                    'bank_details' => [
                        'bank_name' => $payload['bank_name'] ?? null,
                        'bank_account' => $payload['bank_account'] ?? null,
                        'account_name' => $payload['account_name'] ?? null,
                    ],
                    'notes' => $payload['notes'] ?? null,
                ],
                $actor,
            );
        });
    }
}
