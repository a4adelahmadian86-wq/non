@extends('layouts.app')

@section('content')
<div class="container" style="max-width:820px;margin:42px auto;padding:0 18px">
    <section class="card" style="padding:28px;border-radius:18px">
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:18px">
            <span aria-hidden="true" style="font-size:28px">🎯</span>
            <div>
                <h1 style="margin:0;font-size:1.35rem">تنظیمات پیشنهادهای بازاریابی</h1>
                <p style="margin:6px 0 0;color:var(--muted,#64748b)">کنترل کامل با شماست؛ فعال‌سازی کاملاً اختیاری است.</p>
            </div>
        </div>
        @if(session('status'))
            <div role="status" style="padding:12px 14px;border-radius:12px;background:#ecfdf5;color:#065f46;margin-bottom:16px">{{ session('status') }}</div>
        @endif
        <p style="line-height:2">
            در صورت رضایت، فراست فقط هنگام ورود شما به حساب و تعامل با فروشگاه، رویدادهای محدودی مانند مشاهده محصول،
            افزودن به علاقه‌مندی یا سبد خرید و تلاش برای استفاده از کد تخفیف را ثبت می‌کند تا پیشنهادهای مرتبط‌تری ارائه دهد.
        </p>
        <ul style="line-height:2">
            <li>هیچ ردیابی بین‌سایتی، اثرانگشت‌گیری مرورگر یا شناسه مخفی انجام نمی‌شود.</li>
            <li>بدون فعال‌سازی این گزینه، رویدادهای بازاریابی حساب شما ثبت نمی‌شوند.</li>
            <li>سیگنال‌های بازاریابی فقط در بازه تحلیل تنظیم‌شده برای پیشنهادها استفاده می‌شوند و پس از پایان مدت نگهداری قابل تنظیم (پیش‌فرض ۹۰ روز) به‌صورت خودکار پاک می‌شوند.</li>
            <li>اعلان پیشنهادها فقط از سیستم اعلان داخلی فراست ارسال می‌شود.</li>
            <li>هر زمان بخواهید می‌توانید رضایت را لغو کنید؛ با لغو رضایت، سیگنال‌های بازاریابی قبلی پاک و کدهای اختصاصی استفاده‌نشده باطل می‌شوند.</li>
            <li>اطلاع‌رسانی‌های ضروری سفارش، امنیت و حساب کاربری مستقل از این گزینه هستند.</li>
        </ul>
        <form method="POST" action="{{ route('account.marketing-preferences.update') }}" style="margin-top:24px">
            @csrf
            @method('PUT')
            <input type="hidden" name="marketing_consent" value="0">
            <label style="display:flex;align-items:flex-start;gap:12px;padding:16px;border:1px solid var(--border,#dbe3ee);border-radius:14px;cursor:pointer">
                <input type="checkbox" name="marketing_consent" value="1" @checked($marketingConsent) style="width:20px;height:20px;margin-top:4px">
                <span>
                    <strong>با ثبت سیگنال‌های محدود فروشگاه و دریافت پیشنهادهای بازاریابی مرتبط موافقم.</strong>
                    <small style="display:block;margin-top:6px;color:var(--muted,#64748b)">این انتخاب اختیاری است و روی خرید، دسترسی به حساب یا اعلان‌های ضروری اثری ندارد.</small>
                </span>
            </label>
            @error('marketing_consent')<p role="alert" style="color:#b91c1c">{{ $message }}</p>@enderror
            <button type="submit" class="btn btn-primary" style="margin-top:18px">ذخیره تنظیمات</button>
        </form>
        @if($consentUpdatedAt)
            <p style="margin:16px 0 0;color:var(--muted,#64748b);font-size:.88rem">آخرین تغییر رضایت: {{ $consentUpdatedAt->format('Y/m/d H:i') }}</p>
        @endif
    </section>
</div>
@endsection
