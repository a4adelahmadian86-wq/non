@extends('layouts.app')
@section('content')
<div class="farast-admin" dir="rtl">
    <aside class="farast-admin-nav">
        <div class="farast-admin-brand">
            <span class="farast-admin-mark" aria-hidden="true"><i></i><i></i><i></i><i></i><b></b></span>
            <div><strong>فراست</strong><small>مرکز عملیات</small></div>
        </div>
        <div class="farast-admin-nav-label">کنسول مدیریت</div>
        <nav>
            <a class="is-active" href="#overview"><i class="fa-solid fa-grid-2"></i><span>نمای کلی</span></a>
            <a href="#users"><i class="fa-solid fa-users"></i><span>کاربران</span></a>
            <a href="#ai"><i class="fa-solid fa-sparkles"></i><span>هوش مصنوعی</span></a>
            <a href="#operations"><i class="fa-solid fa-chart-line"></i><span>عملیات</span></a>
            <a href="#content"><i class="fa-solid fa-bullhorn"></i><span>محتوا و اعلان</span></a>
            <a href="#pricing"><i class="fa-solid fa-tags"></i><span>قیمت‌گذاری</span></a>
        </nav>
        <div class="farast-admin-nav-bottom">
            <a href="{{ route('admin.finance') }}"><i class="fa-solid fa-wallet"></i><span>مالی</span></a>
            <a href="{{ route('admin.emails') }}"><i class="fa-solid fa-envelope"></i><span>ایمیل</span></a>
            <a href="{{ route('admin.social') }}"><i class="fa-solid fa-share-nodes"></i><span>شبکه‌های اجتماعی</span></a>
        </div>
        <div class="farast-admin-live"><span></span><div><b>سامانه عملیاتی</b><small>کنترل‌های زنده فعال هستند</small></div></div>
    </aside>

    <main class="farast-admin-main">
        <header class="farast-admin-topbar">
            <div class="farast-admin-heading">
                <div class="farast-admin-kicker"><span></span> MASTER CONTROL</div>
                <h1>مرکز عملیات فراست</h1>
                <p>وضعیت واقعی محصول، کاربران، AI و کنترل‌های اجرایی را از یک فضای واحد مدیریت کنید.</p>
            </div>
            <div class="farast-admin-top-actions">
                <a href="/" target="_blank" class="admin-ghost"><i class="fa-solid fa-arrow-up-right-from-square"></i> مشاهده سایت</a>
                <div class="farast-admin-user"><span>م</span><div><b>مدیر اصلی</b><small>{{ auth()->user()->mobile }}</small></div><form method="post" action="{{ route('logout') }}">@csrf<button aria-label="خروج"><i class="fa-solid fa-right-from-bracket"></i></button></form></div>
            </div>
        </header>

        @if(session('status'))
            <div class="farast-admin-toast"><i class="fa-solid fa-circle-check"></i><span>{{ session('status') }}</span></div>
        @endif

        <section id="overview" class="admin-command-hero">
            <div class="hero-copy">
                <div class="hero-badge"><i class="fa-solid fa-shield-halved"></i> محیط مدیریت امن</div>
                <h2>یک داشبورد برای دیدن، تصمیم‌گیری و کنترل</h2>
                <p>اطلاعات این صفحه از داده‌های واقعی سیستم خوانده می‌شود؛ داشبورد نقش کنسول عملیات را دارد، نه مجموعه‌ای از تب‌های نمایشی.</p>
                <div class="hero-actions"><a class="admin-primary" href="#users"><i class="fa-solid fa-users"></i> مدیریت کاربران</a><a class="admin-outline" href="#ai"><i class="fa-solid fa-sparkles"></i> تنظیم AI</a></div>
            </div>
            <div class="hero-orbit" aria-hidden="true"><div class="orbit-core"><span></span><span></span><span></span><span></span><b></b></div><i class="orbit-dot d1"></i><i class="orbit-dot d2"></i><i class="orbit-dot d3"></i></div>
        </section>

        <section class="admin-metric-grid" aria-label="شاخص‌های اصلی">
            <article class="metric-card metric-blue"><div class="metric-top"><span>کاربران</span><i class="fa-solid fa-users"></i></div><strong>{{ number_format($stats['users']) }}</strong><small>کل حساب‌های ثبت‌شده</small></article>
            <article class="metric-card metric-violet"><div class="metric-top"><span>اسناد</span><i class="fa-solid fa-file-lines"></i></div><strong>{{ number_format($stats['documents']) }}</strong><small>سندهای ایجادشده</small></article>
            <article class="metric-card metric-cyan"><div class="metric-top"><span>تعامل AI</span><i class="fa-solid fa-sparkles"></i></div><strong>{{ number_format($stats['ai']) }}</strong><small>{{ number_format($stats['ai_today']) }} تعامل در امروز</small></article>
            <article class="metric-card metric-green"><div class="metric-top"><span>وضعیت Gemini</span><i class="fa-solid fa-circle-nodes"></i></div><strong class="metric-status">{{ $secretStatus['gemini'] ? 'متصل' : 'تنظیم نشده' }}</strong><small>{{ $settings['gemini_model'] }}</small></article>
        </section>

        <section id="operations" class="admin-section-grid">
            <article class="admin-panel operations-panel">
                <div class="admin-panel-head"><div><span class="panel-eyebrow">LIVE CONTROL</span><h2>وضعیت عملیات</h2><p>شاخص‌هایی که برای تصمیم‌های روزمره مدیر مهم هستند.</p></div><span class="live-pill"><i></i> زنده</span></div>
                <div class="ops-grid">
                    <div><span class="ops-icon"><i class="fa-solid fa-user-check"></i></span><div><b>{{ number_format($users->where('is_blocked', false)->count()) }}</b><small>کاربر فعال در فهرست اخیر</small></div></div>
                    <div><span class="ops-icon purple"><i class="fa-solid fa-file-circle-check"></i></span><div><b>{{ number_format($stats['documents']) }}</b><small>سند موجود</small></div></div>
                    <div><span class="ops-icon cyan"><i class="fa-solid fa-bolt"></i></span><div><b>{{ number_format($stats['ai_today']) }}</b><small>درخواست AI امروز</small></div></div>
                    <div><span class="ops-icon orange"><i class="fa-solid fa-bullhorn"></i></span><div><b>{{ number_format($announcements->where('is_active', true)->count()) }}</b><small>اعلان فعال</small></div></div>
                </div>
            </article>
            <article id="ai" class="admin-panel ai-status-panel">
                <div class="admin-panel-head"><div><span class="panel-eyebrow">AI GATEWAY</span><h2>دروازه هوش مصنوعی</h2><p>وضعیت تنظیمات حساس بدون نمایش مقدار کلیدها.</p></div><span class="status-chip {{ $secretStatus['gemini'] ? 'ok' : 'warn' }}"><i class="fa-solid fa-circle"></i>{{ $secretStatus['gemini'] ? 'آماده' : 'نیازمند تنظیم' }}</span></div>
                <div class="ai-provider"><span class="provider-mark"><i class="fa-solid fa-sparkles"></i></span><div><b>Gemini</b><small>{{ $settings['gemini_model'] }}</small></div><span class="provider-state {{ $secretStatus['gemini'] ? 'ok' : 'warn' }}">{{ $secretStatus['gemini'] ? 'متصل' : 'بدون کلید' }}</span></div>
                <div class="ai-provider"><span class="provider-mark files"><i class="fa-solid fa-file-shield"></i></span><div><b>Files</b><small>پردازش ورودی‌های بزرگ</small></div><span class="provider-state {{ $secretStatus['files'] ? 'ok' : 'warn' }}">{{ $secretStatus['files'] ? 'متصل' : 'بدون کلید' }}</span></div>
                <a class="panel-link" href="#ai-settings">باز کردن تنظیمات AI <i class="fa-solid fa-arrow-left"></i></a>
            </article>
        </section>

        <section id="ai-settings" class="admin-section admin-panel-wide">
            <div class="admin-panel-head"><div><span class="panel-eyebrow">CONFIGURATION</span><h2>تنظیمات مرکزی AI و ظرفیت سرویس</h2><p>کلیدها رمزنگاری‌شده ذخیره می‌شوند و مقدار واقعی آن‌ها در رابط نمایش داده نمی‌شود.</p></div></div>
            <form method="post" action="{{ route('admin.settings.update') }}" class="settings-modern">
                @csrf
                <label class="modern-field secret"><span><i class="fa-solid fa-key"></i> کلید Gemini</span><input type="password" name="gemini_api_key" placeholder="{{ $secretStatus['gemini'] ? 'کلید تنظیم شده است؛ برای تغییر وارد کنید' : 'کلید Gemini API' }}" autocomplete="new-password"><small>خالی بگذارید تا مقدار فعلی حفظ شود.</small></label>
                <label class="modern-field secret"><span><i class="fa-solid fa-file-shield"></i> کلید Files</span><input type="password" name="files_api_key" placeholder="{{ $secretStatus['files'] ? 'کلید تنظیم شده است؛ برای تغییر وارد کنید' : 'کلید سرویس Files' }}" autocomplete="new-password"><small>برای سرویس فایل و ورودی‌های بزرگ.</small></label>
                <label class="modern-field"><span><i class="fa-solid fa-microchip"></i> مدل Gemini</span><input name="gemini_model" value="{{ $settings['gemini_model'] }}"></label>
                <label class="modern-field"><span><i class="fa-solid fa-file-arrow-up"></i> سقف فایل</span><input type="number" name="max_file_mb" value="{{ $settings['max_file_mb'] }}" min="1"><small>مگابایت</small></label>
                <label class="modern-field"><span><i class="fa-solid fa-bolt"></i> درخواست AI روزانه</span><input type="number" name="daily_ai_requests" value="{{ $settings['daily_ai_requests'] }}" min="0"><small>برای کاربر عادی</small></label>
                <label class="modern-field"><span><i class="fa-solid fa-gift"></i> صفحه رایگان هفتگی</span><input type="number" name="weekly_free_pages" value="{{ $settings['weekly_free_pages'] }}" min="0"><small>برای کاربر عادی</small></label>
                <div class="settings-submit"><button class="admin-primary"><i class="fa-solid fa-floppy-disk"></i> ذخیره تنظیمات</button></div>
            </form>
        </section>

        <section class="admin-section admin-panel-wide">
            <div class="admin-panel-head"><div><span class="panel-eyebrow">CAPABILITIES</span><h2>قابلیت‌های پیش‌فرض کاربران</h2><p>این کنترل‌ها به صورت مرکزی اعمال می‌شوند و برای کاربران قابل override هستند.</p></div></div>
            <form method="post" action="{{ route('admin.settings.update') }}" class="capability-modern">
                @csrf
                @foreach(['can_type'=>'تایپ و ویرایش','can_ai'=>'پردازش Gemini','can_voice'=>'تایپ صوتی','can_export_docx'=>'خروجی Word','can_export_pdf'=>'خروجی PDF','can_feedback'=>'ثبت بازخورد','can_support'=>'پشتیبانی'] as $key=>$label)
                    <label class="capability-item"><span><i class="fa-solid {{ $key==='can_ai'?'fa-sparkles':($key==='can_voice'?'fa-microphone':($key==='can_support'?'fa-headset':'fa-check')) }}"></i>{{ $label }}</span><input type="hidden" name="{{ $key }}" value="0"><input type="checkbox" name="{{ $key }}" value="1" {{ filter_var($settings[$key], FILTER_VALIDATE_BOOLEAN) ? 'checked' : '' }}><b></b></label>
                @endforeach
                <div class="settings-submit"><button class="admin-outline"><i class="fa-solid fa-check-double"></i> ذخیره دسترسی‌ها</button></div>
            </form>
        </section>

        <section id="users" class="admin-section admin-panel-wide">
            <div class="admin-panel-head"><div><span class="panel-eyebrow">IDENTITY</span><h2>کاربران و دسترسی اختصاصی</h2><p>کنترل وضعیت و ظرفیت مصرف هر کاربر بدون خروج از کنسول.</p></div><span class="count-chip">{{ $users->count() }} کاربر اخیر</span></div>
            <div class="user-modern-list">
                @forelse($users as $u)
                    @php $cap=app(\App\Services\CapabilityService::class)->forUser($u); @endphp
                    <article class="user-modern-row">
                        <div class="user-identity"><span class="user-modern-avatar">{{ mb_substr($u->name ?: 'ک',0,1) }}</span><div><b>{{ $u->name ?: 'کاربر بدون نام' }}</b><small>{{ $u->mobile }} @if($u->email) · {{ $u->email }} @endif</small></div></div>
                        <div class="user-state"><span class="state-badge {{ $u->is_blocked ? 'blocked' : 'active' }}">{{ $u->is_blocked ? 'مسدود' : 'فعال' }}</span><span class="role-badge">{{ $u->role }}</span></div>
                        @if(!$u->isAdmin())
                            <form method="post" action="{{ route('admin.user.capabilities', $u) }}" class="user-modern-controls">
                                @csrf
                                <div class="user-toggles">@foreach(['can_type'=>'تایپ','can_ai'=>'AI','can_voice'=>'صوت','can_export_docx'=>'Word','can_export_pdf'=>'PDF','can_support'=>'پشتیبانی'] as $key=>$label)<label title="{{ $label }}"><input type="hidden" name="{{ $key }}" value="0"><input type="checkbox" name="{{ $key }}" value="1" {{ $cap[$key] ? 'checked' : '' }}><span>{{ $label }}</span></label>@endforeach</div>
                                <div class="user-limits"><label>رایگان/هفته<input type="number" name="weekly_free_pages" value="{{ $cap['weekly_free_pages'] }}" min="0"></label><label>MB<input type="number" name="max_file_mb" value="{{ $cap['max_file_mb'] }}" min="1"></label><label>AI/روز<input type="number" name="daily_ai_requests" value="{{ $cap['daily_ai_requests'] }}" min="0"></label></div>
                                <div class="user-actions"><button class="save-mini"><i class="fa-solid fa-floppy-disk"></i> ذخیره</button><button type="submit" formaction="{{ route('admin.user.toggle', $u) }}" formmethod="post" class="block-mini">{{ $u->is_blocked ? 'رفع مسدودی' : 'مسدود کردن' }}</button></div>
                            </form>
                        @else
                            <div class="master-unlimited"><i class="fa-solid fa-infinity"></i> مدیر اصلی · نامحدود</div>
                        @endif
                    </article>
                @empty
                    <div class="admin-empty">هنوز کاربری ثبت نشده است.</div>
                @endforelse
            </div>
        </section>

        <section id="content" class="admin-section-grid">
            <article class="admin-panel content-panel">
                <div class="admin-panel-head"><div><span class="panel-eyebrow">BROADCAST</span><h2>مرکز اعلان</h2><p>انتشار پیام عمومی و کنترل وضعیت اعلان‌ها.</p></div><span class="count-chip">{{ $announcements->count() }}</span></div>
                <form method="post" action="{{ route('admin.announcements.store') }}" class="announcement-modern">
                    @csrf
                    <label>عنوان<input name="title" required maxlength="180" placeholder="عنوان اعلان"></label>
                    <label>نوع<select name="type"><option value="info">اطلاع‌رسانی</option><option value="success">موفقیت</option><option value="warning">هشدار</option><option value="danger">مهم</option></select></label>
                    <label class="full">متن اعلان<textarea name="body" rows="3" required maxlength="5000" placeholder="پیام کوتاه و واضح برای کاربران"></textarea></label>
                    <label class="check-line"><input type="checkbox" name="is_active" value="1" checked> همین حالا فعال باشد</label>
                    <button class="admin-primary"><i class="fa-solid fa-paper-plane"></i> انتشار اعلان</button>
                </form>
                <div class="announcement-modern-list">@forelse($announcements->take(5) as $announcement)<div class="announcement-modern-row"><span class="announcement-dot type-{{ $announcement->type }}"></span><div><b>{{ $announcement->title }}</b><small>{{ \Illuminate\Support\Str::limit($announcement->body,100) }}</small></div><span class="announcement-state {{ $announcement->is_active ? 'on' : 'off' }}">{{ $announcement->is_active ? 'فعال' : 'خاموش' }}</span><form method="post" action="{{ route('admin.announcements.toggle', $announcement) }}">@csrf<button aria-label="تغییر وضعیت"><i class="fa-solid fa-power-off"></i></button></form></div>@empty<div class="admin-empty">اعلانی ثبت نشده است.</div>@endforelse</div>
            </article>
            <article id="pricing" class="admin-panel pricing-panel">
                <div class="admin-panel-head"><div><span class="panel-eyebrow">PRICING ENGINE</span><h2>قیمت‌گذاری</h2><p>قواعد فعلی قیمت‌گذاری از backend کنترل می‌شوند.</p></div></div>
                <div class="pricing-modern-list">@foreach($rules as $rule)<form method="post" action="{{ route('admin.pricing.update') }}" class="pricing-modern-row">@csrf<input type="hidden" name="key" value="{{ $rule->key }}"><label><span>عنوان</span><input name="label" value="{{ $rule->label }}"></label><label><span>مقدار</span><input type="number" name="value" value="{{ $rule->value }}" min="0"></label><button aria-label="ذخیره"><i class="fa-solid fa-floppy-disk"></i></button></form>@endforeach</div>
            </article>
        </section>

        <section class="admin-footer-actions">
            <a href="{{ route('admin.finance') }}"><i class="fa-solid fa-wallet"></i><div><b>مرکز مالی</b><small>کیف پول، مالیات و درگاه</small></div><i class="fa-solid fa-arrow-left"></i></a>
            <a href="{{ route('admin.emails') }}"><i class="fa-solid fa-envelope"></i><div><b>مرکز ایمیل</b><small>ارسال، تنظیمات و لاگ‌ها</small></div><i class="fa-solid fa-arrow-left"></i></a>
            <a href="{{ route('admin.social') }}"><i class="fa-solid fa-share-nodes"></i><div><b>شبکه‌های اجتماعی</b><small>مدیریت لینک‌ها و حضور اجتماعی</small></div><i class="fa-solid fa-arrow-left"></i></a>
        </section>
    </main>
</div>
@endsection
