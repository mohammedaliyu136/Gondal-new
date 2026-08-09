@extends('layouts.auth')

@section('title', 'Forgot password')
@section('heading', 'Reset your password')
@section('subheading', 'We will email you a code')

@section('content')
  {{-- AUTH-4 — email, then a 6-digit code with a 15-minute expiry. --}}
  <form class="login-form" method="POST" action="{{ route('password.forgot.store') }}">
    @csrf
    @include('partials.auth-errors')

    <div class="field mb-16">
      <label for="email">Email address</label>
      <input type="email" id="email" name="email" value="{{ old('email') }}"
             placeholder="you@gondalfulbe.ng" autocomplete="username" required autofocus />
    </div>

    <button type="submit" class="btn btn-primary btn-block">Send reset code</button>

    <div class="alert info mt-16">
      <span>&#8505;&#65039;</span>
      <div>
        Administrators never see or set your password &mdash; you choose it yourself after entering the code.
      </div>
    </div>

    <div class="login-note">
      <a href="{{ route('login') }}" class="text-primary font-bold">Back to sign in</a>
    </div>
  </form>
@endsection
