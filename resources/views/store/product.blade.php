@extends('layouts.app')
@push('head')
<meta name="description" content="{{ $product->seo_description ?: \Illuminate\Support\Str::limit(strip_tags($product->short_description ?: $product->description ?: $product->title), 155) }}">
<meta property="og:title" content="{{ $product->seo_title ?: $product->title }}">
<meta property="og:description" content="{{ $product->seo_description ?: $product->short_description }}">
<meta property="og:type" content="product">
<meta property="og:url" content="{{ request()->url() }}">
@php
    $ratingCount = array_sum($ratingBreakdown);
    $productStructuredData = [
        '@context' => 'https://schema.org',
        '@type' => 'Product',
        'name' => $product->title,
        'description' => $product->seo_description ?: $product->short_description ?: \Illuminate\Support\Str::limit(strip_tags($product->description), 300),
        'image' => $product->cover_path ? [asset($product->cover_path)] : [],
        'sku' => $product->sku,
        'url' => request()->url(),
        'offers' => [
            '@type' => 'Offer',
            'url' => request()->url(),
            'priceCurrency' => 'IRR',
            'price' => (int) $product->price_rials,
            'availability' => 'https://schema.org/InStock',
            'itemCondition' => 'https://schema.org/NewCondition',
        ],
    ];
    if ($ratingCount > 0 && $averageRating !== null) {
        $productStructuredData['aggregateRating'] = [
            '@type' => 'AggregateRating',
            'ratingValue' => round((float) $averageRating, 1),
            'reviewCount' => $ratingCount,
            'bestRating' => 5,
            'worstRating' => 1,
        ];
    }
@endphp
<script type="application/ld+json">@json($productStructuredData)</script>
@endpush

