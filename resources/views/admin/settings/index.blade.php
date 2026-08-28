@extends('layouts.app')
@section('title', 'Settings')

@section('content')
  <div class="page-head">
    <div>
      <h1>Settings</h1>
      <p>Reference data the whole system reads from. Change it here and every screen that uses it follows.</p>
    </div>
    <div class="page-actions">
      <a href="{{ route('admin.settings.workflows') }}" class="btn btn-outline">Approval Workflows</a>
      {{-- §9's registers, which used to render read-only here. --}}
      <a href="{{ route('admin.reference.index') }}" class="btn btn-outline">Reference data</a>
    </div>
  </div>

  <div class="alert info mb-16">
    <span>&#8505;&#65039;</span>
    <div>
      <strong>Everything here is shared.</strong>
      Changing the Grade A rate updates grading, payment and every report at once. Every change is written
      to the <a href="{{ route('admin.audit-log') }}" class="text-primary">audit log</a> with its before and
      after values.
    </div>
  </div>

  <div class="tabs">
    <span class="tab active">Milk &amp; Quality</span>
    <a href="#locations" class="tab">Locations &amp; Routes</a>
    <a href="#cooperatives" class="tab">Cooperatives</a>
    <a href="#shop" class="tab">Shop &amp; Inventory</a>
    <a href="#payments" class="tab">Payment Gateways</a>
    <a href="{{ route('admin.settings.workflows') }}" class="tab">Approval Workflows</a>
    <a href="#numbering" class="tab">Numbering</a>
  </div>

  <div class="split">
    <div class="stack">
      <div class="card">
        <div class="card-head">
          <div><h3>Milk Grades &amp; Rates</h3><p>Used by grading, farmer payment and every volume report</p></div>
          <a href="#modal-grade" class="btn btn-ghost btn-sm">+ Set a rate</a>
        </div>
        <div class="card-body flush">
          <div class="table-wrap">
            <table>
              <thead><tr><th>Grade</th><th>Code</th><th class="num">Rate / litre</th>
                <th>Criteria</th><th>Effective from</th><th>Status</th></tr></thead>
              <tbody>
                @foreach ($grades as $grade)
                  @php($current = $grade->currentRate())
                  <tr>
                    <td class="font-bold">{{ $grade->name }}</td>
                    <td class="perm-key">{{ $grade->code }}</td>
                    <td class="num font-bold">{{ \App\Support\Money::format($current?->rate_per_litre_minor) }}</td>
                    <td>{{ $grade->criteria }}</td>
                    <td>{{ \App\Support\Wat::date($current?->effective_from) }}</td>
                    <td>
                      <span class="badge {{ $grade->is_system ? 'muted' : 'success' }}">
                        {{ $grade->is_system ? 'System' : ucfirst($grade->status) }}
                      </span>
                      @if ($grade->is_rejection)
                        <div class="cell-sub">never payable</div>
                      @endif
                    </td>
                  </tr>
                  @foreach ($grade->rates->skip(1) as $historical)
                    <tr>
                      <td colspan="2" class="text-muted text-small">&nbsp;&nbsp;previous rate</td>
                      <td class="num text-muted">{{ \App\Support\Money::format($historical->rate_per_litre_minor) }}</td>
                      <td colspan="2" class="text-muted text-small">
                        effective {{ \App\Support\Wat::date($historical->effective_from) }}
                      </td>
                      <td></td>
                    </tr>
                  @endforeach
                @endforeach
              </tbody>
            </table>
          </div>
        </div>
        <div class="card-body">
          <div class="alert warn">
            <span>&#9888;&#65039;</span>
            <div>
              Changing a rate applies to <strong>future</strong> confirmations only. A consignment already
              confirmed keeps the rate saved onto it, so no historical figure can move. Rate history is kept
              above for reconciliation.
            </div>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-head">
          <div><h3>Rejection Reasons</h3><p>The only reasons selectable anywhere in the system</p></div>
        </div>
        <div class="card-body flush">
          <div class="table-wrap">
            <table>
              <thead><tr><th>Reason</th><th>Code</th><th class="perm-check">At point</th>
                <th class="perm-check">At center</th><th class="perm-check">At factory</th>
                <th class="num">Follow-up after</th><th class="actions">Actions</th></tr></thead>
              <tbody>
                @foreach ($rejectionReasons as $reason)
                  <tr>
                    <td class="font-bold">{{ $reason->name }}
                      @if ($reason->help_text)<div class="cell-sub">{{ $reason->help_text }}</div>@endif
                      @if ($reason->is_cutoff_breach)
                        <div class="cell-sub"><span class="badge info plain">cut-off breach</span></div>
                      @endif</td>
                    <td class="perm-key">{{ $reason->code }}</td>
                    <td class="perm-check"><input type="checkbox" disabled @checked($reason->available_at_point) /></td>
                    <td class="perm-check"><input type="checkbox" disabled @checked($reason->available_at_center) /></td>
                    <td class="perm-check"><input type="checkbox" disabled @checked($reason->available_at_factory) /></td>
                    <td class="num">
                      @if ($reason->opensFollowups())
                        {{ $reason->followup_threshold }} in {{ $reason->followup_window_days }} days
                      @else
                        &mdash;
                      @endif
                    </td>
                    <td class="actions"><a href="#modal-reason-{{ $reason->id }}" class="btn btn-ghost btn-sm">Edit</a></td>
                  </tr>
                @endforeach
              </tbody>
            </table>
          </div>
        </div>
        <div class="card-body">
          <div class="text-small text-muted">
            Agents and officers pick from this list only; they cannot type their own reason.
            Reaching a threshold opens a
            <a href="{{ route('field-activities.index') }}" class="text-primary">quality follow-up</a>
            automatically.
          </div>
        </div>
      </div>

      <form method="POST" action="{{ route('admin.settings.update') }}">
        @csrf @method('PUT')
        <div class="card">
          <div class="card-head">
            <div><h3>Quality &amp; Tolerance</h3><p>Applied when an officer grades, and when a batch is reconciled</p></div>
          </div>
          <div class="card-body">
            <div class="form-grid">
              <div class="field"><label for="st-cutoff">Default delivery cut-off <span class="req">*</span></label>
                <input type="time" id="st-cutoff" name="milk_delivery_cutoff_default"
                       value="{{ $settings['cutoff_default'] }}" required />
                <div class="hint">Individual points may override this on their own record.</div></div>
              <div class="field"><label for="st-cutoff-latest">Latest permitted cut-off override <span class="req">*</span></label>
                <input type="time" id="st-cutoff-latest" name="milk_delivery_cutoff_latest_override"
                       value="{{ $settings['cutoff_latest'] }}" required />
                <div class="hint">A point&rsquo;s own cut-off can be no later than this.</div></div>
              <div class="field full"><label for="st-tolerance">Discrepancy tolerance on a factory batch (%) <span class="req">*</span></label>
                <input type="text" id="st-tolerance" name="milk_batch_discrepancy_tolerance_pct"
                       inputmode="decimal" value="{{ $settings['tolerance'] }}" required />
                <div class="hint">
                  Beyond this, the supervisor must record an explanation before the batch can be released.
                </div></div>
              <div class="field full">
                <div class="divider" style="margin: 8px 0 16px 0;"></div>
                <label class="check-label" for="st-direct-credit" style="font-weight:600;font-size:14px;">
                  <input type="checkbox" id="st-direct-credit" name="milk_direct_wallet_credit_enabled" value="1"
                         @checked($settings['direct_wallet_credit_enabled']) />
                  Direct Farmer Wallet Crediting (Bypass Consignment Dispatch &amp; Batch Reconciliation)
                </label>
                <div class="hint" style="margin-top:6px;line-height:1.5;">
                  When enabled, recording milk intake at collection points immediately credits the farmer&rsquo;s electronic wallet based on current milk rate, allowing payout runs (<code>/farmer-payments</code>) to proceed directly without requiring consignment dispatch or batch reconciliation.
                </div>
              </div>
            </div>

            <div class="divider"></div>
            <h3 class="mb-16">Quality tests</h3>
            <div class="table-wrap">
              <table>
                <thead><tr><th>Test</th><th>Kind</th><th>Acceptable</th><th>Required</th></tr></thead>
                <tbody>
                  @foreach ($qualityTests as $test)
                    <tr>
                      <td class="font-bold">{{ $test->name }}<div class="cell-sub perm-key">{{ $test->code }}</div></td>
                      <td>{{ ucfirst($test->kind) }}</td>
                      <td>{{ $test->describeRange() }}</td>
                      <td><span class="badge {{ $test->is_required ? 'success' : 'muted' }}">
                        {{ $test->is_required ? 'Required' : 'Optional' }}</span></td>
                    </tr>
                  @endforeach
                </tbody>
              </table>
            </div>
            <div class="hint mt-16">
              A grade cannot be assigned until every required test above has been recorded.
            </div>
          </div>

          <div class="card-body" id="cooperatives">
            <div class="divider"></div>
            <h3 class="mb-16">Cooperative defaults</h3>
            <div class="form-grid">
              <div class="field"><label for="st-savings">Savings deduction (% of milk payment) <span class="req">*</span></label>
                <input type="text" id="st-savings" name="cooperative_default_savings_deduction_pct"
                       inputmode="decimal" value="{{ $settings['savings_pct'] }}" required /></div>
              <div class="field"><label for="st-levy">Cooperative levy (%) <span class="req">*</span></label>
                <input type="text" id="st-levy" name="cooperative_default_levy_pct"
                       inputmode="decimal" value="{{ $settings['levy_pct'] }}" required /></div>
              <div class="field"><label for="st-social">Social fund (₦ / member / month) <span class="req">*</span></label>
                <input type="text" id="st-social" name="cooperative_default_social_contribution"
                       inputmode="decimal" value="{{ \App\Support\Money::decimal($settings['social_minor']) }}" required /></div>
              <div class="field full">
                <label>Accounts held per cooperative</label>
                <div class="stack" style="gap:10px;margin-top:6px">
                  <label class="check-label"><input type="checkbox" checked disabled /> General cooperative fund</label>
                  <label class="check-label"><input type="checkbox" checked disabled /> Social fund</label>
                  <label class="check-label"><input type="checkbox" disabled @checked($settings['loan_book_enabled']) />
                    Loan book <span class="text-muted">&mdash; not in use</span></label>
                </div>
              </div>
            </div>
          </div>

          <div class="card-body" id="shop">
            <div class="divider"></div>
            <h3 class="mb-16">Shop &amp; inventory</h3>
            <div class="field">
              <label class="check-label" for="st-lowstock">
                <input type="checkbox" id="st-lowstock" name="shop_low_stock_warning_enabled" value="1"
                       @checked($settings['low_stock_warning']) />
                Notify when a product reaches its reorder level
              </label>
              <div class="hint">
                Units and reorder levels are per category, managed on
                <a href="{{ route('shop.categories.index') }}" class="text-primary">Product Categories</a>.
              </div>
            </div>
          </div>

          <div class="card-body" id="payments">
            <div class="divider"></div>
            <div class="flex" style="justify-content:space-between;align-items:center;margin-bottom:16px">
              <div>
                <h3 style="margin-bottom:4px">Payment Gateways</h3>
                <p class="text-muted text-small" style="margin:0">Configure online payment gateways (Paystack, Monnify, Zainpay) and API credentials</p>
              </div>
              <div>
                <span class="badge {{ $paymentSettings['paystack']['enabled'] || $paymentSettings['monnify']['enabled'] || $paymentSettings['zainpay']['enabled'] ? 'success' : 'muted' }}">
                  {{ $paymentSettings['paystack']['enabled'] || $paymentSettings['monnify']['enabled'] || $paymentSettings['zainpay']['enabled'] ? 'Gateways Active' : 'All Disabled' }}
                </span>
              </div>
            </div>

            <div class="form-grid mb-16">
              <div class="field full">
                <label for="st-pay-default">Default Payment Gateway <span class="req">*</span></label>
                <select id="st-pay-default" name="payment_default_gateway" required>
                  <option value="paystack" @selected($paymentSettings['default_gateway'] === 'paystack')>Paystack (Cards, Bank Transfer, USSD)</option>
                  <option value="monnify" @selected($paymentSettings['default_gateway'] === 'monnify')>Monnify (Dynamic Virtual Accounts, Cards)</option>
                  <option value="zainpay" @selected($paymentSettings['default_gateway'] === 'zainpay')>Zainpay (Virtual Accounts, Cards)</option>
                </select>
                <div class="hint">The primary gateway used for online collections and checkout sessions across the system.</div>
              </div>
            </div>

            {{-- Paystack Configuration Card --}}
            <div class="card mb-16" style="border:1px solid #e2e8f0;background:#f8fafc">
              <div class="card-head" style="padding:12px 16px;background:#fff;border-bottom:1px solid #e2e8f0">
                <div class="flex" style="align-items:center;gap:12px">
                  <label class="check-label" style="font-weight:600;font-size:1rem">
                    <input type="checkbox" name="payment_paystack_enabled" value="1"
                           @checked($paymentSettings['paystack']['enabled']) />
                    Paystack
                  </label>
                  <span class="badge {{ $paymentSettings['paystack']['mode'] === 'live' ? 'success' : 'info' }} plain">
                    {{ strtoupper($paymentSettings['paystack']['mode']) }} MODE
                  </span>
                </div>
                <div class="text-small text-muted">Supports Card, Bank Transfer, USSD &amp; Bulk Transfers</div>
              </div>
              <div class="card-body" style="padding:16px">
                <div class="form-grid">
                  <div class="field">
                    <label for="st-paystack-mode">Environment Mode <span class="req">*</span></label>
                    <select id="st-paystack-mode" name="payment_paystack_mode" required>
                      <option value="test" @selected($paymentSettings['paystack']['mode'] === 'test')>Test / Sandbox</option>
                      <option value="live" @selected($paymentSettings['paystack']['mode'] === 'live')>Live / Production</option>
                    </select>
                  </div>
                  <div class="field">
                    <label for="st-paystack-pub">Public Key</label>
                    <input type="text" id="st-paystack-pub" name="payment_paystack_public_key"
                           value="{{ $paymentSettings['paystack']['public_key'] }}" placeholder="pk_test_... or pk_live_..." autocomplete="off" />
                  </div>
                  <div class="field full">
                    <label for="st-paystack-sec">Secret Key <span class="req">*</span></label>
                    <input type="password" id="st-paystack-sec" name="payment_paystack_secret_key"
                           value="{{ $paymentSettings['paystack']['secret_key'] }}" placeholder="sk_test_... or sk_live_..." autocomplete="off" />
                  </div>
                </div>
                <div class="hint mt-16">
                  <strong>Paystack Webhook URL:</strong> <code>{{ url('/api/payments/webhook/paystack') }}</code>
                </div>
              </div>
            </div>

            {{-- Monnify Configuration Card --}}
            <div class="card mb-16" style="border:1px solid #e2e8f0;background:#f8fafc">
              <div class="card-head" style="padding:12px 16px;background:#fff;border-bottom:1px solid #e2e8f0">
                <div class="flex" style="align-items:center;gap:12px">
                  <label class="check-label" style="font-weight:600;font-size:1rem">
                    <input type="checkbox" name="payment_monnify_enabled" value="1"
                           @checked($paymentSettings['monnify']['enabled']) />
                    Monnify
                  </label>
                  <span class="badge {{ $paymentSettings['monnify']['mode'] === 'live' ? 'success' : 'info' }} plain">
                    {{ strtoupper($paymentSettings['monnify']['mode']) }} MODE
                  </span>
                </div>
                <div class="text-small text-muted">Supports Reserved Accounts, Dynamic Accounts, Cards &amp; Batch Payouts</div>
              </div>
              <div class="card-body" style="padding:16px">
                <div class="form-grid">
                  <div class="field">
                    <label for="st-monnify-mode">Environment Mode <span class="req">*</span></label>
                    <select id="st-monnify-mode" name="payment_monnify_mode" required>
                      <option value="test" @selected($paymentSettings['monnify']['mode'] === 'test')>Test / Sandbox</option>
                      <option value="live" @selected($paymentSettings['monnify']['mode'] === 'live')>Live / Production</option>
                    </select>
                  </div>
                  <div class="field">
                    <label for="st-monnify-contract">Contract Code</label>
                    <input type="text" id="st-monnify-contract" name="payment_monnify_contract_code"
                           value="{{ $paymentSettings['monnify']['contract_code'] }}" placeholder="e.g. 1234567890" />
                  </div>
                  <div class="field">
                    <label for="st-monnify-api">API Key</label>
                    <input type="text" id="st-monnify-api" name="payment_monnify_api_key"
                           value="{{ $paymentSettings['monnify']['api_key'] }}" placeholder="MK_TEST_... or MK_PROD_..." autocomplete="off" />
                  </div>
                  <div class="field">
                    <label for="st-monnify-sec">Secret Key</label>
                    <input type="password" id="st-monnify-sec" name="payment_monnify_secret_key"
                           value="{{ $paymentSettings['monnify']['secret_key'] }}" placeholder="Monnify Secret Key" autocomplete="off" />
                  </div>
                  <div class="field full">
                    <label for="st-monnify-src">Source Account Number (Disbursements / Transfers)</label>
                    <input type="text" id="st-monnify-src" name="payment_monnify_source_account_number"
                           value="{{ $paymentSettings['monnify']['source_account_number'] }}" placeholder="e.g. 10-digit Monnify Wallet / Settlement Account Number" />
                    <div class="hint">Your funded source wallet/account number on Monnify from which bulk salary and vendor transfers will be debited.</div>
                  </div>
                </div>
                <div class="hint mt-16">
                  <strong>Monnify Webhook URL:</strong> <code>{{ url('/api/payments/webhook/monnify') }}</code>
                </div>
              </div>
            </div>

            {{-- Zainpay Configuration Card --}}
            <div class="card mb-16" style="border:1px solid #e2e8f0;background:#f8fafc">
              <div class="card-head" style="padding:12px 16px;background:#fff;border-bottom:1px solid #e2e8f0">
                <div class="flex" style="align-items:center;gap:12px">
                  <label class="check-label" style="font-weight:600;font-size:1rem">
                    <input type="checkbox" name="payment_zainpay_enabled" value="1"
                           @checked($paymentSettings['zainpay']['enabled']) />
                    Zainpay
                  </label>
                  <span class="badge {{ $paymentSettings['zainpay']['mode'] === 'live' ? 'success' : 'info' }} plain">
                    {{ strtoupper($paymentSettings['zainpay']['mode']) }} MODE
                  </span>
                </div>
                <div class="text-small text-muted">Supports Virtual Accounts, Wallets &amp; Inter-bank Transfers</div>
              </div>
              <div class="card-body" style="padding:16px">
                <div class="form-grid">
                  <div class="field">
                    <label for="st-zainpay-mode">Environment Mode <span class="req">*</span></label>
                    <select id="st-zainpay-mode" name="payment_zainpay_mode" required>
                      <option value="test" @selected($paymentSettings['zainpay']['mode'] === 'test')>Test / Sandbox</option>
                      <option value="live" @selected($paymentSettings['zainpay']['mode'] === 'live')>Live / Production</option>
                    </select>
                  </div>
                  <div class="field">
                    <label for="st-zainpay-box">Zainbox Code <span class="req">*</span></label>
                    <input type="text" id="st-zainpay-box" name="payment_zainpay_zainbox_code"
                           value="{{ $paymentSettings['zainpay']['zainbox_code'] }}" placeholder="e.g. zn_box_..." />
                  </div>
                  <div class="field">
                    <label for="st-zainpay-pub">API Token (Bearer Key) <span class="req">*</span></label>
                    <input type="password" id="st-zainpay-pub" name="payment_zainpay_public_key"
                           value="{{ $paymentSettings['zainpay']['public_key'] }}" placeholder="Bearer token / Secret Key" autocomplete="off" />
                  </div>
                  <div class="field">
                    <label for="st-zainpay-src">Wallet / Source Account Number</label>
                    <input type="text" id="st-zainpay-src" name="payment_zainpay_source_account_number"
                           value="{{ $paymentSettings['zainpay']['source_account_number'] }}" placeholder="Zainpay Source Wallet / Settlement Account" />
                  </div>
                </div>
                <div class="hint mt-16">
                  <strong>Zainpay Webhook URL:</strong> <code>{{ url('/api/payments/webhook/zainpay') }}</code>
                </div>
              </div>
            </div>
          </div>

          <div class="action-bar">
            <div class="ab-note">Changes to rates, reasons and payment settings affect every module that reads them.</div>
            <button type="submit" class="btn btn-primary">Save settings</button>
          </div>
        </div>
      </form>

      <div class="card" id="numbering">
        <div class="card-head">
          <div><h3>Reference Numbering</h3><p>One format per record type</p></div>
        </div>
        <div class="card-body flush">
          <div class="table-wrap">
            <table>
              <thead><tr><th>Record</th><th>Prefix</th><th>Example</th><th>Resets</th>
                <th class="num">Current</th><th class="actions">Actions</th></tr></thead>
              <tbody>
                @foreach ($sequences as $sequence)
                  <tr>
                    <td>{{ $sequence->label }}</td>
                    <td class="perm-key">{{ $sequence->prefix }}</td>
                    <td class="perm-key">{{ $sequence->preview() }}</td>
                    <td>{{ ucfirst($sequence->reset_period) }}</td>
                    <td class="num">{{ number_format($sequence->current_value) }}</td>
                    <td class="actions"><a href="#modal-seq-{{ $sequence->id }}" class="btn btn-ghost btn-sm">Edit</a></td>
                  </tr>
                @endforeach
              </tbody>
            </table>
          </div>
        </div>
        <div class="card-body">
          <div class="text-small text-muted">Changing a prefix does not renumber existing records.</div>
        </div>
      </div>
    </div>

    <div class="stack">
      <div class="card" id="locations">
        <div class="card-head"><div><h3>Transport Tariffs</h3><p>Route fees used by logistics</p></div></div>
        <div class="card-body flush">
          <div class="table-wrap">
            <table>
              <thead><tr><th>Route</th><th class="num">Distance</th><th class="num">Fee</th></tr></thead>
              <tbody>
                @forelse ($routes as $route)
                  <tr>
                    <td>{{ $route->name }}
                      @if ($route->vehicle_type)<span class="cell-sub">{{ $route->vehicle_type }}</span>@endif</td>
                    <td class="num">{{ $route->distance_km ? $route->distance_km.' km' : '—' }}</td>
                    <td class="num">{{ $route->formattedTariff() }}</td>
                  </tr>
                @empty
                  <tr><td colspan="3"><div class="text-muted text-small">No routes configured yet.</div></td></tr>
                @endforelse
              </tbody>
            </table>
          </div>
        </div>
        <div class="card-body">
          <div class="text-small text-muted">
            A trip keeps the fee its route charged on the day it was logged. Changing a tariff here will not
            alter a trip already logged.
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-head"><div><h3>Locations</h3><p>LGAs and communities available across the system</p></div></div>
        <div class="card-body">
          <div class="meta-grid cols-2 mb-16">
            <div class="meta-item"><div class="meta-label">LGAs</div>
              <div class="meta-value big">{{ $lgas->count() }}</div></div>
            <div class="meta-item"><div class="meta-label">Wards / communities</div>
              <div class="meta-value big">{{ $communityCount }}</div></div>
          </div>
          <div class="chip-group">
            @foreach ($lgas as $lga)
              <span class="chip on">{{ $lga->name }} <span class="text-muted">&middot; {{ $lga->communities_count }}</span></span>
            @endforeach
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-head"><div><h3>Other Reference Lists</h3></div></div>
        <div class="card-body">
          <div class="kpi-list">
            <div class="kpi-row"><div class="kpi-ic">&#9998;</div>
              <div class="grow"><div class="kpi-name">Adjustment reasons</div>
                <div class="text-muted text-small">{{ $adjustmentReasons->where('status', 'active')->count() }} active</div></div>
              <div class="kpi-val small">{{ $adjustmentReasons->count() }}</div></div>
            <div class="kpi-row"><div class="kpi-ic">&#9878;</div>
              <div class="grow"><div class="kpi-name">Discrepancy causes</div>
                <div class="text-muted text-small">{{ $discrepancyCauses->where('status', 'active')->count() }} active</div></div>
              <div class="kpi-val small">{{ $discrepancyCauses->count() }}</div></div>
            <div class="kpi-row"><div class="kpi-ic">&#128203;</div>
              <div class="grow"><div class="kpi-name">Activity types</div>
                <div class="text-muted text-small">{{ $activityTypes->where('closes_quality_followup', true)->count() }} can close a follow-up</div></div>
              <div class="kpi-val small">{{ $activityTypes->count() }}</div></div>
            <div class="kpi-row"><div class="kpi-ic">&#127958;</div>
              <div class="grow"><div class="kpi-name">Leave types</div>
                <div class="text-muted text-small">with annual entitlements</div></div>
              <div class="kpi-val small">{{ $leaveTypes->count() }}</div></div>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-head"><div><h3>Disabled Modules</h3><p>Switched off for this deployment</p></div></div>
        <div class="card-body">
          @foreach ($disabledModules as $module)
            <div class="queue-item">
              <div class="qi-ic" style="background:#eef1f5;color:var(--muted)">&#128683;</div>
              <div>
                <div class="qi-title">{{ \Illuminate\Support\Str::headline($module) }}</div>
                <div class="qi-sub">
                  @if ($module === 'projects')
                    Unused &middot; disabled {{ $projectsDisabledOn ?: 'at review' }} &middot;
                    {{ $retiredPermissionCount }} permissions retired
                  @else
                    Not part of this deployment
                  @endif
                </div>
              </div>
              <div class="qi-right"><span class="badge muted">Off</span></div>
            </div>
          @endforeach
          <div class="alert info mt-16">
            <span>&#8505;&#65039;</span>
            <div>Disabling a module hides its screens and retires its permissions without deleting historical
              data.</div>
          </div>
        </div>
      </div>
    </div>
  </div>

  {{-- BR-13 / REF-2 — a rate change INSERTS an effective-dated row. --}}
  <div id="modal-grade" class="modal">
    <a href="#" class="modal-overlay"></a>
    <div class="modal-dialog narrow">
      <div class="modal-head">
        <div><h3>Set a Grade Rate</h3><p>Effective-dated &mdash; nothing historical moves</p></div>
        <a href="#" class="modal-close">&times;</a>
      </div>
      <form method="POST" action="{{ route('admin.settings.grades.store') }}">
        @csrf
          <input type="hidden" name="_modal" value="modal-grade" />
        <div class="modal-body">
            @include('partials.modal-errors', ['modal' => 'modal-grade'])
          <div class="field mb-16"><label for="gr-grade">Grade <span class="req">*</span></label>
            <select id="gr-grade" name="grade_id" required>
              @foreach ($grades->reject->is_system as $grade)
                <option value="{{ $grade->id }}">{{ $grade->name }} ({{ $grade->code }})</option>
              @endforeach
            </select>
            <div class="hint">The Rejected grade is a system grade &mdash; rejected volume is always valued at zero.</div></div>
          <div class="field mb-16"><label for="gr-rate">Rate per litre (₦) <span class="req">*</span></label>
            <input type="text" id="gr-rate" name="rate_per_litre" inputmode="decimal" required /></div>
          <div class="field mb-16"><label for="gr-from">Effective from <span class="req">*</span></label>
            <input type="date" id="gr-from" name="effective_from"
                   value="{{ \App\Support\Wat::today()->addDay()->toDateString() }}" required />
            <div class="hint">Consignments confirmed before this date keep the rate that was saved onto them.</div></div>
          <div class="field"><label for="gr-criteria">Criteria</label>
            <textarea id="gr-criteria" name="criteria" rows="3"></textarea></div>
        </div>
        <div class="modal-foot">
          <a href="#" class="btn btn-ghost">Cancel</a>
          <button type="submit" class="btn btn-primary">Set rate</button>
        </div>
      </form>
    </div>
  </div>

  @foreach ($rejectionReasons as $reason)
    <div id="modal-reason-{{ $reason->id }}" class="modal">
      <a href="#" class="modal-overlay"></a>
      <div class="modal-dialog narrow">
        <div class="modal-head">
          <div><h3>Edit Rejection Reason</h3><p>{{ $reason->name }} &middot; {{ $reason->code }}</p></div>
          <a href="#" class="modal-close">&times;</a>
        </div>
        <form method="POST" action="{{ route('admin.settings.reasons.update', $reason) }}">
          @csrf
          <input type="hidden" name="_modal" value="modal-reason-{{ $reason->id }}" /> @method('PUT')
          <div class="modal-body">
            @include('partials.modal-errors', ['modal' => 'modal-reason-'.$reason->id.''])
            <div class="field mb-16"><label for="rr-{{ $reason->id }}-name">Reason <span class="req">*</span></label>
              <input type="text" id="rr-{{ $reason->id }}-name" name="name" value="{{ $reason->name }}" required /></div>
            <div class="field mb-16"><label for="rr-{{ $reason->id }}-help">Help text shown to agents</label>
              <input type="text" id="rr-{{ $reason->id }}-help" name="help_text" value="{{ $reason->help_text }}" /></div>
            <div class="field mb-16">
              <label>Available at</label>
              <div class="stack" style="gap:10px;margin-top:6px">
                <label class="check-label"><input type="checkbox" name="available_at_point" value="1"
                  @checked($reason->available_at_point) /> Collection point (agent)</label>
                <label class="check-label"><input type="checkbox" name="available_at_center" value="1"
                  @checked($reason->available_at_center) /> Collection center (officer)</label>
                <label class="check-label"><input type="checkbox" name="available_at_factory" value="1"
                  @checked($reason->available_at_factory) /> Factory (supervisor)</label>
              </div>
            </div>
            <div class="field mb-16">
              <label>Open a quality follow-up after</label>
              <div class="flex">
                <label class="sr-only" for="rr-{{ $reason->id }}-threshold" style="position:absolute;left:-9999px">Occurrences</label>
                <input type="number" id="rr-{{ $reason->id }}-threshold" name="followup_threshold"
                       value="{{ $reason->followup_threshold }}" min="0" max="100" style="max-width:80px" />
                <span class="text-muted text-small">occurrences within</span>
                <label class="sr-only" for="rr-{{ $reason->id }}-window" style="position:absolute;left:-9999px">Days</label>
                <input type="number" id="rr-{{ $reason->id }}-window" name="followup_window_days"
                       value="{{ $reason->followup_window_days }}" min="0" max="365" style="max-width:80px" />
                <span class="text-muted text-small">days</span>
              </div>
              <div class="hint">Reaching this opens a quality follow-up automatically and notifies the extension team.</div>
            </div>
            <div class="field mb-16">
              <label class="check-label"><input type="checkbox" name="excluded_from_payment" value="1"
                @checked($reason->excluded_from_payment) />
                Rejected volume is not paid for and is not carried to the factory</label>
            </div>
            <div class="field"><label for="rr-{{ $reason->id }}-status">Status <span class="req">*</span></label>
              <select id="rr-{{ $reason->id }}-status" name="status" required>
                <option value="active" @selected($reason->status === 'active')>Active</option>
                <option value="retired" @selected($reason->status === 'retired')>Retired</option>
              </select>
              <div class="hint">Retiring hides it from new records; existing records keep the reason they were given.</div></div>
          </div>
          <div class="modal-foot">
            <a href="#" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-primary">Save reason</button>
          </div>
        </form>
      </div>
    </div>
  @endforeach

  @foreach ($sequences as $sequence)
    <div id="modal-seq-{{ $sequence->id }}" class="modal">
      <a href="#" class="modal-overlay"></a>
      <div class="modal-dialog narrow">
        <div class="modal-head">
          <div><h3>Edit Reference Format</h3><p>{{ $sequence->label }}</p></div>
          <a href="#" class="modal-close">&times;</a>
        </div>
        <form method="POST" action="{{ route('admin.settings.sequences.update', $sequence) }}">
          @csrf
          <input type="hidden" name="_modal" value="modal-seq-{{ $sequence->id }}" /> @method('PUT')
          <div class="modal-body">
            @include('partials.modal-errors', ['modal' => 'modal-seq-'.$sequence->id.''])
            <div class="field mb-16"><label for="sq-{{ $sequence->id }}-prefix">Prefix <span class="req">*</span></label>
              <input type="text" id="sq-{{ $sequence->id }}-prefix" name="prefix" value="{{ $sequence->prefix }}" required />
              <div class="hint">Changing a prefix does not renumber existing records.</div></div>
            <div class="field mb-16"><label for="sq-{{ $sequence->id }}-digits">Digits <span class="req">*</span></label>
              <input type="number" id="sq-{{ $sequence->id }}-digits" name="digits"
                     value="{{ $sequence->digits }}" min="1" max="12" required /></div>
            <div class="field mb-16"><label for="sq-{{ $sequence->id }}-reset">Counter resets <span class="req">*</span></label>
              <select id="sq-{{ $sequence->id }}-reset" name="reset_period" required>
                @foreach (['daily', 'monthly', 'yearly', 'never'] as $period)
                  <option value="{{ $period }}" @selected($sequence->reset_period === $period)>{{ ucfirst($period) }}</option>
                @endforeach
              </select></div>
            <div class="field mb-16"><label for="sq-{{ $sequence->id }}-format">Format <span class="req">*</span></label>
              <input type="text" id="sq-{{ $sequence->id }}-format" name="reference_format"
                     value="{{ $sequence->reference_format }}" required />
              <div class="hint">Placeholders: {prefix} {year} {month} {day} {number}</div></div>
            <div class="field"><label>Preview</label>
              <input type="text" value="{{ $sequence->preview() }}" disabled /></div>
          </div>
          <div class="modal-foot">
            <a href="#" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-primary">Save format</button>
          </div>
        </form>
      </div>
    </div>
  @endforeach
@endsection
