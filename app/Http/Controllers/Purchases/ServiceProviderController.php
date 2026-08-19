<?php

namespace App\Http\Controllers\Purchases;

use App\Authorization\Access;
use App\Http\Controllers\Controller;
use App\Models\ServiceProvider;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Payment\BankService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class ServiceProviderController extends Controller
{
    public function __construct(
        private readonly Access $access,
        private readonly AuditLogger $audit,
        private readonly BankService $bankService,
    ) {}

    public function index(Request $request): View
    {
        $this->access->authorize($this->currentUser(), 'purchase.service_providers.view', null, 'View service providers');

        $query = ServiceProvider::query()->with('creator');

        if ($search = trim((string) $request->input('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('contact', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('bank_name', 'like', "%{$search}%")
                    ->orWhere('bank_account', 'like', "%{$search}%")
                    ->orWhere('account_name', 'like', "%{$search}%");
            });
        }

        if ($request->has('status') && $request->input('status') !== '') {
            $query->where('is_active', $request->input('status') === '1');
        }

        $providers = $query->orderBy('name')->paginate(20)->withQueryString();

        $stats = [
            'total' => ServiceProvider::query()->count(),
            'active' => ServiceProvider::query()->where('is_active', true)->count(),
            'inactive' => ServiceProvider::query()->where('is_active', false)->count(),
        ];

        return view('purchases.service_providers.index', [
            'providers' => $providers,
            'stats' => $stats,
            'banks' => $this->bankService->getBanks(),
            'search' => $search,
            'statusFilter' => $request->input('status', ''),
            'canCreate' => $this->currentUser()->hasPermission('purchase.service_providers.create'),
            'canEdit' => $this->currentUser()->hasPermission('purchase.service_providers.edit'),
            'canDelete' => $this->currentUser()->hasPermission('purchase.service_providers.delete'),
        ]);
    }

    /**
     * AJAX endpoint to verify account details against the payment gateway.
     */
    public function verifyBank(Request $request): JsonResponse
    {
        $this->authorizeAnyAccess(['purchase.service_providers.create', 'purchase.service_providers.edit'], null, 'Verify provider bank account');

        $validated = $request->validate([
            'account_number' => ['required', 'string'],
            'bank_code' => ['required', 'string'],
        ]);

        $result = $this->bankService->verifyAccount($validated['account_number'], $validated['bank_code']);

        return response()->json($result, $result['success'] ? 200 : 422);
    }

    /**
     * AJAX endpoint to get list of supported Nigerian banks.
     */
    public function banks(): JsonResponse
    {
        return response()->json($this->bankService->getBanks());
    }

    public function store(Request $request): RedirectResponse
    {
        $this->access->authorize($this->currentUser(), 'purchase.service_providers.create', null, 'Create service provider');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'email' => ['nullable', 'email', 'max:191'],
            'contact' => ['nullable', 'string', 'max:50'],
            'bank_name' => ['nullable', 'string', 'max:100'],
            'bank_account' => ['nullable', 'string', 'max:50'],
            'bank_code' => ['nullable', 'string', 'max:30'],
            'account_name' => ['nullable', 'string', 'max:191'],
            'is_active' => ['nullable', 'boolean'],
            'billing_name' => ['nullable', 'string', 'max:191'],
            'billing_country' => ['nullable', 'string', 'max:100'],
            'billing_state' => ['nullable', 'string', 'max:100'],
            'billing_city' => ['nullable', 'string', 'max:100'],
            'billing_phone' => ['nullable', 'string', 'max:50'],
            'billing_zip' => ['nullable', 'string', 'max:30'],
            'billing_address' => ['nullable', 'string', 'max:1000'],
            'image' => ['nullable', 'image', 'max:2048'],
        ]);

        $imagePath = null;
        if ($request->hasFile('image')) {
            $imagePath = $request->file('image')->store('service_providers', 'public');
        }

        $provider = ServiceProvider::query()->create([
            'name' => $validated['name'],
            'email' => $validated['email'] ?? null,
            'contact' => $validated['contact'] ?? null,
            'image' => $imagePath,
            'bank_name' => $validated['bank_name'] ?? null,
            'bank_account' => $validated['bank_account'] ?? null,
            'bank_code' => $validated['bank_code'] ?? null,
            'account_name' => $validated['account_name'] ?? null,
            'is_active' => $request->boolean('is_active', true),
            'billing_name' => $validated['billing_name'] ?? null,
            'billing_country' => $validated['billing_country'] ?? null,
            'billing_state' => $validated['billing_state'] ?? null,
            'billing_city' => $validated['billing_city'] ?? null,
            'billing_phone' => $validated['billing_phone'] ?? null,
            'billing_zip' => $validated['billing_zip'] ?? null,
            'billing_address' => $validated['billing_address'] ?? null,
            'created_by_user_id' => $this->currentUser()->id,
        ]);

        $this->audit->created(
            $provider,
            "Service Provider {$provider->name} created",
            'Purchases',
            $provider->toArray(),
            $this->currentUser(),
        );

        return redirect()->route('service-providers.index')->with('success', "Service Provider '{$provider->name}' created successfully.");
    }

    public function update(Request $request, ServiceProvider $serviceProvider): RedirectResponse
    {
        $this->access->authorize($this->currentUser(), 'purchase.service_providers.edit', $serviceProvider, "Edit service provider {$serviceProvider->name}");

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'email' => ['nullable', 'email', 'max:191'],
            'contact' => ['nullable', 'string', 'max:50'],
            'bank_name' => ['nullable', 'string', 'max:100'],
            'bank_account' => ['nullable', 'string', 'max:50'],
            'bank_code' => ['nullable', 'string', 'max:30'],
            'account_name' => ['nullable', 'string', 'max:191'],
            'is_active' => ['nullable', 'boolean'],
            'billing_name' => ['nullable', 'string', 'max:191'],
            'billing_country' => ['nullable', 'string', 'max:100'],
            'billing_state' => ['nullable', 'string', 'max:100'],
            'billing_city' => ['nullable', 'string', 'max:100'],
            'billing_phone' => ['nullable', 'string', 'max:50'],
            'billing_zip' => ['nullable', 'string', 'max:30'],
            'billing_address' => ['nullable', 'string', 'max:1000'],
            'image' => ['nullable', 'image', 'max:2048'],
        ]);

        $before = $serviceProvider->toArray();

        if ($request->hasFile('image')) {
            if ($serviceProvider->image && Storage::disk('public')->exists($serviceProvider->image)) {
                Storage::disk('public')->delete($serviceProvider->image);
            }
            $serviceProvider->image = $request->file('image')->store('service_providers', 'public');
        }

        $serviceProvider->fill([
            'name' => $validated['name'],
            'email' => $validated['email'] ?? null,
            'contact' => $validated['contact'] ?? null,
            'bank_name' => $validated['bank_name'] ?? null,
            'bank_account' => $validated['bank_account'] ?? null,
            'bank_code' => $validated['bank_code'] ?? null,
            'account_name' => $validated['account_name'] ?? null,
            'is_active' => $request->boolean('is_active', true),
            'billing_name' => $validated['billing_name'] ?? null,
            'billing_country' => $validated['billing_country'] ?? null,
            'billing_state' => $validated['billing_state'] ?? null,
            'billing_city' => $validated['billing_city'] ?? null,
            'billing_phone' => $validated['billing_phone'] ?? null,
            'billing_zip' => $validated['billing_zip'] ?? null,
            'billing_address' => $validated['billing_address'] ?? null,
        ])->save();

        $this->audit->edited(
            $serviceProvider,
            "Service Provider {$serviceProvider->name} updated",
            'Purchases',
            $before,
            $serviceProvider->toArray(),
            $this->currentUser(),
        );

        return redirect()->route('service-providers.index')->with('success', "Service Provider '{$serviceProvider->name}' updated successfully.");
    }

    public function destroy(ServiceProvider $serviceProvider): RedirectResponse
    {
        $this->access->authorize($this->currentUser(), 'purchase.service_providers.delete', $serviceProvider, "Delete service provider {$serviceProvider->name}");

        $name = $serviceProvider->name;
        $serviceProvider->delete();

        $this->audit->deleted(
            $serviceProvider,
            "Service Provider {$name} deleted",
            'Purchases',
            ['name' => $name],
            $this->currentUser(),
        );

        return redirect()->route('service-providers.index')->with('success', "Service Provider '{$name}' deleted successfully.");
    }
}
