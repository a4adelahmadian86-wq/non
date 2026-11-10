@extends('layouts.app')

@section('content')
<div class="container" dir="rtl" style="max-width:560px;margin:48px auto">
    <section class="security-panel" style="padding:28px">
        <div style="text-align:center;margin-bottom:24px">
            <div style="font-size:32px;color:#3156a5;margin-bottom:10px"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i></div>
            <h1 style="font-size:24px;font-weight:800">تأیید دومرحله‌ای فراست</h1>
            <p style="color:#64748b;line-height:1.9">رمز عبور پذیرفته شد. برای تکمیل ورود، کد برنامه احراز هویت یا یکی از کدهای بازیابی خود را وارد کنید.</p>
        </div>
        @if(session('status'))<div class="security-alert success" role="status">{{ session('status') }}</div>@endif
        @if($errors->any())<div class="security-alert error" role="alert">{{ $errors->first() }}</div>@endif
        <form method="post" action="{{ route('two-factor.challenge.verify') }}" style="display:grid;gap:14px">
            @csrf
            <label for="twoFactorCode" style="font-weight:700">کد تأیید یا کد بازیابی</label>
            <input id="twoFactorCode" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="80" required autofocus placeholder="کد شش‌رقمی یا کد بازیابی" style="width:100%;padding:14px;border:1px solid #cbd5e1;border-radius:12px;direction:ltr;text-align:center;font-size:20px;letter-spacing:2px">
            <button type="submit" style="border:0;border-radius:12px;padding:13px 18px;background:#1e3a8a;color:#fff;font-weight:700;cursor:pointer">تأیید و ورود به حساب</button>
        </form>
        <div style="margin-top:22px;padding:14px;border-radius:12px;background:#f8fafc;color:#64748b;font-size:13px;line-height:1.9">
            <strong style="color:#334155">به برنامه احراز هویت دسترسی ندارید؟</strong>
            <p style="margin:4px 0 0">یکی از کدهای بازیابی را وارد کنید. هر کد فقط یک بار قابل استفاده است. اگر کدهای بازیابی را هم ندارید، از مسیر پشتیبانی رسمی فراست برای بازیابی حساب اقدام کنید.</p>
        </div>
        <form method="post" action="{{ route('logout') }}" style="margin-top:18px;text-align:center">
            @csrf
            <button type="submit" style="background:transparent;border:0;color:#64748b;text-decoration:underline;cursor:pointer">خروج از حساب</button>
        </form>
    </section>
</div>
@endsection
