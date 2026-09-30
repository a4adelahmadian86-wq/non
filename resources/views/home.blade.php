@extends('layouts.app')

@php
    $title = 'فراست | کار با فایل و متن، از ورود تا خروجی';
@endphp

@push('head')
    <meta name="description" content="فراست فضای یکپارچه‌ای برای کار با فایل و متن است؛ فایل را وارد کنید، برآورد و پردازش را انجام دهید، در ویرایشگر ادامه دهید یا فایل دیجیتال موردنیازتان را از فروشگاه تهیه کنید.">
    <link rel="canonical" href="{{ url('/') }}">
    <meta property="og:type" content="website">
    <meta property="og:title" content="فراست | کار با فایل و متن، از ورود تا خروجی">
    <meta property="og:description" content="فایل را وارد کنید، مسیر مناسب را انتخاب کنید و کار را در فضای فراست ادامه دهید.">
    <meta property="og:url" content="{{ url('/') }}">
    <meta property="og:site_name" content="فراست">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="فراست | کار با فایل و متن، از ورود تا خروجی">
    <meta name="twitter:description" content="فایل، متن و خرید محتوای دیجیتال در یک مسیر یکپارچه.">
    <script type="application/ld+json">
    {"@context":"https://schema.org","@type":"WebSite","name":"فراست","alternateName":"FARAST","url":"{{ url('/') }}"}
    </script>
@endpush

@section('content')
<div class="farast-home" dir="rtl">
@if($announcements->count())
<section class="home-announcements" aria-label="آخرین اطلاعیه">
    <span class="home-announcement-label"><i class="fa-solid fa-bullhorn" aria-hidden="true"></i> آخرین اطلاعیه</span>
    <div class="home-announcement-main"><strong>{{ $announcements->first()->title }}</strong><span>{{ \Illuminate\Support\Str::limit($announcements->first()->body,150) }}</span></div>
    <a href="{{ route('announcements') }}">مشاهده اطلاعیه‌ها <i class="fa-solid fa-arrow-left" aria-hidden="true"></i></a>
</section>
@endif

<section class="home-hero" id="home-start" aria-labelledby="home-hero-title">
    <div class="home-hero-copy">
        <span class="home-eyebrow"><i class="fa-solid fa-layer-group" aria-hidden="true"></i> فضای یکپارچه کار با فایل و متن</span>
        <h1 id="home-hero-title">از فایل خام تا نتیجه، در یک مسیر روشن</h1>
        <p class="home-hero-lead">فایل خود را وارد کنید، برآورد و پردازش را ببینید و ادامه کار را در فضای فراست انجام دهید؛ یا اگر فایل آماده می‌خواهید، مستقیم به فروشگاه بروید.</p>
        <div class="home-hero-actions">
            <a class="home-btn home-btn-primary" href="#home-upload" data-home-focus-upload><i class="fa-solid fa-cloud-arrow-up" aria-hidden="true"></i> شروع با فایل</a>
            <a class="home-btn home-btn-secondary" href="{{ route('store') }}"><i class="fa-solid fa-store" aria-hidden="true"></i> مشاهده فروشگاه</a>
        </div>
        <div class="home-hero-notes" aria-label="اطلاعات مسیر">
            <span><i class="fa-solid fa-calculator" aria-hidden="true"></i> برآورد پیش از ادامه</span>
            <span><i class="fa-solid fa-pen-ruler" aria-hidden="true"></i> ادامه در ویرایشگر</span>
            <span><i class="fa-solid fa-file-export" aria-hidden="true"></i> خروجی Word و PDF</span>
        </div>
    </div>
    <div class="home-hero-visual" aria-label="نمایش مسیر کار">
        <div class="home-flow-card">
            <div class="home-flow-header"><span>مسیر کار در فراست</span><span class="home-flow-status"><i class="fa-solid fa-circle" aria-hidden="true"></i> آماده شروع</span></div>
            <div class="home-flow">
                <div class="home-flow-node is-active"><span class="home-flow-icon"><i class="fa-solid fa-file-arrow-up" aria-hidden="true"></i></span><strong>فایل</strong><small>ورود</small></div>
                <span class="home-flow-line" aria-hidden="true"></span>
                <div class="home-flow-node"><span class="home-flow-icon"><i class="fa-solid fa-calculator" aria-hidden="true"></i></span><strong>برآورد</strong><small>شرایط</small></div>
                <span class="home-flow-line" aria-hidden="true"></span>
                <div class="home-flow-node"><span class="home-flow-icon"><i class="fa-solid fa-pen-to-square" aria-hidden="true"></i></span><strong>ویرایش</strong><small>فضای کار</small></div>
                <span class="home-flow-line" aria-hidden="true"></span>
                <div class="home-flow-node"><span class="home-flow-icon"><i class="fa-solid fa-file-export" aria-hidden="true"></i></span><strong>خروجی</strong><small>Word / PDF</small></div>
            </div>
            <div class="home-flow-footer"><span><i class="fa-solid fa-shield-halved" aria-hidden="true"></i> مسیر روشن، بدون پرش بین ابزارها</span><span>فراست</span></div>
        </div>
    </div>
