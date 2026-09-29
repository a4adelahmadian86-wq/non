@extends('layouts.app')
@section('content')
<section class="store-page" dir="rtl">
  <div class="store-container">
    <div class="store-hero">
      <div>
        <div class="store-hero-kicker">فروشگاه دیجیتال فراست</div>
        <h1>فایل مورد نیازت را سریع و مطمئن پیدا کن</h1>
        <p>پیش‌نمایش واقعی، خرید امن، و دانلود فوری پس از پرداخت — روی همان زیرساخت فراست.</p>
        <form method="get" action="{{ route('store') }}" class="store-search">
          <input name="q" value="{{ $q }}" placeholder="جستجوی فایل، عنوان یا موضوع…" aria-label="جستجوی فروشگاه">
          <button type="submit"><i class="fa-solid fa-magnifying-glass"></i> جستجو</button>
        </form>
      </div>
      <div class="store-feature-row" style="grid-template-columns:1fr 1fr;margin:0">
        <div class="store-feature"><i class="fa-solid fa-eye"></i><strong>پیش‌نمایش واقعی</strong><span>قبل از خرید ببینید</span></div>
        <div class="store-feature"><i class="fa-solid fa-shield-halved"></i><strong>پرداخت امن</strong><span>کیف‌پول فراست</span></div>
        <div class="store-feature"><i class="fa-solid fa-bolt"></i><strong>دانلود فوری</strong><span>پس از خرید در کتابخانه</span></div>
        <div class="store-feature"><i class="fa-solid fa-tags"></i><strong>کد تخفیف</strong><span>اعمال روی سبد خرید</span></div>
      </div>
    </div>

    @if(session('status'))
      <div class="store-alert success"><i class="fa-solid fa-circle-check"></i>{{ session('status') }}</div>
    @endif

    @if(isset($featured) && $featured->count())
      <div class="store-section-head">
        <div><span class="eyebrow">منتخب</span><h1 style="font-size:1.15rem">محصولات ویژه</h1></div>
        <a class="store-outline-btn" href="{{ route('library') }}"><i class="fa-solid fa-book"></i> کتابخانه من</a>
      </div>
      <div class="store-product-grid" style="margin-bottom:24px">
        @foreach($featured as $product)
          @include('store.partials.card', ['product' => $product])
        @endforeach
      </div>
    @endif

    <div class="store-layout">
      <aside class="store-filters">
        <strong>دسته‌بندی‌ها</strong>
        <a class="{{ !$category ? 'active' : '' }}" href="{{ route('store') }}">همه محصولات</a>
        @foreach($categories as $cat)
          <a class="{{ $category===$cat->slug ? 'active' : '' }}" href="{{ route('store.category',$cat->slug) }}">{{ $cat->name }}</a>
        @endforeach
      </aside>
      <main>
        <div class="store-toolbar">
          <strong>{{ $q ? 'نتایج جستجو' : 'محصولات منتشرشده' }}</strong>
          <span>{{ $products->total() }} محصول</span>
        </div>
        <div class="store-product-grid">
          @forelse($products as $product)
            @include('store.partials.card', ['product' => $product])
          @empty
            <div class="store-empty-state">
              <i class="fa-solid fa-box-open"></i>
              <h2>محصولی پیدا نشد</h2>
              <p>دسته‌بندی یا عبارت جستجو را تغییر دهید. برای تست می‌توانید یک محصول آزمایشی seed کنید.</p>
            </div>
          @endforelse
        </div>
        {{ $products->links() }}
      </main>
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
  document.querySelectorAll('[data-add-cart]').forEach(b=>b.addEventListener('click',async()=>{
    b.disabled=true; const t=b.textContent;
    try{
      const r=await fetch('/cart/products/'+b.dataset.addCart,{method:'POST',headers:{'X-CSRF-TOKEN':csrf,'Accept':'application/json','Content-Type':'application/json'},body:JSON.stringify({quantity:1})});
      const j=await r.json(); if(!r.ok) throw new Error(j.message||'خطا');
      b.textContent='اضافه شد';
      if(window.FarastCart?.refresh) window.FarastCart.refresh();
      setTimeout(()=>{b.textContent=t;b.disabled=false},1200);
    }catch(e){alert(e.message);b.disabled=false;b.textContent=t}
  }));
})();
</script>
@endpush
