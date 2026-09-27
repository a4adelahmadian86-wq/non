@extends('layouts.app')
@section('content')
<div class="auth-shell">
  <div class="auth-card auth-card-premium">
    <div class="auth-brand"><div class="mark"><i class="fa-solid fa-layer-group"></i></div><span class="auth-kicker">FARAST ACCOUNT</span><h1>ورود به فراست</h1><p>شماره موبایل یا ایمیل؛ ساده، مرحله‌به‌مرحله و امن.</p></div>
    @if(session('warning'))<div class="auth-message auth-error">{{ session('warning') }}</div>@endif
    @if($continueUrl)<div class="upload-continue"><i class="fa-solid fa-rotate-left"></i> پس از ورود، دقیقاً به همان مرحله‌ای که بودید برمی‌گردید.</div>@endif
    <form id="phoneForm">
      @csrf
      <div class="auth-field"><label><i class="fa-solid fa-mobile-screen-button"></i> شماره موبایل</label><input name="mobile" inputmode="tel" autocomplete="tel" placeholder="۰۹۱۲۱۲۳۴۵۶۷" required></div>
      <button class="auth-btn primary" type="submit"><i class="fa-solid fa-arrow-left"></i> ادامه با شماره موبایل</button>
    </form>
    <div id="loginMessage" class="auth-message" hidden></div>
    <div class="login-divider"><span>یا</span></div>
    <a class="email-login-hero" href="/login/email"><span class="email-icon"><i class="fa-solid fa-envelope"></i></span><span><b>ورود با ایمیل</b><small>کد ورود به ایمیل شما ارسال می‌شود و سپس شماره موبایل حساب تأیید می‌شود.</small></span><i class="fa-solid fa-chevron-left arrow"></i></a>
    <div class="auth-links"><a href="/forgot-password"><i class="fa-solid fa-key"></i> فراموشی رمز عبور</a><a href="/"><i class="fa-solid fa-house"></i> صفحه اصلی</a></div>
    <div class="auth-note"><i class="fa-solid fa-circle-info"></i> ایمیل برای باشگاه مشتریان، اطلاع‌رسانی و ورود جایگزین استفاده می‌شود.</div>
  </div>
</div>
@endsection
@push('scripts')
<script>
(()=>{const form=document.getElementById('phoneForm'),msg=document.getElementById('loginMessage'),csrf=document.querySelector('meta[name=csrf-token]').content;form.addEventListener('submit',async e=>{e.preventDefault();const btn=form.querySelector('button');btn.disabled=true;msg.hidden=false;msg.className='auth-message';msg.textContent='در حال بررسی شماره…';try{const r=await fetch('/login/phone',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':csrf},body:JSON.stringify(Object.fromEntries(new FormData(form)))});const j=await r.json();if(!r.ok)throw new Error(j.message||'اطلاعات واردشده صحیح نیست.');location.href=j.next;}catch(err){msg.className='auth-message auth-error';msg.textContent=err.message;btn.disabled=false;}});})();
</script>
@endpush