</section>

<section class="home-intent" aria-labelledby="home-intent-title">
    <div class="home-section-heading"><span class="home-section-kicker">اول مقصدت را انتخاب کن</span><h2 id="home-intent-title">فقط مسیر مناسب خودت را بردار</h2><p>فراست سه نقطه شروع روشن دارد؛ لازم نیست قابلیت‌ها را خودت به هم وصل کنی.</p></div>
    <div class="home-intent-grid">
        <article class="home-intent-card home-intent-featured" id="home-upload">
            <div class="home-intent-icon"><i class="fa-solid fa-file-arrow-up" aria-hidden="true"></i></div>
            <span class="home-card-label">اگر فایل داری</span><h3>فایل را وارد کن</h3>
            <p>فایل را بارگذاری کن تا مسیر برآورد و ادامه کار برایت روشن شود.</p>
            <ul><li><i class="fa-solid fa-check" aria-hidden="true"></i> بررسی و برآورد اولیه</li><li><i class="fa-solid fa-check" aria-hidden="true"></i> ادامه در فضای ویرایش</li><li><i class="fa-solid fa-check" aria-hidden="true"></i> خروجی نهایی Word یا PDF</li></ul>
            <div class="home-upload-box" id="homeDrop" tabindex="0" role="button" aria-label="انتخاب یا رها کردن فایل برای شروع">
                <div class="home-upload-icon"><i class="fa-solid fa-cloud-arrow-up" aria-hidden="true"></i></div>
                <div><strong>فایل را اینجا رها کنید</strong><span>یا از دستگاه انتخاب کنید</span></div>
                <button type="button" id="homeChoose" class="home-upload-button"><i class="fa-solid fa-folder-open" aria-hidden="true"></i> انتخاب فایل</button>
                <input id="homeFile" type="file" accept="image/*,.pdf,.zip" hidden>
            </div>
            <div id="homeMsg" class="home-upload-msg" role="status" aria-live="polite" hidden></div>
        </article>

        <article class="home-intent-card">
            <div class="home-intent-icon"><i class="fa-solid fa-pen-ruler" aria-hidden="true"></i></div>
            <span class="home-card-label">اگر می‌خواهی خودت کار کنی</span><h3>وارد فضای ویرایش شو</h3>
            <p>برای نوشتن، ویرایش، کمک هوشمند و ادامه کار روی سند، مسیر Editor را باز کن.</p>
            <ul><li><i class="fa-solid fa-check" aria-hidden="true"></i> ویرایش سند در فضای کار</li><li><i class="fa-solid fa-check" aria-hidden="true"></i> کمک هوشمند برای متن</li><li><i class="fa-solid fa-check" aria-hidden="true"></i> تایپ صوتی و خروجی سند</li></ul>
            <a class="home-card-link" href="{{ route('editor') }}">ورود به ویرایشگر <i class="fa-solid fa-arrow-left" aria-hidden="true"></i></a>
        </article>

        <article class="home-intent-card">
            <div class="home-intent-icon home-intent-icon-store"><i class="fa-solid fa-store" aria-hidden="true"></i></div>
            <span class="home-card-label">اگر فایل آماده می‌خواهی</span><h3>از فروشگاه انتخاب کن</h3>
            <p>محصول را ببین، پیش‌نمایش و اطلاعاتش را بررسی کن و در صورت نیاز به سبد خرید اضافه کن.</p>
            <ul><li><i class="fa-solid fa-check" aria-hidden="true"></i> مرور محصولات واقعی فروشگاه</li><li><i class="fa-solid fa-check" aria-hidden="true"></i> سبد خرید</li><li><i class="fa-solid fa-check" aria-hidden="true"></i> ادامه تا پرداخت و کتابخانه</li></ul>
            <a class="home-card-link" href="{{ route('store') }}">ورود به فروشگاه <i class="fa-solid fa-arrow-left" aria-hidden="true"></i></a>
        </article>
    </div>
