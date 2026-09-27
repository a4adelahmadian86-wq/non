@extends('layouts.app')
@section('content')
<div class="auth-shell"><div class="auth-card">
 <div class="auth-brand"><div class="mark">F</div><h1>ورود با ایمیل</h1><p>ایمیل و سپس شماره موبایل همان حساب تأیید می‌شود.</p></div>
 @if(!$otpStep)
 <form id="emailForm"><div class="auth-field"><label>ایمیل</label><input name="email" type="email" autocomplete="email" required placeholder="you@example.com"></div><button class="auth-btn primary">ارسال کد تأیید</button></form>
 <div id="emailMessage" class="auth-message" hidden></div>
 @else
 <div class="upload-continue">کد تأیید به ایمیل ثبت‌شده ارسال شد.</div>
 <form id="emailOtpForm"><div class="auth-field"><label>کد تأیید ایمیل</label><input name="code" inputmode="numeric" maxlength="6" autocomplete="one-time-code" placeholder="کد ۶ رقمی" required></div><button class="auth-btn primary">تأیید ایمیل</button></form>
 <div id="emailOtpMessage" class="auth-message" hidden></div>
 @endif
 <div class="auth-links"><a href="/login">ورود با شماره موبایل</a></div>
</div></div>
@endsection
@push('scripts')<script>
(()=>{const csrf=document.querySelector('meta[name=csrf-token]').content;const f=document.getElementById('emailForm');const o=document.getElementById('emailOtpForm');
if(f){const m=document.getElementById('emailMessage');f.addEventListener('submit',async e=>{e.preventDefault();const b=f.querySelector('button');b.disabled=true;try{const r=await fetch('/login/email',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':csrf},body:JSON.stringify(Object.fromEntries(new FormData(f)))});const j=await r.json();if(!r.ok)throw new Error(j.message||'ارسال کد ناموفق بود');location.reload()}catch(err){m.hidden=false;m.className='auth-message auth-error';m.textContent=err.message;b.disabled=false}})}
if(o){const m=document.getElementById('emailOtpMessage');o.addEventListener('submit',async e=>{e.preventDefault();try{const r=await fetch('/login/email/verify',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':csrf},body:JSON.stringify(Object.fromEntries(new FormData(o)))});if(!r.ok){const j=await r.json();throw new Error(j.message||'کد نادرست است')}location.href='/login/email/mobile'}catch(err){m.hidden=false;m.className='auth-message auth-error';m.textContent=err.message}})}})();
</script>@endpush
