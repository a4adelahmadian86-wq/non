@extends('layouts.app')

@php
    $title = 'فراست | فروشگاه محصولات دیجیتال و خدمات هوشمند اسناد';
    $isAuthenticated = auth()->check();
@endphp

@push('head')
<meta name="description" content="در فراست محصولات دیجیتال و دوره‌های آموزشی را بررسی و تهیه کنید؛ یا با ابزارهای تایپ، OCR، تبدیل گفتار به متن و ویرایش اسناد، کارتان را در یک محیط یکپارچه پیش ببرید.">
<meta name="robots" content="index,follow">
<link rel="canonical" href="{{ url('/') }}">
<meta property="og:type" content="website">
<meta property="og:locale" content="fa_IR">
<meta property="og:site_name" content="فراست">
<meta property="og:title" content="فراست | فروشگاه محصولات دیجیتال و خدمات هوشمند اسناد">
<meta property="og:description" content="محصول دیجیتال انتخاب کنید یا کار با فایل و سند را با ابزارهای فراست آغاز کنید.">
<meta property="og:url" content="{{ url('/') }}">
<meta name="twitter:card" content="summary">
<meta name="twitter:title" content="فراست | محصولات دیجیتال و خدمات هوشمند">
<meta name="twitter:description" content="فروشگاه محصولات دیجیتال در کنار ابزارهای تایپ، OCR، گفتار به متن و ویرایش اسناد.">
<script type="application/ld+json">
@json([
    '@context' => 'https://schema.org',
    '@type' => 'Organization',
    'name' => 'فراست',
    'alternateName' => 'FARAST',
    'url' => url('/'),
    'logo' => asset('icons/farast-icon.svg'),
    'description' => 'فروشگاه محصولات دیجیتال و محیط یکپارچه خدمات تایپ، OCR، تبدیل گفتار به متن و ویرایش اسناد.',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
</script>
<script type="application/ld+json">
@json([
    '@context' => 'https://schema.org',
    '@type' => 'WebSite',
    'name' => 'فراست',
    'url' => url('/'),
    'inLanguage' => 'fa-IR',
    'potentialAction' => [
        '@type' => 'SearchAction',
        'target' => route('store').'?q={search_term_string}',
        'query-input' => 'required name=search_term_string',
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
</script>
@endpush

@section('content')
<div class="farast-home" dir="rtl" data-authenticated="{{ $isAuthenticated ? '1' : '0' }}">
    <section class="home-hero" aria-labelledby="home-title">
        <div class="home-hero-copy">
            <span class="home-kicker"><i class="fa-solid fa-sparkles" aria-hidden="true"></i> FARAST</span>
            <h1 id="home-title">از ایده تا نتیجه، مسیرتان را با فراست شروع کنید</h1>
            <p>محصول دیجیتال موردنیازتان را پیدا کنید یا کار با سند را همین‌جا آغاز کنید؛ از ورود فایل و تبدیل گفتار به متن تا ویرایش و آماده‌سازی خروجی، در یک مسیر روشن و یکپارچه.</p>
            <div class="home-hero-actions">
                <a class="home-hero-cta home-hero-cta--primary" href="#homeDiscovery"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> شروع یک کار جدید</a>
                <a class="home-hero-cta home-hero-cta--secondary" href="{{ route('store') }}"><i class="fa-solid fa-store" aria-hidden="true"></i> ورود به فروشگاه</a>
            </div>
            <div class="home-hero-assurance" aria-label="دو مسیر اصلی فراست">
                <span><i class="fa-solid fa-book-open" aria-hidden="true"></i> محصولات دیجیتال</span>
                <span><i class="fa-solid fa-file-pen" aria-hidden="true"></i> خدمات و ابزارهای سند</span>
            </div>
        </div>
        <div class="home-hero-rule" aria-hidden="true"></div>
    </section>

    <section class="home-pathways" aria-label="دو مسیر اصلی استفاده از فراست">
        <article class="home-pathway home-pathway--products">
            <div class="pathway-symbol" aria-hidden="true"><i class="fa-solid fa-bag-shopping"></i></div>
            <div class="pathway-copy">
                <span class="pathway-eyebrow">فروشگاه محصولات دیجیتال</span>
                <h2>دانش و فایل موردنیازتان را پیدا کنید</h2>
                <p>دوره‌های آموزشی و فایل‌های دیجیتال را بررسی کنید، پیش‌نمایش محصولات را ببینید و با آگاهی انتخاب کنید.</p>
                <ul class="pathway-tags" aria-label="انواع محصولات">
                    <li>دوره آموزشی</li>
                    <li>فایل و منابع دیجیتال</li>
                    <li>پیش‌نمایش پیش از خرید</li>
                </ul>
                <a class="pathway-link" href="{{ route('store') }}">مشاهده فروشگاه <i class="fa-solid fa-arrow-left" aria-hidden="true"></i></a>
            </div>
        </article>

        <article class="home-pathway home-pathway--services">
            <div class="pathway-symbol" aria-hidden="true"><i class="fa-solid fa-wand-magic-sparkles"></i></div>
            <div class="pathway-copy">
                <span class="pathway-eyebrow">خدمات هوشمند فراست</span>
                <h2>کار با سند را ساده‌تر و سریع‌تر پیش ببرید</h2>
                <p>از فایل یا گفتار شروع کنید، متن را در محیط ویرایشگر بازبینی کنید و پس از تکمیل، خروجی مناسب بگیرید.</p>
                <ul class="pathway-tags" aria-label="خدمات فراست">
                    <li>تایپ و OCR</li>
                    <li>تبدیل گفتار به متن</li>
                    <li>ویرایش و خروجی</li>
                </ul>
                <a class="pathway-link" href="#homeDiscovery">شروع استفاده از خدمات <i class="fa-solid fa-arrow-left" aria-hidden="true"></i></a>
            </div>
        </article>
    </section>

    @if($homeCategories->isNotEmpty())
    <nav class="home-category-nav" aria-label="دسته‌بندی محصولات فروشگاه">
        <div class="home-category-nav__heading">
            <strong>دسته‌بندی‌ها</strong>
            <a href="{{ route('store') }}">مشاهده همه <i class="fa-solid fa-arrow-left" aria-hidden="true"></i></a>
        </div>
        <div class="home-category-nav__list">
            @foreach($homeCategories as $category)
                <a href="{{ route('store', ['category' => $category->slug]) }}">
                    <span class="home-category-nav__icon"><i class="{{ $category->icon ?: 'fa-solid fa-folder-open' }}" aria-hidden="true"></i></span>
                    <span>{{ $category->name }}</span>
                    <small>{{ number_format($category->published_products_count) }}</small>
                </a>
            @endforeach
        </div>
    </nav>
    @endif

    <section class="home-products" aria-labelledby="home-products-title">
        <div class="home-section-heading">
            <div>
                <span class="home-kicker">انتخاب از محصولات منتشرشده</span>
                <h2 id="home-products-title">محصولات فروشگاه فراست</h2>
                <p>محصولات موجود را ببینید و جزئیات هرکدام را پیش از انتخاب بررسی کنید.</p>
            </div>
            <a class="home-section-link" href="{{ route('store') }}">همه محصولات <i class="fa-solid fa-arrow-left" aria-hidden="true"></i></a>
        </div>
        <div class="home-product-grid">
            @foreach($homeProducts as $product)
                @php($homeProductImage = $product->cover_path ?: $product->images->where('is_active', true)->first()?->path)
                <article class="home-product-card">
                    <a class="home-product-cover" href="{{ route('store.product', $product->slug) }}">
                        @if($homeProductImage)
                            <img src="{{ asset($homeProductImage) }}" alt="{{ $product->title }}" loading="lazy">
                        @else
                            <span class="home-product-placeholder" aria-hidden="true"><i class="fa-solid fa-{{ $product->type === 'course' ? 'graduation-cap' : 'file-lines' }}"></i><b>FARAST</b></span>
                        @endif
                        <span class="home-product-kind">{{ $product->type === 'course' ? 'دوره آموزشی' : ($product->category?->name ?? 'محصول دیجیتال') }}</span>
                    </a>
                    <div class="home-product-body">
                        <h3><a href="{{ route('store.product', $product->slug) }}">{{ $product->title }}</a></h3>
                        <p>{{ \Illuminate\Support\Str::limit($product->short_description ?: $product->description, 96) }}</p>
                        <div class="home-product-meta">
                            <strong>{{ (int) $product->price_rials === 0 ? 'رایگان' : number_format((int) $product->price_rials / 10).' تومان' }}</strong>
                            <a href="{{ route('store.product', $product->slug) }}">جزئیات <i class="fa-solid fa-arrow-left" aria-hidden="true"></i></a>
                        </div>
                    </div>
                </article>
            @endforeach
        </div>
    </section>
    @else
    <section class="home-products home-products--empty" aria-labelledby="home-products-title">
        <div class="home-section-heading">
            <div>
                <span class="home-kicker">فروشگاه فراست</span>
                <h2 id="home-products-title">محصولات دیجیتال</h2>
                <p>در حال حاضر محصول منتشرشده‌ای برای نمایش در ویترین صفحه اصلی وجود ندارد. برای مشاهده آخرین موارد منتشرشده، وارد فروشگاه شوید.</p>
            </div>
            <a class="home-section-link" href="{{ route('store') }}">ورود به فروشگاه <i class="fa-solid fa-arrow-left" aria-hidden="true"></i></a>
        </div>
    </section>
    @endif

    <section class="home-service-showcase" aria-labelledby="home-services-title">
        <div class="home-section-heading home-section-heading--center">
            <div>
                <span class="home-kicker">از فایل تا سند آماده</span>
                <h2 id="home-services-title">خدماتی که کار را جلو می‌برند</h2>
                <p>ابزارهای اصلی فراست برای تبدیل، نوشتن و آماده‌سازی اسناد</p>
            </div>
        </div>
        <div class="home-service-grid">
            <article class="home-service-card">
                <span><i class="fa-solid fa-file-arrow-up" aria-hidden="true"></i></span>
                <h3>تبدیل فایل به متن</h3>
                <p>فایل PDF، تصویر یا اسکن را وارد کنید و مسیر استخراج متن را آغاز کنید.</p>
                <a class="home-service-card__action" href="{{ route('editor') }}">ورود به محیط کار <i class="fa-solid fa-arrow-left" aria-hidden="true"></i></a>
            </article>
            <article class="home-service-card">
                <span><i class="fa-solid fa-microphone-lines" aria-hidden="true"></i></span>
                <h3>تایپ با گفتار</h3>
                <p>به‌جای تایپ همه متن، گفتار خود را به متن قابل ویرایش تبدیل کنید.</p>
                <a class="home-service-card__action" href="{{ route('editor') }}">ورود به محیط کار <i class="fa-solid fa-arrow-left" aria-hidden="true"></i></a>
            </article>
            <article class="home-service-card">
                <span><i class="fa-solid fa-file-pen" aria-hidden="true"></i></span>
                <h3>ویرایش حرفه‌ای سند</h3>
                <p>متن را در محیط ویرایشگر بازبینی و قالب‌بندی کنید و ادامه دهید.</p>
                <a class="home-service-card__action" href="{{ route('editor') }}">ورود به محیط کار <i class="fa-solid fa-arrow-left" aria-hidden="true"></i></a>
            </article>
            <article class="home-service-card">
                <span><i class="fa-solid fa-file-export" aria-hidden="true"></i></span>
                <h3>خروجی Word و PDF</h3>
                <p>پس از تکمیل سند و طی فرایند سفارش، خروجی موردنیازتان را دریافت کنید.</p>
                <a class="home-service-card__action" href="{{ route('editor') }}">ورود به محیط کار <i class="fa-solid fa-arrow-left" aria-hidden="true"></i></a>
            </article>
        </div>
        <div class="home-service-note">
            <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
            <span>برآورد هزینه بر اساس محتوای واقعی فایل انجام می‌شود؛ جزئیات و مبلغ پیش از تأیید به شما نمایش داده می‌شود.</span>
            <a href="{{ route('pricing') }}">روش محاسبه هزینه</a>
        </div>
    </section>

    <section class="home-process" aria-labelledby="home-process-title">
        <div class="home-section-heading home-section-heading--center">
            <div>
                <span class="home-kicker">مسیر ساده و شفاف</span>
                <h2 id="home-process-title">از انتخاب تا انجام کار</h2>
                <p>برای استفاده از فراست لازم نیست از ابتدا همه جزئیات را بدانید؛ مسیر را مرحله‌به‌مرحله پیش ببرید.</p>
            </div>
        </div>
        <div class="home-process-grid">
            <article class="home-process-step">
                <span class="home-process-step__number">۱</span>
                <div><h3>مسیرتان را انتخاب کنید</h3><p>محصول دیجیتال می‌خواهید یا قصد دارید روی یک فایل و سند کار کنید؟</p></div>
            </article>
            <article class="home-process-step">
                <span class="home-process-step__number">۲</span>
                <div><h3>فایل را وارد کنید یا بسازید</h3><p>از فایل موجود شروع کنید یا پروژه‌ای تازه با قالب مناسب بسازید.</p></div>
            </article>
            <article class="home-process-step">
                <span class="home-process-step__number">۳</span>
                <div><h3>بازبینی و ادامه دهید</h3><p>محتوا را در محیط کار بررسی و ویرایش کنید و گزینه‌های خروجی را دنبال کنید.</p></div>
            </article>
        </div>
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
                    <span><strong>دنبال یک سرویس هستم</strong><small>تایپ، OCR، تبدیل گفتار به متن، ویرایش یا خروجی Word و PDF می‌خواهم.</small></span>
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

    <section class="home-faq" aria-labelledby="home-faq-title">
        <div class="home-section-heading home-section-heading--center">
            <div>
                <span class="home-kicker">پیش از شروع</span>
                <h2 id="home-faq-title">پاسخ چند پرسش کاربردی</h2>
                <p>اطلاعات اصلی را شفاف می‌گوییم تا با دید روشن‌تری انتخاب کنید.</p>
            </div>
        </div>
        <div class="home-faq-grid">
            <details class="home-faq-item">
                <summary>فراست فروشگاه است یا ابزار انجام کار؟</summary>
                <p>هر دو مسیر را در یک سایت ارائه می‌کند: می‌توانید محصولات دیجیتال منتشرشده را بررسی کنید یا برای کار با فایل و سند وارد محیط خدمات شوید.</p>
            </details>
            <details class="home-faq-item">
                <summary>برای شروع کار با فایل چه مراحلی دارم؟</summary>
                <p>نوع محتوای فایل را مشخص می‌کنید، فایل را بارگذاری می‌کنید و پس از آماده‌شدن برآورد، جزئیات را پیش از تأیید بررسی می‌کنید.</p>
            </details>
            <details class="home-faq-item">
                <summary>هزینه خدمات چگونه مشخص می‌شود؟</summary>
                <p>برای فرایندهای مشمول برآورد، اطلاعات فایل بررسی می‌شود و مبلغ قابل پرداخت پیش از تأیید به شما نمایش داده می‌شود. جزئیات روش محاسبه در صفحه قیمت‌گذاری آمده است.</p>
                <a href="{{ route('pricing') }}">مشاهده روش قیمت‌گذاری <i class="fa-solid fa-arrow-left" aria-hidden="true"></i></a>
            </details>
            <details class="home-faq-item">
                <summary>چطور به کار نیمه‌تمامم برگردم؟</summary>
                <p>پس از ورود به حساب، از فضای کاربری یا بخش پروژه‌های اخیر می‌توانید کارهای ذخیره‌شده خود را ادامه دهید.</p>
                <a href="{{ route('dashboard') }}">ورود به فضای کاربری <i class="fa-solid fa-arrow-left" aria-hidden="true"></i></a>
            </details>
        </div>
    </section>
    <script type="application/ld+json">
    @json([
        '@context' => 'https://schema.org',
        '@type' => 'FAQPage',
        'mainEntity' => array_map(static fn ($item) => [
            '@type' => 'Question',
            'name' => $item[0],
            'acceptedAnswer' => ['@type' => 'Answer', 'text' => $item[1]],
        ], \$homeFaqSchema),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    </script>
</div>
@endsection

@push('scripts')
<script src="{{ asset('js/home.js') }}" defer></script>
@endpush
