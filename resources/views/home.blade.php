@extends('layouts.app')
@section('content')
@if($announcements->count())
<section class="home-announcements" dir="rtl">
    <div class="home-announcement-label"><i class="fa-solid fa-bullhorn"></i><b>آخرین اطلاعیه</b></div>
    <div class="home-announcement-main"><strong>{{ $announcements->first()->title }}</strong><span>{{ \Illuminate\Support\Str::limit($announcements->first()->body,150) }}</span></div>
    <a href="/announcements">همه اعلانات <i class="fa-solid fa-arrow-left"></i></a>
</section>
@endif

<section class="hero farast-home-hero" id="start">
    <div class="hero-copy">
        <span class="eyebrow"><i class="fa-solid fa-bolt"></i> مسیر سریع تا نتیجه</span>
        <h1>فایل بخرید یا سفارش تایپ بدهید — در چند دقیقه شروع کنید</h1>
        <p>دو مسیر شفاف: خرید فایل دیجیتال یا بارگذاری برای تایپ و پردازش هوشمند. هر دو در یک فضای یکپارچه با سبد خرید و پرداخت امن.</p>
        <div class="actions">
            <a class="btn primary" href="#farastStore"><i class="fa-solid fa-store"></i> خرید از فروشگاه</a>
            <a class="btn ghost" href="#start" id="heroUploadFocus"><i class="fa-solid fa-cloud-arrow-up"></i> برآورد سریع فایل</a>
        </div>
        <div class="hero-trust">
            <span><i class="fa-solid fa-shield-halved"></i> پرداخت امن</span>
            <span><i class="fa-solid fa-bag-shopping"></i> سبد یکپارچه</span>
            <span><i class="fa-solid fa-wand-magic-sparkles"></i> پردازش هوشمند</span>
            <span><i class="fa-solid fa-gift"></i> اعتبار هفتگی</span>
        </div>
    </div>

    <div class="hero-card premium-upload-card">
        <div class="upload-home" id="homeDrop">
            <div class="upload-orbit"><span><i class="fa-solid fa-cloud-arrow-up"></i></span><i class="fa-solid fa-sparkles orbit-spark"></i></div>
            <strong>فایل را اینجا رها کنید</strong>
            <span>تصویر · PDF · ZIP تصاویر — برآورد فوری</span>
            <button type="button" id="homeChoose"><i class="fa-solid fa-folder-open"></i> انتخاب فایل</button>
            <input id="homeFile" type="file" accept="image/*,.pdf,.zip" hidden>
        </div>
        <div id="homeMsg" class="home-upload-msg" hidden></div>
        <small><i class="fa-solid fa-gift"></i> با اعتبار هفتگی فعال، یک صفحه در برآورد اعمال می‌شود.</small>
    </div>
</section>

<section class="farast-app-section farast-path-section" aria-label="انتخاب مسیر">
    <div class="farast-section-head">
        <div>
            <h2>کدام مسیر را می‌خواهید؟</h2>
            <p>هدف خود را مشخص کنید و مستقیم وارد همان مسیر شوید.</p>
        </div>
    </div>
    <div class="farast-path-grid">
        <article class="farast-path-card path-store" data-searchable>
            <div class="farast-path-icon"><i class="fa-solid fa-store"></i></div>
            <h3>خرید فایل دیجیتال</h3>
            <p>کاتالوگ آماده، پیش‌نمایش، افزودن به سبد و دانلود امن پس از پرداخت.</p>
            <ul class="farast-path-list">
                <li><i class="fa-solid fa-check"></i> جستجو و فیلتر سریع</li>
                <li><i class="fa-solid fa-check"></i> سبد خرید یکپارچه</li>
                <li><i class="fa-solid fa-check"></i> کتابخانه فایل‌های خریداری‌شده</li>
            </ul>
            <a class="farast-path-cta" href="#farastStore"><i class="fa-solid fa-arrow-left"></i> ورود به ویترین</a>
        </article>
        <article class="farast-path-card path-service" data-searchable>
            <div class="farast-path-icon"><i class="fa-solid fa-pen-ruler"></i></div>
            <h3>تایپ و خدمات هوشمند</h3>
            <p>بارگذاری فایل، برآورد هزینه، ویرایشگر حرفه‌ای و خروجی Word / PDF.</p>
            <ul class="farast-path-list">
                <li><i class="fa-solid fa-check"></i> برآورد قبل از پرداخت</li>
                <li><i class="fa-solid fa-check"></i> OCR و تایپ صوتی</li>
                <li><i class="fa-solid fa-check"></i> ویرایش و خروجی نهایی</li>
            </ul>
            <div class="farast-path-actions">
                <a class="farast-path-cta primary" href="#start"><i class="fa-solid fa-cloud-arrow-up"></i> بارگذاری فایل</a>
                <a class="farast-path-link" href="/pricing"><i class="fa-solid fa-calculator"></i> نرخنامه</a>
            </div>
        </article>
    </div>
