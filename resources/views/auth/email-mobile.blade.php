@extends('layouts.app')
@section('content')
<div class="auth-shell"><div class="auth-card">
 <div class="auth-brand"><div class="mark">F</div><h1>تأیید شماره موبایل</h1><p>برای تکمیل ورود با ایمیل، شماره ثبت‌شده حساب را تأیید کنید.</p></div>
 @if(!$otpSent)
 <form id="mobileForm"><div class="auth-field"><label>شماره موبایل حساب</label><input name="mobile" inputmode="tel" autocomplete="tel" value="{{ $mobile }}" required></div><button class="auth-btn primary">ارسال کد پیامک</button></form><div id="mobileMessage" class="auth-message" hidden></div>
 @else
 <div class="upload-continue">کد به شماره {{ $mobile }} ارسال شد.</div>
 <form id="mobileOtpForm"><div class="auth-field"><label>کد پیامک</label><input name="code" inputmode="numeric" maxlength="6" autocomplete="one-time-code" required></div><button class="auth-btn primary">ورود به حساب</button></form><div id="mobileOtpMessage" class="auth-message" hidden></div>
 @endif
</div></div>
@endsection
@push('scripts')<script>
(()=>{const csrf=document.querySelector('meta[name=csrf-token]').content;const f=document.getElementById('mobileForm'),o=document.getElementById('mobileOtpForm');
if(f){const m=document.getElementById('mobileMessage');f.addEventListener('submit',async e=>{e.preventDefault();const b=f.querySelector('button');b.disabled=true;try{const r=await fetch('/login/email/mobile',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':csrf},body:JSON.stringify(Object.fromEntries(new FormData(f)))});const j=await r.json();if(!r.ok)throw new Error(j.message||'ارسال کد ناموفق بود');location.reload()}catch(err){m.hidden=false;m.className='auth-message auth-error';m.textContent=err.message;b.disabled=false}})}
if(o){const m=document.getElementById('mobileOtpMessage');o.addEventListener('submit',async e=>{e.preventDefault();try{const r=await fetch('/login/email/mobile/verify',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':csrf},body:JSON.stringify(Object.fromEntries(new FormData(o)))});if(r.redirected){location.href=r.url;return}const j=await r.json();throw new Error(j.message||'کد نادرست است')}catch(err){m.hidden=false;m.className='auth-message auth-error';m.textContent=err.message}})}})();
</script>@endpush
