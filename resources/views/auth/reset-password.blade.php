@extends('layouts.app')
@section('content')
<div class="auth-shell"><div class="auth-card">
 <div class="auth-brand"><div class="mark">F</div><h1>رمز عبور جدید</h1><p>رمز جدید را وارد و دوباره تأیید کنید.</p></div>
 <form method="post" action="{{ route('password.update') }}">@csrf
  <div class="auth-field"><label>رمز جدید</label><input name="password" type="password" minlength="8" autocomplete="new-password" required></div>
  <div class="auth-field"><label>تکرار رمز جدید</label><input name="password_confirmation" type="password" minlength="8" autocomplete="new-password" required></div>
  @if($errors->any())<div class="auth-message auth-error">{{ $errors->first() }}</div>@endif
  <button class="auth-btn primary">ذخیره رمز و ورود</button>
 </form>
</div></div>
@endsection
