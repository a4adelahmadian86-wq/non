@extends('layouts.app')
@section('content')
<div class="auth-shell">
  <div class="auth-card auth-card-premium auth-secondary-card">
    <div class="auth-brand">
      <div class="mark" aria-hidden="true"><i class="fa-solid fa-lock"></i></div>
      <span class="auth-kicker">FARAST ACCOUNT</span>
      <h1>انتخاب رمز عبور جدید</h1>
      <p>برای حفظ امنیت حساب، یک رمز تازه و مطمئن انتخاب کنید.</p>
    </div>
    <div class="upload-continue" role="status"><i class="fa-solid fa-circle-check" aria-hidden="true"></i> شماره موبایل شما تأیید شده است.</div>
    <form method="post" action="{{ route('password.update') }}">
      @csrf
      <div class="auth-field">
        <label for="newPassword">رمز عبور جدید</label>
        <input id="newPassword" name="password" type="password" minlength="8" autocomplete="new-password" aria-describedby="newPasswordHint" required>
        <small class="auth-field-hint" id="newPasswordHint">حداقل ۸ نویسه انتخاب کنید.</small>
      </div>
      <div class="auth-field">
        <label for="newPasswordConfirmation">تکرار رمز عبور جدید</label>
        <input id="newPasswordConfirmation" name="password_confirmation" type="password" minlength="8" autocomplete="new-password" required>
      </div>
      @if($errors->any())
        <div class="auth-message auth-error" role="alert"><i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i> {{ $errors->first() }}</div>
      @endif
      <button class="auth-btn primary" type="submit"><i class="fa-solid fa-floppy-disk" aria-hidden="true"></i> ذخیره رمز و ورود</button>
    </form>
  </div>
</div>
@endsection