</section>

<section class="home-workflow" aria-labelledby="home-workflow-title">
    <div class="home-section-heading home-section-heading-centered"><span class="home-section-kicker">مسیر محصول</span><h2 id="home-workflow-title">از ورود فایل تا خروجی، مرحله‌ها قابل فهم‌اند</h2><p>هر مرحله فقط یک کار اصلی دارد تا کاربر بداند بعد از این چه اتفاقی می‌افتد.</p></div>
    <div class="home-workflow-grid">
        <div class="home-workflow-step"><span>۱</span><div><strong>ورود</strong><p>فایل یا مسیر موردنظر را انتخاب می‌کنی.</p></div></div>
        <div class="home-workflow-step"><span>۲</span><div><strong>بررسی و برآورد</strong><p>شرایط و برآورد اولیه قبل از ادامه نمایش داده می‌شود.</p></div></div>
        <div class="home-workflow-step"><span>۳</span><div><strong>کار در فضای فراست</strong><p>ویرایش، پردازش، کمک هوشمند یا تایپ صوتی در مسیر مربوط انجام می‌شود.</p></div></div>
        <div class="home-workflow-step"><span>۴</span><div><strong>خروجی یا خرید</strong><p>سند را به خروجی Word یا PDF می‌رسانی یا خرید را تا کتابخانه ادامه می‌دهی.</p></div></div>
    </div>
</section>

<section class="home-product-proof" aria-labelledby="home-product-proof-title">
    <div class="home-product-proof-copy">
        <span class="home-section-kicker">تجربه محصول</span><h2 id="home-product-proof-title">وقتی کار شروع شد، ابزارها کنار هم قرار می‌گیرند</h2>
        <p>فراست فقط نقطه ورود نیست؛ مسیر بعد از ورود هم در همان اکوسیستم ادامه دارد: ویرایش سند، کمک هوشمند، تایپ صوتی و خروجی فایل.</p>
        <div class="home-proof-list">
            <div><i class="fa-solid fa-pen-to-square" aria-hidden="true"></i><span><strong>ویرایش</strong> برای ادامه کار روی سند</span></div>
            <div><i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i><span><strong>کمک هوشمند</strong> برای کارهای متنی موجود در Editor</span></div>
            <div><i class="fa-solid fa-microphone-lines" aria-hidden="true"></i><span><strong>تایپ صوتی</strong> برای ورود گفتاری متن</span></div>
            <div><i class="fa-solid fa-file-export" aria-hidden="true"></i><span><strong>Word / PDF</strong> برای گرفتن خروجی</span></div>
        </div>
        <a class="home-text-link" href="{{ route('editor') }}">رفتن به مسیر ویرایش <i class="fa-solid fa-arrow-left" aria-hidden="true"></i></a>
    </div>
    <div class="home-editor-visual" role="img" aria-label="نمای مفهومی فضای ویرایشگر فراست">
        <div class="home-editor-window"><div class="home-editor-toolbar"><span></span><span></span><span></span><b>ویرایشگر فراست</b></div>
            <div class="home-editor-body"><div class="home-editor-sidebar"><span class="is-selected"></span><span></span><span></span><span></span></div>
                <div class="home-editor-page"><div class="home-editor-line wide"></div><div class="home-editor-line"></div><div class="home-editor-line medium"></div><div class="home-editor-line wide"></div><div class="home-editor-line short"></div><div class="home-editor-highlight"></div></div>
                <div class="home-editor-ai"><span><i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i> کمک هوشمند</span><div></div><div></div><div></div></div>
            </div>
        </div>
    </div>
