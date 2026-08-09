{{--
  NFR-8 — the sign-in and activation endpoints are rate limited.

  The limit is right; what the person saw was not. Laravel's fallback page is a
  bare white screen reading "429 Too Many Requests", with no branding, no reason
  and no indication of when to try again. Somebody who mistypes a password a few
  times, or an agent whose point shares one phone between several staff, hits
  this and has no idea whether they are locked out for a minute or for good.

  This says what happened, why, and when they may try again — in the same voice
  as the access-denied screen, which was built with care while this one was left
  to the framework.

  Deliberately NOT extending layouts.app: the throttle can fire before anybody is
  signed in, and the application shell renders a user menu and a navigation tree
  that would have nothing to show.
--}}
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Too many attempts &middot; Gondal ERP</title>
  <link rel="stylesheet" href="{{ asset('css/styles.css') }}" />
</head>
<body>
  <div class="auth-wrap" style="max-width:520px;margin:10vh auto;padding:0 16px">
    <div class="card">
      <div class="card-body" style="text-align:center">
        <div style="font-size:44px;line-height:1">&#9203;</div>
        <h1 style="margin:12px 0 8px">Too many attempts</h1>

        <p>
          You have tried this too many times in a short period, so it has been
          paused for a moment. Nothing is wrong with your account and nothing has
          been locked.
        </p>

        @php($wait = (int) ($retryAfter ?? request()->headers->get('Retry-After') ?? 60))
        <p class="text-muted">
          Try again in
          <strong>{{ $wait >= 60 ? ceil($wait / 60).' minute'.(ceil($wait / 60) === 1.0 ? '' : 's') : $wait.' seconds' }}</strong>.
        </p>

        <div class="divider"></div>

        <p class="text-small text-muted">
          If you have forgotten your password, ask an administrator to send a fresh
          activation code &mdash; they cannot see or set your password, only reissue
          the code.
        </p>

        <a href="{{ url('/login') }}" class="btn btn-primary mt-16">Back to sign in</a>
      </div>
    </div>
  </div>
</body>
</html>
