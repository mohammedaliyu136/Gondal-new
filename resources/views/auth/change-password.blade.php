@extends('layouts.app')

@section('title', 'Change password')

@section('content')
  <div class="page-head">
    <div>
      <h1>Change your password</h1>
      <p>Passwords expire after {{ config('gondal.auth.password_max_age_days') }} days</p>
    </div>
  </div>

  <div class="card" style="max-width:520px">
    <div class="card-head">
      <div>
        <h3>New password</h3>
        <p>Changing it signs out your other sessions</p>
      </div>
    </div>
    <form method="POST" action="{{ route('password.change.store') }}">
      @csrf
      <div class="card-body">
        <div class="field mb-16">
          <label for="current_password">Current password <span class="req">*</span></label>
          <input type="password" id="current_password" name="current_password"
                 autocomplete="current-password" required />
        </div>
        <div class="field mb-16">
          <label for="password">New password <span class="req">*</span></label>
          <input type="password" id="password" name="password" autocomplete="new-password" required />
          <div class="hint">{{ $policyDescription }}</div>
        </div>
        <div class="field">
          <label for="password_confirmation">Confirm new password <span class="req">*</span></label>
          <input type="password" id="password_confirmation" name="password_confirmation"
                 autocomplete="new-password" required />
        </div>
      </div>
      <div class="modal-foot">
        <button type="submit" class="btn btn-primary">Change password</button>
      </div>
    </form>
  </div>
@endsection
