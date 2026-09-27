@extends('layouts.app')
@section('content')
<div class="container user-dashboard" dir="rtl">
    <header class="page-head">
        <div><span class="eyebrow"><i class="fa-solid fa-gauge-high"></i> فضای کاری</span><h1>داشبورد شما</h1><p class="dashboard-sub">اسناد، ظرفیت حساب و قابلیت‌های فعال شما در یک نمای ساده و خصوصی.</p></div>
        @if($capabilities['active'] && $capabilities['can_type'])<a class="btn primary" href="{{ route('editor') }}"><i class="fa-solid fa-plus"></i> تایپ جدید</a>@endif
    </header>
    <section class="cards dashboard-cards" aria-label="خلاصه حساب">
        <div><b>{{ number_format($documents->count()) }}</b><span><i class="fa-solid fa-file-lines"></i> سندهای من</span></div>
        <div><b>{{ $capabilities['unlimited'] ? 'نامحدود' : number_format($capabilities['weekly_free_pages']) }}</b><span><i class="fa-solid fa-gift"></i> صفحه رایگان هفتگی</span></div>
        <div><b>{{ $capabilities['unlimited'] ? 'نامحدود' : number_format($capabilities['daily_ai_requests']) }}</b><span><i class="fa-solid fa-wand-magic-sparkles"></i> سقف AI روزانه</span></div>
        <div><b>{{ $capabilities['unlimited'] ? 'نامحدود' : number_format($capabilities['max_file_mb']).' MB' }}</b><span><i class="fa-solid fa-file-arrow-up"></i> سقف فایل</span></div>
    </section>
    <div class="dashboard-content-grid">
        <section class="panel capability-panel"><div class="panel-heading"><div><h2>قابلیت‌های حساب</h2><p>دسترسی‌ها از سمت سامانه محاسبه می‌شوند و صرفاً نمایشی نیستند.</p></div></div><div class="capability-chips">
            @foreach(['can_type'=>'تایپ و ویرایش','can_ai'=>'هوش مصنوعی','can_voice'=>'تایپ صوتی','can_export_docx'=>'خروجی Word','can_export_pdf'=>'خروجی PDF','can_feedback'=>'بازخورد','can_support'=>'پشتیبانی'] as $key=>$label)
                <span class="cap-chip {{ $capabilities[$key] ? 'on' : 'off' }}"><i class="fa-solid {{ $capabilities[$key] ? 'fa-check' : 'fa-lock' }}"></i>{{ $label }}</span>
            @endforeach
        </div></section>
        <section class="panel account-panel"><div class="panel-heading"><div><h2>حساب و دسترسی</h2><p>وضعیت فعلی حساب شما</p></div></div><div class="account-state"><span class="account-state__icon"><i class="fa-solid {{ $capabilities['active'] ? 'fa-user-check' : 'fa-user-lock' }}"></i></span><div><strong>{{ $capabilities['active'] ? 'حساب فعال' : 'دسترسی محدود' }}</strong><small>{{ auth()->user()->is_verified ? 'حساب تأییدشده' : 'حساب هنوز تأیید نشده است' }}</small></div></div><div class="account-actions">@if($capabilities['can_support'])<a href="{{ route('support') }}"><i class="fa-solid fa-headset"></i> پشتیبانی</a>@endif<a href="{{ route('wallet') }}"><i class="fa-solid fa-wallet"></i> کیف پول</a></div></section>
    </div>
    <section class="panel recent-documents"><div class="panel-heading"><div><h2>اسناد اخیر</h2><p>هر سند فقط برای حساب مالک آن نمایش داده می‌شود.</p></div><span class="dashboard-count">{{ number_format($documents->count()) }}</span></div>
        @forelse($documents as $d)<article class="doc"><span><i class="fa-regular fa-file-lines"></i><span>{{ $d->title }}</span></span><span>{{ number_format($d->page_count) }} صفحه</span><span>{{ number_format($d->price_rials) }} ریال</span><a href="{{ route('editor') }}"><i class="fa-solid fa-arrow-left"></i> باز کردن در ویرایشگر</a></article>
        @empty<div class="empty-state"><i class="fa-regular fa-folder-open"></i><p>هنوز سندی ندارید.</p>@if($capabilities['can_type'])<a href="{{ route('editor') }}">ایجاد اولین سند</a>@endif</div>@endforelse
    </section>
</div>
@endsection
