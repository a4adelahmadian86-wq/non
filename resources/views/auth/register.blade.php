@extends('layouts.app')

@section('content')
<div class="auth-shell">
  <div class="auth-card auth-card-premium register-card">
    <div class="auth-brand">
      <div class="mark" aria-hidden="true"><i class="fa-solid fa-user-plus"></i></div>
      <span class="auth-kicker">FARAST ACCOUNT</span>
      <h1>ساخت حساب کاربری</h1>
      <p>حساب خود را با شماره موبایل تأییدشده بسازید و به فضای کاری فراست وارد شوید.</p>
    </div>

    <ol class="auth-step register-progress" aria-label="مراحل ساخت حساب">
      <li class="active"><span>۱</span><small>تأیید شماره</small></li>
      <li class="{{ $verified ? 'active' : '' }}"><span>۲</span><small>اطلاعات حساب</small></li>
    </ol>

    @if(!$verified)
      <div class="register-phone-summary" role="status">
        <i class="fa-solid fa-mobile-screen-button" aria-hidden="true"></i>
        <span>کد تأیید برای این شماره ارسال شده است:</span>
        <strong dir="ltr">{{ $phone }}</strong>
      </div>
      <form id="registerOtpForm" novalidate>
        @csrf
        <div class="auth-field">
          <label for="registerOtpCode">کد تأیید پیامک</label>
          <input id="registerOtpCode" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="[0-9]{6}" placeholder="کد ۶ رقمی" aria-describedby="registerOtpHint" required>
          <small class="auth-field-hint" id="registerOtpHint">کد ۶ رقمی ارسال‌شده را وارد کنید. اعتبار کد محدود است.</small>
        </div>
        <button class="auth-btn primary" type="submit">
          <i class="fa-solid fa-shield-check" aria-hidden="true"></i>
          تأیید شماره و ادامه
        </button>
        <div id="registerMessage" class="auth-message" role="status" aria-live="polite" aria-atomic="true" tabindex="-1" hidden></div>
      </form>
    @else
      <div class="register-phone-summary is-verified" role="status">
        <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
        <span>شماره موبایل تأیید شد</span>
        <strong dir="ltr">{{ $phone }}</strong>
      </div>
      <form method="post" action="{{ route('register.store') }}">
        @csrf
        <div class="auth-field">
          <label for="registerName">نام و نام خانوادگی</label>
          <input id="registerName" name="name" type="text" autocomplete="name" maxlength="120" value="{{ old('name') }}" placeholder="نام شما" aria-describedby="registerNameHint" required>
          <small class="auth-field-hint" id="registerNameHint">این نام در فضای کاری و اسناد شما نمایش داده می‌شود.</small>
        </div>
        <div class="auth-field">
          <label for="registerEmail">ایمیل <span class="register-optional">اختیاری</span></label>
          <input id="registerEmail" name="email" type="email" autocomplete="email" maxlength="255" value="{{ old('email') }}" placeholder="name@example.com" dir="ltr" aria-describedby="registerEmailHint">
          <small class="auth-field-hint" id="registerEmailHint">برای اطلاع‌رسانی و بازیابی دسترسی جایگزین استفاده می‌شود.</small>
        </div>
        <div class="auth-field">
          <label for="registerPassword">رمز عبور</label>
          <input id="registerPassword" name="password" type="password" autocomplete="new-password" minlength="8" aria-describedby="registerPasswordHint" required>
          <small class="auth-field-hint" id="registerPasswordHint">حداقل ۸ نویسه انتخاب کنید.</small>
        </div>
        <div class="auth-field">
          <label for="registerPasswordConfirmation">تکرار رمز عبور</label>
          <input id="registerPasswordConfirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="8" required>
        </div>
        @if($errors->any())
          <div class="auth-message auth-error" role="alert">
            <i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i>
            {{ $errors->first() }}
          </div>
        @endif
        <button class="auth-btn primary" type="submit">
          <i class="fa-solid fa-user-check" aria-hidden="true"></i>
          ساخت حساب و ورود به فراست
        </button>
      </form>
    @endif

    <div class="auth-links">
      <a href="{{ route('login') }}"><i class="fa-solid fa-arrow-right" aria-hidden="true"></i> بازگشت به ورود</a>
      <a href="{{ route('home') }}"><i class="fa-solid fa-house" aria-hidden="true"></i> صفحه اصلی</a>
    </div>
    <div class="auth-note"><i class="fa-solid fa-lock" aria-hidden="true"></i><span>شماره موبایل تأییدشده به حساب شما متصل می‌ماند و اطلاعات ورود با ارتباط امن ارسال می‌شوند.</span></div>
  </div>
</div>
@endsection

@if(!$verified)
@push('scripts')
<script>
(()=>{
  const form=document.getElementById('registerOtpForm');
  const message=document.getElementById('registerMessage');
  const codeInput=document.getElementById('registerOtpCode');
  const button=form?.querySelector('button[type="submit"]');
  const csrf=document.querySelector('meta[name="csrf-token"]')?.content||'';
  if(!form||!message||!codeInput||!button)return;

  const toAsciiDigits=value=>value.replace(/[۰-۹٠-٩]/g,digit=>{
    const fa='۰۱۲۳۴۵۶۷۸۹';
    const ar='٠١٢٣٤٥٦٧٨٩';
    const faIndex=fa.indexOf(digit);
    return String(faIndex>=0?faIndex:ar.indexOf(digit));
  }).replace(/[^0-9]/g,'').slice(0,6);

  const showMessage=(text,isError=false)=>{
    message.hidden=false;
    message.className='auth-message '+(isError?'auth-error':'auth-success');
    message.setAttribute('role',isError?'alert':'status');
    message.setAttribute('aria-live',isError?'assertive':'polite');
    message.textContent=text;
    message.focus();
  };

  codeInput.addEventListener('input',()=>{codeInput.value=toAsciiDigits(codeInput.value);});
  form.addEventListener('submit',async event=>{
    event.preventDefault();
    codeInput.value=toAsciiDigits(codeInput.value);
    if(!form.reportValidity())return;

    button.disabled=true;
    button.setAttribute('aria-busy','true');
    button.innerHTML='<i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i> در حال بررسی کد...';
    message.hidden=true;

    try{
      const response=await fetch('/login/register/verify',{
        method:'POST',
        credentials:'same-origin',
        headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':csrf},
        body:JSON.stringify({code:codeInput.value})
      });
      const raw=await response.text();
      let data={};
      try{data=raw?JSON.parse(raw):{};}catch(_){data={};}
      if(!response.ok)throw new Error(data.message||'کد تأیید معتبر نیست یا منقضی شده است.');
      window.location.assign(response.url||window.location.href);
    }catch(error){
      showMessage(error.message||'ارتباط با سرور برقرار نشد. دوباره تلاش کنید.',true);
      button.disabled=false;
      button.removeAttribute('aria-busy');
      button.innerHTML='<i class="fa-solid fa-shield-check" aria-hidden="true"></i> تأیید شماره و ادامه';
    }
  });
})();
</script>
@endpush
@endif
