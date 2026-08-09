@extends('layouts.app')
@section('title', $user->name)

@section('content')
  <div class="crumbs">
    <a href="{{ route('dashboard') }}">Dashboard</a><span class="sep">/</span>
    <a href="{{ route('admin.users.index') }}">Users</a><span class="sep">/</span>
    <span class="here">{{ $user->name }}</span>
  </div>

  <div class="detail-head">
    <div class="avatar-lg">{{ $user->initials() }}</div>
    <div class="dh-main">
      <h1>{{ $user->name }}</h1>
      <div class="dh-sub">{{ $user->email }}
        @if ($user->department) &middot; {{ $user->department->name }} @endif
        @if ($user->position) &middot; {{ $user->position }} @endif
      </div>
      <div class="dh-tags">
        <span class="badge {{ $user->isActive() ? 'success' : 'muted' }}">
          {{ $user->isActive() ? 'Active' : 'Deactivated' }}</span>
        @if ($user->is_test)<span class="badge muted">test account</span>@endif
        @if ($user->isLocked())<span class="badge danger">locked</span>@endif
        @if ($user->isActive() && $user->password_changed_at === null)
          {{--
            A new hire who never redeemed their invitation looked identical to one
            who simply had not signed in today.
          --}}
          <span class="badge warning">pending activation</span>
        @endif
        @if ($user->isActive() && $user->passwordIsTemporary())
          {{-- BR-31, qualified — somebody other than the holder knows a working
               password for this account, until they change it. --}}
          <span class="badge danger">temporary password &mdash; must change at next sign-in</span>
        @elseif ($user->isActive() && $user->awaitingPasswordReset())
          {{-- An administrator cleared the password; the user has not yet chosen
               its replacement, so nothing opens this account but the code. --}}
          <span class="badge warning">password reset &mdash; awaiting their new one</span>
        @endif
        <span class="pill">{{ count($effectivePermissions) }} effective permissions</span>
      </div>
    </div>
    <div class="dh-actions">
      @if ($canEdit)
        <a href="#modal-edit-user" class="btn btn-outline">Edit</a>
        @if ($user->password_changed_at === null)
          <form method="POST" action="{{ route('admin.users.send-activation', $user) }}" style="display:inline">
            @csrf
            <button type="submit" class="btn btn-ghost">Resend activation</button>
          </form>
        @elseif ($user->isActive() && $user->id !== auth()->id())
          {{--
            BR-31 — the other half of "creation AND reset both send a code".
            Offered from the day the user first signs in onwards, which is when
            "resend activation" stops being the right words for the same job; a
            colleague who has forgotten their password had no lever here at all
            before this, only deactivate-then-reactivate. Not offered on your own
            account (the profile screen and "Forgot password?" are for that) and
            not on a deactivated one, where reactivation already sends a code.
          --}}
          <a href="#modal-reset-password" class="btn btn-ghost">Email a reset code</a>
          {{--
            BR-31, qualified — and the option for when a code is no use, because
            the user cannot reach their mailbox from where they are standing. The
            weaker of the two on purpose: you end up knowing their password until
            they change it, which the modal says in as many words. Listed second so
            the safe one is the one under the cursor.
          --}}
          <a href="#modal-set-password" class="btn btn-ghost">Set a password</a>
        @endif
        @if ($user->isLocked())
          {{--
            AUTH-6 — the lock email tells the user to call IT, and until this
            button existed IT could see the "locked" badge and do nothing about
            it but deactivate-then-reactivate, which also revokes every trusted
            device and sends a welcome email. At 05:30 with milk in the churn
            that is the whole morning for a collection agent.
          --}}
          <form method="POST" action="{{ route('admin.users.unlock', $user) }}" style="display:inline">
            @csrf
            <button type="submit" class="btn btn-primary">Unlock account</button>
          </form>
        @endif
      @endif
      @if ($canDeactivate && $user->isActive())
        <a href="#modal-deactivate" class="btn btn-ghost text-danger">Deactivate</a>
      @elseif ($canEdit && ! $user->isActive())
        <form method="POST" action="{{ route('admin.users.reactivate', $user) }}" style="display:inline">
          @csrf
          <button type="submit" class="btn btn-primary">Reactivate</button>
        </form>
      @endif
    </div>
  </div>

  @unless ($user->isActive())
    <div class="alert warn mb-16">
      <span>&#9888;&#65039;</span>
      <div>
        <strong>Deactivated {{ \App\Support\Wat::date($user->deactivated_at) }}</strong>
        @if ($user->deactivated_reason) &mdash; {{ $user->deactivated_reason }} @endif.
        Sign-in is blocked and sessions were revoked, but every record they touched still carries their
        name.
      </div>
    </div>
  @endunless

  @if ($user->isActive() && $user->awaitingPasswordReset())
    {{--
      BR-31 — say it plainly, because the next thing that happens is a phone call
      asking why a password stopped working, and whoever takes it needs the answer
      on the screen rather than in the audit log.
    --}}
    <div class="alert {{ $user->passwordIsTemporary() ? 'danger' : 'warn' }} mb-16">
      <span>&#128273;</span>
      <div>
        @if ($user->passwordIsTemporary())
          <strong>Temporary password set {{ \App\Support\Wat::relative($user->password_reset_at) }}
            by {{ $user->passwordResetBy?->name ?? 'a removed account' }}</strong>
          @if ($user->password_reset_reason) &mdash; {{ $user->password_reset_reason }} @endif.
          It was given to {{ $user->name }} directly, not emailed. Until they sign in and change it,
          whoever set it knows a password that opens this account &mdash; so this state is meant to last
          minutes, not days. {{ $user->name }} has been emailed that it happened.
        @else
          <strong>Password reset {{ \App\Support\Wat::relative($user->password_reset_at) }}
            by {{ $user->passwordResetBy?->name ?? 'a removed account' }}</strong>
          @if ($user->password_reset_reason) &mdash; {{ $user->password_reset_reason }} @endif.
          Their old password no longer works and no new one has been set &mdash; not by them, and not by
          anybody else. A code has gone to {{ $user->email }}; they choose the password themselves.
        @endif
      </div>
    </div>
  @endif

  <div class="split">
    <div class="stack">
      <div class="card">
        <div class="card-head">
          <div><h3>Roles &amp; Data Scope</h3>
            <p>Each assignment carries its own scope, so the same permission can cover different places
               under different roles</p></div>
          @if ($canManageRoles)
            <a href="#modal-assign" class="btn btn-primary btn-sm">Assign role</a>
          @endif
        </div>
        <div class="card-body flush">
          <div class="table-wrap">
            <table>
              <thead><tr><th>Role</th><th>Scope</th><th class="num">Permissions</th>
                <th>Assigned</th><th class="actions">Actions</th></tr></thead>
              <tbody>
                @forelse ($assignments as $assignment)
                  <tr>
                    <td>
                      @can('admin.roles.view')
                        <a href="{{ route('admin.roles.show', $assignment->role) }}" class="font-bold">{{ $assignment->role?->name }}</a>
                      @else
                        <span class="font-bold">{{ $assignment->role?->name }}</span>
                      @endcan
                      @if ($assignment->role?->is_automatic)
                        <div class="cell-sub">automatic &mdash; every user holds this</div>
                      @endif
                    </td>
                    <td>{{ $assignment->describeScope() }}
                      <div class="cell-sub">{{ $assignment->scopeType()->label() }}</div></td>
                    <td class="num">{{ $assignment->role?->livePermissions()->count() }}</td>
                    <td>{{ \App\Support\Wat::date($assignment->assigned_at) }}
                      <div class="cell-sub">{{ $assignment->assignedBy?->name }}</div></td>
                    <td class="actions">
                      @if ($canManageRoles && ! $assignment->role?->is_automatic)
                        <form method="POST" action="{{ route('admin.users.roles.destroy', [$user, $assignment]) }}">
                          @csrf @method('DELETE')
                          <button type="submit" class="btn btn-ghost btn-sm text-danger">Remove</button>
                        </form>
                      @endif
                    </td>
                  </tr>
                @empty
                  <tr><td colspan="5">@include('partials.empty', ['title' => 'No roles assigned', 'icon' => '&#128737;'])</td></tr>
                @endforelse
              </tbody>
            </table>
          </div>
        </div>
        <div class="card-body">
          <div class="meta-item">
            <div class="meta-label">Overall data scope</div>
            <div class="meta-value">{{ $scopeDescription }}</div>
            <div class="cell-sub">This is what they are told they can reach if they are refused access.</div>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-head">
          <div><h3>Effective Permissions</h3>
            <p>Everything their roles grant, combined. Anything not listed here is refused</p></div>
          <span class="pill">{{ count($effectivePermissions) }}</span>
        </div>
        <div class="card-body">
          <div class="chip-group">
            @forelse (collect($effectivePermissions)->sort() as $key)
              <span class="chip on perm-key">{{ $key }}</span>
            @empty
              <span class="text-muted text-small">No permissions &mdash; this account can reach the dashboard and nothing else.</span>
            @endforelse
          </div>
        </div>
      </div>

      {{--
        The card is titled "Sessions & Devices" and the controller has always
        passed $sessions, but only the devices table was ever rendered — so an
        administrator investigating an account could see which devices were
        trusted and not where the account was actually signed in. Both halves are
        here now.
      --}}
      <div class="card">
        <div class="card-head">
          <div><h3>Sessions</h3><p>Where this account is signed in</p></div>
          <div class="flex">
            <span class="badge {{ $sessions->whereNull('ended_at')->isNotEmpty() ? 'success' : 'muted' }}">
              {{ $sessions->whereNull('ended_at')->count() }} open
            </span>
            @if ($canEdit && ($sessions->whereNull('ended_at')->isNotEmpty() || $apiTokens->isNotEmpty()))
              <form method="POST" action="{{ route('admin.users.sign-out-everywhere', $user) }}" style="display:inline">
                @csrf
                <button type="submit" class="btn btn-ghost btn-sm">Sign out everywhere</button>
              </form>
            @endif
          </div>
        </div>
        <div class="card-body flush">
          <div class="table-wrap">
            <table>
              <thead><tr><th>Started</th><th>Last seen</th><th>IP</th><th>Device</th><th>Status</th></tr></thead>
              <tbody>
                @forelse ($sessions as $session)
                  <tr>
                    <td>{{ \App\Support\Wat::dateTime($session->started_at) }}</td>
                    <td>{{ \App\Support\Wat::relative($session->last_seen_at) }}</td>
                    <td class="mono">{{ $session->ip ?? '—' }}</td>
                    <td>{{ $session->device?->label ?? '—' }}</td>
                    <td>
                      @if ($session->ended_at === null)
                        <span class="badge success">Open</span>
                      @else
                        <span class="badge muted">Ended{{ $session->ended_reason ? ' · '.str_replace('_', ' ', $session->ended_reason) : '' }}</span>
                      @endif
                    </td>
                  </tr>
                @empty
                  <tr><td colspan="5"><div class="text-muted text-small">This account has never signed in.</div></td></tr>
                @endforelse
              </tbody>
            </table>
          </div>
        </div>
      </div>

      {{--
        ARCH-2 — the phones, which are neither sessions nor trusted devices.
        MobileSigninService writes no session register row and only issues a
        Device when the agent ticked "remember this phone", so a live bearer
        token against the whole /api/v1 write surface — POST /sync/batch records
        deliveries, sales and farmer registrations — was visible on no screen an
        administrator could reach. "Sign out everywhere" above revokes them.
      --}}
      <div class="card">
        <div class="card-head">
          <div><h3>Mobile sign-ins</h3>
            <p>AgentConnect phones holding a live token for this account</p></div>
          <span class="badge {{ $apiTokens->isNotEmpty() ? 'success' : 'muted' }}">{{ $apiTokens->count() }} live</span>
        </div>
        <div class="card-body flush">
          <div class="table-wrap">
            <table>
              <thead><tr><th>Phone</th><th>Platform</th><th>Last IP</th><th>Expires</th><th>Last used</th></tr></thead>
              <tbody>
                @forelse ($apiTokens as $token)
                  <tr>
                    <td>{{ $token->name }}
                      @if ($token->device)<span class="text-muted text-small">&middot; trusted device</span>@endif
                    </td>
                    <td>{{ $token->platform ?? '—' }}{{ $token->app_version ? ' v'.$token->app_version : '' }}</td>
                    <td class="mono">{{ $token->last_ip ?? '—' }}</td>
                    <td>{{ $token->expires_at ? \App\Support\Wat::date($token->expires_at) : 'never' }}</td>
                    <td>{{ \App\Support\Wat::relative($token->last_used_at) }}</td>
                  </tr>
                @empty
                  <tr><td colspan="5"><div class="text-muted text-small">No phone is signed in to this account.</div></td></tr>
                @endforelse
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-head"><div><h3>Trusted devices</h3>
          <p>Devices this account can skip the sign-in code on</p></div></div>
        <div class="card-body flush">
          <div class="table-wrap">
            <table>
              <thead><tr><th>Device</th><th>Last IP</th><th>Trusted until</th><th>Last seen</th><th class="actions"></th></tr></thead>
              <tbody>
                @forelse ($devices as $device)
                  <tr>
                    <td>{{ $device->label ?? 'Unnamed' }}</td>
                    <td class="mono">{{ $device->last_ip ?? '—' }}</td>
                    <td>{{ $device->revoked_at ? 'revoked' : \App\Support\Wat::date($device->trusted_until) }}</td>
                    <td>{{ \App\Support\Wat::relative($device->last_seen_at) }}</td>
                    <td class="actions">
                      @if ($canEdit && $device->revoked_at === null)
                        {{-- AUTH-2's administrator half: the stolen-phone lever. --}}
                        <form method="POST" action="{{ route('admin.users.devices.revoke', [$user, $device]) }}">
                          @csrf
                          <button type="submit" class="btn btn-ghost btn-sm text-danger">Revoke</button>
                        </form>
                      @endif
                    </td>
                  </tr>
                @empty
                  <tr><td colspan="5"><div class="text-muted text-small">No trusted devices.</div></td></tr>
                @endforelse
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <div class="stack">
      <div class="card">
        <div class="card-head"><div><h3>Account</h3></div></div>
        <div class="card-body">
          <div class="meta-grid cols-2">
            <div class="meta-item"><div class="meta-label">Email</div><div class="meta-value">{{ $user->email }}</div>
              @if ($user->email_changed_at)
                {{--
                  AUTH-8 — this address receives every activation and reset code,
                  so who last moved it is a security fact, not a profile detail.
                --}}
                <div class="cell-sub text-warning">changed {{ \App\Support\Wat::relative($user->email_changed_at) }}
                  by {{ $user->emailChangedBy?->name ?? 'a removed account' }}</div>
              @endif</div>
            <div class="meta-item"><div class="meta-label">Phone</div><div class="meta-value">{{ $user->phone ?? '—' }}</div></div>
            <div class="meta-item"><div class="meta-label">Employee record</div>
              <div class="meta-value">{{ $user->employee?->name ?? 'not linked' }}</div></div>
            <div class="meta-item"><div class="meta-label">Two-factor</div>
              <div class="meta-value">{{ $user->two_factor_enabled ? 'Required' : 'Not required' }}</div></div>
            <div class="meta-item"><div class="meta-label">Password changed</div>
              <div class="meta-value">{{ $user->password_changed_at ? \App\Support\Wat::date($user->password_changed_at) : 'never' }}</div>
              @if ($user->passwordIsTemporary())
                <div class="cell-sub text-danger">temporary, set
                  {{ \App\Support\Wat::relative($user->password_reset_at) }} by
                  {{ $user->passwordResetBy?->name ?? 'a removed account' }} &mdash; they must change it at
                  next sign-in</div>
              @elseif ($user->awaitingPasswordReset())
                {{-- The date above is when the USER last chose one, which is still
                     the honest answer; it just is not the password in force, because
                     there is not one. --}}
                <div class="cell-sub text-warning">reset {{ \App\Support\Wat::relative($user->password_reset_at) }}
                  by {{ $user->passwordResetBy?->name ?? 'a removed account' }} &mdash; awaiting their new one</div>
              @elseif ($user->passwordHasExpired())
                <div class="cell-sub text-danger">expired &mdash; must change on next sign-in</div>
              @endif</div>
            <div class="meta-item"><div class="meta-label">Created by</div>
              <div class="meta-value">{{ $user->createdBy?->name ?? 'system' }}</div></div>
          </div>
          <div class="divider"></div>
          {{--
            The old wording here was "there is no password on this screen and no
            way to set one". Half of that is no longer true, and a reassurance that
            has stopped being true is worse than none — so it says what is actually
            the case: you cannot READ a password, only replace it.
          --}}
          <div class="alert info">
            <span>&#128274;</span>
            <div>No password can be read from this screen &mdash; hashes are one-way, so not even the
              database has one. &ldquo;Email a reset code&rdquo; lets the user choose their own and you
              never see it. &ldquo;Set a password&rdquo; means you know theirs until they change it at
              their next sign-in, and they are emailed that you did.</div>
          </div>
        </div>
      </div>
    </div>
  </div>

  @if ($canManageRoles)
    <div id="modal-assign" class="modal">
      <a href="#" class="modal-overlay"></a>
      <div class="modal-dialog">
        <div class="modal-head">
          <div><h3>Assign Role</h3><p>The scope you choose is what this assignment can reach</p></div>
          <a href="#" class="modal-close">&times;</a>
        </div>
        <form method="POST" action="{{ route('admin.users.roles.store', $user) }}">
          @csrf
          <input type="hidden" name="_modal" value="modal-assign" />
          <div class="modal-body">
            @include('partials.modal-errors', ['modal' => 'modal-assign'])
            <div class="field mb-16"><label for="ar-role">Role <span class="req">*</span></label>
              <select id="ar-role" name="role_id" required>
                @foreach ($assignableRoles as $role)
                  <option value="{{ $role->id }}">
                    {{ $role->name }} ({{ $role->livePermissions()->count() }} permissions,
                    default {{ $role->defaultScopeType()->label() }})
                  </option>
                @endforeach
              </select>
              <div class="hint">A draft or retired role cannot be assigned.</div></div>

            <div class="field mb-16"><label for="ar-scope">Data scope <span class="req">*</span></label>
              <select id="ar-scope" name="scope_type" required>
                @foreach ($scopeTypes as $scopeType)
                  <option value="{{ $scopeType->value }}">{{ $scopeType->label() }}</option>
                @endforeach
              </select>
              <div class="hint">
                A scope that needs a target reaches nothing until you choose the target below.
              </div></div>

            {{--
              One picker per scope dimension, each taking as many targets as the
              job needs. A supervisor who covers two centres is an ordinary fact
              of the network, and the alternative — the same role assigned twice —
              makes it easy to revoke one centre and believe you revoked both.

              They are separate selects rather than one merged list because the
              ids are only unique within their own table: centre 1 and LGA 1 are
              different places, and a single list could not tell the two apart.
            --}}
            <div class="field mb-16">
              <label for="ar-target">Targets — for a point, center, LGA or department scope</label>
              <select id="ar-target" name="scope_target_ids[]" multiple size="8">
                <optgroup label="Collection centers">
                  @foreach ($centers as $center)
                    <option value="{{ $center->id }}"
                      @selected(in_array($center->id, (array) old('scope_target_ids', []))) >{{ $center->name }}</option>
                  @endforeach
                </optgroup>
                <optgroup label="Collection points">
                  @foreach ($points as $point)
                    <option value="{{ $point->id }}"
                      @selected(in_array($point->id, (array) old('scope_target_ids', []))) >{{ $point->name }}</option>
                  @endforeach
                </optgroup>
                <optgroup label="LGAs">
                  @foreach ($lgas as $lga)
                    <option value="{{ $lga->id }}"
                      @selected(in_array($lga->id, (array) old('scope_target_ids', []))) >{{ $lga->name }}</option>
                  @endforeach
                </optgroup>
                <optgroup label="Departments">
                  @foreach ($departments as $department)
                    <option value="{{ $department->id }}"
                      @selected(in_array($department->id, (array) old('scope_target_ids', []))) >{{ $department->name }}</option>
                  @endforeach
                </optgroup>
              </select>
              <div class="hint">
                Pick from the group matching the scope type above &mdash; hold
                {{ 'Ctrl' }} (or {{ 'Cmd' }}) to choose more than one. Leave empty
                for a network or own-records scope.
              </div>
            </div>

            <div class="field">
              <label for="ar-communities">Communities &mdash; for the communities scope</label>
              <select id="ar-communities" name="community_ids[]" multiple size="8">
                @foreach ($communities as $community)
                  <option value="{{ $community->id }}"
                    @selected(in_array($community->id, (array) old('community_ids', []))) >{{ $community->name }} ({{ $community->lga?->name }})</option>
                @endforeach
              </select>
              <div class="hint">Choose every community this assignment covers.</div>
            </div>
          </div>
          <div class="modal-foot">
            <a href="#" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-primary">Assign role</button>
          </div>
        </form>
      </div>
    </div>
  @endif

  @if ($canEdit)
    <div id="modal-edit-user" class="modal">
      <a href="#" class="modal-overlay"></a>
      <div class="modal-dialog">
        <div class="modal-head"><div><h3>Edit Account</h3><p>{{ $user->email }}</p></div>
          <a href="#" class="modal-close">&times;</a></div>
        <form method="POST" action="{{ route('admin.users.update', $user) }}">
          @csrf
          <input type="hidden" name="_modal" value="modal-edit-user" /> @method('PUT')
          <div class="modal-body">
            @include('partials.modal-errors', ['modal' => 'modal-edit-user'])
            <div class="form-grid">
              <div class="field"><label for="eu-name">Name <span class="req">*</span></label>
                <input type="text" id="eu-name" name="name" value="{{ $user->name }}" required /></div>
              <div class="field"><label for="eu-email">Email <span class="req">*</span></label>
                <input type="email" id="eu-email" name="email" value="{{ $user->email }}" required /></div>
              <div class="field"><label for="eu-phone">Phone</label>
                <input type="text" id="eu-phone" name="phone" value="{{ $user->phone }}" /></div>
              <div class="field"><label for="eu-department">Department</label>
                <select id="eu-department" name="department_id">
                  <option value="">None</option>
                  @foreach ($departments as $department)
                    <option value="{{ $department->id }}" @selected($user->department_id == $department->id)>{{ $department->name }}</option>
                  @endforeach
                </select></div>
              <div class="field"><label for="eu-position">Position</label>
                <input type="text" id="eu-position" name="position" value="{{ $user->position }}" /></div>
              <div class="field full">
                <div class="stack" style="gap:10px">
                  <label class="check-label"><input type="checkbox" name="two_factor_enabled" value="1"
                    @checked($user->two_factor_enabled) /> Require the emailed sign-in code</label>
                  <label class="check-label"><input type="checkbox" name="is_test" value="1"
                    @checked($user->is_test) /> Test account &mdash; excluded from every report, aggregate and payroll</label>
                </div>
              </div>
            </div>
          </div>
          <div class="modal-foot">
            <a href="#" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-primary">Save account</button>
          </div>
        </form>
      </div>
    </div>
  @endif

  @if ($canEdit && $user->isActive() && $user->password_changed_at !== null && $user->id !== auth()->id())
    {{-- BR-31 — a reset, with no password field in it. --}}
    <div id="modal-reset-password" class="modal">
      <a href="#" class="modal-overlay"></a>
      <div class="modal-dialog narrow">
        <div class="modal-head">
          <div><h3>Email {{ $user->name }} a reset code</h3>
            <p>You do not choose the new password &mdash; they do</p></div>
          <a href="#" class="modal-close">&times;</a>
        </div>
        <form method="POST" action="{{ route('admin.users.reset-password', $user) }}">
          @csrf
          <input type="hidden" name="_modal" value="modal-reset-password" />
          <div class="modal-body">
            @include('partials.modal-errors', ['modal' => 'modal-reset-password'])
            <div class="alert warn mb-16">
              <span>&#9888;&#65039;</span>
              <div>Their current password stops working immediately, and every session and mobile token
                ends. A code goes to <strong>{{ $user->email }}</strong>, which they redeem to choose a
                new password. Trusted devices are left alone.</div>
            </div>
            <div class="field"><label for="rp-reason">Reason <span class="req">*</span></label>
              <input type="text" id="rp-reason" name="reason" required
                     placeholder="e.g. forgot password, called IT from Yola centre" />
              <div class="hint">Recorded in the audit log <em>and</em> included in the email they receive,
                so they know why their password changed and who did it.</div></div>
          </div>
          <div class="modal-foot">
            <a href="#" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-primary">Reset password</button>
          </div>
        </form>
      </div>
    </div>
  @endif

  @if ($canEdit && $user->isActive() && $user->password_changed_at !== null && $user->id !== auth()->id())
    {{--
      BR-31, qualified — the one password field in the administration screens.
      Everything in this modal that reads like a warning is load-bearing: an
      administrator who does not understand that they will know this person's
      password is an administrator who will use this instead of the reset code
      because it is one step shorter.
    --}}
    <div id="modal-set-password" class="modal">
      <a href="#" class="modal-overlay"></a>
      <div class="modal-dialog narrow">
        <div class="modal-head">
          <div><h3>Set a temporary password for {{ $user->name }}</h3>
            <p>They must change it the first time they sign in</p></div>
          <a href="#" class="modal-close">&times;</a>
        </div>
        <form method="POST" action="{{ route('admin.users.set-password', $user) }}">
          @csrf
          <input type="hidden" name="_modal" value="modal-set-password" />
          <div class="modal-body">
            @include('partials.modal-errors', ['modal' => 'modal-set-password'])
            <div class="alert warn mb-16">
              <span>&#9888;&#65039;</span>
              <div><strong>You will know {{ $user->name }}&rsquo;s password until they change it</strong>,
                which means you could sign in as them &mdash; and anything done that way carries their name,
                not yours. Use &ldquo;Email a reset code&rdquo; instead unless they cannot reach their
                mailbox right now. {{ $user->name }} is emailed that you did this, and Internal Audit and
                the General Manager are notified.</div>
            </div>
            <div class="field mb-16"><label for="sp-password">Temporary password <span class="req">*</span></label>
              <input type="password" id="sp-password" name="password" required autocomplete="new-password" />
              <div class="hint">{{ $policyDescription }}</div></div>
            <div class="field mb-16"><label for="sp-password-confirm">Confirm it <span class="req">*</span></label>
              <input type="password" id="sp-password-confirm" name="password_confirmation" required
                     autocomplete="new-password" />
              <div class="hint">Say it to them by phone or in person. It is deliberately not emailed &mdash;
                a live password sitting in an inbox is the thing the reset code exists to avoid.</div></div>
            <div class="field"><label for="sp-reason">Reason <span class="req">*</span></label>
              <input type="text" id="sp-reason" name="reason" required
                     placeholder="e.g. no mobile data at Yola centre, needs access now" />
              <div class="hint">Recorded in the audit log and included in the email they receive.</div></div>
          </div>
          <div class="modal-foot">
            <a href="#" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-danger">Set temporary password</button>
          </div>
        </form>
      </div>
    </div>
  @endif

  @if ($canDeactivate)
    <div id="modal-deactivate" class="modal">
      <a href="#" class="modal-overlay"></a>
      <div class="modal-dialog narrow">
        <div class="modal-head">
          <div><h3>Deactivate {{ $user->name }}</h3><p>Nothing is deleted</p></div>
          <a href="#" class="modal-close">&times;</a>
        </div>
        <form method="POST" action="{{ route('admin.users.deactivate', $user) }}">
          @csrf
          <input type="hidden" name="_modal" value="modal-deactivate" />
          <div class="modal-body">
            @include('partials.modal-errors', ['modal' => 'modal-deactivate'])
            <div class="alert warn mb-16">
              <span>&#9888;&#65039;</span>
              <div>Sign-in is blocked, sessions and trusted devices are revoked, and every historical record
                keeps their name.</div>
            </div>
            <div class="field"><label for="da-reason">Reason <span class="req">*</span></label>
              <input type="text" id="da-reason" name="reason" required />
              <div class="hint">Recorded in the audit log.</div></div>
          </div>
          <div class="modal-foot">
            <a href="#" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-danger">Deactivate</button>
          </div>
        </form>
      </div>
    </div>
  @endif
@endsection
