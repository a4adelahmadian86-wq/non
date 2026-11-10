@extends('layouts.app')
@section('content')
<div class="auth-shell">
  <div class="auth-card auth-card-premium auth-secondary-card">
    <div class="auth-brand">
      <div class="mark" aria-hidden="true"><i class="fa-solid fa-lock"></i></div>
      <span class="auth-kicker">FARAST ACCOUNT</span>
      <h1>ورود با رمز عبور</h1>
      <p>شماره <strong dir="ltr">{{ $phone }}</strong> شناسایی شد. رمز حساب خود را وارد کنید.</p>
    </div>
    <form method="post" action="{{ route('login.password.store') }}">
      @csrf
      <div class="auth-field">
        <label for="loginPassword">رمز عبور</label>
        <div class="auth-password-wrap">
          <input id="loginPassword" name="password" type="password" autocomplete="current-password" aria-describedby="loginPasswordHint" required autofocus>
          <button class="auth-password-toggle" type="button" id="toggleLoginPassword" aria-label="نمایش رمز عبور" aria-pressed="false">
            <i class="fa-solid fa-eye" aria-hidden="true"></i>
          </button>
        </div>
        <small class="auth-field-hint" id="loginPasswordHint">رمز عبوری را وارد کنید که هنگام ساخت حساب انتخاب کرده‌اید.</small>
      </div>
      @error('password')
        <div class="auth-message auth-error" role="alert"><i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i> {{ $message }}</div>
      @enderror
      <label class="auth-remember-option">
        <input type="checkbox" name="remember" value="1">
        <span>در این دستگاه وارد بمانم</span>
      </label>
      <button class="auth-btn primary" type="submit"><i class="fa-solid fa-right-to-bracket" aria-hidden="true"></i> ورود به حساب</button>
    </form>
    <div class="auth-links">
      <a href="{{ route('password.request') }}"><i class="fa-solid fa-key" aria-hidden="true"></i> بازیابی رمز عبور</a>
      <a href="{{ route('login') }}"><i class="fa-solid fa-mobile-screen-button" aria-hidden="true"></i> تغییر شماره</a>
    </div>
  </div>
</div>
@endsection

@push('scripts')
<script>
(()=> {
  const input=document.getElementById('loginPassword');
  const toggle=document.getElementById('toggleLoginPassword');
  if(!input||!toggle)return;
  toggle.addEventListener('click',()=>{
    const reveal=input.type==='password';
    input.type=reveal?'text':'password';
    toggle.setAttribute('aria-pressed',reveal?'true':'false');
    toggle.setAttribute('aria-label',reveal?'پنهان کردن رمز عبور':'نمایش رمز عبور');
    const icon=toggle.querySelector('i');
    if(icon){icon.classList.toggle('fa-eye',!reveal);icon.classList.toggle('fa-eye-slash',reveal);}
  });
})();
</script>
@endpush
