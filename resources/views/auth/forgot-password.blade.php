@extends('layouts.app')
@section('content')
<div class="auth-shell"><div class="auth-card">
 <div class="auth-brand"><div class="mark">F</div><h1>فراموشی رمز عبور</h1><p>کد بازیابی به شماره موبایل حساب شما پیامک می‌شود.</p></div>
 @if($step==='phone')
 <form id="resetPhone"><div class="auth-field"><label>شماره موبایل</label><input name="mobile" inputmode="tel" autocomplete="tel" placeholder="۰۹۱۲۱۲۳۴۵۶۷" required></div><button class="auth-btn primary">ارسال کد بازیابی</button></form>
 @else
 <form id="resetOtp"><div class="auth-field"><label>کد پیامک</label><input name="code" inputmode="numeric" maxlength="6" autocomplete="one-time-code" required></div><button class="auth-btn primary">تأیید کد</button></form>
 @endif
 <div id="resetMessage" class="auth-message" hidden></div><div class="auth-links"><a href="/login">بازگشت به ورود</a></div>
</div></div>
@endsection
@push('scripts')<script>
(()=>{const csrf=document.querySelector('meta[name=csrf-token]').content;const f=document.getElementById('resetPhone'),o=document.getElementById('resetOtp'),m=document.getElementById('resetMessage');
if(f)f.addEventListener('submit',async e=>{e.preventDefault();try{const r=await fetch('/forgot-password',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':csrf},body:JSON.stringify(Object.fromEntries(new FormData(f)))});const j=await r.json();if(!r.ok)throw new Error(j.message||'ارسال کد ناموفق بود');location.href='/forgot-password?step=otp'}catch(err){m.hidden=false;m.className='auth-message auth-error';m.textContent=err.message}});
if(o)o.addEventListener('submit',async e=>{e.preventDefault();try{const r=await fetch('/forgot-password/verify',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':csrf},body:JSON.stringify(Object.fromEntries(new FormData(o)))});if(r.redirected){location.href=r.url;return}const j=await r.json();throw new Error(j.message||'کد نادرست است')}catch(err){m.hidden=false;m.className='auth-message auth-error';m.textContent=err.message}});
})();
</script>@endpush
