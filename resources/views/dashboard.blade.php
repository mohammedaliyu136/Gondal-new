@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
  @php($m = $metrics)

  <div class="page-head">
    <div>
      <h1>{{ \App\Support\Wat::local()->hour < 12 ? 'Good morning' : (\App\Support\Wat::local()->hour < 17 ? 'Good afternoon' : 'Good evening') }},
        {{ \Illuminate\Support\Str::before(auth()->user()->name, ' ') }}</h1>
      <p>Here is what is happening across your scope today &middot; {{ \App\Support\Wat::longDate($m['today']) }}</p>
    </div>
    <div class="page-actions">
      {{--
        These carry the modal's fragment, not just the screen's URL. Without it
        the button landed you on the list with the form still shut — the label
        promised an action and delivered a page.
      --}}
      @can('milk.deliveries.create')
        <a href="{{ route('deliveries.index') }}#modal-record" class="btn btn-outline">+ Record Milk Intake</a>
      @endcan
      @can('purchase.requisitions.create')
        <a href="{{ route('requisitions.index') }}#modal-new-req" class="btn btn-primary">+ New Requisition</a>
      @endcan
    </div>
  </div>

  {{-- SCOPE-4 — say whose figures these are. --}}
  <div class="alert info mb-16">
    <span>&#8505;&#65039;</span>
    <div>@include('partials.scope-note', ['seesNetwork' => $m['sees_network_totals']])</div>
  </div>

  <div class="grid grid-4 mb-16">
    @if ($m['milk'])
      <div class="stat green">
        <div class="stat-ic">&#127869;</div>
        <div class="stat-label">Milk Confirmed Today</div>
        <div class="stat-value">{{ \App\Support\Volume::format($m['milk']['litres_confirmed']) }}</div>
        <div class="stat-foot">
          @if ($m['milk']['change_pct'] !== null)
            {!! $m['milk']['change_pct'] >= 0 ? '&#9650;' : '&#9660;' !!}
            {{ abs($m['milk']['change_pct']) }}% vs yesterday &middot;
          @endif
          {{ number_format($m['milk']['deliveries']) }} deliveries
        </div>
      </div>
    @endif

    @if ($m['farmers'])
      <div class="stat blue">
        <div class="stat-ic">&#127806;</div>
        <div class="stat-label">Active Farmers</div>
        <div class="stat-value">{{ number_format($m['farmers']['active']) }}</div>
        <div class="stat-foot">+ {{ number_format($m['farmers']['enrolled_this_week']) }} enrolled this week</div>
      </div>
    @endif

    @if ($m['approvals'])
      <div class="stat amber">
        <div class="stat-ic">&#128221;</div>
        <div class="stat-label">Requisitions Awaiting You</div>
        <div class="stat-value">{{ number_format($m['approvals']['awaiting']) }}</div>
        <div class="stat-foot">
          @forelse ($m['approvals']['by_stage']->take(2) as $stage => $count)
            {{ $count }} at {{ $stage }}@if (! $loop->last) &middot; @endif
          @empty
            Nothing waiting on you
          @endforelse
        </div>
      </div>
    @endif

    @if ($m['rejections'])
      <div class="stat red">
        <div class="stat-ic">&#128230;</div>
        <div class="stat-label">Rejections Today</div>
        <div class="stat-value">{{ \App\Support\Volume::format($m['rejections']['total']) }}</div>
        <div class="stat-foot">
          @forelse ($m['rejections']['by_reason']->take(2) as $row)
            {{ \App\Support\Volume::format($row['litres'], false) }} L {{ \Illuminate\Support\Str::lower($row['reason']) }}@if (! $loop->last) &middot; @endif
          @empty
            None recorded
          @endforelse
        </div>
      </div>
    @endif
  </div>

  <div class="grid grid-3 mb-16">
    @if ($m['intake_week'])
      <div class="card" style="grid-column: span 2">
        <div class="card-head">
          <div>
            <h3>Milk Intake &mdash; Last 7 Days</h3>
            <p>Confirmed litres within your scope</p>
          </div>
          @if ($m['quality'])
            <div class="flex">
              @foreach ($m['quality']['rows']->take(2) as $row)
                <span class="pill {{ $loop->first ? 'green' : '' }}">{{ $row['grade'] }}: {{ $row['percentage'] }}%</span>
              @endforeach
            </div>
          @endif
        </div>
        <div class="card-body">
          <div class="chart-bar">
            @foreach ($m['intake_week']['days'] as $day)
              @php($height = max(4, (int) round($day['centilitres'] / $m['intake_week']['peak_centilitres'] * 100)))
              <div class="chart-col">
                <div class="bar{{ $loop->last ? '' : ($loop->index % 3 === 2 ? ' alt' : '') }}"
                     style="height:{{ $height }}%"
                     title="{{ $day['date'] }}: {{ \App\Support\Volume::format($day['litres']) }}"></div>
                <span>{{ $day['label'] }}</span>
              </div>
            @endforeach
          </div>
        </div>
      </div>
    @endif

    @if ($m['quality'])
      <div class="card">
        <div class="card-head">
          <div>
            <h3>Collection Quality</h3>
            <p>Today&rsquo;s intake by grade</p>
          </div>
        </div>
        <div class="card-body">
          <div class="kpi-list">
            @foreach ($m['quality']['rows'] as $row)
              <div class="kpi-row">
                <div class="kpi-ic" style="background:{{ $row['is_rejection'] ? 'var(--danger-soft)' : ($loop->first ? 'var(--primary-soft)' : 'var(--info-soft)') }};color:{{ $row['is_rejection'] ? 'var(--danger)' : ($loop->first ? 'var(--primary-dark)' : 'var(--info)') }}">
                  {{ \Illuminate\Support\Str::substr($row['grade'], -1) }}
                </div>
                <div class="grow">
                  <div class="kpi-name">{{ $row['grade'] }}</div>
                  <div class="text-muted text-small">{{ \App\Support\Volume::format($row['litres']) }}</div>
                </div>
                <div class="kpi-val {{ $row['percentage'] < 5 ? 'small' : '' }}">{{ $row['percentage'] }}%</div>
              </div>
            @endforeach

            @if ($m['milk'])
              <div class="kpi-row">
                <div class="kpi-ic" style="background:var(--warning-soft);color:var(--warning)">&#9962;</div>
                <div class="grow">
                  <div class="kpi-name">Collection Points Active</div>
                  <div class="text-muted text-small">{{ $m['milk']['points_active'] }} of {{ $m['milk']['points_total'] }}</div>
                </div>
                <div class="kpi-val small">
                  {{ $m['milk']['points_total'] > 0 ? round($m['milk']['points_active'] / $m['milk']['points_total'] * 100) : 0 }}%
                </div>
              </div>
            @endif
          </div>
        </div>
      </div>
    @endif
  </div>

  <div class="grid grid-3">
    @if ($m['approvals'])
      <div class="card">
        <div class="card-head">
          <div>
            <h3>Approvals Awaiting You</h3>
            <p>Your workflow queue</p>
          </div>
          @can('purchase.requisitions.view')
            <a href="{{ route('requisitions.index') }}" class="btn btn-ghost btn-sm">View all</a>
          @endcan
        </div>
        <div class="card-body">
          @php($queue = auth()->user()->hasPermissionMatching('purchase.approve.')
              ? app(\App\Services\Workflow\WorkflowEngine::class)->queueFor(auth()->user())->limit(4)->get()
              : collect())
          @forelse ($queue as $item)
            <div class="queue-item">
              <div class="qi-ic">&#128221;</div>
              <div>
                <div class="qi-title">
                  @if ($item->subject instanceof \App\Models\Requisition)
                    <a href="{{ route('requisitions.show', $item->subject) }}">{{ $item->subject->reference }}</a>
                  @else
                    {{ $item->subject?->reference ?? class_basename($item->subject_type) }}
                  @endif
                </div>
                <div class="qi-sub">
                  {{ $item->subject->title ?? $item->workflow->name }}
                  @if ($item->amount_minor) &middot; {{ \App\Support\Money::compact((int) $item->amount_minor) }} @endif
                </div>
              </div>
              <div class="qi-right">
                <span class="badge {{ $item->isOverdue() ? 'danger' : 'warning' }}">
                  At {{ $item->currentStage?->name }}
                </span>
              </div>
            </div>
          @empty
            @include('partials.empty', ['title' => 'Nothing waiting on you', 'icon' => '&#9989;'])
          @endforelse
        </div>
      </div>
    @endif

    @if ($m['recent_centers'])
      <div class="card">
        <div class="card-head">
          <div>
            <h3>Latest Collections</h3>
            <p>Confirmed at centers today</p>
          </div>
          @can('milk.points.view')
            <a href="{{ route('collection-centers.index') }}" class="btn btn-ghost btn-sm">View all</a>
          @endcan
        </div>
        <div class="card-body">
          @forelse ($m['recent_centers'] as $row)
            <div class="queue-item">
              <div class="qi-ic">&#127869;</div>
              <div>
                <div class="qi-title">
                  <a href="{{ route('collection-centers.show', $row['center']) }}">{{ $row['center']->name }}</a>
                </div>
                <div class="qi-sub">
                  {{ number_format($row['farmers']) }} farmers &middot; {{ \App\Support\Volume::format($row['litres']) }}
                </div>
              </div>
              <div class="qi-right">
                @if ($row['dominant_grade'])
                  <span class="badge {{ $row['dominant_grade']->is_rejection ? 'danger' : ($loop->index % 2 === 0 ? 'success' : 'info') }}">
                    {{ $row['dominant_grade']->name }}
                  </span>
                @endif
              </div>
            </div>
          @empty
            @include('partials.empty', ['title' => 'No confirmations yet today', 'icon' => '&#127869;'])
          @endforelse
        </div>
      </div>
    @endif

    <div class="card">
      <div class="card-head">
        <div>
          <h3>Recent Activity</h3>
          <p>{{ auth()->user()->hasPermission('admin.audit.view') ? 'System-wide timeline' : 'Your recent actions' }}</p>
        </div>
        @can('admin.audit.view')
          <a href="{{ route('admin.audit-log') }}" class="btn btn-ghost btn-sm">Audit log</a>
        @endcan
      </div>
      <div class="card-body">
        <div class="timeline">
          @forelse ($timeline as $entry)
            <div class="tl-item {{ in_array($entry->event_type, ['blocked_access', 'failed_signin', 'rejection'], true) ? 'red' : (in_array($entry->event_type, ['permission_change', 'role_change'], true) ? 'amber' : '') }}">
              <div class="tl-title">{{ \Illuminate\Support\Str::headline($entry->event_type) }}</div>
              <div class="tl-sub">{{ $entry->summary }}</div>
              <div class="tl-time">{{ \App\Support\Wat::relative($entry->occurred_at) }}</div>
            </div>
          @empty
            @include('partials.empty', ['title' => 'Nothing recorded yet', 'icon' => '&#128220;'])
          @endforelse
        </div>
      </div>
    </div>
  </div>
@endsection
