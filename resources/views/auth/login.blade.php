@extends('layouts.app')
@section('content')
<div class="auth-shell">
  <div class="auth-card auth-card-premium">
    <div class="auth-brand">
      <div class="mark" aria-hidden="true"><i class="fa-solid fa-layer-group"></i></div>
      <span class="auth-kicker">FARAST ACCOUNT</span>
      <h1>ورود به فراست</h1>
      <p>شماره موبایل یا ایمیل؛ ساده، مرحله‌به‌مرحله و امن.</p>
    </div>
    @if(session('warning'))<div class="auth-message auth-error" role="alert">{{ session('warning') }}</div>@endif
    @if($continueUrl)<div class="upload-continue" role="status"><i class="fa-solid fa-rotate-left" aria-hidden="true"></i> پس از ورود، دقیقاً به همان مرحله‌ای که بودید برمی‌گردید.</div>@endif
    <form id="phoneForm" novalidate>
      @csrf
      <div class="auth-field">
        <label for="loginMobile"><i class="fa-solid fa-mobile-screen-button" aria-hidden="true"></i> شماره موبایل</label>
        <input id="loginMobile" name="mobile" type="tel" inputmode="tel" autocomplete="tel" placeholder="۰۹۱۲۱۲۳۴۵۶۷" aria-describedby="mobileHint" required>
        <small class="auth-field-hint" id="mobileHint">شماره‌ای را وارد کنید که با آن در فراست حساب ساخته‌اید.</small>
      </div>
      <button class="auth-btn primary" type="submit"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> ادامه با شماره موبایل</button>
    </form>
    <div id="loginMessage" class="auth-message" role="status" aria-live="polite" aria-atomic="true" tabindex="-1" hidden></div>
    <div class="login-divider"><span>یا</span></div>
    <a class="email-login-hero" href="{{ url('/login/email') }}">
      <span class="email-icon"><i class="fa-solid fa-envelope" aria-hidden="true"></i></span>
      <span><b>ورود با ایمیل</b><small>کد ورود به ایمیل شما ارسال می‌شود و سپس شماره موبایل حساب تأیید می‌شود.</small></span>
      <i class="fa-solid fa-chevron-left arrow" aria-hidden="true"></i>
    </a>
    <div class="auth-links"><a href="{{ url('/forgot-password') }}"><i class="fa-solid fa-key" aria-hidden="true"></i> فراموشی رمز عبور</a><a href="{{ url('/') }}"><i class="fa-solid fa-house" aria-hidden="true"></i> صفحه اصلی</a></div>
    <div class="auth-note"><i class="fa-solid fa-circle-info" aria-hidden="true"></i> ایمیل برای باشگاه مشتریان، اطلاع‌رسانی و ورود جایگزین استفاده می‌شود.</div>
  </div>
</div>
@endsection
@push('scripts')
<script>
(()=>{
 const form=document.getElementById('phoneForm');
 const msg=document.getElementById('loginMessage');
 const button=form.querySelector('button[type="submit"]');
 const csrf=document.querySelector('meta[name="csrf-token"]')?.content||'';
 const original=button.innerHTML;
 const showMessage=(message,isError=false)=>{
   msg.hidden=false;
   msg.className='auth-message '+(isError?'auth-error':'auth-success');
   msg.setAttribute('role',isError?'alert':'status');
   msg.setAttribute('aria-live',isError?'assertive':'polite');
   msg.textContent=message;
   msg.focus();
 };
 form.addEventListener('submit',async event=>{
   event.preventDefault();
   if(!form.reportValidity())return;
   button.disabled=true;
   button.setAttribute('aria-busy','true');
   button.innerHTML='<i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i> در حال بررسی...';
   msg.hidden=true;
   try{
     const response=await fetch('/login/phone',{
       method:'POST',
       credentials:'same-origin',
       headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':csrf},
       body:JSON.stringify(Object.fromEntries(new FormData(form)))
     });
     const raw=await response.text();
     let data={};
     try{data=raw?JSON.parse(raw):{};}catch(_){data={};}
     if(!response.ok)throw new Error(data.message||'اطلاعات واردشده صحیح نیست. شماره را بررسی و دوباره تلاش کنید.');
     if(!data.next)throw new Error('مرحله بعدی ورود مشخص نشد. لطفاً دوباره تلاش کنید.');
     window.location.assign(data.next);
   }catch(error){
     showMessage(error.message||'ارتباط با سرور برقرار نشد. لطفاً دوباره تلاش کنید.',true);
     button.disabled=false;
     button.removeAttribute('aria-busy');
     button.innerHTML=original;
   }
 });
})();
</script>
@endpush
