{{--
  SCR-2 — rendered from the signed-in user's effective permissions. A user
  without shop.inventory.view does not see the One-Stop Shop item at all, and a
  group whose children are all hidden is omitted entirely.

  The markup matches the prototype's sidebar exactly (rule 1). NFR-11 — the
  `#nav` / `.nav-close` anchors are the prototype's CSS-only mobile toggle and
  are kept as-is.
--}}
@php($navigation = \App\Support\Navigation::forUser(auth()->user()))

<aside class="sidebar" id="nav">
  <a href="#" class="nav-close" aria-label="Close menu">&times;</a>
  <div class="brand">
    <div class="brand-logo">G</div>
    <div>
      <div class="brand-name">Gondal ERP</div>
      <div class="brand-sub">Trust Data Systems</div>
    </div>
  </div>

  <nav class="nav">
    @foreach ($navigation as $item)
      @if (($item['type'] ?? 'link') === 'group')
        @php($groupIsActive = collect($item['children'])->contains(fn ($child) => \App\Support\Navigation::isActive($child['route'])))
        <details class="nav-group" @if ($groupIsActive) open @endif>
          <summary>
            @include('partials.icon', ['name' => $item['icon']])
            {{ $item['label'] }}<span class="caret"></span>
          </summary>
          <div class="nav-sub">
            @foreach ($item['children'] as $child)
              <a href="{{ route($child['route']) }}"
                 @class(['active' => \App\Support\Navigation::isActive($child['route'])])>{{ $child['label'] }}</a>
            @endforeach
          </div>
        </details>
      @else
        <a href="{{ route($item['route']) }}"
           @class(['active' => \App\Support\Navigation::isActive($item['route'])])>
          @include('partials.icon', ['name' => $item['icon']])
          {{ $item['label'] }}
        </a>
      @endif
    @endforeach
  </nav>

  <div class="sidebar-account">
    <a href="{{ route('profile') }}" @class(['active' => \App\Support\Navigation::isActive('profile')])>
      @include('partials.icon', ['name' => 'user'])
      My Profile
    </a>
    <form method="POST" action="{{ route('auth.signout') }}">
      @csrf
      <button type="submit" class="signout" style="all:unset;cursor:pointer;display:flex;align-items:center;gap:10px;width:100%;padding:9px 12px;border-radius:8px;color:inherit">
        @include('partials.icon', ['name' => 'signout'])
        Sign out
      </button>
    </form>
  </div>
  <div class="sidebar-footer">Gondal Fulbe Development Co. &middot; v2.0.0</div>
</aside>