</section>

<section class="farast-app-section" id="farastStore">
    <div class="farast-section-head">
        <div>
            <h2>ویترین فایل‌های دیجیتال</h2>
            <p>محصولات آماده خرید — انتخاب کنید و به سبد اضافه کنید.</p>
        </div>
        <a class="farast-section-link" href="/store"><i class="fa-solid fa-store"></i> همه محصولات</a>
    </div>
    @if(isset($products) && $products->count())
        <div class="farast-product-grid">
            @foreach($products as $product)
            <article class="farast-product-card" data-searchable>
                <div class="farast-product-preview"><i class="fa-regular fa-file-lines"></i></div>
                <div class="farast-product-body">
                    <h3>{{ $product->title }}</h3>
                    <p>{{ \Illuminate\Support\Str::limit($product->short_description ?? '',75) }}</p>
                    <div class="farast-product-meta">
                        <span class="farast-product-price">{{ number_format($product->price) }} تومان</span>
                        <div class="farast-product-actions">
                            <button type="button" class="add-cart" data-cart-add="{{ $product->id }}" data-title="{{ $product->title }}" data-price="{{ $product->price }}" aria-label="افزودن به سبد"><i class="fa-solid fa-bag-shopping"></i></button>
                        </div>
                    </div>
                </div>
            </article>
            @endforeach
        </div>
    @else
        <div class="farast-store-empty">
            <div><i class="fa-solid fa-box-open"></i></div>
            <strong>ویترین در حال آماده‌سازی است</strong>
            <span>به‌زودی محصولات دیجیتال اینجا نمایش داده می‌شوند. فعلاً می‌توانید از مسیر خدمات تایپ شروع کنید.</span>
            <a class="farast-empty-cta" href="#start"><i class="fa-solid fa-cloud-arrow-up"></i> شروع با بارگذاری فایل</a>
        </div>
    @endif
</section>

<section class="farast-app-section farast-journey" aria-label="مسیر کار">
    <div class="farast-section-head">
        <div>
            <h2>مسیر شفاف تا دریافت نتیجه</h2>
            <p>چهار مرحله ساده از انتخاب تا تحویل.</p>
        </div>
    </div>
    <div class="home-steps">
        <div><b>۱</b><span>فایل یا محصول را انتخاب کنید</span></div>
        <div><b>۲</b><span>برآورد و شرایط را ببینید</span></div>
        <div><b>۳</b><span>پرداخت و ادامه در فضای کار</span></div>
        <div><b>۴</b><span>خروجی یا دانلود را دریافت کنید</span></div>
    </div>
</section>

<section class="features farast-feature-strip">
    <article>
        <span class="feature-icon"><i class="fa-solid fa-file-pen"></i></span>
        <b>ویرایشگر حرفه‌ای</b>
        <span>محیط A4، ویرایش، بررسی واژه‌های مشکوک و خروجی Word و PDF.</span>
    </article>
    <article>
        <span class="feature-icon"><i class="fa-solid fa-microphone-lines"></i></span>
        <b>ورودی صوتی</b>
        <span>مسیر مستقیم مرورگر و پشتیبان سروری برای تایپ صوتی.</span>
    </article>
    <article>
        <span class="feature-icon"><i class="fa-solid fa-bag-shopping"></i></span>
        <b>خرید یکپارچه</b>
        <span>سبد خرید همیشه در دسترس و انتخاب‌ها در فضای کار حفظ می‌شوند.</span>
    </article>
    <article>
        <span class="feature-icon"><i class="fa-solid fa-lock"></i></span>
        <b>فضای خصوصی</b>
        <span>فایل‌ها و سفارش‌ها فقط برای شما قابل دسترسی هستند.</span>
    </article>
