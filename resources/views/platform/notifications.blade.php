@extends('layouts.app')

@section('content')
@php
    $filter = $filter ?? 'all';
    $search = $search ?? '';
    $counts = $counts ?? ['all'=>0,'unread'=>0,'archived'=>0,'support'=>0,'finance'=>0,'announcement'=>0,'community'=>0,'security'=>0,'marketing'=>0,'system'=>0];
    $filters = [
        'all' => ['همه اعلان‌ها', 'all'],
        'unread' => ['خوانده‌نشده', 'unread'],
        'support' => ['پشتیبانی', 'support'],
        'finance' => ['مالی و پرداخت', 'finance'],
        'announcement' => ['اطلاعیه‌های رسمی', 'announcement'],
        'community' => ['گفت‌وگوها', 'community'],
        'security' => ['امنیت حساب', 'security'],
        'marketing' => ['پیام‌های بازاریابی', 'marketing'],
        'system' => ['سیستم و گردش‌کار', 'system'],
        'archived' => ['بایگانی شخصی', 'archived'],
    ];
@endphp
<link rel="stylesheet" href="{{ asset('css/notifications.css') }}?v=20261109center1">

<div class="nf-page" dir="rtl">
    <header class="nf-hero">
        <div class="nf-hero-copy">
            <span class="nf-eyebrow"><i class="fa-solid fa-layer-group"></i> FARAST NOTIFICATION CENTER</span>
            <h1>مرکز اعلان‌ها</h1>
            <p>همه رویدادهای مهم حساب، پشتیبانی، پرداخت‌ها، گفت‌وگوها و اطلاعیه‌های فراست در یک جریان یکپارچه.</p>
        </div>
        <div class="nf-hero-actions">
            <a href="{{ route('announcements') }}" class="nf-secondary-link"><i class="fa-solid fa-bullhorn"></i> اطلاعیه‌های سرویس</a>
            @if($filter !== 'archived' && ($counts['unread'] ?? 0) > 0)
                <form method="post" action="{{ route('platform.notifications.readAll') }}">
                    @csrf
                    <button type="submit" class="nf-primary-button"><i class="fa-solid fa-check-double"></i> خواندن همه</button>
                </form>
            @endif
        </div>
    </header>

    <section class="nf-overview" aria-label="خلاصه اعلان‌ها">
        <div class="nf-overview-card">
            <span>در مرکز اعلان‌ها</span>
            <strong>{{ number_format((int) ($counts['all'] ?? 0)) }}</strong>
            <small>اعلان فعال</small>
        </div>
        <div class="nf-overview-card is-unread">
            <span>نیازمند توجه</span>
            <strong>{{ number_format((int) ($counts['unread'] ?? 0)) }}</strong>
            <small>خوانده‌نشده</small>
        </div>
        <div class="nf-overview-card">
            <span>بایگانی شخصی</span>
            <strong>{{ number_format((int) ($counts['archived'] ?? 0)) }}</strong>
            <small>موارد کنارگذاشته‌شده</small>
        </div>
    </section>

    <section class="nf-workspace">
        <nav class="nf-filters" aria-label="دسته‌بندی اعلان‌ها">
            @foreach($filters as $key => [$label, $countKey])
                <a href="{{ route('platform.notifications', array_filter(['filter' => $key, 'q' => $search])) }}"
                   class="{{ $filter === $key ? 'is-on' : '' }}"
                   @if($filter === $key) aria-current="page" @endif>
                    <span>{{ $label }}</span>
                    <b>{{ number_format((int) ($counts[$countKey] ?? 0)) }}</b>
                </a>
            @endforeach
        </nav>

        <div class="nf-content">
            <div class="nf-toolbar">
                <div>
                    <h2>{{ $filters[$filter][0] ?? 'همه اعلان‌ها' }}</h2>
                    <p>{{ number_format((int) $items->total()) }} مورد در این فهرست</p>
                </div>
                <form class="nf-search" method="get" action="{{ route('platform.notifications') }}">
                    <input type="hidden" name="filter" value="{{ $filter }}">
                    <label class="sr-only" for="nf-search-input">جستجو در اعلان‌ها</label>
                    <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                    <input id="nf-search-input" type="search" name="q" value="{{ $search }}" maxlength="120" placeholder="جستجو در عنوان و متن اعلان...">
                    @if($search !== '')
                        <a href="{{ route('platform.notifications', ['filter' => $filter]) }}" aria-label="پاک کردن جستجو"><i class="fa-solid fa-xmark"></i></a>
                    @endif
                    <button type="submit">جستجو</button>
                </form>
            </div>

            <div class="nf-list">
                @forelse($items as $notification)
                    @php
                        $category = $notification->category();
                        $isArchived = $notification->isArchived();
                    @endphp
                    <article class="nf-item {{ $notification->isRead() ? 'is-read' : 'is-unread' }} nf-cat-{{ $category }}">
                        <div class="nf-icon"><i class="fa-solid {{ $notification->icon() }}" aria-hidden="true"></i></div>
                        <div class="nf-body">
                            <div class="nf-row">
                                <strong>{{ $notification->title }}</strong>
                                <time datetime="{{ $notification->created_at?->toIso8601String() }}">{{ $notification->created_at?->timezone(config('app.timezone'))->diffForHumans() }}</time>
                            </div>
                            <div class="nf-meta">
                                <span class="nf-chip">{{ $notification->categoryLabel() }}</span>
                                @unless($notification->isRead())<span class="nf-dot"><i class="fa-solid fa-circle"></i> جدید</span>@endunless
                                @if($isArchived)<span class="nf-archived-label">بایگانی‌شده</span>@endif
                            </div>
                            @if($notification->body)
                                <p>{{ $notification->body }}</p>
                            @endif
                            <div class="nf-actions">
                                @if($notification->url())
                                    <a href="{{ route('platform.notifications.visit', $notification->id) }}" class="nf-action-primary"><i class="fa-solid fa-arrow-up-right-from-square"></i> مشاهده جزئیات</a>
                                @endif
                                @unless($notification->isRead() || $isArchived)
                                    <form method="post" action="{{ route('platform.notifications.read', $notification->id) }}">
                                        @csrf
                                        <button type="submit" class="nf-action-ghost"><i class="fa-solid fa-check"></i> خوانده شد</button>
                                    </form>
                                @endunless
                                @if($isArchived)
                                    <form method="post" action="{{ route('platform.notifications.restore', $notification->id) }}">
                                        @csrf
                                        <button type="submit" class="nf-action-ghost"><i class="fa-solid fa-box-open"></i> بازگردانی</button>
                                    </form>
                                @else
                                    <form method="post" action="{{ route('platform.notifications.archive', $notification->id) }}">
                                        @csrf
                                        <button type="submit" class="nf-action-ghost"><i class="fa-solid fa-box-archive"></i> بایگانی</button>
                                    </form>
                                @endif
                            </div>
                        </div>
                    </article>
                @empty
                    <div class="nf-empty">
                        <span class="nf-empty-icon"><i class="fa-regular fa-bell-slash"></i></span>
                        <strong>{{ $search !== '' ? 'نتیجه‌ای پیدا نشد' : 'فعلاً اعلانی در این بخش نیست' }}</strong>
                        <p>{{ $search !== '' ? 'عبارت دیگری را جستجو کنید یا فیلتر را تغییر دهید.' : 'وقتی رویداد تازه‌ای در حساب شما رخ دهد، اینجا نمایش داده می‌شود.' }}</p>
                        @if($filter !== 'all' || $search !== '')
                            <a href="{{ route('platform.notifications') }}">بازگشت به همه اعلان‌ها</a>
                        @endif
                    </div>
                @endforelse
            </div>

            @if($items->hasPages())
                <div class="nf-pager">{{ $items->withQueryString()->links() }}</div>
            @endif
        </div>
    </section>
</div>

@if(session('status'))
    <div class="nf-toast" role="status">{{ session('status') }}</div>
    <script>window.setTimeout(() => document.querySelector('.nf-toast')?.remove(), 4000);</script>
@endif
@endsection
