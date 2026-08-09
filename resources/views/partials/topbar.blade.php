{{--
  The prototype topbar. The bell count and the user block come from real data.
  §15.5 — global search is a known gap deliberately out of v1, so the search box
  is rendered (rule 1: the HTML is normative) but marked as not yet wired rather
  than pretending to work.
--}}
@php($user = auth()->user())
@php($unread = $user ? $user->appNotifications()->unread()->count() : 0)

<header class="topbar">
  <a href="#nav" class="topbar-menu" aria-label="Open menu">@include('partials.icon', ['name' => 'menu'])</a>
  <div class="topbar-title">{{ $pageTitle ?? 'Dashboard' }}</div>
  <div class="topbar-search">
    <span>&#128269;</span>
    <label for="topbar-search" class="sr-only" style="position:absolute;left:-9999px">Search</label>
    <input id="topbar-search" type="text" placeholder="Search is not available yet" disabled />
  </div>
  <a href="{{ route('notifications.index') }}" class="topbar-bell" aria-label="Notifications">
    @include('partials.icon', ['name' => 'bell'])
    @if ($unread > 0)<span class="dot">{{ $unread }}</span>@endif
  </a>
  <div class="topbar-user">
    <div class="avatar">{{ $user?->initials() }}</div>
    <div>
      <div class="font-bold" style="font-size:13px">{{ $user?->name }}</div>
      <div class="text-muted" style="font-size:11.5px">
        {{ $user?->primaryRoleLabel() }}@if ($user?->is_test) &middot; test account @endif
      </div>
    </div>
  </div>
</header>
