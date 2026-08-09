{{--
  SCOPE-4 — a figure on screen is only as wide as the viewer's scope, so the
  screen says which it is rather than leaving the reader to assume "network".
--}}
<div class="text-small text-muted">
  Figures cover <strong>{{ auth()->user()?->overallScopeDescription() }}</strong>.
  @unless ($seesNetwork ?? false)
    Network-wide totals are not shown to your role.
  @endunless
</div>
