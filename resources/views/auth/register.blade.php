@extends('layouts.app')
@section('content')
<div class="auth-shell"><div class="auth-card">
  <div class="auth-brand"><div class="mark">F</div><h1>ساخت حساب</h1><p>شماره {{ $phone }} تأیید شد. حالا نام و رمز عبور خود را بسازید.</p></div>
  @if(!$verified)
    <form id="registerOtpForm">
      @csrf
      <div class="auth-step"><span class="active"></span><span></span><span></span></div>
      <div class="auth-field"><label>کد پیامک</label><input name="code" inputmode="numeric" maxlength="6" autocomplete="one-time-code" placeholder="کد ۶ رقمی" required></div>
      <button class="auth-btn primary">تأیید شماره</button>
      <div id="registerMessage" class="auth-message" hidden></div>
    </form>
  @else
    <form method="post" action="{{ route('register.store') }}">
      @csrf
      <div class="auth-step"><span class="active"></span><span class="active"></span><span class="active"></span></div>
      <div class="auth-field"><label>نام و نام خانوادگی</label><input name="name" autocomplete="name" required></div>
      <div class="auth-field"><label>ایمیل اختیاری</label><input name="email" type="email" autocomplete="email" placeholder="برای اطلاع‌رسانی و باشگاه مشتریان"></div>
      <div class="auth-field"><label>رمز عبور</label><input name="password" type="password" autocomplete="new-password" minlength="8" required></div>
      <div class="auth-field"><label>تکرار رمز عبور</label><input name="password_confirmation" type="password" autocomplete="new-password" minlength="8" required></div>
      @if($errors->any())<div class="auth-message auth-error">{{ $errors->first() }}</div>@endif
      <button class="auth-btn primary">ساخت حساب و ادامه تایپ</button>
    </form>
  @endif
  <div class="auth-links"><a href="/login">بازگشت به ورود</a></div>
</div></div>
@endsection
@if(!$verified)
@push('scripts')<script>
(()=>{const f=document.getElementById('registerOtpForm'),m=document.getElementById('registerMessage'),csrf=document.querySelector('meta[name=csrf-token]').content;f.addEventListener('submit',async e=>{e.preventDefault();try{const r=await fetch('/login/register/verify',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':csrf},body:JSON.stringify(Object.fromEntries(new FormData(f)))});if(!r.ok){const j=await r.json();throw new Error(j.message||'کد نادرست است.')}location.reload()}catch(err){m.hidden=false;m.className='auth-message auth-error';m.textContent=err.message}})})();
</script>@endpush
@endif
