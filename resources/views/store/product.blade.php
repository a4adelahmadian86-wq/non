@extends('layouts.app')
@section('content')
<section class="store-page" dir="rtl">
  <div class="store-container">
    <div class="store-breadcrumb">
      <a href="{{ route('store') }}">فروشگاه</a><span>›</span>
      <span>{{ $product->category?->name }}</span>
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
          <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:10px">
            <h2 style="margin:0">پیش‌نمایش</h2>
            @php $activePreviews = $product->previews->where('is_active', true); @endphp
            @if($activePreviews->isNotEmpty())
              <a class="store-primary-btn" href="{{ route('store.reader', $product->slug) }}"><i class="fa-solid fa-book-open"></i> خوانشگر تمام‌صفحه</a>
            @endif
          </div>
          @php $showPreviews = $activePreviews->take($product->preview_pages ?: 3); @endphp
          @if($showPreviews->isNotEmpty())
            <div class="store-preview-grid">
              @foreach($showPreviews as $preview)
                <a href="{{ route('store.reader', $product->slug) }}">
                  @if(($preview->kind ?? '') === 'pdf' || str_contains((string)$preview->mime, 'pdf'))
                    <div style="aspect-ratio:3/4;display:grid;place-items:center;background:#f0f4fa;border-radius:8px;border:1px solid #e2e9f2;font-size:.7rem;color:#6b7c93">PDF</div>
                  @else
                    <img src="{{ route('store.preview',$preview) }}" alt="پیش‌نمایش {{ $loop->iteration }}" loading="lazy">
                  @endif
                  <small>صفحه {{ $loop->iteration }}</small>
                </a>
              @endforeach
            </div>
          @else
            <div class="store-preview-empty">
              <strong style="display:block;color:#1a2b45;margin-bottom:6px">پیش‌نمایش آماده نشد</strong>
              هنوز تصویر/صفحه پیش‌نمایش برای این محصول ثبت نشده است. پس از خرید، فایل کامل از کتابخانه قابل دریافت است.
            </div>
          @endif
        </div>

        <article class="store-panel">
          <h2>توضیحات</h2>
          <div class="body">{!! nl2br(e($product->description ?: $product->short_description ?: 'توضیحی ثبت نشده است.')) !!}</div>
        </article>
      </section>

      <aside class="store-buy-card">
        <small>{{ $product->category?->name }}</small>
        <h1>{{ $product->title }}</h1>
        <p class="lead">{{ $product->short_description }}</p>
        <div class="price">
          @if((int)$product->price_rials===0) رایگان
          @else {{ number_format((int)$product->price_rials/10) }} تومان @endif
        </div>
        <button type="button" class="add-to-cart" data-product-id="{{ $product->id }}">افزودن به سبد خرید</button>
        @auth
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:10px">
            <button type="button" class="store-outline-btn wishlist-btn" data-product-id="{{ $product->id }}" data-list="wishlist" style="justify-content:center">
              <i class="fa-regular fa-heart"></i> علاقه‌مندی
            </button>
            <button type="button" class="store-outline-btn wishlist-btn" data-product-id="{{ $product->id }}" data-list="later" style="justify-content:center">
              <i class="fa-regular fa-clock"></i> بعداً می‌خرم
            </button>
          </div>
        @else
          <p style="font-size:.72rem;color:#6b7c93;margin-top:10px"><a href="{{ route('login') }}">ورود</a> برای علاقه‌مندی و خرید</p>
        @endauth
        <div class="meta">
          <span>نسخه {{ $product->version ?: 'فعلی' }}</span>
          <span>دانلود امن پس از خرید</span>
          <span>مجوز {{ $product->license_type ?: 'استاندارد' }}</span>
        </div>
      </aside>
    </div>
  </div>
</section>
@endsection
@push('styles')
<link rel="stylesheet" href="/css/store.css?v=20260929r">
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
      b.textContent='به سبد اضافه شد';
      if(window.FarastCart?.refresh) window.FarastCart.refresh();
      setTimeout(()=>b.textContent='افزودن به سبد خرید',1500);
    }catch(e){alert(e.message)} finally{b.disabled=false}
  }));
  document.querySelectorAll('.wishlist-btn').forEach(b=>b.addEventListener('click',async()=>{
    b.disabled=true;
    try{
      const r=await fetch('/wishlist/'+b.dataset.productId,{method:'POST',headers:{'X-CSRF-TOKEN':csrf,'Accept':'application/json','Content-Type':'application/json'},body:JSON.stringify({list_type:b.dataset.list})});
      const j=await r.json(); if(!r.ok) throw new Error(j.message||'خطا');
      b.style.borderColor=j.active?'#1769ff':'';
      b.style.color=j.active?'#1769ff':'';
    }catch(e){alert(e.message)} finally{b.disabled=false}
  }));
})();
</script>
@endpush
