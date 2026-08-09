<?php

namespace App\Http\Middleware;

use App\Authorization\Access;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * §4 — who may open `/approvals`.
 *
 * THE PROBLEM. The queue was gated on `purchase.approve.*`, but it is not a
 * purchasing queue: `WorkflowEngine::queueFor()` returns leave requests, payroll
 * runs and requisitions alike. The HR Manager is named on the leave and payroll
 * stages, has items waiting in that queue, and could not open the page to see
 * them. The work stalled somewhere no one was looking, because the person it was
 * waiting on was told they had no access to it.
 *
 * THE FIX. Admit anyone holding a permission that an active workflow stage
 * actually names. Read from `workflow_stages` rather than written out as a list,
 * so adding a stage cannot lock its own approver out — the failure this replaces
 * was exactly that, a list that stopped matching the workflows.
 *
 * This is layer 1 only (ARCH-4). Which items a given approver may act on is
 * BR-23's business, decided per instance by the engine with the record in hand;
 * a person who gets in here and is named on nothing simply sees an empty queue.
 */
class RequireApprovalQueueAccess
{
    /**
     * NFR-2 — where the memo below lives.
     *
     * The container, deliberately: it is torn down and rebuilt with each request,
     * so the memo's lifetime is exactly one request. A static property would
     * outlive the request under a persistent worker and keep serving a stage list
     * an administrator had already changed; a cache entry would need a busting
     * hook on the workflow settings writer, which is a second thing to get wrong.
     */
    private const MEMO = 'gondal.approval_permission_keys';

    public function __construct(private readonly Access $access) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        $keys = self::approvalPermissionKeys();

        if ($user instanceof User) {
            foreach ($keys as $key) {
                if ($user->hasPermission($key)) {
                    return $next($request);
                }
            }
        }

        /*
         * Denied. Routed through Access so the refusal is the standard one:
         * SCR-1's populated access-denied screen and BR-34's audit entry, naming
         * a permission that means something to whoever reads the log.
         */
        $this->access->authorizeAny($user, $keys === [] ? ['purchase.approve.depthead.approve'] : $keys);

        return $next($request);
    }

    /**
     * Every permission an active workflow stage requires.
     *
     * Memoised because Navigation::maySee() consults it on EVERY page render,
     * not only on /approvals — one query per request across the whole
     * application, to answer a question whose answer cannot change mid-request.
     *
     * @return array<int, string>
     */
    public static function approvalPermissionKeys(): array
    {
        $container = app();

        if ($container->bound(self::MEMO)) {
            return $container->make(self::MEMO);
        }

        $keys = DB::table('workflow_stages')
            ->whereNull('deleted_at')
            ->whereNotNull('required_permission')
            // A submission stage is where work ENTERS the workflow; being able to
            // raise a requisition is not being able to approve one.
            ->where('is_submission', false)
            ->distinct()
            ->pluck('required_permission')
            ->map(static fn ($key) => (string) $key)
            ->all();

        $container->instance(self::MEMO, $keys);

        return $keys;
    }
}