</section>

<section class="home-capabilities" aria-labelledby="home-capabilities-title">
    <div class="home-section-heading"><span class="home-section-kicker">قابلیت‌های واقعی</span><h2 id="home-capabilities-title">هر قابلیت، یک نتیجه مشخص برای کاربر</h2></div>
    <div class="home-capability-grid">
        <article><i class="fa-solid fa-file-pen" aria-hidden="true"></i><h3>ویرایش سند</h3><p>کار روی سند در محیط ویرایشگر، به‌جای جابه‌جایی بین ابزارهای جدا.</p></article>
        <article><i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i><h3>کمک هوشمند</h3><p>عملیات متنی موجود در Editor برای اصلاح، بازنویسی و کارهای متنی.</p></article>
        <article><i class="fa-solid fa-microphone-lines" aria-hidden="true"></i><h3>تایپ صوتی</h3><p>ورود صوتی متن از مسیرهای موجود در فضای ویرایشگر.</p></article>
        <article><i class="fa-solid fa-file-export" aria-hidden="true"></i><h3>خروجی Word و PDF</h3><p>پس از تکمیل کار، سند را در قالب‌های خروجی موجود دریافت کن.</p></article>
    </div>
</section>

<section class="home-trust" aria-labelledby="home-trust-title">
    <div class="home-section-heading home-section-heading-centered"><span class="home-section-kicker">اعتماد از شفافیت می‌آید</span><h2 id="home-trust-title">قبل از تصمیم، مسیر و شرایط را ببین</h2><p>به‌جای عدد و ادعای تبلیغاتی، خود محصول و فرآیند تصمیم‌گیری را شفاف می‌کنیم.</p></div>
    <div class="home-trust-grid">
        <article><i class="fa-solid fa-receipt" aria-hidden="true"></i><h3>قیمت و برآورد</h3><p>مسیر قیمت‌گذاری و برآورد از طریق صفحات و فرآیندهای واقعی پروژه قابل پیگیری است.</p><a href="{{ route('pricing') }}">مشاهده نرخنامه <i class="fa-solid fa-arrow-left" aria-hidden="true"></i></a></article>
        <article><i class="fa-solid fa-lock" aria-hidden="true"></i><h3>فضای خصوصی</h3><p>فایل‌ها و سفارش‌های کاربر در مسیرهای احراز هویت‌شده پروژه مدیریت می‌شوند.</p><a href="{{ route('privacy') }}">حریم خصوصی <i class="fa-solid fa-arrow-left" aria-hidden="true"></i></a></article>
        <article><i class="fa-solid fa-headset" aria-hidden="true"></i><h3>پشتیبانی و قوانین</h3><p>برای راهنمایی و بررسی شرایط، مسیرهای پشتیبانی و قوانین در دسترس هستند.</p><a href="{{ route('terms') }}">قوانین استفاده <i class="fa-solid fa-arrow-left" aria-hidden="true"></i></a></article>
    </div>
</section>

<section class="home-store-bridge" aria-labelledby="home-store-title">
    <div><span class="home-section-kicker">فایل آماده می‌خواهی؟</span><h2 id="home-store-title">محصول واقعی را در ویترین ببین</h2><p>به‌جای نمایش کارت‌های نمونه یا اطلاعات ساختگی، فهرست فعلی فروشگاه را مستقیماً ببین و از همان‌جا خرید را ادامه بده.</p></div>
    <a class="home-btn home-btn-primary" href="{{ route('store') }}"><i class="fa-solid fa-store" aria-hidden="true"></i> ورود به فروشگاه</a>
</section>

