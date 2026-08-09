{{--
  §4 — "Auth screens have no app shell." Markup matches login.html.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>@yield('title', 'Sign in') · Gondal ERP</title>
  <link rel="stylesheet" href="{{ asset('css/styles.css') }}" />
</head>
<body class="login-body">
  <div class="login-card">
    <div class="login-head">
      <div class="login-brand">G</div>
      <h1>@yield('heading', 'Welcome back')</h1>
      <p class="login-sub">@yield('subheading', 'Sign in to Gondal ERP')</p>
    </div>

    @yield('content')
  </div>
</body>
</html>
