@extends('layouts.app')

@section('content')
<section class="farast-search-page" dir="rtl">
    <header class="farast-search-page__hero">
        <span class="farast-search-page__eyebrow"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i> جستجوی یکپارچه فراست</span>
        <h1>چیزی که دنبالش هستید را پیدا کنید</h1>
        <p>محصولات، مقاله‌ها و ابزارهای فراست را از یکجا جستجو کنید.</p>
        <form action="{{ route('search') }}" method="get" class="farast-search-page__form" role="search">
            <input type="search" name="q" value="{{ $q }}" minlength="2" maxlength="120" placeholder="مثلاً OCR، تایپ، PDF یا دوره آموزشی" aria-label="عبارت جستجو" required>
            <button type="submit"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i> جستجو</button>
        </form>
        @if(mb_strlen($q, 'UTF-8') < 2)
            <div class="farast-search-page__hint">برای جستجو حداقل دو نویسه وارد کنید.</div>
        @elseif($total > 0)
            <div class="farast-search-page__summary">نمایش {{ number_format($total) }} نتیجه مرتبط برای «{{ $q }}»</div>
        @else
            <div class="farast-search-page__summary">نتیجه‌ای برای «{{ $q }}» پیدا نشد.</div>
        @endif
    </header>

    @if(mb_strlen($q, 'UTF-8') >= 2 && $total > 0)
        <div class="farast-search-page__groups">
            @foreach($groups as $group)
                <section class="farast-search-results" aria-labelledby="search-group-{{ $group['key'] }}">
                    <div class="farast-search-results__heading">
                        <h2 id="search-group-{{ $group['key'] }}">{{ $group['label'] }}</h2>
                        <span>{{ number_format(count($group['items'])) }}</span>
                    </div>
                    <div class="farast-search-results__list">
                        @foreach($group['items'] as $item)
                            <a class="farast-search-result" href="{{ $item['url'] }}">
                                <span class="farast-search-result__icon"><i class="fa-solid {{ $item['icon'] }}" aria-hidden="true"></i></span>
                                <span class="farast-search-result__copy">
                                    <strong>{{ $item['title'] }}</strong>
                                    <small>{{ $item['description'] }}</small>
                                </span>
                                @if($item['meta'])
                                    <span class="farast-search-result__meta">{{ $item['meta'] }}</span>
                                @endif
                                <i class="fa-solid fa-arrow-left farast-search-result__arrow" aria-hidden="true"></i>
                            </a>
                        @endforeach
                    </div>
                </section>
            @endforeach
        </div>
    @elseif(mb_strlen($q, 'UTF-8') >= 2)
        <div class="farast-search-page__empty">
            <span><i class="fa-regular fa-face-meh" aria-hidden="true"></i></span>
            <h2>نتیجه‌ای پیدا نشد</h2>
            <p>املای عبارت را بررسی کنید یا با واژه‌های کوتاه‌تر و عمومی‌تر جستجو کنید.</p>
            <a href="{{ route('store') }}">مرور فروشگاه</a>
        </div>
    @endif
</section>
@endsection
