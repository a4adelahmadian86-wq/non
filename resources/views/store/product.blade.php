@extends('layouts.app')
@php
  $title = ($product->seo_title ?: $product->title).' | فروشگاه فراست';
  $galleryImage = $product->images->first(fn ($image) => $image->is_active);
  $productImagePath = $product->cover_path ?: $galleryImage?->path;
  $productImage = $productImagePath ? asset($productImagePath) : null;
  $productDescription = $product->seo_description ?: ($product->short_description ?: \Illuminate\Support\Str::limit(strip_tags((string) $product->description), 160));
  $productStructuredData = array_filter([
    '@context' => 'https://schema.org',
    '@type' => 'Product',
    'name' => $product->title,
    'description' => $productDescription,
    'image' => $productImage,
    'category' => $product->category?->name,
    'brand' => ['@type' => 'Brand', 'name' => 'FARAST'],
    'offers' => [
      '@type' => 'Offer',
      'priceCurrency' => 'IRR',
      'price' => (string) ((int) $product->price_rials),
      'availability' => 'https://schema.org/InStock',
      'url' => route('store.product', $product->slug),
    ],
  ], static fn ($value) => $value !== null && $value !== '');
@endphp
@push('head')
<meta name="description" content="{{ $productDescription }}">
<link rel="canonical" href="{{ route('store.product', $product->slug) }}">
<meta property="og:type" content="product">
<meta property="og:title" content="{{ $title }}">
<meta property="og:description" content="{{ $productDescription }}">
@if($productImage)<meta property="og:image" content="{{ $productImage }}">@endif
<meta property="og:url" content="{{ route('store.product', $product->slug) }}">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="{{ $title }}">
<meta name="twitter:description" content="{{ $productDescription }}">
@if($productImage)<meta name="twitter:image" content="{{ $productImage }}">@endif
<script type="application/ld+json">@json($productStructuredData)</script>
@endpush
@section('content')
<section class="store-page" dir="rtl">
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
      <div><span class="store-product-overview-kicker">انتخاب هوشمندانه با فراست</span><strong>جزئیات را ببینید، نمونه را بررسی کنید، با اطمینان انتخاب کنید.</strong></div>
      @if($hasPreview)<span class="store-product-preview-pill"><i class="fa-solid fa-eye"></i> پیش‌نمایش فعال</span>@endif
    </div>
    <div class="store-detail-grid">
      <section>
        <div class="store-detail-cover">
          @if($product->cover_path)
            <img src="{{ asset($product->cover_path) }}" alt="{{ $product->title }}">
          @else
            <span>FARAST</span>
          @endif
        </div>

        <div class="store-panel">
          <div class="store-panel-head">
            <h2>نمونه رایگان / پیش‌نمایش</h2>
            @if($hasPreview)
              <a class="store-primary-btn" href="{{ route('store.reader', $product->slug) }}">
                <i class="fa-solid fa-book-open"></i> مطالعه نمونه
              </a>
            @endif
          </div>
          @php $showPreviews = $product->previews->where('is_active', true)->take($product->preview_pages ?: 5); @endphp
          @if($showPreviews->isNotEmpty())
            <div class="store-preview-grid">
              @foreach($showPreviews as $preview)
                <a href="{{ route('store.reader', $product->slug) }}" title="باز کردن خوانشگر">
                  @if(($preview->kind ?? '') === 'pdf' || str_contains((string)$preview->mime, 'pdf'))
                    <div class="store-preview-pdf">PDF · ص {{ $preview->page_number ?: $loop->iteration }}</div>
                  @else
                    <img src="{{ route('store.preview',$preview) }}" alt="پیش‌نمایش {{ $loop->iteration }}" loading="lazy">
                  @endif
                  <small>صفحه {{ $preview->page_number ?: $loop->iteration }}</small>
                </a>
              @endforeach
            </div>
            <p class="store-preview-note">این صفحات فقط نمونه هستند. فایل کامل پس از خرید در «کتابخانه من» قرار می‌گیرد.</p>
          @else
            <div class="store-preview-empty">
              <strong>نمونه هنوز آپلود نشده</strong>
              <p>مدیر فروشگاه باید از پنل ادمین تصویر/صفحه پیش‌نمایش اضافه کند. تا آن زمان فقط توضیحات و خرید فعال است.</p>
            </div>
          @endif
        </div>

        <article class="store-panel">
          <h2>درباره این فایل</h2>
          <div class="body">{!! nl2br(e($product->description ?: $product->short_description ?: 'توضیحی ثبت نشده است.')) !!}</div>
        </article>
      </section>

      <aside class="store-buy-card">
        <small>{{ $product->category?->name ?? 'فایل دیجیتال' }}</small>
        <h1>{{ $product->title }}</h1>
        <p class="lead">{{ $product->short_description }}</p>
        <div class="price">
          @if((int)$product->price_rials===0) رایگان
          @else
            {{ number_format((int)$product->price_rials/10) }} تومان
            @if($product->compare_at_price_rials && $product->compare_at_price_rials > $product->price_rials)
              <del style="font-size:.8rem;color:#6b7c93;font-weight:600;margin-right:8px">{{ number_format((int)$product->compare_at_price_rials/10) }}</del>
            @endif
          @endif
        </div>

        <button type="button" class="add-to-cart" data-product-id="{{ $product->id }}">
          <i class="fa-solid fa-bag-shopping"></i>
          {{ (int)$product->price_rials===0 ? 'افزودن (رایگان)' : 'افزودن به سبد' }}
        </button>
        <a class="store-outline-btn" href="{{ route('cart') }}" style="margin-top:8px;justify-content:center;width:100%">مشاهده سبد خرید</a>

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
          <span><i class="fa-solid fa-shield-halved"></i> دریافت در کتابخانه پس از پرداخت</span>
          <span><i class="fa-solid fa-file-contract"></i> مجوز {{ $product->license_type ?: 'شخصی' }}</span>
          @if($hasPreview)<span><i class="fa-solid fa-eye"></i> نمونه قابل مطالعه</span>@endif
        </div>
        <div class="store-product-assurance"><i class="fa-solid fa-circle-check"></i><span><strong>پیش از خرید، مشخصات را بررسی کنید</strong><small>فرمت، مجوز و نمونه‌های قابل مشاهده در همین صفحه درج شده‌اند.</small></span></div>
        <section class="store-purchase-flow" aria-labelledby="store-purchase-flow-title">
          <div class="store-purchase-flow-heading">
            <span>شفاف و مرحله‌به‌مرحله</span>
            <strong id="store-purchase-flow-title">بعد از انتخاب چه اتفاقی می‌افتد؟</strong>
          </div>
          <ol>
            <li><span class="store-flow-number">۱</span><div><b>افزودن به سبد خرید</b><small>این فایل را به سفارش خود اضافه کنید.</small></div></li>
            <li><span class="store-flow-number">۲</span><div><b>{{ (int) $product->price_rials === 0 ? 'ثبت سفارش رایگان' : 'تکمیل پرداخت' }}</b><small>{{ (int) $product->price_rials === 0 ? 'برای دریافت این محصول هزینه‌ای پرداخت نمی‌کنید.' : 'مبلغ و جزئیات سفارش را پیش از نهایی‌سازی بررسی کنید.' }}</small></div></li>
            <li><span class="store-flow-number">۳</span><div><b>دریافت از کتابخانه من</b><small>پس از تکمیل سفارش، فایل کامل در کتابخانه حساب شما قرار می‌گیرد.</small></div></li>
          </ol>
        </section>
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
</section>
@endsection
@push('styles')
<link rel="stylesheet" href="/css/store.css?v=20261107premium1">
@endpush
@push('scripts')
<script>
(()=>{
  const csrf=document.querySelector('meta[name="csrf-token"]')?.content||'';
  document.querySelectorAll('.add-to-cart').forEach(b=>b.addEventListener('click',async()=>{
    b.disabled=true;
    try{
      const r=await fetch('/cart/products/'+b.dataset.productId,{method:'POST',headers:{'X-CSRF-TOKEN':csrf,'Accept':'application/json','Content-Type':'application/json'},body:JSON.stringify({quantity:1})});
      const j=await r.json(); if(!r.ok) throw new Error(j.message||'افزودن ناموفق');
      b.innerHTML='<i class="fa-solid fa-check"></i> به سبد اضافه شد';
      if(window.FarastCart?.refresh) window.FarastCart.refresh();
      const c=document.getElementById('farastCartCount'); if(c&&j.count!=null) c.textContent=j.count;
    }catch(e){alert(e.message)} finally{b.disabled=false}
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
