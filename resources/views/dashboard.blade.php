@extends('layouts.app')

@push('styles')
<link rel="stylesheet" href="{{ asset('css/user-dashboard.css') }}?v=20261107a">
@endpush

@section('content')
@include('components.dashboard-announcements')

<div class="container user-dashboard" dir="rtl">
    <header class="user-dashboard__hero">
        <div>
            <span class="user-dashboard__eyebrow"><i class="fa-solid fa-gauge-high"></i> فضای کاری شخصی</span>
            <h1>داشبورد شما</h1>
            <p>نمای یکپارچه‌ای از اسناد، پروژه‌ها، اعلان‌ها و وضعیت حساب شما.</p>
        </div>
        <div class="user-dashboard__hero-actions">
            @if($canCreateProjects && $capabilities['active'] && $capabilities['can_type'])
                <a class="user-dashboard__button user-dashboard__button--primary" href="{{ route('projects.create') }}">
                    <i class="fa-solid fa-plus"></i> پروژه جدید
                </a>
            @endif
            <a class="user-dashboard__button user-dashboard__button--quiet" href="{{ route('platform.documents') }}">
                <i class="fa-solid fa-folder-open"></i> مدیریت اسناد
            </a>
        </div>
    </header>

    <section class="user-dashboard__metrics" aria-label="خلاصه فضای کاری">
        <article class="user-dashboard__metric">
            <span class="user-dashboard__metric-icon"><i class="fa-solid fa-file-lines"></i></span>
            <div><strong>{{ number_format($documentTotal) }}</strong><span>کل اسناد فعال</span></div>
            <a href="{{ route('platform.documents') }}" aria-label="مشاهده اسناد"><i class="fa-solid fa-arrow-up-left-from-circle"></i></a>
        </article>
        <article class="user-dashboard__metric">
            <span class="user-dashboard__metric-icon"><i class="fa-solid fa-diagram-project"></i></span>
            <div><strong>{{ number_format($projectTotal) }}</strong><span>پروژه‌ها</span></div>
            @if($canCreateProjects)<a href="{{ route('projects.create') }}" aria-label="ایجاد پروژه"><i class="fa-solid fa-arrow-up-left-from-circle"></i></a>@endif
        </article>
        <article class="user-dashboard__metric">
            <span class="user-dashboard__metric-icon"><i class="fa-solid fa-wallet"></i></span>
            <div><strong>{{ number_format($walletBalance) }}</strong><span>موجودی کیف پول · ریال</span></div>
            <a href="{{ route('wallet') }}" aria-label="مشاهده کیف پول"><i class="fa-solid fa-arrow-up-left-from-circle"></i></a>
        </article>
        <article class="user-dashboard__metric">
            <span class="user-dashboard__metric-icon"><i class="fa-solid fa-bell"></i></span>
            <div><strong>{{ number_format($unreadNotificationCount) }}</strong><span>اعلان خوانده‌نشده</span></div>
            <a href="{{ route('platform.notifications') }}" aria-label="مشاهده اعلان‌ها"><i class="fa-solid fa-arrow-up-left-from-circle"></i></a>
        </article>
        <article class="user-dashboard__metric">
            <span class="user-dashboard__metric-icon"><i class="fa-solid fa-headset"></i></span>
            <div><strong>{{ number_format($openTicketCount) }}</strong><span>درخواست پشتیبانی باز</span></div>
            @if($capabilities['can_support'])<a href="{{ route('support') }}" aria-label="مشاهده پشتیبانی"><i class="fa-solid fa-arrow-up-left-from-circle"></i></a>@endif
        </article>
    </section>

    <div class="user-dashboard__columns">
        <section id="editor-documents" class="user-dashboard__panel user-dashboard__panel--wide">
            <div class="user-dashboard__panel-head">
                <div><span class="user-dashboard__kicker">ویرایشگر</span><h2>{{ $documentStatusFilter ? 'پیش‌نویس‌های من' : 'اسناد اخیر' }}</h2><p>{{ $documentStatusFilter ? 'فقط پیش‌نویس‌های ذخیره‌شده نمایش داده می‌شوند.' : 'با انتخاب هر سند، همان سند در ویرایشگر باز می‌شود.' }}</p></div>
                <span class="user-dashboard__count">{{ number_format($documents->total()) }}</span>
            </div>
            @forelse($documents as $document)
                <article class="user-dashboard__document">
                    <span class="user-dashboard__document-icon"><i class="fa-regular fa-file-lines"></i></span>
                    <div class="user-dashboard__document-main">
                        <strong>{{ $document->title ?: 'سند بدون عنوان' }}</strong>
                        <small>آخرین تغییر: {{ optional($document->updated_at)->format('Y/m/d · H:i') }}</small>
                    </div>
                    <span class="user-dashboard__document-meta">{{ number_format($document->page_count) }} صفحه</span>
                    <a href="{{ route('editor', ['document' => $document->id]) }}" class="user-dashboard__text-link"><span>باز کردن</span><i class="fa-solid fa-arrow-left"></i></a>
                </article>
            @empty
                <div class="user-dashboard__empty"><i class="fa-regular fa-folder-open"></i><strong>هنوز سندی در ویرایشگر ندارید</strong><span>با ساخت یک پروژه یا سند جدید، کارتان را شروع کنید.</span></div>
            @endforelse
            @if($documents->hasPages())
                <div class="user-dashboard__pagination">{{ $documents->links() }}</div>
            @endif
        </section>

        <section class="user-dashboard__panel">
            <div class="user-dashboard__panel-head">
                <div><span class="user-dashboard__kicker">حساب کاربری</span><h2>وضعیت و دسترسی‌ها</h2><p>قابلیت‌های فعال این حساب</p></div>
            </div>
            <div class="user-dashboard__account-state">
                <span class="{{ $capabilities['active'] ? 'is-active' : 'is-limited' }}"><i class="fa-solid {{ $capabilities['active'] ? 'fa-shield-check' : 'fa-lock' }}"></i></span>
                <div><strong>{{ $capabilities['active'] ? 'حساب فعال' : 'دسترسی محدود' }}</strong><small>{{ auth()->user()->is_verified ? 'حساب تأییدشده' : 'حساب هنوز تأیید نشده است' }}</small></div>
            </div>
            <div class="user-dashboard__capabilities">
                @foreach(['can_type'=>'تایپ و ویرایش','can_ai'=>'هوش مصنوعی','can_voice'=>'تایپ صوتی','can_export_docx'=>'خروجی Word','can_export_pdf'=>'خروجی PDF','can_feedback'=>'ثبت بازخورد','can_support'=>'پشتیبانی'] as $key=>$label)
                    <span class="{{ $capabilities[$key] ? 'is-on' : 'is-off' }}"><i class="fa-solid {{ $capabilities[$key] ? 'fa-check' : 'fa-lock' }}"></i>{{ $label }}</span>
                @endforeach
            </div>
            <div class="user-dashboard__limits">
                <div><span>صفحه رایگان هفتگی</span><strong>{{ $capabilities['unlimited'] ? 'نامحدود' : number_format($capabilities['weekly_free_pages']) }}</strong></div>
                <div><span>درخواست AI روزانه</span><strong>{{ $capabilities['unlimited'] ? 'نامحدود' : number_format($capabilities['daily_ai_requests']) }}</strong></div>
                <div><span>حداکثر حجم فایل</span><strong>{{ number_format($capabilities['max_file_mb']) }} MB</strong></div>
            </div>
            <div id="workspace-shortcuts" class="user-dashboard__quick-links">
                <a href="{{ route('wallet') }}"><i class="fa-solid fa-wallet"></i> کیف پول</a>
                <a href="{{ route('platform.notifications') }}"><i class="fa-solid fa-bell"></i> اعلان‌ها</a>
                @if($capabilities['can_support'])<a href="{{ route('support') }}"><i class="fa-solid fa-headset"></i> پشتیبانی</a>@endif
            </div>
        </section>
    </div>

    <div class="user-dashboard__columns user-dashboard__columns--bottom">
        <section class="user-dashboard__panel">
            <div class="user-dashboard__panel-head">
                <div><span class="user-dashboard__kicker">کتابخانه</span><h2>اسناد فضای کاری</h2><p>اسناد اصلی ذخیره‌شده در کتابخانه یکپارچه</p></div>
                <a href="{{ route('platform.documents') }}" class="user-dashboard__text-link">همه اسناد <i class="fa-solid fa-arrow-left"></i></a>
            </div>
            @forelse($workspaceDocuments as $document)
                <article class="user-dashboard__document user-dashboard__document--compact">
                    <span class="user-dashboard__document-icon"><i class="fa-solid {{ $document->is_favorite ? 'fa-star' : 'fa-folder-open' }}"></i></span>
                    <div class="user-dashboard__document-main"><strong>{{ $document->title }}</strong><small>آخرین تغییر: {{ optional($document->updated_at)->format('Y/m/d · H:i') }}</small></div>
                    <a href="{{ route('platform.documents') }}" class="user-dashboard__text-link">مدیریت <i class="fa-solid fa-arrow-left"></i></a>
                </article>
            @empty
                <div class="user-dashboard__empty user-dashboard__empty--small"><i class="fa-regular fa-folder"></i><span>سند مستقل دیگری در کتابخانه ندارید.</span></div>
            @endforelse
        </section>

        <section class="user-dashboard__panel">
            <div class="user-dashboard__panel-head">
                <div><span class="user-dashboard__kicker">پیگیری</span><h2>پروژه‌های اخیر</h2><p>آخرین پروژه‌های ثبت‌شده شما</p></div>
                @if($canCreateProjects)<a href="{{ route('projects.create') }}" class="user-dashboard__text-link">پروژه جدید <i class="fa-solid fa-arrow-left"></i></a>@endif
            </div>
            @forelse($projects as $project)
                <article class="user-dashboard__project">
                    <span class="user-dashboard__project-icon"><i class="fa-solid fa-diagram-project"></i></span>
                    <div><strong>{{ $project->name }}</strong><small>{{ number_format($project->used_pages) }} از {{ number_format($project->estimated_pages) }} صفحه · {{ $project->status }}</small></div>
                    @if($canCreateProjects && $capabilities['can_type'])<a href="{{ route('editor', ['project' => $project->id]) }}" aria-label="ادامه پروژه {{ $project->name }}"><i class="fa-solid fa-arrow-left"></i></a>@endif
                </article>
            @empty
                <div class="user-dashboard__empty user-dashboard__empty--small"><i class="fa-solid fa-diagram-project"></i><span>هنوز پروژه‌ای ایجاد نکرده‌اید.</span></div>
            @endforelse
        </section>
    </div>
</div>
@endsection