@section('content')
<section class="store-page" dir="rtl" data-product-id="{{ $product->id }}">
  <div class="store-container">
    <div class="store-breadcrumb">
      <a href="{{ route('store') }}">فروشگاه</a><span>›</span>
      @if($product->category)
        <a href="{{ route('store', ['category'=>$product->category->slug]) }}">{{ $product->category->name }}</a><span>›</span>
      @endif
      <span>{{ $product->title }}</span>
    </div>

    <div class="store-product-overview">
      <span class="store-product-overview-mark"><i class="fa-solid fa-gem"></i></span>
      <div><span class="store-product-overview-kicker">انتخاب هوشمندانه با فراست</span><strong>مشخصات محصول و شرایط دریافت را پیش از خرید بررسی کنید.</strong></div>
    </div>
    <nav class="store-product-jump" aria-label="دسترسی سریع به بخش‌های محصول">
      <a href="#store-product-description"><i class="fa-solid fa-align-right" aria-hidden="true"></i> توضیحات</a>
      @if($product->type === 'course' && $product->course)
        <a href="#store-product-curriculum"><i class="fa-solid fa-list-check" aria-hidden="true"></i> سرفصل‌ها</a>
      @endif
      <a href="#store-buy-card"><i class="fa-solid fa-bag-shopping" aria-hidden="true"></i> قیمت و خرید</a>
      <a href="#store-discussions"><i class="fa-regular fa-comments" aria-hidden="true"></i> نظرها و پرسش‌ها</a>
    </nav>
    <div class="store-detail-grid">
      <section>
        @php
          $galleryImages = $product->images->where('is_active', true)->values();
          $mainGalleryImage = $galleryImages->firstWhere('path', $product->cover_path) ?: $galleryImages->first();
          $mainImagePath = $product->cover_path ?: $mainGalleryImage?->path;
          $mainImageAlt = $mainGalleryImage?->alt ?: $product->title;
          $additionalGalleryImages = $galleryImages->reject(fn ($image) => $image->path === $mainImagePath)->values();
        @endphp
        @if($mainImagePath)
          <div class="store-product-media" aria-label="تصاویر اختصاصی محصول">
            <button type="button" class="store-detail-cover store-gallery-open" data-gallery-src="{{ asset($mainImagePath) }}" data-gallery-alt="{{ $mainImageAlt }}" aria-label="بزرگ‌نمایی تصویر اصلی محصول">
              <img src="{{ asset($mainImagePath) }}" alt="{{ $mainImageAlt }}" fetchpriority="high">
              <span><i class="fa-solid fa-magnifying-glass-plus" aria-hidden="true"></i> بزرگ‌نمایی تصویر</span>
            </button>
            @if($additionalGalleryImages->isNotEmpty())
              <div class="store-product-gallery" aria-label="سایر تصاویر اختصاصی محصول">
                @foreach($additionalGalleryImages as $image)
                  <button type="button" class="store-gallery-open" data-gallery-src="{{ asset($image->path) }}" data-gallery-alt="{{ $image->alt ?: $product->title }}" aria-label="بزرگ‌نمایی تصویر {{ $loop->iteration + 1 }}"><img src="{{ asset($image->path) }}" alt="{{ $image->alt ?: $product->title }}" loading="lazy"><span><i class="fa-solid fa-magnifying-glass-plus" aria-hidden="true"></i> بزرگ‌نمایی</span></button>
                @endforeach
              </div>
            @endif
          </div>
        @endif

        @if($hasPreview)
          <section class="store-product-sample" id="store-product-sample" aria-label="نمونه قابل مطالعه">
            <div>
              <strong><i class="fa-solid fa-book-open" aria-hidden="true"></i> نمونه قابل مطالعه</strong>
              <span>نمونه امن محصول آماده شده است؛ محتوای آن فقط با انتخاب شما در خوانشگر باز می‌شود.</span>
            </div>
            <a class="store-primary-btn" href="{{ route('store.reader', $product->slug) }}">
              <i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i> مشاهده نمونه
            </a>
          </section>
        @endif

        <article class="store-panel" id="store-product-description">
          <h2>{{ $product->type === 'course' ? 'درباره این دوره' : 'درباره این فایل' }}</h2>
          <div class="body">{!! nl2br(e($product->description ?: $product->short_description ?: 'توضیحی ثبت نشده است.')) !!}</div>
        </article>
        @if($product->type === 'course' && $product->course)
          <div class="store-panel course-public-curriculum" id="store-product-curriculum">
            <div class="store-panel-head"><h2>سرفصل‌ها و برنامه آموزشی</h2><span>{{ $product->course->sections->sum(fn($section)=>$section->lessons->where('is_published',true)->count()) }} جلسه منتشرشده</span></div>
            <div class="course-public-meta">
              @if($product->course->instructor_name)
                <span><i class="fa-solid fa-chalkboard-user"></i> مدرس: {{ $product->course->instructor_name }}</span>
              @endif
              <span><i class="fa-solid fa-signal"></i> سطح: {{ ['beginner'=>'مقدماتی','intermediate'=>'متوسط','advanced'=>'پیشرفته','all'=>'همه سطوح'][$product->course->level] ?? 'مقدماتی' }}</span>
              @if($product->course->duration_minutes)
                <span><i class="fa-regular fa-clock"></i> {{ $product->course->duration_minutes }} دقیقه آموزش</span>
              @endif
            </div>
            @forelse($product->course->sections as $section)
              @if($section->lessons->where('is_published',true)->isNotEmpty())
                <div class="course-public-section"><strong>{{ $loop->iteration }}. {{ $section->title }}</strong><span>{{ $section->lessons->where('is_published',true)->count() }} جلسه</span></div>
                @foreach($section->lessons->where('is_published',true) as $lesson)
                  <div class="course-public-lesson">
                    <i class="fa-solid fa-{{ $lesson->lesson_type==='video'?'play':($lesson->lesson_type==='audio'?'headphones':'file-lines') }}"></i>
                    <span>{{ $lesson->title }}</span>
                    @if($lesson->is_preview)
                      <a class="course-preview-link" href="{{ route('store.course.preview',[$product,$lesson]) }}">نمونه رایگان</a>
                    @endif
                    @if($lesson->duration_seconds)
                      <small>{{ gmdate('i:s',$lesson->duration_seconds) }}</small>
                    @endif
                  </div>
                @endforeach
              @endif
            @empty
              <div class="store-preview-empty">سرفصل‌های این دوره هنوز منتشر نشده‌اند.</div>
            @endforelse
          </div>
        @endif
        @include('shared.discussions', ['context' => 'product', 'targetKey' => (string) $product->id, 'entries' => $discussions, 'counts' => $discussionCounts, 'averageRating' => $averageRating, 'ratingBreakdown' => $ratingBreakdown])
      </section>

      <aside class="store-buy-card" id="store-buy-card">
        <small>{{ $product->type === 'course' ? 'دوره آموزشی' : ($product->category?->name ?? 'فایل دیجیتال') }}</small>
        <h1>{{ $product->title }}</h1>
        @if(filled($product->short_description) && filled($product->description) && trim(strip_tags((string) $product->short_description)) !== trim(strip_tags((string) $product->description)))
          <p class="lead">{{ $product->short_description }}</p>
        @endif
        <div class="store-product-rating" aria-label="امتیاز کاربران">
          @if($ratingCount > 0 && $averageRating !== null)
            <span class="store-product-rating__score"><i class="fa-solid fa-star" aria-hidden="true"></i> {{ number_format((float) $averageRating, 1) }}</span>
            <span class="store-product-rating__detail">{{ number_format($ratingCount) }} امتیاز ثبت‌شده</span>
            <a href="#store-discussions">مشاهده دیدگاه‌ها</a>
          @else
            <span class="store-product-rating__empty"><i class="fa-regular fa-star" aria-hidden="true"></i> هنوز امتیازی ثبت نشده</span>
            <a href="#store-discussions">اولین دیدگاه را ثبت کنید</a>
          @endif
        </div>
        @if($product->tags->isNotEmpty())
          <div class="store-product-tags" aria-label="برچسب‌های محصول">
            @foreach($product->tags as $tag)<span>{{ $tag->name }}</span>@endforeach
          </div>
        @endif
        <div class="price">
          @if((int)$product->price_rials===0) رایگان
          @else
            {{ number_format((int)$product->price_rials/10) }} تومان
            @if($product->compare_at_price_rials && $product->compare_at_price_rials > $product->price_rials)
              <del style="font-size:.8rem;color:#6b7c93;font-weight:600;margin-right:8px">{{ number_format((int)$product->compare_at_price_rials/10) }}</del>
            @endif
          @endif
        </div>

        <button type="button" class="add-to-cart" data-product-id="{{ $product->id }}" aria-live="polite">
          <i class="fa-solid fa-bag-shopping"></i>
          {{ (int)$product->price_rials===0 ? 'افزودن (رایگان)' : 'افزودن به سبد' }}
        </button>
        <a class="store-outline-btn" href="{{ route('cart') }}" style="margin-top:8px;justify-content:center;width:100%">مشاهده سبد خرید</a>
        <button type="button" class="store-share-product" data-share-product><i class="fa-solid fa-share-nodes" aria-hidden="true"></i> اشتراک‌گذاری محصول</button>

        @auth
          <div class="store-buy-actions">
            <button type="button" class="store-outline-btn wishlist-btn {{ $inWishlist ? 'is-on' : '' }}" data-product-id="{{ $product->id }}" data-list="wishlist">
              <i class="fa-{{ $inWishlist ? 'solid' : 'regular' }} fa-heart"></i> علاقه‌مندی
            </button>
            <button type="button" class="store-outline-btn wishlist-btn {{ $inLater ? 'is-on' : '' }}" data-product-id="{{ $product->id }}" data-list="later">
              <i class="fa-regular fa-clock"></i> بعداً
            </button>
          </div>
          <a class="store-text-link" href="{{ route('wishlist') }}">لیست علاقه‌مندی‌ها</a>
        @else
          <p class="store-login-hint"><a href="{{ route('login') }}">وارد شوید</a> تا علاقه‌مندی و خرید ثبت شود.</p>
        @endauth

        <div class="meta">
          <span><i class="fa-solid fa-code-branch"></i> نسخه {{ $product->version ?: '1.0' }}</span>
          @if($product->type !== 'course' && $product->files->isNotEmpty())
            <span><i class="fa-solid fa-file-lines"></i> فرمت فایل: {{ strtoupper(pathinfo((string) $product->files->first()->original_name, PATHINFO_EXTENSION)) ?: 'نامشخص' }}</span>
            <span><i class="fa-solid fa-hard-drive"></i> حجم فایل: {{ $product->files->first()->size_bytes >= 1048576 ? number_format($product->files->first()->size_bytes / 1048576, 2).' مگابایت' : ($product->files->first()->size_bytes >= 1024 ? number_format($product->files->first()->size_bytes / 1024, 1).' کیلوبایت' : number_format($product->files->first()->size_bytes).' بایت') }}</span>
          @endif
          <span><i class="fa-solid fa-shield-halved"></i> {{ $product->type === 'course' ? 'دسترسی به دوره در کتابخانه آموزشی' : (($product->download_policy ?? 'signed') === 'reader_only' ? 'مطالعه در خوانشگر پس از پرداخت' : 'دریافت در کتابخانه پس از پرداخت') }}</span>
          <span><i class="fa-solid fa-file-contract"></i> مجوز {{ $product->license_type ?: 'شخصی' }}</span>
        </div>
        <div class="store-product-assurance"><i class="fa-solid fa-circle-check"></i><span><strong>پیش از خرید، شرایط محصول را بررسی کنید</strong><small>جزئیات دریافت و مجوز استفاده در همین بخش مشخص شده‌اند.</small></span></div>
      </aside>
    </div>

    @if($related->count())
      <section class="store-shelf" style="margin-top:28px">
        <div class="store-shelf-head"><h2>محصولات مشابه</h2><span>پیشنهاد بر اساس دسته</span></div>
        <div class="store-shelf-track">
          @foreach($related as $p)
            @include('store.partials.card', ['product' => $p])
          @endforeach
        </div>
      </section>
    @endif

    @if($alsoViewed->count())
      <section class="store-shelf">
        <div class="store-shelf-head"><h2>بر اساس بازدیدهای اخیر شما</h2><span>مارکتینگ هوشمند</span></div>
        <div class="store-shelf-track">
          @foreach($alsoViewed as $p)
            @include('store.partials.card', ['product' => $p])
          @endforeach
        </div>
      </section>
    @endif
  </div>
  <dialog class="store-gallery-dialog" aria-label="نمایش بزرگ تصویر محصول">
    <form method="dialog"><button class="store-gallery-close" aria-label="بستن تصویر"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></form>
    <img src="" alt="" data-gallery-dialog-image>
  </dialog>