</section>

<section class="farast-app-section farast-final-cta">
    <div class="farast-final-card">
        <div>
            <h2>آماده شروع هستید؟</h2>
            <p>فایل خود را بارگذاری کنید یا مستقیم وارد فروشگاه شوید.</p>
        </div>
        <div class="farast-final-actions">
            <a class="btn primary" href="#start"><i class="fa-solid fa-cloud-arrow-up"></i> برآورد سریع فایل</a>
            <a class="btn ghost" href="#farastStore"><i class="fa-solid fa-store"></i> مشاهده ویترین</a>
        </div>
    </div>
</section>

<div class="typing-quote-modal" id="typingQuoteModal" hidden>
    <div class="typing-quote-card" role="dialog" aria-modal="true" aria-labelledby="typingQuoteTitle">
        <button class="typing-quote-close" type="button" id="typingQuoteClose" aria-label="بستن"><i class="fa-solid fa-xmark"></i></button>
        <div class="typing-quote-icon"><i class="fa-solid fa-file-invoice-dollar"></i></div>
        <h2 id="typingQuoteTitle">برآورد اولیه فایل</h2>
        <p id="typingQuoteLead">فایل بررسی شد.</p>
        <div class="typing-quote-lines" id="typingQuoteLines"></div>
        <p class="typing-quote-note">این مبلغ برآورد سریع پیش از تایپ است و مبلغ نهایی پس از پردازش دقیق متن محاسبه می‌شود.</p>
        <div class="typing-quote-actions" id="typingQuoteActions">
            <button type="button" class="quote-continue" id="typingQuoteAccept">ادامه</button>
            <button type="button" class="quote-decline" id="typingQuoteDecline">فعلاً ادامه نمی‌دهم</button>
        </div>
        <div class="typing-decline-message" id="typingDeclineMessage" hidden></div>
    </div>
</div>
@endsection

