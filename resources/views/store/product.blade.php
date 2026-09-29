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
          <h2>پیش‌نمایش</h2>
          @php $activePreviews = $product->previews->where('is_active', true)->take($product->preview_pages ?: 3); @endphp
          @if($activePreviews->isNotEmpty())
            <div class="store-preview-grid">
              @foreach($activePreviews as $preview)
                <a href="{{ route('store.preview',$preview) }}" target="_blank" rel="noopener">
                  <img src="{{ route('store.preview',$preview) }}" alt="پیش‌نمایش {{ $loop->iteration }}" loading="lazy">
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
<link rel="stylesheet" href="/css/store.css?v=20260929">
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
})();
</script>
@endpush
