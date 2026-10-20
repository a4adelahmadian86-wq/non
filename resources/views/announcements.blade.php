@extends('layouts.app')

@section('content')
@php $pinned = $pinned ?? collect(); @endphp
<link rel="stylesheet" href="{{ asset('css/announcements.css') }}?v=20261020a1">

<div class="an-page" dir="rtl">
    <nav class="an-breadcrumb">
        <a href="{{ route('announcements') }}" class="an-strong">اعلانات</a>
        ·
        <a href="{{ route('dashboard') }}">داشبورد</a>
        ·
        <span>اطلاع‌رسانی سرویس</span>
    </nav>

    <article class="an-card">
        <div class="an-card-head">
            <div>
                <h1>اعلانات داشبورد</h1>
                <p>اطلاعیه‌های رسمی فراست برای حساب شما — در فرانت عمومی سایت نمایش داده نمی‌شود</p>
            </div>
            @if(auth()->user()?->isAdmin())
                <a href="{{ route('admin.announcements.index') }}" class="an-btn an-btn-ghost an-btn-sm">
                    <i class="fa-solid fa-sliders"></i> مدیریت
                </a>
            @endif
        </div>
        <div class="an-card-body">
            @if($pinned->isNotEmpty())
                <div class="an-feed" style="margin-bottom:16px">
                    @foreach($pinned as $announcement)
                        <article class="an-feed-item is-pinned type-{{ $announcement->type }}">
                            <div class="an-feed-icon"><i class="fa-solid {{ $announcement->icon() }}"></i></div>
                            <div style="flex:1;min-width:0">
                                <div class="an-feed-meta">
                                    <span class="an-badge an-badge-pin"><i class="fa-solid fa-thumbtack"></i> پین</span>
                                    <span class="an-badge an-badge-{{ $announcement->type }}">{{ $announcement->typeLabel() }}</span>
                                    <span>{{ $announcement->created_at?->timezone(config('app.timezone'))->format('Y/m/d H:i') }}</span>
                                </div>
                                <h2>{{ $announcement->title }}</h2>
                                <p class="an-body">{{ $announcement->body }}</p>
                                @if($announcement->link_url)
                                    <a class="an-feed-link" href="{{ $announcement->link_url }}" target="_blank" rel="noopener">
                                        مشاهده لینک مرتبط <i class="fa-solid fa-arrow-up-left-from-square"></i>
                                    </a>
                                @endif
                            </div>
                        </article>
                    @endforeach
                </div>
            @endif

            <div class="an-feed">
                @php $shownEmpty = true; @endphp
                @foreach($announcements as $announcement)
                    @if($pinned->contains('id', $announcement->id))
                        @continue
                    @endif
                    @php $shownEmpty = false; @endphp
                    <article class="an-feed-item type-{{ $announcement->type }} {{ $announcement->is_pinned ? 'is-pinned' : '' }}">
                        <div class="an-feed-icon"><i class="fa-solid {{ $announcement->icon() }}"></i></div>
                        <div style="flex:1;min-width:0">
                            <div class="an-feed-meta">
                                <span class="an-badge an-badge-{{ $announcement->type }}">{{ $announcement->typeLabel() }}</span>
                                @if($announcement->is_pinned)
                                    <span class="an-badge an-badge-pin"><i class="fa-solid fa-thumbtack"></i> پین</span>
                                @endif
                                <span>{{ $announcement->created_at?->timezone(config('app.timezone'))->format('Y/m/d H:i') }}</span>
                            </div>
                            <h2>{{ $announcement->title }}</h2>
                            <p class="an-body">{{ $announcement->body }}</p>
                            @if($announcement->link_url)
                                <a class="an-feed-link" href="{{ $announcement->link_url }}" target="_blank" rel="noopener">
                                    مشاهده لینک مرتبط <i class="fa-solid fa-arrow-up-left-from-square"></i>
                                </a>
                            @endif
                        </div>
                    </article>
                @endforeach
                @if($shownEmpty && $pinned->isEmpty())
                    <div class="an-empty">
                        <i class="fa-regular fa-bell-slash"></i>
                        <b>اعلان فعالی برای شما نیست</b>
                        <span>وقتی اعلان جدیدی منتشر شود، اینجا و در زنگوله اعلان‌ها نمایش داده می‌شود.</span>
                    </div>
                @endif
            </div>
        </div>
        @if(method_exists($announcements, 'links'))
            <div class="an-footer-bar">{{ $announcements->links() }}</div>
        @endif
    </article>
</div>
@endsection
