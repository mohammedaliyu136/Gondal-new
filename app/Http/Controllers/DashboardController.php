<?php

namespace App\Http\Controllers;

use App\Models\AuditEntry;
use App\Services\Reporting\DashboardMetrics;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * index.html — the dashboard.
 *
 * SCOPE-4 — every figure comes from DashboardMetrics, which runs each query
 * through the model's global scope and withholds network-wide totals unless
 * milk.totals.network.view is held. A collection officer scoped to Kumbotso sees
 * Kumbotso.
 */
class DashboardController extends Controller
{
    public function __construct(private readonly DashboardMetrics $metrics) {}

    public function __invoke(Request $request): View
    {
        $user = $this->currentUser();

        return view('dashboard', [
            'metrics' => $this->metrics->for($user),
            // The "Recent Activity" timeline. AUDIT-6 gates the full log, but a
            // user with admin.audit.view sees the system-wide feed here too.
            'timeline' => $this->allows('admin.audit.view')
                ? AuditEntry::query()
                    ->excludingTestData()
                    ->latest('occurred_at')
                    ->limit(5)
                    ->get()
                : AuditEntry::query()
                    ->where('actor_user_id', $user?->getKey())
                    ->latest('occurred_at')
                    ->limit(5)
                    ->get(),
        ]);
    }
}
