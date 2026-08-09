@extends('layouts.app')
@section('title', 'My Profile')

@section('content')
  <div class="page-head">
    <div>
      <h1>My Profile</h1>
      <p>{{ $user->email }} &middot; {{ $user->department?->name ?? 'no department' }}</p>
    </div>
    <div class="page-actions">
      <a href="{{ route('password.change') }}" class="btn btn-outline">Change password</a>
    </div>
  </div>

  @if ($user->passwordHasExpired())
    <div class="alert warn mb-16">
      <span>&#9888;&#65039;</span>
      <div>Your password is over {{ config('gondal.auth.password_max_age_days') }} days old and must be changed.</div>
    </div>
  @endif

  <div class="split">
    <div class="stack">
      <div class="card">
        <div class="card-head"><div><h3>Details</h3><p>What you can change yourself</p></div></div>
        <form method="POST" action="{{ route('profile.update') }}">
          @csrf @method('PUT')
          <div class="card-body">
            <div class="form-grid">
              <div class="field"><label for="pf-name">Name <span class="req">*</span></label>
                <input type="text" id="pf-name" name="name" value="{{ $user->name }}" required /></div>
              <div class="field"><label for="pf-phone">Phone</label>
                <input type="text" id="pf-phone" name="phone" value="{{ $user->phone }}" /></div>
              <div class="field"><label>Email</label>
                <input type="email" value="{{ $user->email }}" disabled />
                <div class="hint">Your administrator changes this.</div></div>
              <div class="field"><label>Two-factor sign-in</label>
                <input type="text" value="{{ $user->two_factor_enabled ? 'Required' : 'Not required' }}" disabled />
                <div class="hint">Set by an administrator, not by you.</div></div>
            </div>
          </div>
          <div class="modal-foot"><button type="submit" class="btn btn-primary">Save profile</button></div>
        </form>
      </div>

      <div class="card">
        <div class="card-head">
          <div><h3>Trusted Devices</h3><p>A device stays trusted for {{ config('gondal.auth.device_trust_days') }} days and can be revoked at any time</p></div>
        </div>
        <div class="card-body flush">
          <div class="table-wrap">
            <table>
              <thead><tr><th>Device</th><th>Last IP</th><th>Trusted until</th><th>Last seen</th><th class="actions">Actions</th></tr></thead>
              <tbody>
                @forelse ($devices as $device)
                  <tr>
                    <td>{{ $device->label ?? 'Unnamed device' }}</td>
                    <td class="mono">{{ $device->last_ip ?? '—' }}</td>
                    <td>
                      @if ($device->revoked_at)
                        <span class="badge muted">Revoked</span>
                      @elseif ($device->isTrusted())
                        {{ \App\Support\Wat::date($device->trusted_until) }}
                      @else
                        <span class="badge muted">Expired</span>
                      @endif
                    </td>
                    <td>{{ \App\Support\Wat::relative($device->last_seen_at) }}</td>
                    <td class="actions">
                      @if ($device->isTrusted())
                        <form method="POST" action="{{ route('profile.devices.revoke', $device) }}">
                          @csrf
                          <button type="submit" class="btn btn-ghost btn-sm text-danger">Revoke</button>
                        </form>
                      @endif
                    </td>
                  </tr>
                @empty
                  <tr><td colspan="5">@include('partials.empty', [
                    'title' => 'No trusted devices',
                    'message' => 'Every sign-in will ask for an emailed code.',
                    'icon' => '&#128274;',
                  ])</td></tr>
                @endforelse
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-head">
          <div><h3>Active Sessions</h3><p>Changing your password signs out every other session and every phone</p></div>
          <form method="POST" action="{{ route('profile.sessions.revoke') }}">
            @csrf
            <button type="submit" class="btn btn-outline btn-sm">Sign out other sessions &amp; phones</button>
          </form>
        </div>
        <div class="card-body flush">
          <div class="table-wrap">
            <table>
              <thead><tr><th>Started</th><th>IP</th><th>Device</th><th>Last seen</th></tr></thead>
              <tbody>
                @forelse ($sessions as $session)
                  <tr>
                    <td>{{ \App\Support\Wat::dateTime($session->started_at) }}</td>
                    <td class="mono">{{ $session->ip ?? '—' }}</td>
                    <td>{{ $session->device?->label ?? 'Untrusted device' }}</td>
                    <td>{{ \App\Support\Wat::relative($session->last_seen_at) }}</td>
                  </tr>
                @empty
                  <tr><td colspan="4">@include('partials.empty', ['title' => 'No live sessions recorded', 'icon' => '&#128273;'])</td></tr>
                @endforelse
              </tbody>
            </table>
          </div>
        </div>
      </div>

      {{--
        ARCH-2 — the phones. A mobile sign-in leaves no row in the sessions table
        above, so a live AgentConnect token used to appear on no screen at all:
        you could not tell that a lost phone was still syncing, let alone stop it.
        The "sign out other sessions" button above ends every one of these.
      --}}
      <div class="card">
        <div class="card-head">
          <div><h3>Mobile sign-ins</h3>
            <p>AgentConnect phones holding a live token &middot; each lasts
              {{ config('gondal.auth.api_token_days') }} days</p></div>
          <span class="badge {{ $apiTokens->isNotEmpty() ? 'success' : 'muted' }}">{{ $apiTokens->count() }} live</span>
        </div>
        <div class="card-body flush">
          <div class="table-wrap">
            <table>
              <thead><tr><th>Phone</th><th>Last IP</th><th>Expires</th><th>Last used</th></tr></thead>
              <tbody>
                @forelse ($apiTokens as $token)
                  <tr>
                    <td>{{ $token->name }}
                      @if ($token->app_version)<span class="text-muted text-small">v{{ $token->app_version }}</span>@endif
                    </td>
                    <td class="mono">{{ $token->last_ip ?? '—' }}</td>
                    <td>{{ $token->expires_at ? \App\Support\Wat::date($token->expires_at) : 'never' }}</td>
                    <td>{{ \App\Support\Wat::relative($token->last_used_at) }}</td>
                  </tr>
                @empty
                  <tr><td colspan="4">@include('partials.empty', ['title' => 'No phone is signed in', 'icon' => '&#128241;'])</td></tr>
                @endforelse
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <div class="stack">
      <div class="card">
        <div class="card-head"><div><h3>Your Access</h3><p>Roles decide what you can do; scope decides which records</p></div></div>
        <div class="card-body">
          <div class="meta-grid cols-2 mb-16">
            <div class="meta-item"><div class="meta-label">Roles</div>
              <div class="meta-value big">{{ $user->effectiveRoles()->count() }}</div></div>
            <div class="meta-item"><div class="meta-label">Permissions</div>
              <div class="meta-value big">{{ count($user->effectivePermissionKeys()) }}</div></div>
          </div>

          @foreach ($assignments as $assignment)
            <div class="queue-item">
              <div class="qi-ic">&#128737;</div>
              <div>
                <div class="qi-title">{{ $assignment->role?->name }}</div>
                <div class="qi-sub">{{ $assignment->describeScope() }}</div>
              </div>
              <div class="qi-right">
                <span class="badge {{ $assignment->role?->is_automatic ? 'muted' : 'info' }}">
                  {{ $assignment->role?->is_automatic ? 'Automatic' : 'Assigned' }}
                </span>
              </div>
            </div>
          @endforeach

          <div class="divider"></div>
          <div class="text-small text-muted">
            Everyone can request their own leave and open their own payslips. If your roles are changed,
            the change takes effect the next time a page loads.
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-head"><div><h3>Self-service</h3></div></div>
        <div class="card-body">
          <div class="flex flex-wrap">
            <a href="{{ route('leave.index') }}" class="btn btn-outline btn-sm">My leave</a>
            @if ($user->employee_id)
              @php($ownPayslip = \App\Models\Payslip::query()->where('employee_id', $user->employee_id)->latest('id')->first())
              @if ($ownPayslip)
                <a href="{{ route('payroll.payslips.show', $ownPayslip) }}" class="btn btn-outline btn-sm">Latest payslip</a>
              @endif
            @endif
            <a href="{{ route('notifications.index') }}" class="btn btn-outline btn-sm">Notification preferences</a>
          </div>
        </div>
      </div>
    </div>
  </div>
@endsection
