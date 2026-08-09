{{--
  SCR-1 — access-denied.html, populated from the denial.

  Every field the prototype shows is real: the attempted page, the missing
  permission and its action, the user's role, and their data scope. AUDIT-5 — the
  quotable reference (DENY-####) is the one written to the audit log.

  SCOPE-3 — a scope failure renders this same page. Only the leading sentence
  differs, because "you lack the permission" and "this record is not yours to
  see" are different facts and the user deserves the right one.
--}}
@extends('layouts.app')

@section('title', 'Access denied')

@section('content')
  <div class="denied-wrap">
    <div class="denied-ic">&#128274;</div>
    <h1>You don&rsquo;t have access to this page</h1>

    @php($permissionName = $denial['permission_label'] ?: 'This action')

    @if ($isScopeFailure)
      <p>
        <strong>{{ $permissionName }}</strong> is part of your role,
        <strong>{{ $denial['primary_role'] }}</strong>, but this record is outside your data scope
        &mdash; <strong>{{ $denial['data_scope'] }}</strong>.
      </p>
    @else
      <p>
        <strong>{{ $permissionName }}</strong> is not part of your role,
        <strong>{{ $denial['primary_role'] }}</strong>.
      </p>
    @endif

    <div class="card mt-20" style="text-align:left">
      <div class="card-head">
        <div>
          <h3>What you tried to open</h3>
        </div>
      </div>
      <div class="card-body">
        <div class="meta-grid cols-2">
          <div class="meta-item">
            <div class="meta-label">Page</div>
            <div class="meta-value">{{ $denial['attempted_label'] ?? $denial['attempted_route'] ?? '—' }}</div>
          </div>
          <div class="meta-item">
            <div class="meta-label">Permission Needed</div>
            <div class="meta-value mono">
              @if ($denial['permission_key'])
                {{ $denial['permission_key'] }}
                @if ($denial['permission_label'])
                  <div class="cell-sub">{{ $denial['permission_label'] }}</div>
                @endif
              @else
                &mdash;
              @endif
            </div>
          </div>
          <div class="meta-item">
            <div class="meta-label">Your Role{{ count($denial['roles']) > 1 ? 's' : '' }}</div>
            <div class="meta-value">
              {{ count($denial['roles']) ? implode(', ', $denial['roles']) : 'None assigned' }}
            </div>
          </div>
          <div class="meta-item">
            <div class="meta-label">Your Data Scope</div>
            <div class="meta-value">{{ $denial['data_scope'] ?? 'Own records only' }}</div>
          </div>
        </div>
        <div class="divider"></div>
        <div class="alert info">
          <span>&#8505;&#65039;</span>
          <div>
            If you need this access for your work, ask your system administrator to add this permission
            to your role. Quote the reference at the bottom of this page.
          </div>
        </div>
      </div>
    </div>

    <div class="flex flex-wrap mt-20" style="justify-content:center">
      <a href="{{ route('dashboard') }}" class="btn btn-primary">Back to dashboard</a>
      @can('milk.points.view')
        <a href="{{ route('collection-centers.index') }}" class="btn btn-outline">Go to my collection center</a>
      @endcan
    </div>

    {{-- AUDIT-5 — the reference the user can quote. --}}
    <div class="text-small text-muted mt-20">
      Attempt recorded in the audit log &middot; {{ $denial['occurred_at'] }} &middot;
      ref {{ $denial['reference'] }}
    </div>
  </div>
@endsection
