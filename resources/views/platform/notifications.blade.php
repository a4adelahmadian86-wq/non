@extends('layouts.app')

@section('content')
@php
    $filter = $filter ?? 'all';
    $counts = $counts ?? ['all'=>0,'unread'=>0,'support'=>0,'finance'=>0,'announcement'=>0,'system'=>0];
@endphp
<link rel="stylesheet" href="{{ asset('css/notifications.css') }}?v=20261019n2">

<div class="nf-page" dir="rtl">
    <header class="nf-head">
        <div>
            <h1>اعلان‌ها</h1>
            <p>پشتیبانی، مالی، اعلان‌های عمومی و رویدادهای حساب — همه‌جا یک‌جا</p>
        </div>
        <div class="nf-head-actions">
            <a href="{{ route('announcements') }}" class="nf-link">اعلانات عمومی</a>
            <a href="{{ route('support') }}" class="nf-link">گفتگوهای پشتیبانی</a>
            <form method="post" action="{{ route('platform.notifications.readAll') }}">
                @csrf
                <button type="submit" class="nf-btn">همه را خواندم</button>
            </form>
        </div>
    </header>

    <div class="nf-filters">
        <a href="{{ route('platform.notifications', ['filter' => 'all']) }}" class="{{ $filter==='all'?'is-on':'' }}">همه <b>{{ $counts['all'] }}</b></a>
        <a href="{{ route('platform.notifications', ['filter' => 'unread']) }}" class="{{ $filter==='unread'?'is-on':'' }}">خوانده‌نشده <b>{{ $counts['unread'] }}</b></a>
        <a href="{{ route('platform.notifications', ['filter' => 'support']) }}" class="{{ $filter==='support'?'is-on':'' }}">پشتیبانی <b>{{ $counts['support'] }}</b></a>
        <a href="{{ route('platform.notifications', ['filter' => 'finance']) }}" class="{{ $filter==='finance'?'is-on':'' }}">مالی <b>{{ $counts['finance'] }}</b></a>
        <a href="{{ route('platform.notifications', ['filter' => 'announcement']) }}" class="{{ $filter==='announcement'?'is-on':'' }}">اعلان‌ها <b>{{ $counts['announcement'] ?? 0 }}</b></a>
        <a href="{{ route('platform.notifications', ['filter' => 'system']) }}" class="{{ $filter==='system'?'is-on':'' }}">سیستم <b>{{ $counts['system'] }}</b></a>
    </div>

    <div class="nf-list">
        @forelse($items as $n)
            @php
                /** @var \App\Models\FarastNotification $n */
                $url = $n->url();
                $cat = $n->category();
            @endphp
            <article class="nf-item {{ $n->isRead() ? 'is-read' : 'is-unread' }} nf-cat-{{ $cat }}">
                <div class="nf-icon"><i class="fa-solid {{ $n->icon() }}"></i></div>
                <div class="nf-body">
                    <div class="nf-row">
                        <strong>{{ $n->title }}</strong>
                        <time>{{ $n->created_at?->timezone(config('app.timezone'))->diffForHumans() }}</time>
                    </div>
                    <div class="nf-meta">
                        <span class="nf-chip">{{ $n->categoryLabel() }}</span>
                        @unless($n->isRead())<span class="nf-dot">جدید</span>@endunless
                    </div>
                    @if($n->body)
                        <p>{{ $n->body }}</p>
                    @endif
                    <div class="nf-actions">
                        @if($url)
                            <a href="{{ $url }}" class="nf-action-primary">مشاهده</a>
                        @endif
                        @unless($n->isRead())
                            <form method="post" action="{{ route('platform.notifications.read', $n->id) }}">
                                @csrf
                                <button type="submit" class="nf-action-ghost">خوانده شد</button>
                            </form>
                        @endunless
                    </div>
                </div>
            </article>
        @empty
            <div class="nf-empty">
                <i class="fa-regular fa-bell-slash"></i>
                <p>اعلانی در این فیلتر نیست.</p>
            </div>
        @endforelse
    </div>

    <div class="nf-pager">{{ $items->withQueryString()->links() }}</div>
</div>

@if(session('status'))
    <div class="nf-toast">{{ session('status') }}</div>
    <script>setTimeout(()=>document.querySelector('.nf-toast')?.remove(),4000)</script>
@endif
@endsection
