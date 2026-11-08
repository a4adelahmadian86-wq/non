@extends('layouts.app')

@php
    $title = 'فراست | چه کاری می‌خواهید انجام دهید؟';
    $isAuthenticated = auth()->check();
@endphp

@push('head')
<meta name="description" content="با فراست از فایل، ایده یا یک سرویس هوشمند شروع کنید؛ مسیر مناسب را انتخاب کنید و کارتان را در یک فضای حرفه‌ای ادامه دهید.">
<link rel="canonical" href="{{ url('/') }}">
<meta property="og:type" content="website">
<meta property="og:site_name" content="فراست | FARAST">
<meta property="og:title" content="فراست؛ از ایده تا نتیجه">
<meta property="og:description" content="فایل، ایده یا سرویس موردنیازتان را انتخاب کنید و با راهنمایی مرحله‌به‌مرحله وارد مسیر درست شوید.">
<meta property="og:url" content="{{ url('/') }}">
<meta name="twitter:card" content="summary">
<meta name="twitter:title" content="فراست؛ از ایده تا نتیجه">
<meta name="twitter:description" content="یک نقطه شروع روشن برای کار با اسناد و ابزارهای هوشمند.">
@endpush

@section('content')
<div class="farast-home" dir="rtl" data-authenticated="{{ $isAuthenticated ? '1' : '0' }}">
    <section class="home-hero" aria-labelledby="home-title">
        <div class="home-hero-copy">
            <span class="home-kicker"><i class="fa-solid fa-sparkles" aria-hidden="true"></i> FARAST</span>
            <h1 id="home-title">از ایده تا نتیجه، مسیرتان را با فراست شروع کنید</h1>
            <p>فایل دارید، می‌خواهید چیزی خلق کنید یا به یک سرویس هوشمند نیاز دارید؟ فراست مسیر مناسب را پیدا می‌کند تا بدون پیچیدگی وارد جریان کار شوید.</p>
        </div>
        <div class="home-hero-rule" aria-hidden="true"></div>
    </section>

    <section class="home-value" aria-label="مزیت‌های استفاده از فراست">
        <article class="home-value-item">
            <span class="home-value-icon"><i class="fa-solid fa-route" aria-hidden="true"></i></span>
            <div><strong>مسیر روشن برای شروع</strong><p>به‌جای گشتن میان ابزارها، بگویید چه کاری دارید تا قدم بعدی مشخص شود.</p></div>
        </article>
        <article class="home-value-item">
            <span class="home-value-icon"><i class="fa-solid fa-layer-group" aria-hidden="true"></i></span>
            <div><strong>از فایل تا سند نهایی</strong><p>از فایل و اسکن شروع کنید یا یک سند تازه بسازید؛ مسیر متناسب را انتخاب کنید.</p></div>
        </article>
        <article class="home-value-item">
            <span class="home-value-icon"><i class="fa-solid fa-sliders" aria-hidden="true"></i></span>
            <div><strong>انتخاب با کنترل شما</strong><p>پیش از ادامه، نوع کار و مسیر موردنظر خودتان را مشخص می‌کنید.</p></div>
        </article>
    </section>

    <main class="home-discovery" id="homeDiscovery">
        <section class="discovery-panel" aria-labelledby="discovery-title">
            <div class="discovery-heading">
                <span class="home-kicker">شروع هوشمند</span>
                <h2 id="discovery-title">امروز چه کاری را پیش می‌برید؟</h2>
                <p id="discovery-help">یک نقطه شروع انتخاب کنید؛ فراست فقط اطلاعات ضروری را می‌پرسد و شما را به ابزار درست می‌رساند.</p>
            </div>

            <div class="intent-grid" id="intentGrid">
                <button class="intent-card" type="button" data-intent="file">
                    <span class="intent-icon"><i class="fa-solid fa-file-arrow-up" aria-hidden="true"></i></span>
                    <span><strong>فایل دارم</strong><small>PDF، تصویر یا اسکن دارم و می‌خواهم روی آن کار کنم.</small></span>
                </button>
                <button class="intent-card" type="button" data-intent="zero">
                    <span class="intent-icon"><i class="fa-solid fa-pen-ruler" aria-hidden="true"></i></span>
                    <span><strong>از صفر شروع می‌کنم</strong><small>می‌خواهم یک متن، مقاله، کتاب، رزومه، قرارداد یا فرم بسازم.</small></span>
                </button>
                <button class="intent-card" type="button" data-intent="service">
                    <span class="intent-icon"><i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i></span>
                    <span><strong>دنبال یک سرویس هستم</strong><small>OCR، تبدیل صوت به متن، ترجمه، ویرایش یا خروجی می‌خواهم.</small></span>
                </button>
                <button class="intent-card intent-card-muted" type="button" data-intent="unknown">
                    <span class="intent-icon"><i class="fa-solid fa-circle-question" aria-hidden="true"></i></span>
                    <span><strong>هنوز مطمئن نیستم</strong><small>با یک سؤال کوتاه مسیر مناسب را پیدا می‌کنیم.</small></span>
                </button>
            </div>

            <div class="discovery-step" id="discoveryStep" hidden>
                <button class="discovery-back" type="button" id="discoveryBack"><i class="fa-solid fa-arrow-right" aria-hidden="true"></i> بازگشت</button>
                <div class="step-content" id="stepContent"></div>
            </div>

            <div class="discovery-status" id="discoveryStatus" role="status" aria-live="polite"></div>
        </section>

        @if($isAuthenticated && $recentProjects->isNotEmpty())
        <section class="home-recent" aria-labelledby="recent-title">
            <div>
                <span class="home-kicker">ادامه کار</span>
                <h2 id="recent-title">پروژه‌های اخیر</h2>
            </div>
            <div class="recent-list">
                @foreach($recentProjects as $project)
                <a class="recent-item" href="{{ route('editor', ['project' => $project->id]) }}">
                    <span class="recent-file"><i class="fa-regular fa-file-lines" aria-hidden="true"></i></span>
                    <span><strong>{{ $project->name }}</strong><small>{{ $project->context['template'] ?? $project->template_code ?? 'سند' }}</small></span>
                    <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>
                </a>
                @endforeach
            </div>
        </section>
        @endif
    </main>

    <section class="home-explore" aria-labelledby="home-explore-title">
        <div class="home-explore-copy">
            <span class="home-kicker">بیشتر کشف کنید</span>
            <h2 id="home-explore-title">برای قدم بعدی آماده‌اید؟</h2>
            <p>با راهنماهای مجله آشنا شوید یا نمونه‌ها و فایل‌های کاربردی را در فروشگاه ببینید.</p>
        </div>
        <div class="home-explore-actions">
            <a class="home-explore-link primary" href="{{ route('store') }}"><i class="fa-solid fa-bag-shopping" aria-hidden="true"></i> دیدن فروشگاه</a>
            <a class="home-explore-link secondary" href="{{ route('blog') }}"><i class="fa-solid fa-book-open" aria-hidden="true"></i> خواندن مجله</a>
        </div>
    </section>
</div>
@endsection

@push('scripts')
<script src="{{ asset('js/home.js') }}" defer></script>
@endpush
