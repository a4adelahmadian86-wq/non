@extends('layouts.app')

@section('content')
@php
  $activePreviews = $product->previews->where('is_active', true)->take(max(1, (int)($product->preview_pages ?: 3)));
@endphp
<div class="fm-product" dir="rtl">
  <div class="fm-product-wrap">
    <div class="fm-breadcrumb">
      <a href="{{ route('store') }}">فروشگاه</a>
      <span>›</span>
      @if($product->category)
        <a href="{{ route('store.category', $product->category->slug) }}">{{ $product->category->name }}</a>
        <span>›</span>
      @endif
      <span>{{ $product->title }}</span>
    </div>

    <div class="fm-product-grid">
      <section class="fm-product-main">
        <div class="fm-cover">
          @if($product->cover_path)
            <img src="{{ asset($product->cover_path) }}" alt="{{ $product->title }}">
          @else
            <i class="fa-solid fa-file-lines"></i>
          @endif
        </div>

        <div class="fm-preview-panel">
          <div class="fm-preview-head">
            <div>
              <strong>پیش‌نمایش محدود</strong>
              <small>محتوا از فایل مشتق‌شده انتخاب شده و برای هر محصول ثابت می‌ماند.</small>
            </div>
            <span>{{ $activePreviews->count() ? $activePreviews->count() . ' صفحه' : 'آماده‌سازی' }}</span>
          </div>

          @if($activePreviews->isNotEmpty())
            <div class="fm-preview-grid">
              @foreach($activePreviews as $preview)
                <a href="{{ route('store.preview', $preview) }}" target="_blank" rel="noopener">
                  <div class="fm-preview-frame">
                    <img src="{{ route('store.preview', $preview) }}" alt="پیش‌نمایش {{ $loop->iteration }}" loading="lazy">
                  </div>
                  <small>صفحه {{ $loop->iteration }}</small>
                </a>
              @endforeach
            </div>
          @else
            <div class="fm-preview-empty">
              <i class="fa-solid fa-eye-slash"></i>
              <strong>پیش‌نمایش آماده نشد</strong>
              <p>محصول آزمایشی برای بررسی کامل رابط کاربری فروشگاه است. پیش‌نمایش پس از بارگذاری فایل فعال می‌شود.</p>
            </div>
          @endif
        </div>

        <article class="fm-desc">
          <h2>توضیحات</h2>
          {!! nl2br(e($product->description ?: $product->short_description ?: 'توضیحی ثبت نشده است.')) !!}
        </article>
      </section>

      <aside class="fm-buy">
        <small>{{ $product->category?->name ?: 'فایل دیجیتال' }}</small>
        <h1>{{ $product->title }}</h1>
        <p>{{ $product->short_description }}</p>
        <div class="fm-buy-price">{{ number_format((int) $product->price_rials / 10) }} تومان</div>
        <button type="button" class="fm-buy-btn" data-product-id="{{ $product->id }}">
          <i class="fa-solid fa-cart-plus"></i> افزودن به سبد خرید
        </button>
        <div class="fm-buy-meta">
          <span><i class="fa-solid fa-code-branch"></i> نسخه {{ $product->version ?: 'فعلی' }}</span>
          <span><i class="fa-solid fa-shield-halved"></i> دانلود امن پس از خرید</span>
          <span><i class="fa-solid fa-certificate"></i> مجوز {{ $product->license_type ?: 'استاندارد' }}</span>
        </div>
      </aside>
    </div>
  </div>
</div>
@endsection