</section>
@endsection
@push('styles')
<link rel="stylesheet" href="/css/store.css?v=20261110product6">
<link rel="stylesheet" href="/css/content-discussions.css?v=20261109community4">
<style>


.store-product-media{display:grid;gap:10px;margin:0 0 14px}
.store-detail-cover.store-gallery-open{width:100%;height:min(420px,55vw);padding:0;border:1px solid var(--st-line);border-radius:var(--st-radius);background:#f5f7fa;cursor:zoom-in}
.store-detail-cover.store-gallery-open img{display:block;width:100%;height:100%;object-fit:contain}
.store-detail-cover.store-gallery-open>span{opacity:0}
.store-detail-cover.store-gallery-open:hover>span,.store-detail-cover.store-gallery-open:focus-visible>span{opacity:1}
.store-product-sample{display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;margin:0 0 14px;padding:13px 15px;border:1px solid #dfe8f4;border-radius:13px;background:#f8fafd}
.store-product-sample>div{display:grid;gap:4px;min-width:0}
.store-product-sample strong{display:flex;align-items:center;gap:8px;color:#294568;font-size:.78rem}
.store-product-sample strong i{color:#3975c8}
.store-product-sample span{color:#718198;font-size:.68rem;line-height:1.7}
.store-product-sample .store-primary-btn{white-space:nowrap}
@media(max-width:560px){.store-detail-cover.store-gallery-open{height:min(360px,78vw)}.store-product-sample{align-items:stretch}.store-product-sample .store-primary-btn{justify-content:center;width:100%}}

.store-product-rating{display:flex;align-items:center;gap:9px;flex-wrap:wrap;margin:8px 0 14px;padding:9px 11px;border:1px solid #e5ebf4;border-radius:11px;background:#f8fafd;font-size:.7rem}
.store-product-rating__score{display:inline-flex;align-items:center;gap:5px;color:#9a6500;font-weight:900;font-variant-numeric:tabular-nums}
.store-product-rating__score i{color:#e6a52a}
.store-product-rating__detail,.store-product-rating__empty{color:#687b95}
.store-product-rating>a{margin-inline-start:auto;color:#356bb0;text-decoration:none;font-weight:800}
.store-product-rating>a:hover{text-decoration:underline}
.store-product-tags{display:flex;flex-wrap:wrap;gap:6px;margin:10px 0 14px}
.store-product-tags span{padding:5px 9px;border-radius:99px;background:#f1f5fb;color:#536783;font-size:.65rem;font-weight:700}

.store-product-gallery{display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:10px;margin:12px 0 16px}
.store-product-gallery figure{margin:0;overflow:hidden;border:1px solid var(--st-line);border-radius:12px;background:#f5f7fa}
.store-product-gallery img{display:block;width:100%;aspect-ratio:4/3;object-fit:cover}

.course-public-curriculum{margin-top:14px}.course-public-meta{display:flex;gap:10px;flex-wrap:wrap;margin:10px 0 14px}.course-public-meta span{font-size:.7rem;color:var(--st-muted);background:#f4f7fc;border-radius:8px;padding:7px 9px}.course-public-section{display:flex;justify-content:space-between;gap:12px;padding:11px 10px;background:#f6f9fd;border:1px solid var(--st-line);border-radius:9px;margin-top:10px}.course-public-section strong{font-size:.78rem}.course-public-section span{font-size:.68rem;color:var(--st-muted)}.course-public-lesson{display:flex;align-items:center;gap:9px;padding:9px 12px;border-bottom:1px solid #edf1f6;font-size:.75rem}.course-public-lesson>span{flex:1}.course-public-lesson>i{color:var(--st-blue)}.course-public-lesson small{font-size:.64rem;color:var(--st-muted);background:#f0f4fa;border-radius:99px;padding:4px 7px}.course-public-lesson .course-preview-link{font-size:.64rem;color:#0f8d68;background:#e8f8f1;border-radius:99px;padding:4px 8px;text-decoration:none;white-space:nowrap}
</style>
@endpush
@push('scripts')
<script>
(()=>{
  const csrf=document.querySelector('meta[name="csrf-token"]')?.content||'';
  const galleryDialog=document.querySelector('.store-gallery-dialog');
  const galleryDialogImage=galleryDialog?.querySelector('[data-gallery-dialog-image]');
  document.querySelectorAll('.store-gallery-open').forEach(button=>button.addEventListener('click',()=>{
    if(!galleryDialog||!galleryDialogImage)return;
    galleryDialogImage.src=button.dataset.gallerySrc||'';
    galleryDialogImage.alt=button.dataset.galleryAlt||'';
    if(typeof galleryDialog.showModal==='function')galleryDialog.showModal();
  }));
  galleryDialog?.addEventListener('click',event=>{if(event.target===galleryDialog)galleryDialog.close();});
  document.querySelector('[data-share-product]')?.addEventListener('click',async event=>{
    const button=event.currentTarget;
    const shareData={title:document.querySelector('.store-buy-card h1')?.textContent?.trim()||document.title,text:document.querySelector('.store-buy-card .lead')?.textContent?.trim()||'',url:window.location.href};
    try{
      if(navigator.share){await navigator.share(shareData);}
      else if(navigator.clipboard?.writeText){await navigator.clipboard.writeText(shareData.url);button.innerHTML='<i class="fa-solid fa-check" aria-hidden="true"></i> پیوند کپی شد';}
      else{window.prompt('پیوند محصول را کپی کنید:',shareData.url);}
    }catch(error){if(error?.name!=='AbortError')window.prompt('پیوند محصول را کپی کنید:',shareData.url);}
  });
  document.querySelectorAll('.add-to-cart').forEach(b=>b.addEventListener('click',async()=>{
    if(b.dataset.adding==='1'||b.dataset.added==='1')return;
    b.dataset.adding='1';
    b.disabled=true;
    const originalLabel=b.innerHTML;
    try{
      const r=await fetch('/cart/products/'+b.dataset.productId,{method:'POST',headers:{'X-CSRF-TOKEN':csrf,'Accept':'application/json','Content-Type':'application/json'},body:JSON.stringify({quantity:1})});
      const j=await r.json();
      if(!r.ok||j.ok===false) throw new Error(j.message||'افزودن محصول به سبد خرید انجام نشد.');
      b.dataset.added='1';
      b.innerHTML='<i class="fa-solid fa-check" aria-hidden="true"></i> در سبد خرید قرار گرفت';
      b.setAttribute('aria-label','این محصول به سبد خرید اضافه شده است؛ برای تغییر تعداد، سبد خرید را مشاهده کنید.');
      if(window.FarastCart?.refresh) window.FarastCart.refresh();
      const c=document.getElementById('farastCartCount'); if(c&&j.count!=null) c.textContent=j.count;
    }catch(e){
      b.disabled=false;
      b.innerHTML=originalLabel;
      alert(e.message||'خطا در افزودن محصول');
    }finally{
      delete b.dataset.adding;
    }
  }));
  document.querySelectorAll('.wishlist-btn').forEach(b=>b.addEventListener('click',async()=>{
    b.disabled=true;
    try{
      const r=await fetch('/wishlist/'+b.dataset.productId,{method:'POST',headers:{'X-CSRF-TOKEN':csrf,'Accept':'application/json','Content-Type':'application/json'},body:JSON.stringify({list_type:b.dataset.list})});
      const j=await r.json(); if(!r.ok) throw new Error(j.message||'خطا');
      b.classList.toggle('is-on', !!j.active);
      const icon=b.querySelector('i');
      if(icon && b.dataset.list==='wishlist'){
        icon.className=j.active?'fa-solid fa-heart':'fa-regular fa-heart';
      }
    }catch(e){alert(e.message)} finally{b.disabled=false}
  }));
  document.querySelectorAll('[data-add-cart]').forEach(b=>b.addEventListener('click',async()=>{
    b.disabled=true; const t=b.textContent;
    try{
      const r=await fetch('/cart/products/'+b.dataset.addCart,{method:'POST',headers:{'X-CSRF-TOKEN':csrf,'Accept':'application/json','Content-Type':'application/json'},body:JSON.stringify({quantity:1})});
      const j=await r.json(); if(!r.ok) throw new Error(j.message||'خطا');
      b.textContent='اضافه شد';
      if(window.FarastCart?.refresh) window.FarastCart.refresh();
      setTimeout(()=>{b.textContent=t;b.disabled=false},1000);
    }catch(e){alert(e.message);b.disabled=false;b.textContent=t}
  }));
})();
</script>
@endpush
