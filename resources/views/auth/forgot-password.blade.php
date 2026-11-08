@extends('layouts.app')
@section('content')
<div class="auth-shell">
  <div class="auth-card auth-card-premium auth-secondary-card">
    <div class="auth-brand">
      <div class="mark" aria-hidden="true"><i class="fa-solid fa-key"></i></div>
      <span class="auth-kicker">FARAST ACCOUNT</span>
      <h1>بازیابی رمز عبور</h1>
      <p>با تأیید شماره موبایل حساب، رمز تازه‌ای برای ورود انتخاب کنید.</p>
    </div>
    @if($step==='phone')
      <ol class="auth-flow-progress" aria-label="مراحل بازیابی رمز"><li class="active">۱. شماره</li><li>۲. کد پیامک</li><li>۳. رمز جدید</li></ol>
      <form id="resetPhone" novalidate>
        @csrf
        <div class="auth-field">
          <label for="resetMobile">شماره موبایل حساب</label>
          <input id="resetMobile" name="mobile" type="tel" inputmode="tel" autocomplete="tel" dir="ltr" placeholder="۰۹۱۲۱۲۳۴۵۶۷" aria-describedby="resetMobileHint" required>
          <small class="auth-field-hint" id="resetMobileHint">شماره موبایلی را وارد کنید که هنگام ثبت‌نام تأیید کرده‌اید.</small>
        </div>
        <button class="auth-btn primary" type="submit"><i class="fa-solid fa-message" aria-hidden="true"></i> ارسال کد بازیابی</button>
      </form>
    @else
      <ol class="auth-flow-progress" aria-label="مراحل بازیابی رمز"><li class="active">۱. شماره</li><li class="active">۲. کد پیامک</li><li>۳. رمز جدید</li></ol>
      <div class="upload-continue" role="status"><i class="fa-solid fa-message" aria-hidden="true"></i> کد بازیابی به شماره حساب ارسال شد.</div>
      <form id="resetOtp" novalidate>
        @csrf
        <div class="auth-field">
          <label for="resetOtpCode">کد تأیید پیامک</label>
          <input id="resetOtpCode" name="code" type="text" inputmode="numeric" maxlength="6" pattern="[0-9]{6}" autocomplete="one-time-code" placeholder="کد ۶ رقمی" required>
        </div>
        <button class="auth-btn primary" type="submit"><i class="fa-solid fa-shield-check" aria-hidden="true"></i> تأیید کد و ادامه</button>
      </form>
    @endif
    <div id="resetMessage" class="auth-message" role="status" aria-live="polite" aria-atomic="true" tabindex="-1" hidden></div>
    <div class="auth-links"><a href="{{ route('login') }}"><i class="fa-solid fa-arrow-right" aria-hidden="true"></i> بازگشت به ورود</a></div>
  </div>
</div>
@endsection
@push('scripts')
<script>
(()=>{
  const csrf=document.querySelector('meta[name="csrf-token"]')?.content||'';
  const form=document.getElementById('resetPhone')||document.getElementById('resetOtp');
  const message=document.getElementById('resetMessage');
  if(!form||!message)return;
  const button=form.querySelector('button[type="submit"]');
  const codeInput=document.getElementById('resetOtpCode');
  const toAsciiDigits=value=>value.replace(/[۰-۹٠-٩]/g,digit=>{
    const fa='۰۱۲۳۴۵۶۷۸۹',ar='٠١٢٣٤٥٦٧٨٩',fi=fa.indexOf(digit);
    return String(fi>=0?fi:ar.indexOf(digit));
  }).replace(/[^0-9]/g,'').slice(0,6);
  codeInput?.addEventListener('input',()=>{codeInput.value=toAsciiDigits(codeInput.value);});
  form.addEventListener('submit',async event=>{
    event.preventDefault();if(codeInput)codeInput.value=toAsciiDigits(codeInput.value);
    if(!form.reportValidity())return;
    const original=button.innerHTML;button.disabled=true;button.setAttribute('aria-busy','true');
    button.innerHTML='<i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i> در حال بررسی...';message.hidden=true;
    try{
      const response=await fetch(form.id==='resetPhone'?'/forgot-password':'/forgot-password/verify',{
        method:'POST',credentials:'same-origin',
        headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':csrf},
        body:JSON.stringify(Object.fromEntries(new FormData(form)))
      });
      const raw=await response.text();let data={};try{data=raw?JSON.parse(raw):{};}catch(_){}
      if(!response.ok)throw new Error(data.message||'درخواست انجام نشد. اطلاعات را بررسی کنید.');
      if(response.redirected){window.location.assign(response.url);return;}
      if(form.id==='resetPhone'){window.location.assign('/forgot-password?step=otp');return;}
      showMessage(data.message||'کد تأیید شد.',false);
    }catch(error){
      showMessage(error.message||'ارتباط با سرور برقرار نشد.',true);
      button.disabled=false;button.removeAttribute('aria-busy');button.innerHTML=original;
    }
  });
  function showMessage(text,isError){
    message.hidden=false;message.className='auth-message '+(isError?'auth-error':'auth-success');
    message.setAttribute('role',isError?'alert':'status');message.setAttribute('aria-live',isError?'assertive':'polite');
    message.textContent=text;message.focus();
  }
})();
</script>
@endpush