@push('styles')
<style>
.fm-product{background:#f7f8fc;min-height:calc(100vh - 80px);padding:28px 16px 70px;color:#20242d}
.fm-product-wrap{width:min(1180px,100%);margin:0 auto}
.fm-breadcrumb{display:flex;flex-wrap:wrap;gap:8px;font-size:.78rem;color:#7a8498;margin-bottom:16px}
.fm-breadcrumb a{color:#5b3fd0;text-decoration:none}
.fm-product-grid{display:grid;grid-template-columns:minmax(0,1fr) 340px;gap:20px;align-items:start}
.fm-cover{height:360px;border-radius:20px;background:linear-gradient(145deg,#eeeaff,#f7f8fc);display:grid;place-items:center;overflow:hidden;color:#5b3fd0;font-size:3rem;box-shadow:0 12px 36px rgba(40,30,90,.06)}
.fm-cover img{width:100%;height:100%;object-fit:cover}
.fm-preview-panel,.fm-desc,.fm-buy{background:#fff;border:1px solid #eee;border-radius:18px;box-shadow:0 8px 24px rgba(0,0,0,.04)}
.fm-preview-panel{margin-top:16px;padding:16px}
.fm-preview-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;margin-bottom:12px}
.fm-preview-head strong{display:block;font-size:.95rem}
.fm-preview-head small{display:block;color:#7a8498;font-size:.72rem;margin-top:4px;line-height:1.7}
.fm-preview-head span{font-size:.75rem;color:#8a93a3;white-space:nowrap}
.fm-preview-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}
.fm-preview-grid a{text-decoration:none;color:#667085;font-size:.7rem}
.fm-preview-frame{border:1px solid #e5e7eb;border-radius:10px;overflow:hidden;background:#f8fafc;aspect-ratio:3/4}
.fm-preview-frame img{width:100%;height:100%;object-fit:cover;display:block}
.fm-preview-empty{padding:36px 16px;text-align:center;color:#6b7280;display:grid;gap:8px;justify-items:center}
.fm-preview-empty i{font-size:1.5rem;color:#9aa3b2}
.fm-preview-empty p{margin:0;max-width:420px;font-size:.8rem;line-height:1.8}
.fm-desc{margin-top:16px;padding:18px;font-size:.86rem;line-height:2;color:#4b5563}
.fm-desc h2{margin:0 0 10px;font-size:1rem;color:#1f2937}
.fm-buy{padding:20px;position:sticky;top:88px}
.fm-buy > small{color:#7a8498;font-size:.72rem}
.fm-buy h1{margin:8px 0;font-size:1.25rem;line-height:1.7}
.fm-buy p{margin:0;color:#6b7280;font-size:.82rem;line-height:1.8}
.fm-buy-price{margin:18px 0;font-size:1.3rem;font-weight:900;color:#5b3fd0}
.fm-buy-btn{width:100%;height:46px;border:0;border-radius:12px;background:#5b3fd0;color:#fff;font:inherit;font-weight:800;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;gap:8px}
.fm-buy-btn:hover{background:#4a31b5}
.fm-buy-meta{display:grid;gap:8px;margin-top:14px;padding-top:14px;border-top:1px solid #f0f0f4;color:#6b7280;font-size:.75rem}
.fm-buy-meta span{display:flex;align-items:center;gap:8px}
.fm-buy-meta i{color:#5b3fd0;width:14px}
@media(max-width:900px){.fm-product-grid{grid-template-columns:1fr}.fm-buy{position:static}.fm-cover{height:260px}.fm-preview-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
</style>
@endpush

@push('scripts')
<script>
(() => {
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
  document.querySelectorAll('.fm-buy-btn').forEach(btn => {
    btn.addEventListener('click', async () => {
      btn.disabled = true;
      const old = btn.innerHTML;
      try {
        const r = await fetch('/cart/products/' + btn.dataset.productId, {
          method: 'POST',
          headers: {'X-CSRF-TOKEN': csrf, 'Accept': 'application/json', 'Content-Type': 'application/json'},
          body: JSON.stringify({quantity: 1})
        });
        const j = await r.json();
        if (!r.ok) throw new Error(j.message || 'افزودن به سبد خرید ناموفق بود');
        btn.innerHTML = '<i class="fa-solid fa-check"></i> به سبد اضافه شد';
        if (window.FarastCart?.refresh) window.FarastCart.refresh();
        setTimeout(() => { btn.innerHTML = old; }, 1400);
      } catch (e) {
        alert(e.message);
      } finally {
        btn.disabled = false;
      }
    });
  });
})();
</script>
@endpush
