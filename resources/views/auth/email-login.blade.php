@extends('layouts.app')
@section('content')
<div class="auth-shell">
  <div class="auth-card auth-card-premium auth-secondary-card">
    <div class="auth-brand">
      <div class="mark" aria-hidden="true"><i class="fa-solid fa-envelope"></i></div>
      <span class="auth-kicker">FARAST ACCOUNT</span>
      <h1>ورود با ایمیل</h1>
      <p>ابتدا ایمیل حساب و سپس شماره موبایل همان حساب را تأیید می‌کنید.</p>
    </div>
    @if(!$otpStep)
      <ol class="auth-flow-progress" aria-label="مراحل ورود با ایمیل"><li class="active">۱. ایمیل</li><li>۲. کد تأیید</li><li>۳. موبایل</li></ol>
      <form id="emailForm" novalidate>
        @csrf
        <div class="auth-field">
          <label for="emailLoginInput">ایمیل حساب</label>
          <input id="emailLoginInput" name="email" type="email" autocomplete="email" inputmode="email" dir="ltr" placeholder="you@example.com" aria-describedby="emailLoginHint" required>
          <small class="auth-field-hint" id="emailLoginHint">از همان ایمیلی استفاده کنید که در حساب فراست ثبت کرده‌اید.</small>
        </div>
        <button class="auth-btn primary" type="submit"><i class="fa-solid fa-paper-plane" aria-hidden="true"></i> ارسال کد تأیید</button>
      </form>
      <div id="emailMessage" class="auth-message" role="status" aria-live="polite" aria-atomic="true" tabindex="-1" hidden></div>
    @else
      <ol class="auth-flow-progress" aria-label="مراحل ورود با ایمیل"><li class="active">۱. ایمیل</li><li class="active">۲. کد تأیید</li><li>۳. موبایل</li></ol>
      <div class="upload-continue" role="status"><i class="fa-solid fa-envelope-circle-check" aria-hidden="true"></i> کد تأیید به ایمیل ثبت‌شده ارسال شد.</div>
      <form id="emailOtpForm" novalidate>
        @csrf
        <div class="auth-field">
          <label for="emailOtpCode">کد تأیید ایمیل</label>
          <input id="emailOtpCode" name="code" type="text" inputmode="numeric" maxlength="6" pattern="[0-9]{6}" autocomplete="one-time-code" placeholder="کد ۶ رقمی" aria-describedby="emailOtpHint" required>
          <small class="auth-field-hint" id="emailOtpHint">کد ۶ رقمی ارسال‌شده را وارد کنید.</small>
        </div>
        <button class="auth-btn primary" type="submit"><i class="fa-solid fa-shield-check" aria-hidden="true"></i> تأیید ایمیل</button>
      </form>
      <div id="emailOtpMessage" class="auth-message" role="status" aria-live="polite" aria-atomic="true" tabindex="-1" hidden></div>
    @endif
    <div class="auth-links"><a href="{{ route('login') }}"><i class="fa-solid fa-arrow-right" aria-hidden="true"></i> ورود با شماره موبایل</a></div>
  </div>
</div>
@endsection
@push('scripts')
<script>
(()=>{
  const csrf=document.querySelector('meta[name="csrf-token"]')?.content||'';
  const toAsciiDigits=value=>value.replace(/[۰-۹٠-٩]/g,digit=>{
    const fa='۰۱۲۳۴۵۶۷۸۹',ar='٠١٢٣٤٥٦٧٨٩',fi=fa.indexOf(digit);
    return String(fi>=0?fi:ar.indexOf(digit));
  }).replace(/[^0-9]/g,'').slice(0,6);
  const showMessage=(node,text,isError=false)=>{
    if(!node)return;
    node.hidden=false;
    node.className='auth-message '+(isError?'auth-error':'auth-success');
    node.setAttribute('role',isError?'alert':'status');
    node.setAttribute('aria-live',isError?'assertive':'polite');
    node.textContent=text;
    node.focus();
  };
  const submitJson=async(form,button,url,{redirect=false,reload=false}={})=>{
    const message=form.parentElement.querySelector('.auth-message');
    if(!form.reportValidity())return;
    const original=button.innerHTML;
    button.disabled=true;button.setAttribute('aria-busy','true');
    button.innerHTML='<i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i> در حال بررسی...';
    if(message)message.hidden=true;
    try{
      const body=Object.fromEntries(new FormData(form));
      if(body.code)body.code=toAsciiDigits(String(body.code));
      const response=await fetch(url,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':csrf},body:JSON.stringify(body)});
      const raw=await response.text();let data={};try{data=raw?JSON.parse(raw):{};}catch(_){}
      if(!response.ok)throw new Error(data.message||'درخواست انجام نشد. اطلاعات را بررسی و دوباره تلاش کنید.');
      if(redirect&&response.redirected){window.location.assign(response.url);return;}
      if(reload){window.location.reload();return;}
      showMessage(message,data.message||'درخواست با موفقیت انجام شد.',false);
    }catch(error){
      showMessage(message,error.message||'ارتباط با سرور برقرار نشد. دوباره تلاش کنید.',true);
      button.disabled=false;button.removeAttribute('aria-busy');button.innerHTML=original;
    }
  };
  const emailForm=document.getElementById('emailForm');
  const emailOtpForm=document.getElementById('emailOtpForm');
  if(emailForm)emailForm.addEventListener('submit',event=>{event.preventDefault();submitJson(emailForm,emailForm.querySelector('button[type="submit"]'),'/login/email',{reload:true});});
  if(emailOtpForm){
    const input=document.getElementById('emailOtpCode');
    input?.addEventListener('input',()=>{input.value=toAsciiDigits(input.value);});
    emailOtpForm.addEventListener('submit',event=>{event.preventDefault();submitJson(emailOtpForm,emailOtpForm.querySelector('button[type="submit"]'),'/login/email/verify',{redirect:true});});
  }
})();
</script>
@endpush
