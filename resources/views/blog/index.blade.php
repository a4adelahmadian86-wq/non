@extends('layouts.app')

@php
    $title = 'مجله فراست | ایده‌ها، راهنماها و مهارت‌های دیجیتال';
@endphp

@push('head')
<meta name="description" content="در مجله فراست، راهنماهای کاربردی درباره نوشتن، هوش مصنوعی، OCR، مدیریت اسناد و بهره‌وری دیجیتال بخوانید.">
<link rel="canonical" href="{{ route('blog') }}">
@endpush
@push('scripts')
<script src="{{ asset('js/blog.js') }}?v=20261107b" defer></script>
@endpush

@section('content')
<section class="farast-blog" dir="rtl">
    <div class="blog-container">
        <header class="blog-hero">
            <div class="blog-hero-copy">
                <span class="blog-eyebrow"><i class="fa-solid fa-book-open" aria-hidden="true"></i> مجله فراست</span>
                <h1>دانش کوچک، <span>تغییر بزرگ</span> در کار روزمره</h1>
                <p>ایده‌ها و راهنماهای دقیق برای نوشتن بهتر، کار هوشمندانه‌تر و مدیریت حرفه‌ای اسناد دیجیتال.</p>
                <form class="blog-search" action="{{ route('blog') }}" method="get" role="search">
                    <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                    <input type="search" name="q" value="{{ $q }}" placeholder="جستجو در مقاله‌ها و راهنماها..." aria-label="جستجو در مجله">
                    <button type="submit">جستجو</button>
                </form>
                <div class="blog-hero-meta"><span><i class="fa-solid fa-feather-pointed"></i> محتوای منتخب تحریریه</span><span><i class="fa-regular fa-clock"></i> خواندنی‌های کوتاه و کاربردی</span></div>
            </div>
            <div class="blog-hero-art">
                <img src="https://images.unsplash.com/photo-1497366754035-f200968a6e72?auto=format&fit=crop&w=1200&q=85" alt="فضای کاری روشن و مدرن" fetchpriority="high">
                <div class="blog-hero-art-shade"></div>
                <div class="blog-hero-note"><span class="blog-note-icon"><i class="fa-solid fa-lightbulb"></i></span><span><strong>یک ایده تازه</strong><small>برای بهتر کار کردن</small></span></div>
            </div>
            <div class="blog-hero-orbit" aria-hidden="true"></div>
        </header>

        <nav class="blog-categories" aria-label="دسته‌بندی مقاله‌ها">
            <a class="{{ !$category ? 'is-active' : '' }}" href="{{ route('blog', request()->only('q')) }}">همه مقاله‌ها</a>
            @foreach($categories as $item)
                <a class="{{ $category === $item['slug'] ? 'is-active' : '' }}" href="{{ route('blog', array_merge(request()->only('q'), ['category'=>$item['slug']])) }}">{{ $item['name'] }}</a>
            @endforeach
        </nav>

        @if(!$q && !$category && $featured)
            <section class="blog-featured" aria-labelledby="blog-featured-title">
                <a class="blog-featured-image" href="{{ route('blog.show', $featured['slug']) }}">
                    <img src="{{ $featured['image'] }}" alt="{{ $featured['alt'] }}" loading="lazy">
                    <span>پیشنهاد تحریریه</span>
                </a>
                <div class="blog-featured-copy">
                    <span class="blog-article-category">{{ $featured['category'] }}</span>
                    <h2 id="blog-featured-title"><a href="{{ route('blog.show', $featured['slug']) }}">{{ $featured['title'] }}</a></h2>
                    <p>{{ $featured['excerpt'] }}</p>
                    <div class="blog-article-meta"><span>{{ $featured['author'] }}</span><span>{{ $featured['date'] }}</span><span><i class="fa-regular fa-clock"></i> {{ $featured['read_time'] }} دقیقه مطالعه</span></div>
                    <a class="blog-read-link" href="{{ route('blog.show', $featured['slug']) }}">خواندن مقاله <i class="fa-solid fa-arrow-left"></i></a>
                </div>
            </section>
        @endif

        <div class="blog-section-heading">
            <div><span class="blog-eyebrow">برای کنجکاوها</span><h2>{{ $q ? 'نتایج جستجو' : ($category ? 'مقاله‌های این دسته' : 'تازه‌های مجله') }}</h2></div>
            <span class="blog-results-count">{{ $articles->count() }} مطلب</span>
        </div>

        <div class="blog-grid">
            @forelse($articles as $article)
                <article class="blog-card" data-blog-card="{{ $article['slug'] }}">
                    <a class="blog-card-image" href="{{ route('blog.show', $article['slug']) }}">
                        <img src="{{ $article['image'] }}" alt="{{ $article['alt'] }}" loading="lazy">
                        <span>{{ $article['category'] }}</span>
                    </a>
                    <div class="blog-card-content">
                        <div class="blog-card-meta"><span>{{ $article['date'] }}</span><span><i class="fa-regular fa-clock"></i> {{ $article['read_time'] }} دقیقه</span></div>
                        <h3><a href="{{ route('blog.show', $article['slug']) }}">{{ $article['title'] }}</a></h3>
                        <p>{{ $article['excerpt'] }}</p>
                        <div class="blog-card-footer"><span>{{ $article['author'] }}</span><div class="blog-card-footer-actions"><button type="button" data-blog-save="{{ $article['slug'] }}" aria-label="ذخیره مقاله" aria-pressed="false"><i class="fa-regular fa-bookmark"></i></button><a href="{{ route('blog.show', $article['slug']) }}" aria-label="خواندن {{ $article['title'] }}"><i class="fa-solid fa-arrow-left"></i></a></div></div>
                    </div>
                </article>
            @empty
                <div class="blog-empty"><i class="fa-solid fa-magnifying-glass"></i><h3>مطلبی پیدا نشد</h3><p>عبارت جستجو یا دسته‌بندی را تغییر دهید.</p><a href="{{ route('blog') }}">نمایش همه مقاله‌ها</a></div>
            @endforelse
            <div class="blog-empty blog-saved-empty" data-blog-empty-saved hidden><i class="fa-regular fa-bookmark"></i><h3>هنوز مقاله‌ای ذخیره نکرده‌اید</h3><p>با زدن نشانک روی مقاله‌ها، فهرست مطالعه شخصی‌تان را بسازید.</p></div>
        </div>

        <aside class="blog-bottom-cta">
            <div class="blog-cta-icon"><i class="fa-solid fa-wand-magic-sparkles"></i></div>
            <div><span>از دانستن تا انجام دادن</span><h2>ایده‌تان را همین حالا به یک کار واقعی تبدیل کنید.</h2><p>در فضای کاری فراست بنویسید، تبدیل کنید و پروژه‌هایتان را یک‌جا پیش ببرید.</p></div>
            <a href="{{ route('editor') }}">ورود به فضای کار <i class="fa-solid fa-arrow-left"></i></a>
        </aside>
    </div>
</section>
@endsection

@push('styles')
<link rel="stylesheet" href="{{ asset('css/blog.css') }}?v=20261107b">
@endpush