@push('scripts')
<style>
/* —— Homepage conversion polish —— */
.farast-home-hero{margin:28px auto 0;max-width:1260px;padding:0 22px;display:grid;grid-template-columns:1.15fr .85fr;gap:28px;align-items:center}
.farast-home-hero .hero-copy{padding:12px 0}
.farast-home-hero .eyebrow{display:inline-flex;align-items:center;gap:7px;color:#1769ff;font-weight:700;font-size:.82rem;background:#edf4ff;padding:6px 12px;border-radius:999px}
.farast-home-hero h1{margin:14px 0 10px;font-size:clamp(1.55rem,3.2vw,2.35rem);line-height:1.35;color:#14284c;letter-spacing:-.4px}
.farast-home-hero .hero-copy>p{margin:0 0 18px;color:#6b7c93;font-size:.95rem;line-height:1.9;max-width:540px}
.farast-home-hero .actions{display:flex;flex-wrap:wrap;gap:10px;margin-bottom:16px}
.farast-home-hero .btn{display:inline-flex;align-items:center;gap:8px;padding:12px 18px;border-radius:12px;font-weight:700;font-size:.88rem;transition:.2s}
.farast-home-hero .btn.primary{background:linear-gradient(135deg,#1769ff,#4b6ef5);color:#fff;border:0;box-shadow:0 10px 24px rgba(23,105,255,.22)}
.farast-home-hero .btn.primary:hover{transform:translateY(-2px);box-shadow:0 14px 28px rgba(23,105,255,.28)}
.farast-home-hero .btn.ghost{background:#edf4ff;color:#1769ff;border:1px solid #d0e0ff}
.farast-home-hero .btn.ghost:hover{background:#e0ecff}
.farast-home-hero .hero-trust{display:flex;flex-wrap:wrap;gap:10px 16px}
.farast-home-hero .hero-trust span{display:inline-flex;align-items:center;gap:6px;color:#5d6f88;font-size:.78rem}
.farast-home-hero .hero-trust i{color:#1769ff;font-size:.8rem}

.premium-upload-card{background:linear-gradient(160deg,#0d2450 0%,#1a4db8 55%,#3d5fd4 100%);border-radius:24px;padding:28px 24px;color:#fff;box-shadow:0 28px 60px rgba(18,50,120,.28);min-height:320px;display:flex;flex-direction:column;justify-content:center}
.upload-home{text-align:center;cursor:pointer;border:1.5px dashed rgba(255,255,255,.35);border-radius:18px;padding:28px 18px;transition:.2s;background:rgba(255,255,255,.06)}
.upload-home.dragover,.upload-home:hover{border-color:rgba(255,255,255,.7);background:rgba(255,255,255,.12)}
.upload-orbit{position:relative;width:72px;height:72px;margin:0 auto 14px;display:grid;place-items:center}
.upload-orbit span{width:64px;height:64px;border-radius:50%;background:rgba(255,255,255,.15);display:grid;place-items:center;font-size:1.6rem}
.orbit-spark{position:absolute;top:0;right:0;font-size:.9rem;color:#7de8ff;animation:sparkPulse 1.8s ease-in-out infinite}
@keyframes sparkPulse{0%,100%{opacity:.6;transform:scale(1)}50%{opacity:1;transform:scale(1.15)}}
.upload-home strong{display:block;font-size:1.05rem;margin-bottom:6px}
.upload-home>span{display:block;font-size:.8rem;opacity:.85;margin-bottom:16px}
#homeChoose{border:0;border-radius:11px;padding:10px 18px;background:#fff;color:#1769ff;font:inherit;font-weight:700;font-size:.85rem;cursor:pointer;display:inline-flex;align-items:center;gap:7px;transition:.2s}
#homeChoose:hover{transform:translateY(-1px);box-shadow:0 8px 20px rgba(0,0,0,.15)}
.premium-upload-card small{display:block;margin-top:14px;text-align:center;font-size:.75rem;opacity:.85;line-height:1.7}
.home-upload-msg{margin-top:12px;padding:10px 12px;border-radius:10px;background:rgba(255,255,255,.12);font-size:.8rem;line-height:1.7;text-align:center}

/* Path cards */
.farast-path-section{margin-top:48px}
.farast-path-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}
.farast-path-card{position:relative;padding:26px 24px;border:1px solid #e0e8f3;border-radius:20px;background:#fff;box-shadow:0 12px 32px rgba(18,43,83,.05);transition:.22s;display:flex;flex-direction:column;gap:10px}
.farast-path-card:hover{transform:translateY(-3px);border-color:#c5d7f5;box-shadow:0 20px 44px rgba(18,43,83,.1)}
.farast-path-icon{width:52px;height:52px;border-radius:15px;display:grid;place-items:center;font-size:1.2rem;background:linear-gradient(145deg,#edf4ff,#f3f0ff);color:#3f56d8}
.path-store .farast-path-icon{background:linear-gradient(145deg,#e8f5ee,#edf4ff);color:#1a9b5c}
.farast-path-card h3{margin:0;font-size:1.08rem;color:#1b3155}
.farast-path-card>p{margin:0;color:#718198;font-size:.86rem;line-height:1.85}
.farast-path-list{list-style:none;margin:4px 0 8px;padding:0;display:grid;gap:6px}
.farast-path-list li{display:flex;align-items:center;gap:8px;font-size:.82rem;color:#4a5d78}
.farast-path-list i{color:#1769ff;font-size:.7rem}
.path-store .farast-path-list i{color:#1a9b5c}
.farast-path-cta{display:inline-flex;align-items:center;gap:7px;margin-top:auto;padding:11px 16px;border-radius:11px;background:#1769ff;color:#fff;font-size:.84rem;font-weight:700;border:0;transition:.2s;width:fit-content}
.farast-path-cta:hover{background:#1258d6;transform:translateX(-2px)}
.farast-path-cta.primary{background:linear-gradient(135deg,#1769ff,#4b6ef5)}
.farast-path-actions{display:flex;flex-wrap:wrap;align-items:center;gap:10px;margin-top:auto}
.farast-path-link{display:inline-flex;align-items:center;gap:6px;color:#536680;font-size:.82rem;font-weight:600}
.farast-path-link:hover{color:#1769ff}

/* Store empty */
.farast-store-empty{min-height:220px;padding:36px 28px;border:1px dashed #cad7e8;border-radius:20px;background:linear-gradient(145deg,#fff,#f7faff);display:grid;place-items:center;align-content:center;text-align:center;gap:10px;color:#7b8ba0}
.farast-store-empty div{width:56px;height:56px;border-radius:16px;display:grid;place-items:center;background:#edf4ff;color:#1769ff;font-size:1.4rem}
.farast-store-empty strong{color:#2e4260;font-size:.98rem}
.farast-store-empty span{max-width:520px;font-size:.84rem;line-height:1.9}
.farast-empty-cta{display:inline-flex;align-items:center;gap:7px;margin-top:6px;padding:10px 16px;border-radius:11px;background:#1769ff;color:#fff;font-size:.84rem;font-weight:700}
.farast-empty-cta:hover{background:#1258d6}

/* Journey steps */
.farast-journey{margin-top:48px}
.home-steps{display:grid;grid-template-columns:repeat(4,1fr);gap:14px}
.home-steps>div{background:#fff;border:1px solid #e3eaf3;border-radius:16px;padding:18px 14px;text-align:center;box-shadow:0 8px 22px rgba(16,40,78,.04)}
.home-steps b{display:grid;place-items:center;width:36px;height:36px;margin:0 auto 10px;border-radius:50%;background:linear-gradient(145deg,#1769ff,#4b6ef5);color:#fff;font-size:.9rem}
.home-steps span{display:block;font-size:.82rem;color:#4e607a;line-height:1.7}

/* Features */
.farast-feature-strip{margin:48px auto 0!important;max-width:1260px;padding:0 22px;display:grid;grid-template-columns:repeat(4,1fr);gap:14px}
.farast-feature-strip article{background:#fff;border:1px solid #e3eaf3;border-radius:16px;padding:20px 16px;box-shadow:0 8px 22px rgba(16,40,78,.04)}
.farast-feature-strip .feature-icon{width:42px;height:42px;border-radius:12px;display:grid;place-items:center;background:#edf4ff;color:#1769ff;font-size:1rem;margin-bottom:10px}
.farast-feature-strip b{display:block;font-size:.9rem;color:#1b3155;margin-bottom:6px}
.farast-feature-strip span{display:block;font-size:.8rem;color:#718198;line-height:1.8}

/* Final CTA */
.farast-final-cta{margin:52px auto 20px}
.farast-final-card{display:flex;align-items:center;justify-content:space-between;gap:24px;padding:28px 32px;border-radius:22px;background:linear-gradient(135deg,#0f2148,#1a3d9e 60%,#2a52c4);color:#fff;box-shadow:0 24px 50px rgba(15,40,100,.25)}
.farast-final-card h2{margin:0 0 6px;font-size:1.35rem}
.farast-final-card p{margin:0;opacity:.88;font-size:.9rem}
.farast-final-actions{display:flex;flex-wrap:wrap;gap:10px}
.farast-final-actions .btn{display:inline-flex;align-items:center;gap:8px;padding:12px 18px;border-radius:12px;font-weight:700;font-size:.88rem}
.farast-final-actions .btn.primary{background:#fff;color:#1769ff;border:0}
.farast-final-actions .btn.primary:hover{transform:translateY(-2px)}
.farast-final-actions .btn.ghost{background:transparent;color:#fff;border:1px solid rgba(255,255,255,.4)}
.farast-final-actions .btn.ghost:hover{background:rgba(255,255,255,.12)}

/* Announcements bar (if present) */
.home-announcements{max-width:1260px;margin:16px auto 0;padding:0 22px;display:flex;align-items:center;gap:14px;flex-wrap:wrap}
.home-announcement-label{display:inline-flex;align-items:center;gap:6px;padding:7px 12px;border-radius:10px;background:#edf4ff;color:#1769ff;font-size:.78rem;font-weight:700;white-space:nowrap}
.home-announcement-main{flex:1;min-width:0;display:flex;align-items:center;gap:10px;font-size:.84rem;color:#4a5d78}
.home-announcement-main strong{color:#1b3155}
.home-announcement-main span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.home-announcements>a{font-size:.8rem;color:#1769ff;font-weight:600;white-space:nowrap}

/* Quote modal (unchanged behavior) */
.typing-quote-modal{position:fixed;z-index:9000;inset:0;background:rgba(7,23,52,.58);backdrop-filter:blur(8px);display:grid;place-items:center;padding:20px}
.typing-quote-modal[hidden]{display:none}
.typing-quote-card{position:relative;width:min(520px,100%);background:#fff;border-radius:22px;padding:28px;box-shadow:0 28px 90px rgba(7,25,58,.25)}
.typing-quote-close{position:absolute;left:16px;top:16px;width:34px;height:34px;border:1px solid #e2e8f1;border-radius:10px;background:#fff;color:#6c7c91;cursor:pointer}
.typing-quote-icon{width:54px;height:54px;border-radius:16px;display:grid;place-items:center;background:linear-gradient(145deg,#edf4ff,#f5f1ff);color:#3e57d8;font-size:1.25rem}
.typing-quote-card h2{margin:13px 0 4px;color:#182f52;font-size:1.25rem}
.typing-quote-card>p{margin:0;color:#75859b;font-size:.84rem}
.typing-quote-lines{margin:18px 0 12px;border:1px solid #e3e9f2;border-radius:15px;overflow:hidden}
.typing-quote-line{min-height:45px;padding:10px 13px;display:flex;align-items:center;justify-content:space-between;gap:14px;border-bottom:1px solid #edf1f6;font-size:.84rem}
.typing-quote-line:last-child{border-bottom:0}
.typing-quote-line span{color:#708097}
.typing-quote-line strong{color:#213958}
.typing-quote-line.discount strong{color:#21845b}
.typing-quote-line.total{background:#f7faff}
.typing-quote-line.total strong{font-size:1rem;color:#1769ff}
.typing-quote-line.deposit{background:#fff9ec}
.typing-quote-line.deposit strong{color:#b87709}
.quote-old{text-decoration:line-through;color:#9ca8b8!important;font-weight:500!important}
.typing-quote-note{font-size:.75rem!important;line-height:1.8!important;color:#8996a7!important}
.typing-quote-actions{display:flex;gap:9px;margin-top:18px}
.typing-quote-actions button{flex:1;border-radius:11px;padding:10px 13px;font:inherit;font-size:.84rem;font-weight:700;cursor:pointer}
.quote-continue{border:1px solid #1769ff;background:#1769ff;color:#fff}
.quote-decline{border:1px solid #dce4ef;background:#fff;color:#60728a}
.typing-decline-message{margin-top:18px;padding:16px;border:1px solid #e1e8f2;border-radius:14px;background:#f8faff;color:#52667f;font-size:.84rem;line-height:2}

/* Responsive */
@media(max-width:960px){
  .farast-home-hero{grid-template-columns:1fr;gap:22px}
  .premium-upload-card{min-height:280px}
  .farast-path-grid{grid-template-columns:1fr}
  .home-steps{grid-template-columns:1fr 1fr}
  .farast-feature-strip{grid-template-columns:1fr 1fr}
  .farast-final-card{flex-direction:column;align-items:flex-start;text-align:right}
}
@media(max-width:560px){
  .farast-home-hero{padding:0 14px;margin-top:18px}
  .farast-home-hero h1{font-size:1.4rem}
  .home-steps,.farast-feature-strip{grid-template-columns:1fr}
  .farast-final-card{padding:22px 18px}
  .home-announcements{padding:0 14px}
}
@media(prefers-reduced-motion:reduce){
  .orbit-spark,.farast-path-card,.farast-home-hero .btn{animation:none!important;transition:none!important}
}
</style>
<script>
(()=>{
const d=document.getElementById('homeDrop'),f=document.getElementById('homeFile'),c=document.getElementById('homeChoose'),m=document.getElementById('homeMsg'),token=document.querySelector('meta[name=csrf-token]')?.content||'',modal=document.getElementById('typingQuoteModal'),lines=document.getElementById('typingQuoteLines'),lead=document.getElementById('typingQuoteLead'),accept=document.getElementById('typingQuoteAccept'),decline=document.getElementById('typingQuoteDecline'),close=document.getElementById('typingQuoteClose'),declineMessage=document.getElementById('typingDeclineMessage');
if(!d)return;
const money=n=>new Intl.NumberFormat('fa-IR').format(Math.round(Number(n||0)/10))+' تومان';
const api=async(url,body={})=>{const r=await fetch(url,{method:'POST',headers:{'X-CSRF-TOKEN':token,'Accept':'application/json','Content-Type':'application/json'},body:JSON.stringify(body)});let j={};try{j=await r.json()}catch{}if(!r.ok){const e=new Error(j.message||'خطا در ارتباط با سرور');e.status=r.status;throw e}return j};
function openQuote(q){
  declineMessage.hidden=true;document.getElementById('typingQuoteActions').hidden=false;modal.hidden=false;
  lead.textContent=`حدود ${new Intl.NumberFormat('fa-IR').format(q.pages)} صفحه برای این فایل شناسایی شد.`;
  let html=`<div class="typing-quote-line"><span>برآورد اولیه</span><strong class="${q.discount_rials>0?'quote-old':''}">${money(q.estimate_rials)}</strong></div>`;
  if(q.discount_rials>0)html+=`<div class="typing-quote-line discount"><span>اعتبار هفتگی فراست</span><strong>− ${money(q.discount_rials)}</strong></div>`;
  html+=`<div class="typing-quote-line total"><span>برآورد پس از اعتبار</span><strong>${money(q.payable_estimate_rials)}</strong></div>`;
  if(q.deposit_rials>0)html+=`<div class="typing-quote-line deposit"><span>مبلغ لازم برای شروع این سفارش</span><strong>${money(q.deposit_rials)}</strong></div>`;
  lines.innerHTML=html;
  accept.textContent=q.deposit_rials>0?'پرداخت و ادامه':'تأیید و ادامه';
  accept.dataset.mode=q.mode;
}
async function estimate(){m.hidden=false;m.textContent='در حال برآورد سریع تعداد صفحات و هزینه…';try{const q=await api('/editor/preflight/estimate');m.textContent='برآورد اولیه آماده است.';if(q.mode==='free'){const go=await api('/editor/preflight/accept',{accept:true});location.href=go.editor_url||'/editor';return}openQuote(q)}catch(e){if(e.status===401){location.href='/login?continue='+encodeURIComponent('/?typing_preflight=1');return}m.textContent=e.message}}
async function send(file){m.hidden=false;m.textContent='در حال بارگذاری امن فایل…';const fd=new FormData();fd.append('source',file);try{const r=await fetch('/editor/upload',{method:'POST',headers:{'X-CSRF-TOKEN':token,'Accept':'application/json'},body:fd});const j=await r.json();if(!r.ok)throw new Error(j.message||'آپلود ناموفق بود');if(j.requires_login){m.textContent='فایل موقتاً حفظ شد؛ برای برآورد و ادامه وارد حساب شوید.';location.href='/login?continue='+encodeURIComponent('/?typing_preflight=1');return}await estimate()}catch(e){m.textContent=navigator.onLine?e.message:'اتصال اینترنت در دسترس نیست؛ فایل ارسال نشده است.'}}
c.onclick=()=>f.click();f.onchange=()=>{if(f.files[0])send(f.files[0])};['dragenter','dragover'].forEach(x=>d.addEventListener(x,e=>{e.preventDefault();d.classList.add('dragover')}));['dragleave','drop'].forEach(x=>d.addEventListener(x,e=>{e.preventDefault();d.classList.remove('dragover')}));d.addEventListener('drop',e=>{if(e.dataTransfer.files[0])send(e.dataTransfer.files[0])});
accept.onclick=async()=>{accept.disabled=true;try{const j=await api('/editor/preflight/accept',{accept:true});location.href=j.action==='deposit'?j.checkout_url:(j.editor_url||'/editor')}catch(e){m.hidden=false;m.textContent=e.message;modal.hidden=true}finally{accept.disabled=false}};
decline.onclick=async()=>{decline.disabled=true;try{const j=await api('/editor/preflight/decline');document.getElementById('typingQuoteActions').hidden=true;lines.innerHTML='';lead.textContent='درخواست در همین مرحله متوقف شد.';declineMessage.textContent=j.message;declineMessage.hidden=false}catch(e){declineMessage.textContent='درخواست متوقف شد. هر زمان آماده بودید می‌توانید دوباره برگردید.';declineMessage.hidden=false}finally{decline.disabled=false}};
close.onclick=()=>{modal.hidden=true};modal.addEventListener('click',e=>{if(e.target===modal)modal.hidden=true});
if(new URLSearchParams(location.search).get('typing_preflight')==='1')estimate();

// Smooth focus on upload when CTA clicked
document.querySelectorAll('a[href="#start"]').forEach(a=>{
  a.addEventListener('click',e=>{
    e.preventDefault();
    d.scrollIntoView({behavior:'smooth',block:'center'});
    d.classList.add('dragover');
    setTimeout(()=>d.classList.remove('dragover'),1200);
  });
});
})();
</script>
@endpush
