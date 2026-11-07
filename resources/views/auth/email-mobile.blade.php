@extends('layouts.app')
@section('content')
<div class="auth-shell">
  <div class="auth-card auth-card-premium auth-secondary-card">
    <div class="auth-brand">
      <div class="mark" aria-hidden="true"><i class="fa-solid fa-mobile-screen-button"></i></div>
      <span class="auth-kicker">FARAST ACCOUNT</span>
      <h1>تأیید شماره موبایل</h1>
      <p>برای تکمیل ورود با ایمیل، شماره ثبت‌شدهٔ همین حساب را تأیید کنید.</p>
    </div>
    <ol class="auth-flow-progress" aria-label="مراحل ورود با ایمیل"><li class="active">۱. ایمیل</li><li class="active">۲. کد تأیید</li><li class="active">۳. موبایل</li></ol>
    @if(!$otpSent)
      <form id="mobileForm" novalidate>
        @csrf
        <div class="auth-field">
          <label for="emailAccountMobile">شماره موبایل حساب</label>
          <input id="emailAccountMobile" name="mobile" type="tel" inputmode="tel" autocomplete="tel" value="{{ $mobile }}" dir="ltr" readonly aria-describedby="emailAccountMobileHint" required>
          <small class="auth-field-hint" id="emailAccountMobileHint">برای امنیت حساب، تأیید فقط روی شماره ثبت‌شده امکان‌پذیر است.</small>
        </div>
        <button class="auth-btn primary" type="submit"><i class="fa-solid fa-message" aria-hidden="true"></i> ارسال کد پیامک</button>
      </form>
      <div id="mobileMessage" class="auth-message" role="status" aria-live="polite" aria-atomic="true" tabindex="-1" hidden></div>
    @else
      <div class="register-phone-summary" role="status"><i class="fa-solid fa-mobile-screen-button" aria-hidden="true"></i><span>کد پیامک شد به</span><strong dir="ltr">{{ $mobile }}</strong></div>
      <form id="mobileOtpForm" novalidate>
        @csrf
        <div class="auth-field">
          <label for="emailMobileOtpCode">کد تأیید پیامک</label>
          <input id="emailMobileOtpCode" name="code" type="text" inputmode="numeric" maxlength="6" pattern="[0-9]{6}" autocomplete="one-time-code" placeholder="کد ۶ رقمی" required>
        </div>
        <button class="auth-btn primary" type="submit"><i class="fa-solid fa-right-to-bracket" aria-hidden="true"></i> ورود به حساب</button>
      </form>
      <div id="mobileOtpMessage" class="auth-message" role="status" aria-live="polite" aria-atomic="true" tabindex="-1" hidden></div>
    @endif
    <div class="auth-links"><a href="{{ route('login.email') }}"><i class="fa-solid fa-arrow-right" aria-hidden="true"></i> بازگشت به ورود با ایمیل</a></div>
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
    if(!node)return;node.hidden=false;node.className='auth-message '+(isError?'auth-error':'auth-success');
    node.setAttribute('role',isError?'alert':'status');node.setAttribute('aria-live',isError?'assertive':'polite');
    node.textContent=text;node.focus();
  };
  const submit=async(form,url,{redirect=false,reload=false}={})=>{
    const button=form.querySelector('button[type="submit"]'),message=form.parentElement.querySelector('.auth-message');
    if(!form.reportValidity())return;
    const original=button.innerHTML;button.disabled=true;button.setAttribute('aria-busy','true');
    button.innerHTML='<i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i> در حال بررسی...';if(message)message.hidden=true;
    try{
      const body=Object.fromEntries(new FormData(form));if(body.code)body.code=toAsciiDigits(String(body.code));
      const response=await fetch(url,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':csrf},body:JSON.stringify(body)});
      const raw=await response.text();let data={};try{data=raw?JSON.parse(raw):{};}catch(_){}
      if(!response.ok)throw new Error(data.message||'درخواست انجام نشد. دوباره تلاش کنید.');
      if(redirect&&response.redirected){window.location.assign(response.url);return;}
      if(reload){window.location.reload();return;}
      showMessage(message,data.message||'کد ارسال شد.',false);
    }catch(error){
      showMessage(message,error.message||'ارتباط با سرور برقرار نشد.',true);
      button.disabled=false;button.removeAttribute('aria-busy');button.innerHTML=original;
    }
  };
  const mobileForm=document.getElementById('mobileForm'),otpForm=document.getElementById('mobileOtpForm');
  if(mobileForm)mobileForm.addEventListener('submit',event=>{event.preventDefault();submit(mobileForm,'/login/email/mobile',{reload:true});});
  if(otpForm){
    const input=document.getElementById('emailMobileOtpCode');input?.addEventListener('input',()=>{input.value=toAsciiDigits(input.value);});
    otpForm.addEventListener('submit',event=>{event.preventDefault();submit(otpForm,'/login/email/mobile/verify',{redirect:true});});
  }
})();
</script>
@endpush
