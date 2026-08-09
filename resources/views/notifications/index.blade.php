@extends('layouts.app')
@section('title', 'Notifications')

@section('content')
  <div class="page-head">
    <div>
      <h1>Notifications</h1>
      <p>{{ number_format($unreadCount) }} unread</p>
    </div>
    <div class="page-actions">
      <a href="#modal-preferences" class="btn btn-outline">Preferences</a>
      <form method="POST" action="{{ route('notifications.read-all') }}" style="display:inline">
        @csrf
        <button type="submit" class="btn btn-primary">Mark all read</button>
      </form>
    </div>
  </div>

  <div class="stack">
    <div class="card">
      <div class="card-head"><div><h3>Inbox</h3></div></div>
      <div class="card-body">
        @forelse ($notifications as $notification)
          <div class="notif {{ $notification->isUnread() ? 'unread' : '' }}">
            <div class="n-ic">{!! $notification->isUnread() ? '&#128276;' : '&#128227;' !!}</div>
            <div class="grow">
              <div class="n-title">{{ $notification->title }}</div>
              @if ($notification->body)<div class="n-sub">{{ $notification->body }}</div>@endif
              <div class="n-time">
                {{ \App\Support\Wat::relative($notification->created_at) }}
                &middot; {{ \Illuminate\Support\Str::headline($notification->type) }}
                @if ($notification->channel_flags)
                  &middot; {{ implode(', ', array_map(fn ($c) => str_replace('_', '-', $c), $notification->channel_flags)) }}
                @endif
              </div>
            </div>
            <div class="flex">
              @if ($notification->action_url)
                <a href="{{ $notification->action_url }}" class="btn btn-ghost btn-sm">Open</a>
              @endif
              @if ($notification->isUnread())
                <form method="POST" action="{{ route('notifications.read', $notification) }}">
                  @csrf
                  <button type="submit" class="btn btn-ghost btn-sm">Mark read</button>
                </form>
              @endif
            </div>
          </div>
        @empty
          @include('partials.empty', ['title' => 'Nothing here yet', 'icon' => '&#128276;'])
        @endforelse
      </div>
      @include('partials.pagination', ['paginator' => $notifications, 'noun' => 'notifications'])
    </div>
  </div>

  <div id="modal-preferences" class="modal">
    <a href="#" class="modal-overlay"></a>
    <div class="modal-dialog">
      <div class="modal-head">
        <div><h3>Notification Preferences</h3><p>Per event, per channel</p></div>
        <a href="#" class="modal-close">&times;</a>
      </div>
      <form method="POST" action="{{ route('notifications.preferences') }}">
        @csrf
          <input type="hidden" name="_modal" value="modal-preferences" /> @method('PUT')
        <div class="modal-body">
            @include('partials.modal-errors', ['modal' => 'modal-preferences'])
          <div class="table-wrap">
            <table>
              <thead><tr>
                <th>Event</th><th class="perm-check">In-app</th><th class="perm-check">Email</th><th class="perm-check">SMS</th>
              </tr></thead>
              <tbody>
                @foreach ($events as $event)
                  @php($channels = $channelsFor($event))
                  <tr>
                    <td>
                      <div class="font-bold">{{ $event->name }}</div>
                      <div class="cell-sub">{{ $event->module }}</div>
                    </td>
                    <td class="perm-check">
                      <input type="checkbox" name="preferences[{{ $event->code }}][in_app]" value="1"
                             @checked(in_array('in_app', $channels, true)) />
                    </td>
                    <td class="perm-check">
                      <input type="checkbox" name="preferences[{{ $event->code }}][email]" value="1"
                             @checked(in_array('email', $channels, true)) />
                    </td>
                    <td class="perm-check">
                      <input type="checkbox" name="preferences[{{ $event->code }}][sms]" value="1"
                             @checked(in_array('sms', $channels, true)) />
                    </td>
                  </tr>
                @endforeach
              </tbody>
            </table>
          </div>
        </div>
        <div class="modal-foot">
          <a href="#" class="btn btn-ghost">Cancel</a>
          <button type="submit" class="btn btn-primary">Save preferences</button>
        </div>
      </form>
    </div>
  </div>
@endsection
