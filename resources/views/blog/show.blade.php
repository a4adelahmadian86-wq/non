@extends('layouts.app')

@php
    $title = $article['title'].' | مجله فراست';
@endphp

@push('head')
<meta name="description" content="{{ $article['excerpt'] }}">
<link rel="canonical" href="{{ route('blog.show', $article['slug']) }}">
<meta property="og:type" content="article">
<meta property="og:title" content="{{ $article['title'] }}">
<meta property="og:description" content="{{ $article['excerpt'] }}">
<meta property="og:image" content="{{ $article['image'] }}">
@endpush

@section('content')
<div class="blog-reading-progress" data-reading-progress aria-hidden="true"><span></span></div>
<section class="farast-blog blog-article-page" dir="rtl">
    <div class="blog-container">
        <nav class="blog-breadcrumb" aria-label="مسیر صفحه"><a href="{{ route('home') }}">خانه</a><i class="fa-solid fa-chevron-left"></i><a href="{{ route('blog') }}">مجله</a><i class="fa-solid fa-chevron-left"></i><span>{{ $article['category'] }}</span></nav>
        <header class="blog-article-hero">
            <div class="blog-article-hero-copy">
                <a class="blog-article-category" href="{{ route('blog', ['category'=>$article['category_slug']]) }}">{{ $article['category'] }}</a>
                <h1>{{ $article['title'] }}</h1>
                <p>{{ $article['excerpt'] }}</p>
                <div class="blog-article-meta"><span>{{ $article['author'] }}</span><span>{{ $article['date'] }}</span><span><i class="fa-regular fa-clock"></i> {{ $article['read_time'] }} دقیقه مطالعه</span></div>
                <div class="blog-article-actions">
                    <button type="button" data-blog-save="{{ $article['slug'] }}" aria-pressed="false"><i class="fa-regular fa-bookmark"></i><span>ذخیره برای بعد</span></button>
                    <button type="button" data-blog-share><i class="fa-solid fa-arrow-up-from-bracket"></i><span>اشتراک‌گذاری</span></button>
                    <button type="button" data-blog-copy><i class="fa-regular fa-copy"></i><span>کپی لینک</span></button>
                </div>
            </div>
            <figure class="blog-article-cover"><img src="{{ $article['image'] }}" alt="{{ $article['alt'] }}" fetchpriority="high"></figure>
        </header>

        <div class="blog-reading-layout">
            <aside class="blog-reading-sidebar">
                <div class="blog-toc"><strong><i class="fa-solid fa-list"></i> در این مقاله</strong><nav aria-label="فهرست مطالب">@foreach($article['sections'] as $section)<a href="#{{ $section['id'] }}">{{ $section['title'] }}</a>@endforeach</nav></div>
                <div class="blog-reading-tools"><strong>ابزار مطالعه</strong><button type="button" data-reading-mode><i class="fa-solid fa-book-open"></i> حالت مطالعه</button><a href="{{ route('blog') }}"><i class="fa-solid fa-arrow-right"></i> بازگشت به مجله</a></div>
            </aside>
            <article class="blog-article-body" data-article-body>
                <p class="blog-article-intro">{{ $article['excerpt'] }}</p>
                @foreach($article['sections'] as $section)
                    <section id="{{ $section['id'] }}" class="blog-article-section">
                        <h2>{{ $section['title'] }}</h2>
                        @foreach($section['paragraphs'] as $paragraph)<p>{{ $paragraph }}</p>@endforeach
                    </section>
                @endforeach
                <div class="blog-article-tags"><strong>موضوعات:</strong>@foreach($article['tags'] as $tag)<span>#{{ $tag }}</span>@endforeach</div>
                <div class="blog-author-card"><span class="blog-author-avatar"><i class="fa-solid fa-feather-pointed"></i></span><div><strong>{{ $article['author'] }}</strong><p>یادداشت‌ها و راهنماهای کاربردی از تیم محتوای فراست</p></div><a href="{{ route('blog') }}">مطالب بیشتر</a></div>
            </article>
        </div>

        @if($related->isNotEmpty())
            <section class="blog-related"><div class="blog-section-heading"><div><span class="blog-eyebrow">ادامه مسیر</span><h2>شاید این‌ها هم به کارتان بیاید</h2></div><a href="{{ route('blog') }}">همه مقاله‌ها <i class="fa-solid fa-arrow-left"></i></a></div>
                <div class="blog-grid">@foreach($related as $item)<article class="blog-card"><a class="blog-card-image" href="{{ route('blog.show', $item['slug']) }}"><img src="{{ $item['image'] }}" alt="{{ $item['alt'] }}" loading="lazy"><span>{{ $item['category'] }}</span></a><div class="blog-card-content"><div class="blog-card-meta"><span>{{ $item['date'] }}</span><span>{{ $item['read_time'] }} دقیقه</span></div><h3><a href="{{ route('blog.show', $item['slug']) }}">{{ $item['title'] }}</a></h3><p>{{ $item['excerpt'] }}</p><div class="blog-card-footer"><span>{{ $item['author'] }}</span><a href="{{ route('blog.show', $item['slug']) }}"><i class="fa-solid fa-arrow-left"></i></a></div></div></article>@endforeach</div>
            </section>
        @endif
    </div>
</section>
@endsection

@push('styles')
<link rel="stylesheet" href="{{ asset('css/blog.css') }}?v=20261107a">
@endpush
@push('scripts')
<script src="{{ asset('js/blog.js') }}?v=20261107a" defer></script>
@endpush