<section class="home-faq" aria-labelledby="home-faq-title">
    <div class="home-section-heading"><span class="home-section-kicker">پیش از شروع</span><h2 id="home-faq-title">چند سؤال مهم قبل از اقدام</h2></div>
    <div class="home-faq-list">
        <details><summary>اگر فایل داشته باشم، از کجا شروع کنم؟ <i class="fa-solid fa-chevron-down" aria-hidden="true"></i></summary><p>از «شروع با فایل» استفاده کن. فایل وارد مسیر بارگذاری و سپس preflight می‌شود؛ در صورت نیاز به ورود، همان‌جا به احراز هویت هدایت می‌شوی.</p></details>
        <details><summary>اگر بخواهم خودم روی متن کار کنم چه؟ <i class="fa-solid fa-chevron-down" aria-hidden="true"></i></summary><p>مسیر «ورود به ویرایشگر» برای همین هدف است. Editor در پروژه پشت احراز هویت و محدودیت‌های دسترسی موجود قرار دارد.</p></details>
        <details><summary>فایل آماده برای خرید دارم؛ کجا بروم؟ <i class="fa-solid fa-chevron-down" aria-hidden="true"></i></summary><p>مستقیم وارد فروشگاه شو. اطلاعات محصول، پیش‌نمایش، سبد و ادامه خرید در همان مسیر مدیریت می‌شوند.</p></details>
        <details><summary>قیمت خدمات را از کجا ببینم؟ <i class="fa-solid fa-chevron-down" aria-hidden="true"></i></summary><p>صفحه نرخنامه مسیر رسمی مشاهده قیمت‌گذاری است؛ در مسیر فایل نیز برآورد اولیه قبل از ادامه نمایش داده می‌شود.</p></details>
    </div>
</section>

<section class="home-final-cta" aria-labelledby="home-final-title">
    <div><span class="home-section-kicker">قدم بعدی روشن است</span><h2 id="home-final-title">فایل داری؟ از همین‌جا شروع کن.</h2><p>اگر فایل آماده نداری و فقط می‌خواهی کار کنی، ویرایشگر را باز کن؛ برای خرید هم فروشگاه همیشه در دسترس است.</p></div>
    <div class="home-final-actions">
        <a class="home-btn home-btn-primary" href="#home-upload" data-home-focus-upload><i class="fa-solid fa-cloud-arrow-up" aria-hidden="true"></i> شروع با فایل</a>
        <a class="home-btn home-btn-secondary" href="{{ route('editor') }}"><i class="fa-solid fa-pen-ruler" aria-hidden="true"></i> ویرایشگر</a>
        <a class="home-btn home-btn-tertiary" href="{{ route('store') }}"><i class="fa-solid fa-store" aria-hidden="true"></i> فروشگاه</a>
    </div>
</section>

<div class="typing-quote-modal" id="typingQuoteModal" hidden>
    <div class="typing-quote-card" role="dialog" aria-modal="true" aria-labelledby="typingQuoteTitle" aria-describedby="typingQuoteLead">
        <button class="typing-quote-close" type="button" id="typingQuoteClose" aria-label="بستن"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
        <div class="typing-quote-icon"><i class="fa-solid fa-file-invoice-dollar" aria-hidden="true"></i></div>
        <h2 id="typingQuoteTitle">برآورد اولیه فایل</h2><p id="typingQuoteLead">فایل بررسی شد.</p>
        <div class="typing-quote-lines" id="typingQuoteLines"></div>
        <p class="typing-quote-note">این مبلغ برآورد سریع پیش از ادامه است و مبلغ نهایی طبق پردازش دقیق متن محاسبه می‌شود.</p>
        <div class="typing-quote-actions" id="typingQuoteActions"><button type="button" class="quote-continue" id="typingQuoteAccept">ادامه</button><button type="button" class="quote-decline" id="typingQuoteDecline">فعلاً ادامه نمی‌دهم</button></div>
        <div class="typing-decline-message" id="typingDeclineMessage" hidden></div>
    </div>
</div>
</div>
@endsection

@push('scripts')
<script src="{{ asset('js/home.js') }}" defer></script>
@endpush
