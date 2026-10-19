@extends('layouts.app')

@section('content')
@php
    $events = $events ?? [];
    $smsEnabled = $smsEnabled ?? true;
    $alertMobile = $alertMobile ?? '';
    $hasKey = $hasKey ?? false;
@endphp
<link rel="stylesheet" href="{{ asset('css/support-tickets.css') }}?v=20261019sms">

<div class="tk-page" dir="rtl">
    <nav class="tk-breadcrumb">
        <div class="tk-breadcrumb-inner">
            <a href="{{ route('admin.support.sms') }}" class="tk-crumb-strong">تنظیمات پیامک پشتیبانی</a>
            <span class="tk-crumb-sep"></span>
            <a href="{{ route('admin.support.management') }}">میز پشتیبانی</a>
            <span class="tk-crumb-dash">-</span>
            <a href="{{ route('admin.emails') }}">مرکز ایمیل</a>
        </div>
    </nav>

    <main class="tk-main tk-main-wide">
        @if(session('status'))
            <div class="tk-notice" style="margin-bottom:14px">
                <div class="tk-notice-text"><span class="tk-notice-tag">OK</span><p>{{ session('status') }}</p></div>
            </div>
        @endif

        <article class="tk-card" style="margin-bottom:16px">
            <div class="tk-card-head">
                <hgroup>
                    <h1>پیامک رویدادهای پشتیبانی</h1>
                    <p>روشن/خاموش کردن پیامک هر رویداد مطابق استاندارد help desk (اعلان به کاربر یا کارشناس)</p>
                </hgroup>
                <span class="count-chip" style="background:{{ $hasKey ? '#dcfce7' : '#fef3c7' }};color:{{ $hasKey ? '#166534' : '#92400e' }};padding:6px 12px;border-radius:999px;font-size:.78rem;font-weight:700">
                    {{ $hasKey ? 'کلید کاوه‌نگار تنظیم شده' : 'کلید API تنظیم نشده' }}
                </span>
            </div>

            <form method="post" action="{{ route('admin.support.sms.update') }}" class="tk-create-form">
                @csrf

                <div class="tk-field-block">
                    <label class="tk-chip {{ $smsEnabled ? 'is-on' : '' }}" style="max-width:none;justify-content:flex-start;gap:10px">
                        <input type="hidden" name="sms_enabled" value="0">
                        <input type="checkbox" name="sms_enabled" value="1" @checked($smsEnabled)>
                        ارسال پیامک به‌صورت سراسری فعال باشد
                    </label>
                    <p class="tk-hint">اگر خاموش باشد، هیچ پیامکی برای پشتیبانی ارسال نمی‌شود (حتی اگر رویداد جداگانه روشن باشد).</p>
                </div>

                <div class="tk-field-block">
                    <p class="tk-label">موبایل هشدار ستاد (اختیاری)</p>
                    <input class="tk-input" name="support_alert_mobile" value="{{ old('support_alert_mobile', $alertMobile) }}" placeholder="09xxxxxxxxx" dir="ltr" style="text-align:left;max-width:280px">
                    <p class="tk-hint">برای تیکت جدید، SLA و پاسخ فوری کاربر؛ اگر خالی باشد از موبایل اولین ادمین استفاده می‌شود.</p>
                </div>

                <div class="tk-divider" style="margin:8px 0"></div>
                <p class="tk-label" style="margin-bottom:8px">رویدادها</p>

                <div style="display:grid;gap:10px">
                    @foreach($events as $key => $meta)
                        @php $on = (bool) ($meta['enabled'] ?? false); @endphp
                        <label class="tk-chip {{ $on ? 'is-on' : '' }}" style="max-width:none;display:flex;align-items:flex-start;gap:12px;padding:14px 16px;text-align:right">
                            <input type="hidden" name="sms_{{ $key }}_enabled" value="0">
                            <input type="checkbox" name="sms_{{ $key }}_enabled" value="1" @checked($on) style="margin-top:3px">
                            <span style="flex:1">
                                <b style="display:block;font-size:.9rem;color:#172b4d">{{ $meta['label'] }}</b>
                                <small style="display:block;color:#6e7f96;margin-top:4px;line-height:1.5">{{ $meta['hint'] }}</small>
                                @if(!empty($meta['template']))
                                    <small style="display:block;color:#1769ff;margin-top:4px;font-family:monospace">قالب: {{ $meta['template'] }}</small>
                                @else
                                    <small style="display:block;color:#94a3b8;margin-top:4px">بدون قالب Lookup — ارسال متنی ساده</small>
                                @endif
                            </span>
                        </label>
                    @endforeach
                </div>

                <button type="submit" class="tk-btn-primary tk-submit" style="margin-top:8px">ذخیره تنظیمات پیامک</button>
            </form>
        </article>

        <article class="tk-card">
            <div class="tk-card-head">
                <hgroup>
                    <h1>راهنمای استاندارد</h1>
                    <p>پیشنهاد رایج برای سرویس‌های ایرانی</p>
                </hgroup>
            </div>
            <ul style="margin:0;padding:0 18px 8px;color:#475569;font-size:.88rem;line-height:1.85">
                <li><b>پاسخ به کاربر (ticket_reply)</b> — معمولاً روشن؛ کاربر از پیامک مطلع شود.</li>
                <li><b>بستن تیکت</b> — اختیاری؛ اگر CSAT ایمیلی/درون‌برنامه کافی است می‌تواند خاموش بماند.</li>
                <li><b>ارجاع به کارشناس</b> — برای تیم‌های چندنفره توصیه می‌شود.</li>
                <li><b>تیکت جدید / پاسخ کاربر با اولویت بالا</b> — برای on-call مفید است.</li>
                <li><b>SLA</b> — هشدار نزدیک به نقض و نقض را برای اولویت بالا نگه دارید تا صف از کنترل خارج نشود.</li>
            </ul>
        </article>
    </main>
</div>

<script>
document.querySelectorAll('.tk-chip input[type=checkbox]').forEach(function (input) {
    var chip = input.closest('.tk-chip');
    function sync() { if (chip) chip.classList.toggle('is-on', input.checked); }
    input.addEventListener('change', sync);
    sync();
});
</script>
@endsection
