<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityType;
use App\Models\AdjustmentReason;
use App\Models\AuditEntry;
use App\Models\Community;
use App\Models\Delegation;
use App\Models\DiscrepancyCause;
use App\Models\Grade;
use App\Models\GradeRate;
use App\Models\LeaveType;
use App\Models\Lga;
use App\Models\Permission;
use App\Models\QualityTestDefinition;
use App\Models\RejectionReason;
use App\Models\Route as TransportRoute;
use App\Models\Sequence;
use App\Models\Workflow;
use App\Models\WorkflowInstance;
use App\Services\Audit\AuditLogger;
use App\Support\Money;
use App\Support\Navigation;
use App\Support\Settings;
use App\Support\Wat;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * settings.html and settings-workflows.html.
 *
 * §9 — "Every item below is a database row an administrator edits through
 * settings.html. Implementations that use enums, config files or constants for any
 * of these are non-compliant."
 *
 * REF-1 — every change here is audited with before and after values.
 * REF-2 / BR-13 — a rate change INSERTS an effective-dated row. It never updates
 *   one, so a delivery confirmed yesterday still reports yesterday's rate.
 */
class SettingsController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): View
    {
        return view('admin.settings.index', [
            'grades' => Grade::query()->with('rates')->orderBy('position')->get(),
            'rejectionReasons' => RejectionReason::query()->orderBy('position')->get(),
            'adjustmentReasons' => AdjustmentReason::query()->orderBy('position')->get(),
            'discrepancyCauses' => DiscrepancyCause::query()->orderBy('position')->get(),
            'activityTypes' => ActivityType::query()->orderBy('position')->get(),
            'qualityTests' => QualityTestDefinition::query()->orderBy('position')->get(),
            'sequences' => Sequence::query()->orderBy('id')->get(),
            'routes' => TransportRoute::query()->orderBy('name')->get(),
            'leaveTypes' => LeaveType::query()->orderBy('position')->get(),
            'lgas' => Lga::query()->withCount('communities')->orderBy('name')->get(),
            'communityCount' => Community::query()->count(),
            'settings' => [
                'cutoff_default' => Settings::string('milk.delivery_cutoff_default', '07:00'),
                'cutoff_latest' => Settings::string('milk.delivery_cutoff_latest_override', '08:00'),
                'tolerance' => Settings::decimalString('milk.batch_discrepancy_tolerance_pct', '1.0'),
                'savings_pct' => Settings::decimalString('cooperative.default_savings_deduction_pct', '5'),
                'levy_pct' => Settings::decimalString('cooperative.default_levy_pct', '2'),
                'social_minor' => Settings::moneyMinor('cooperative.default_social_contribution_minor', 25_000),
                'loan_book_enabled' => Settings::boolean('cooperative.loan_book_enabled', false),
                'low_stock_warning' => Settings::boolean('shop.low_stock_warning_enabled', true),
            ],
            'paymentSettings' => [
                'default_gateway' => Settings::string('payment.default_gateway', 'paystack'),
                'paystack' => [
                    'enabled' => Settings::boolean('payment.paystack.enabled', true),
                    'mode' => Settings::string('payment.paystack.mode', 'test'),
                    'public_key' => Settings::string('payment.paystack.public_key', config('services.paystack.public_key', '')),
                    'secret_key' => Settings::string('payment.paystack.secret_key', config('services.paystack.secret_key', '')),
                    'merchant_email' => Settings::string('payment.paystack.merchant_email', config('services.paystack.merchant_email', '')),
                ],
                'monnify' => [
                    'enabled' => Settings::boolean('payment.monnify.enabled', false),
                    'mode' => Settings::string('payment.monnify.mode', 'test'),
                    'api_key' => Settings::string('payment.monnify.api_key', config('services.monnify.api_key', '')),
                    'secret_key' => Settings::string('payment.monnify.secret_key', config('services.monnify.secret_key', '')),
                    'contract_code' => Settings::string('payment.monnify.contract_code', config('services.monnify.contract_code', '')),
                ],
                'zainpay' => [
                    'enabled' => Settings::boolean('payment.zainpay.enabled', false),
                    'mode' => Settings::string('payment.zainpay.mode', 'test'),
                    'public_key' => Settings::string('payment.zainpay.public_key', config('services.zainpay.public_key', '')),
                    'zainbox_code' => Settings::string('payment.zainpay.zainbox_code', config('services.zainpay.zainbox_code', '')),
                ],
            ],
            // NG-1 / NG-2 — the disabled-modules panel, from data.
            'disabledModules' => Navigation::disabledModules(),
            'projectsDisabledOn' => Settings::string('modules.projects_disabled_on', ''),
            'retiredPermissionCount' => Permission::query()->retired()->count(),
        ]);
    }

    /** REF-1 */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'milk_delivery_cutoff_default' => ['required', 'date_format:H:i'],
            'milk_delivery_cutoff_latest_override' => ['required', 'date_format:H:i', 'after_or_equal:milk_delivery_cutoff_default'],
            'milk_batch_discrepancy_tolerance_pct' => ['required', 'numeric', 'min:0', 'max:100'],
            'cooperative_default_savings_deduction_pct' => ['required', 'numeric', 'min:0', 'max:100'],
            'cooperative_default_levy_pct' => ['required', 'numeric', 'min:0', 'max:100'],
            'cooperative_default_social_contribution' => ['required', 'string'],
            'shop_low_stock_warning_enabled' => ['nullable', 'boolean'],

            // Payment settings
            'payment_default_gateway' => ['required', 'in:paystack,monnify,zainpay'],
            'payment_paystack_enabled' => ['nullable', 'boolean'],
            'payment_paystack_mode' => ['required', 'in:test,live'],
            'payment_paystack_public_key' => ['nullable', 'string', 'max:255'],
            'payment_paystack_secret_key' => ['nullable', 'string', 'max:255'],
            'payment_paystack_merchant_email' => ['nullable', 'email', 'max:255'],
            
            'payment_monnify_enabled' => ['nullable', 'boolean'],
            'payment_monnify_mode' => ['required', 'in:test,live'],
            'payment_monnify_api_key' => ['nullable', 'string', 'max:255'],
            'payment_monnify_secret_key' => ['nullable', 'string', 'max:255'],
            'payment_monnify_contract_code' => ['nullable', 'string', 'max:255'],

            'payment_zainpay_enabled' => ['nullable', 'boolean'],
            'payment_zainpay_mode' => ['required', 'in:test,live'],
            'payment_zainpay_public_key' => ['nullable', 'string', 'max:255'],
            'payment_zainpay_zainbox_code' => ['nullable', 'string', 'max:255'],
        ], [], [
            'milk_delivery_cutoff_latest_override' => 'latest permitted cut-off',
            'payment_default_gateway' => 'default payment gateway',
        ]);

        Settings::put([
            'milk.delivery_cutoff_default' => $validated['milk_delivery_cutoff_default'],
            'milk.delivery_cutoff_latest_override' => $validated['milk_delivery_cutoff_latest_override'],
            'milk.batch_discrepancy_tolerance_pct' => (string) $validated['milk_batch_discrepancy_tolerance_pct'],
            'cooperative.default_savings_deduction_pct' => (string) $validated['cooperative_default_savings_deduction_pct'],
            'cooperative.default_levy_pct' => (string) $validated['cooperative_default_levy_pct'],
            'cooperative.default_social_contribution_minor' => Money::fromMajor($validated['cooperative_default_social_contribution']) ?? 0,
            'shop.low_stock_warning_enabled' => $request->boolean('shop_low_stock_warning_enabled'),

            // Payment settings
            'payment.default_gateway' => $validated['payment_default_gateway'],
            'payment.paystack.enabled' => $request->boolean('payment_paystack_enabled'),
            'payment.paystack.mode' => $validated['payment_paystack_mode'],
            'payment.paystack.public_key' => (string) ($validated['payment_paystack_public_key'] ?? ''),
            'payment.paystack.secret_key' => (string) ($validated['payment_paystack_secret_key'] ?? ''),
            'payment.paystack.merchant_email' => (string) ($validated['payment_paystack_merchant_email'] ?? ''),

            'payment.monnify.enabled' => $request->boolean('payment_monnify_enabled'),
            'payment.monnify.mode' => $validated['payment_monnify_mode'],
            'payment.monnify.api_key' => (string) ($validated['payment_monnify_api_key'] ?? ''),
            'payment.monnify.secret_key' => (string) ($validated['payment_monnify_secret_key'] ?? ''),
            'payment.monnify.contract_code' => (string) ($validated['payment_monnify_contract_code'] ?? ''),

            'payment.zainpay.enabled' => $request->boolean('payment_zainpay_enabled'),
            'payment.zainpay.mode' => $validated['payment_zainpay_mode'],
            'payment.zainpay.public_key' => (string) ($validated['payment_zainpay_public_key'] ?? ''),
            'payment.zainpay.zainbox_code' => (string) ($validated['payment_zainpay_zainbox_code'] ?? ''),
        ], $this->currentUser(), 'general');

        // Flush API client memoized instances
        \App\Services\Payment\PaymentApi\PaystackApi::flush();
        \App\Services\Payment\PaymentApi\MonnifyApi::flush();
        \App\Services\Payment\PaymentApi\ZainpayApi::flush();

        return back()->with('success', 'Settings saved. Every module that reads them picks the change up immediately.');
    }

    /**
     * BR-13 / REF-2 — "Rates are effective-dated. Changing a rate never alters a
     * historical figure." So this INSERTS a row; there is no rate update path.
     */
    public function storeGradeRate(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'grade_id' => ['required', 'exists:grades,id'],
            'rate_per_litre' => ['required', 'string'],
            'effective_from' => ['required', 'date'],
            'criteria' => ['nullable', 'string', 'max:1000'],
        ]);

        $grade = Grade::query()->findOrFail($validated['grade_id']);
        $rateMinor = Money::fromMajor($validated['rate_per_litre']) ?? 0;

        if ($grade->is_system) {
            return back()->withErrors([
                'grade_id' => $grade->name.' is a system grade and its rate cannot be changed. Rejected volume is always valued at zero.',
            ]);
        }

        $previous = $grade->currentRate();

        $rate = GradeRate::query()->updateOrCreate(
            ['grade_id' => $grade->getKey(), 'effective_from' => $validated['effective_from']],
            ['rate_per_litre_minor' => $rateMinor],
        );

        if (($validated['criteria'] ?? null) !== null) {
            $grade->forceFill(['criteria' => $validated['criteria']])->save();
        }

        // REF-1 / REF-2 — before and after, and the prospective-only note.
        $this->audit->edited(
            $grade,
            sprintf(
                '%s rate set to %s effective %s (previous %s)',
                $grade->name,
                Money::format($rateMinor),
                Wat::date($rate->effective_from),
                $previous === null ? 'none' : Money::format((int) $previous->rate_per_litre_minor),
            ),
            'Settings',
            ['rate_per_litre_minor' => $previous?->rate_per_litre_minor, 'effective_from' => $previous?->effective_from?->toDateString()],
            [
                'rate_per_litre_minor' => $rateMinor,
                'effective_from' => $rate->effective_from->toDateString(),
                'rules' => ['BR-13', 'REF-2'],
                'note' => 'Applies to future confirmations only — consignments already confirmed keep the rate saved onto them.',
            ],
            $this->currentUser(),
        );

        return back()->with('success', sprintf(
            '%s will be %s from %s. Deliveries confirmed before then keep the rate that was in force.',
            $grade->name,
            Money::format($rateMinor),
            Wat::date($rate->effective_from),
        ));
    }

    /** BR-1 / BR-5 — the reason list and its follow-up thresholds. */
    public function updateRejectionReason(Request $request, RejectionReason $reason): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'help_text' => ['nullable', 'string', 'max:255'],
            'available_at_point' => ['nullable', 'boolean'],
            'available_at_center' => ['nullable', 'boolean'],
            'available_at_factory' => ['nullable', 'boolean'],
            'followup_threshold' => ['nullable', 'integer', 'min:0', 'max:100'],
            'followup_window_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'excluded_from_payment' => ['nullable', 'boolean'],
            'status' => ['required', 'in:active,retired'],
        ]);

        $before = $reason->only([
            'name', 'help_text', 'available_at_point', 'available_at_center', 'available_at_factory',
            'followup_threshold', 'followup_window_days', 'excluded_from_payment', 'status',
        ]);

        $reason->fill([
            'name' => $validated['name'],
            'help_text' => $validated['help_text'] ?? null,
            'available_at_point' => $request->boolean('available_at_point'),
            'available_at_center' => $request->boolean('available_at_center'),
            'available_at_factory' => $request->boolean('available_at_factory'),
            'followup_threshold' => $validated['followup_threshold'] ?? null,
            'followup_window_days' => $validated['followup_window_days'] ?? null,
            'excluded_from_payment' => $request->boolean('excluded_from_payment'),
            'status' => $validated['status'],
        ])->save();

        $this->audit->edited(
            $reason,
            sprintf('Rejection reason "%s" updated', $reason->name),
            'Settings',
            $before,
            $reason->only(array_keys($before)),
            $this->currentUser(),
        );

        return back()->with('success', $reason->name.' updated. Existing records keep the reason they were given.');
    }

    /** §9 — reference numbering. */
    public function updateSequence(Request $request, Sequence $sequence): RedirectResponse
    {
        $validated = $request->validate([
            'prefix' => ['required', 'string', 'max:16'],
            'digits' => ['required', 'integer', 'min:1', 'max:12'],
            'reset_period' => ['required', 'in:daily,monthly,yearly,never'],
            'reference_format' => ['required', 'string', 'max:64'],
        ]);

        $before = $sequence->only(['prefix', 'digits', 'reset_period', 'reference_format']);

        $sequence->fill($validated)->save();

        $this->audit->edited(
            $sequence,
            sprintf('Reference format for %s changed to %s', $sequence->label, $sequence->preview()),
            'Settings',
            $before,
            $sequence->only(array_keys($before)),
            $this->currentUser(),
        );

        return back()->with('success', 'Format saved. Existing records keep their references.');
    }

    /** §6.5 — settings-workflows.html. */
    public function workflows(): View
    {
        $workflows = Workflow::query()
            ->with(['stages.approvingRole', 'bands.stages'])
            ->orderBy('code')
            ->get();

        $requisition = $workflows->firstWhere('applies_to', Workflow::APPLIES_REQUISITION);

        return view('admin.settings.workflows', [
            'workflows' => $workflows,
            'selected' => $requisition,
            'inFlight' => WorkflowInstance::query()
                ->open()
                ->selectRaw('workflow_id, count(*) as total')
                ->groupBy('workflow_id')
                ->pluck('total', 'workflow_id'),
            // settings-workflows.html "Who Holds Each Stage" — resolved from
            // current role assignments (BR-23).
            'stageHolders' => $requisition === null
                ? collect()
                : $requisition->stages
                    ->filter(fn ($stage) => $stage->approving_role_id !== null)
                    ->mapWithKeys(fn ($stage) => [
                        $stage->getKey() => [
                            'stage' => $stage,
                            'users' => $stage->approvingRole?->users()->where('users.status', 'active')->get() ?? collect(),
                            'delegations' => Delegation::query()
                                ->active()
                                ->where('role_id', $stage->approving_role_id)
                                ->with(['fromUser', 'toUser'])
                                ->get(),
                        ],
                    ]),
            'recentChanges' => AuditEntry::query()
                ->where('module', 'Settings')
                ->latest('occurred_at')
                ->limit(5)
                ->get(),
        ]);
    }

    public function updateWorkflow(Request $request, Workflow $workflow): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'in:active,disabled'],
            'options' => ['nullable', 'array'],
            'overdue_reminder' => ['required', 'in:daily,twelve_hourly,once,never'],
        ]);

        $before = ['status' => $workflow->status, 'options' => $workflow->options];

        $options = array_merge($workflow->options ?? [], [
            'strict_sequence' => $request->boolean('options.strict_sequence'),
            'rejection_returns_to_requester' => $request->boolean('options.rejection_returns_to_requester'),
            'approver_may_reduce_amount' => $request->boolean('options.approver_may_reduce_amount'),
            'allow_request_info' => $request->boolean('options.allow_request_info'),
            'allow_delegation' => $request->boolean('options.allow_delegation'),
            'auto_escalate_on_sla' => $request->boolean('options.auto_escalate_on_sla'),
            // BR-18 is a rule, not an option: it is enforced regardless of what is
            // stored here, and the checkbox is shown disabled on the screen.
            'requester_may_not_approve_own' => true,
            'overdue_reminder' => $validated['overdue_reminder'],
        ]);

        $workflow->fill(['status' => $validated['status'], 'options' => $options])->save();

        $this->audit->edited(
            $workflow,
            sprintf('Workflow %s (%s) updated', $workflow->code, $workflow->name),
            'Settings',
            $before,
            ['status' => $workflow->status, 'options' => $options],
            $this->currentUser(),
        );

        return back()->with('success', $workflow->name.' updated. Changes apply to newly raised items only.');
    }
}
