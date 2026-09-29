@extends('layouts.app')
@section('content')
<section class="store-page" dir="rtl">
  <div class="store-container">

    <div class="store-hero store-hero--market">
      <div>
        <div class="store-hero-kicker">کتابخانه و فایل دیجیتال فراست</div>
        <h1>بخوان، ببین، بعد بخر</h1>
        <p>مثل فدیبو و طاقچه: پیش‌نمایش واقعی قبل از خرید، سبد خرید، علاقه‌مندی و دانلود فوری پس از پرداخت.</p>
        <form method="get" action="{{ route('store') }}" class="store-search">
          <input name="q" value="{{ $q }}" placeholder="جستجوی عنوان، موضوع یا فایل…" aria-label="جستجوی فروشگاه">
          <button type="submit"><i class="fa-solid fa-magnifying-glass"></i> جستجو</button>
        </form>
        <div class="store-hero-links">
          <a href="{{ route('cart') }}"><i class="fa-solid fa-bag-shopping"></i> سبد خرید</a>
          @auth
            <a href="{{ route('wishlist') }}"><i class="fa-regular fa-heart"></i> علاقه‌مندی</a>
            <a href="{{ route('library') }}"><i class="fa-solid fa-book"></i> کتابخانه من</a>
          @else
            <a href="{{ route('login') }}"><i class="fa-solid fa-right-to-bracket"></i> ورود برای خرید</a>
          @endauth
        </div>
      </div>
      <div class="store-hero-stats">
        <div><strong>{{ $products->total() }}</strong><span>محصول</span></div>
        <div><strong>{{ $categories->count() }}</strong><span>دسته</span></div>
        <div><strong>{{ $free->count() }}</strong><span>رایگان</span></div>
      </div>
    </div>

    @if(session('status'))
      <div class="store-alert success"><i class="fa-solid fa-circle-check"></i>{{ session('status') }}</div>
    @endif

    <div class="store-chips">
      <a class="{{ !$category && !$q ? 'active' : '' }}" href="{{ route('store') }}">همه</a>
      @foreach($categories as $cat)
        <a class="{{ $category===$cat->slug ? 'active' : '' }}" href="{{ route('store', ['category'=>$cat->slug]) }}">{{ $cat->name }}</a>
      @endforeach
    </div>

    @if(!$isBrowsing)
      @if($featured->count())
        <section class="store-shelf">
          <div class="store-shelf-head"><h2>ویژه و پیشنهادی</h2><span>منتخب فراست</span></div>
          <div class="store-shelf-track">
            @foreach($featured as $product)
              @include('store.partials.card', ['product' => $product])
            @endforeach
          </div>
        </section>
      @endif

      @if($free->count())
        <section class="store-shelf">
          <div class="store-shelf-head"><h2>رایگان‌ها</h2><span>بدون پرداخت</span></div>
          <div class="store-shelf-track">
            @foreach($free as $product)
              @include('store.partials.card', ['product' => $product])
            @endforeach
          </div>
        </section>
      @endif

      @if($latest->count())
        <section class="store-shelf">
          <div class="store-shelf-head"><h2>تازه‌ها</h2><span>آخرین انتشار</span></div>
          <div class="store-shelf-track">
            @foreach($latest as $product)
              @include('store.partials.card', ['product' => $product])
            @endforeach
          </div>
        </section>
      @endif

      @if($recent->count())
        <section class="store-shelf">
          <div class="store-shelf-head"><h2>اخیراً دیده‌اید</h2><span>ادامه مرور</span></div>
          <div class="store-shelf-track">
            @foreach($recent as $product)
              @include('store.partials.card', ['product' => $product])
            @endforeach
          </div>
        </section>
      @endif
    @endif

    <section class="store-shelf store-shelf--grid">
      <div class="store-shelf-head">
        <h2>{{ $q ? 'نتایج جستجو' : ($category ? 'دسته انتخاب‌شده' : 'همه محصولات') }}</h2>
        <span>{{ $products->total() }} مورد</span>
      </div>
      <div class="store-product-grid">
        @forelse($products as $product)
          @include('store.partials.card', ['product' => $product])
        @empty
          <div class="store-empty-state">
            <i class="fa-solid fa-box-open"></i>
            <h2>محصولی پیدا نشد</h2>
            <p>عبارت جستجو یا دسته را تغییر دهید.</p>
            <a class="store-primary-btn" href="{{ route('store') }}">بازگشت به فروشگاه</a>
          </div>
        @endforelse
      </div>
      <div style="margin-top:16px">{{ $products->links() }}</div>
    </section>

  </div>
</section>
@endsection
@push('styles')
<link rel="stylesheet" href="/css/store.css?v=20260929m1">
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
      const c=document.getElementById('farastCartCount'); if(c&&j.count!=null) c.textContent=j.count;
      setTimeout(()=>{b.textContent=t;b.disabled=false},1200);
    }catch(e){alert(e.message);b.disabled=false;b.textContent=t}
  }));
})();
</script>
@endpush
