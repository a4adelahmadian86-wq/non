@extends('layouts.app')
@section('content')
<div class="auth-shell"><div class="auth-card">
  <div class="auth-brand"><div class="mark">F</div><h1>رمز عبور</h1><p>شماره {{ $phone }} شناسایی شد.</p></div>
  <form method="post" action="{{ route('login.password.store') }}">@csrf
    <div class="auth-field"><label>رمز عبور</label><input name="password" type="password" autocomplete="current-password" required autofocus></div>
    @error('password')<div class="auth-message auth-error">{{ $message }}</div>@enderror
    <button class="auth-btn primary">ورود به حساب</button>
  </form>
  <div class="auth-links"><a href="/forgot-password">رمز را فراموش کرده‌ام</a><a href="/login">تغییر شماره</a></div>
</div></div>
@endsection
