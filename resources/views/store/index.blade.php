@extends('layouts.app')
@section('content')
<section class="store-page" dir="rtl">
  <div class="store-container">

    <div class="store-hero store-hero--market">
      <div>
        <div class="store-hero-kicker">کتابخانه و فایل دیجیتال فراست</div>
        <h1>بخوان، ببین، بعد بخر</h1>
        <p>نمونه را ببینید، با آگاهی انتخاب کنید و پس از خرید، فایل‌تان را در کتابخانه شخصی فراست در دسترس داشته باشید.</p>
        <form method="get" action="{{ route('store') }}" class="store-search">
          <input name="q" value="{{ $q }}" placeholder="جستجوی عنوان، موضوع یا فایل…" aria-label="جستجوی فروشگاه">
          @if($category)<input type="hidden" name="category" value="{{ $category }}">@endif
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
      <div class="store-hero-visual">
        <img src="https://images.unsplash.com/photo-1507842217343-583bb7270b66?auto=format&fit=crop&w=1000&q=85" alt="قفسه کتاب‌ها و منابع دیجیتال" fetchpriority="high">
        <div class="store-hero-visual-caption"><i class="fa-solid fa-sparkles"></i><span><strong>کتابخانه‌ای برای کشف</strong><small>دانلود و مطالعه در فضای شخصی شما</small></span></div>
        <div class="store-hero-stats">
          <div><strong>{{ $products->total() }}</strong><span>محصول</span></div>
          <div><strong>{{ $categories->count() }}</strong><span>دسته</span></div>
          <div><strong>{{ $free->count() }}</strong><span>رایگان</span></div>
        </div>
      </div>
    </div>

    <div class="store-feature-row" aria-label="مزیت‌های فروشگاه فراست">
      <div class="store-feature"><i class="fa-solid fa-eye"></i><strong>پیش‌نمایش پیش از خرید</strong><span>نمونه واقعی فایل را بررسی کنید.</span></div>
      <div class="store-feature"><i class="fa-solid fa-cloud-arrow-down"></i><strong>دریافت آسان</strong><span>محصولات خریداری‌شده در کتابخانه شما.</span></div>
      <div class="store-feature"><i class="fa-solid fa-heart"></i><strong>فهرست شخصی</strong><span>موارد دلخواه را برای بعد نگه دارید.</span></div>
      <div class="store-feature"><i class="fa-solid fa-shield-halved"></i><strong>خرید شفاف</strong><span>قیمت، مجوز و توضیحات روشن.</span></div>
    </div>

    @if(session('status'))
      <div class="store-alert success"><i class="fa-solid fa-circle-check"></i>{{ session('status') }}</div>
    @endif

    <nav class="store-chips" aria-label="دسته‌بندی محصولات">
      <a class="{{ !$category ? 'active' : '' }}" href="{{ route('store', request()->only(['q','sort','price'])) }}">همه</a>
      @foreach($categories as $cat)
        <a class="{{ $category===$cat->slug ? 'active' : '' }}" href="{{ route('store', array_merge(request()->only(['q','sort','price']), ['category'=>$cat->slug])) }}">{{ $cat->name }}</a>
      @endforeach
    </nav>

    @php($activeCategoryName = $categories->firstWhere('slug', $category)?->name)
    @if($q !== '' || $category || $price !== 'all')
      <section class="store-active-filters" aria-label="فیلترهای فعال">
        <div class="store-active-filters__summary"><i class="fa-solid fa-sliders" aria-hidden="true"></i><strong>فیلترهای انتخاب‌شده</strong></div>
        <div class="store-active-filters__chips">
          @if($q !== '')<span><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i> {{ $q }}</span>@endif
          @if($activeCategoryName)<span><i class="fa-solid fa-folder" aria-hidden="true"></i> {{ $activeCategoryName }}</span>@endif
          @if($price === 'free')<span><i class="fa-solid fa-gift" aria-hidden="true"></i> فقط رایگان</span>@elseif($price === 'paid')<span><i class="fa-solid fa-tag" aria-hidden="true"></i> فقط پولی</span>@endif
        </div>
        <a href="{{ route('store') }}">پاک‌کردن فیلترها <i class="fa-solid fa-xmark" aria-hidden="true"></i></a>
      </section>
    @endif

    <form method="get" action="{{ route('store') }}" class="store-toolbar store-toolbar--premium">
      @if($q)<input type="hidden" name="q" value="{{ $q }}">@endif
      @if($category)<input type="hidden" name="category" value="{{ $category }}">@endif
      <div class="store-toolbar-controls">
        <label class="store-filter-label">مرتب‌سازی
          <select name="sort" onchange="this.form.submit()" class="store-filter-select">
            <option value="newest" @selected(($sort??'newest')==='newest')>جدیدترین</option>
            <option value="featured" @selected(($sort??'')==='featured')>ویژه</option>
            <option value="price_asc" @selected(($sort??'')==='price_asc')>ارزان‌ترین</option>
            <option value="price_desc" @selected(($sort??'')==='price_desc')>گران‌ترین</option>
            <option value="title" @selected(($sort??'')==='title')>عنوان</option>
          </select>
        </label>
        <label class="store-filter-label">قیمت
          <select name="price" onchange="this.form.submit()" class="store-filter-select">
            <option value="all" @selected(($price??'all')==='all')>همه</option>
            <option value="free" @selected(($price??'')==='free')>فقط رایگان</option>
            <option value="paid" @selected(($price??'')==='paid')>فقط پولی</option>
          </select>
        </label>
        <span class="store-results-total">{{ $products->total() }} مورد</span>
      </div>
    </form>

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
            <p>عبارت جستجو، فیلتر قیمت یا دسته را تغییر دهید.</p>
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
<link rel="stylesheet" href="/css/store.css?v=20261109catalog2">
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
