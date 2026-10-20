@extends('layouts.app')

@section('content')
@php $pinned = $pinned ?? collect(); @endphp
<link rel="stylesheet" href="{{ asset('css/announcements.css') }}?v=20261020pro2">

<div class="an-page" dir="rtl">
    <nav class="an-crumb">
        <a href="{{ route('dashboard') }}">داشبورد</a>
        <span class="dot">/</span>
        <strong>اعلانات سرویس</strong>
    </nav>

    <header class="an-hero">
        <div class="an-hero-main">
            <div class="an-hero-mark" aria-hidden="true">
                <i class="fa-solid fa-bullhorn"></i>
            </div>
            <div>
                <h1>اعلانات سرویس</h1>
                <p>اطلاعیه‌های رسمی فراست برای حساب شما. این بخش فقط داخل داشبورد است و در صفحهٔ عمومی سایت نمایش داده نمی‌شود.</p>
            </div>
        </div>
        @if(auth()->user()?->isAdmin())
            <a href="{{ route('admin.announcements.index') }}" class="an-btn an-btn-ghost an-btn-sm">
                <i class="fa-solid fa-sliders"></i>
                مدیریت
            </a>
        @endif
    </header>

    @if($pinned->isNotEmpty())
        <section class="an-surface" aria-label="اعلانات پین‌شده">
            <div class="an-surface-head">
                <h2><i class="fa-solid fa-thumbtack"></i> پین‌شده</h2>
                <span>{{ $pinned->count() }} مورد</span>
            </div>
            <div class="an-surface-body">
                @foreach($pinned as $a)
                    <article class="an-item is-pinned an-type-{{ $a->type }}">
                        <div class="an-item-icon" aria-hidden="true">
                            <i class="fa-solid {{ $a->icon() }}"></i>
                        </div>
                        <div class="an-item-body">
                            <div class="an-item-meta">
                                <span class="an-tag an-tag-pin"><i class="fa-solid fa-thumbtack"></i> پین</span>
                                <span class="an-tag an-tag-{{ $a->type }}">{{ $a->typeLabel() }}</span>
                            </div>
                            <h3 class="an-item-title">{{ $a->title }}</h3>
                            <p class="an-item-text">{{ $a->body }}</p>
                            @if($a->link_url)
                                <a class="an-item-link" href="{{ $a->link_url }}" target="_blank" rel="noopener">
                                    مشاهده جزئیات
                                    <i class="fa-solid fa-arrow-up-left-from-square"></i>
                                </a>
                            @endif
                        </div>
                        <div class="an-item-side">
                            <time class="an-item-time">{{ $a->created_at?->timezone(config('app.timezone'))->format('Y/m/d H:i') }}</time>
                        </div>
                    </article>
                @endforeach
            </div>
        </section>
    @endif

    <section class="an-surface" aria-label="همه اعلانات">
        <div class="an-surface-head">
            <h2><i class="fa-solid fa-inbox"></i> همه اعلانات</h2>
            <span>{{ method_exists($announcements, 'total') ? $announcements->total() : $announcements->count() }} مورد</span>
        </div>
        <div class="an-surface-body">
            @php $hasList = false; @endphp
            @foreach($announcements as $a)
                @if($pinned->contains('id', $a->id))
                    @continue
                @endif
                @php $hasList = true; @endphp
                <article class="an-item an-type-{{ $a->type }} {{ $a->is_pinned ? 'is-pinned' : '' }}">
                    <div class="an-item-icon" aria-hidden="true">
                        <i class="fa-solid {{ $a->icon() }}"></i>
                    </div>
                    <div class="an-item-body">
                        <div class="an-item-meta">
                            <span class="an-tag an-tag-{{ $a->type }}">{{ $a->typeLabel() }}</span>
                            @if($a->is_pinned)
                                <span class="an-tag an-tag-pin"><i class="fa-solid fa-thumbtack"></i> پین</span>
                            @endif
                        </div>
                        <h3 class="an-item-title">{{ $a->title }}</h3>
                        <p class="an-item-text">{{ $a->body }}</p>
                        @if($a->link_url)
                            <a class="an-item-link" href="{{ $a->link_url }}" target="_blank" rel="noopener">
                                مشاهده جزئیات
                                <i class="fa-solid fa-arrow-up-left-from-square"></i>
                            </a>
                        @endif
                    </div>
                    <div class="an-item-side">
                        <time class="an-item-time">{{ $a->created_at?->timezone(config('app.timezone'))->format('Y/m/d H:i') }}</time>
                    </div>
                </article>
            @endforeach

            @if(! $hasList && $pinned->isEmpty())
                <div class="an-empty">
                    <div class="an-empty-mark"><i class="fa-regular fa-bell"></i></div>
                    <b>اعلانی برای نمایش نیست</b>
                    <span>وقتی اطلاعیه جدیدی منتشر شود، اینجا و در زنگوله اعلان‌ها ظاهر می‌شود.</span>
                </div>
            @elseif(! $hasList && $pinned->isNotEmpty())
                <div class="an-empty" style="padding:24px">
                    <span>سایر اعلان‌ها در حال حاضر خالی است؛ موارد پین در بالا آمده‌اند.</span>
                </div>
            @endif
        </div>
        @if(method_exists($announcements, 'links'))
            <div class="an-footer-bar">{{ $announcements->links() }}</div>
        @endif
    </section>
</div>
@endsection
