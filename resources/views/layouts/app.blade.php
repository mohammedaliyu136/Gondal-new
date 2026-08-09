{{--
  The app shell from the prototype: sidebar + topbar + content.

  ARCH-3 — server-rendered HTML using the prototype's markup. No SPA framework.
  NFR-10 — the prototype's focus states and label bindings are preserved.
  NFR-11 — usable at 360px; the CSS-only `#nav` toggle is the reference behaviour.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="csrf-token" content="{{ csrf_token() }}" />
  <title>@yield('title', 'Gondal ERP') · Gondal ERP</title>
  <link rel="stylesheet" href="{{ asset('css/styles.css') }}" />
</head>
<body>
<div class="layout">

  @include('partials.sidebar')

  <div class="main">
    @include('partials.topbar', ['pageTitle' => trim($__env->yieldContent('title', 'Dashboard'))])

    <main class="content">
      @include('partials.flash')

      @yield('content')
    </main>
  </div>
</div>
  {{--
    Progressive enhancement only: turns long <select>s into searchable ones.
    Deferred, and the page is fully usable if it never arrives.
  --}}
  <script src="{{ asset('js/combo.js') }}" defer></script>
  {{-- Same contract: the revalidation screen works without it. --}}
  <script src="{{ asset('js/validation-bulk.js') }}" defer></script>
</body>
</html>
