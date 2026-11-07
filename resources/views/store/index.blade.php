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

    <p class="store-cart-feedback" id="storeCartFeedback" role="status" aria-live="polite" aria-atomic="true" hidden></p>

    <div class="store-chips">
      <a class="{{ !$category ? 'active' : '' }}" href="{{ route('store', request()->only(['q','sort','price'])) }}">همه</a>
      @foreach($categories as $cat)
        <a class="{{ $category===$cat->slug ? 'active' : '' }}" href="{{ route('store', array_merge(request()->only(['q','sort','price']), ['category'=>$cat->slug])) }}">{{ $cat->name }}</a>
      @endforeach
    </div>

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
<link rel="stylesheet" href="/css/store.css?v=20261107premium1">
@endpush
@push('scripts')
<script>
(()=>{
  const csrf=document.querySelector('meta[name="csrf-token"]')?.content||'';
  const feedback=document.getElementById('storeCartFeedback');
  const announce=(message,state='success')=>{
    if(!feedback)return;
    feedback.hidden=false;
    feedback.dataset.state=state;
    feedback.textContent=message;
  };
  document.querySelectorAll('[data-add-cart]').forEach(button=>button.addEventListener('click',async()=>{
    if(button.disabled)return;
    const originalLabel=button.textContent.trim();
    button.disabled=true;
    button.setAttribute('aria-busy','true');
    button.textContent='در حال افزودن…';
    try{
      const response=await fetch('/cart/products/'+encodeURIComponent(button.dataset.addCart),{
        method:'POST',
        credentials:'same-origin',
        headers:{'X-CSRF-TOKEN':csrf,'Accept':'application/json','Content-Type':'application/json'},
        body:JSON.stringify({quantity:1})
      });
      const raw=await response.text();
      let payload={};
      if(raw){
        try{payload=JSON.parse(raw)}catch(_){payload={}}
      }
      if(!response.ok){
        const message=payload.message||(response.status===401?'برای افزودن محصول ابتدا وارد حساب خود شوید.':'افزودن محصول انجام نشد. لطفاً دوباره تلاش کنید.');
        throw new Error(message);
      }
      button.textContent='اضافه شد';
      announce(payload.message||'محصول با موفقیت به سبد خرید اضافه شد.');
      if(window.FarastCart?.refresh)window.FarastCart.refresh();
      const count=document.getElementById('farastCartCount');
      if(count&&payload.count!=null)count.textContent=payload.count;
      window.setTimeout(()=>{
        button.textContent=originalLabel;
        button.disabled=false;
        button.removeAttribute('aria-busy');
      },1300);
    }catch(error){
      button.textContent=originalLabel;
      button.disabled=false;
      button.removeAttribute('aria-busy');
      announce(error.message||'ارتباط برقرار نشد. لطفاً دوباره تلاش کنید.','error');
    }
  }));
})();
</script>
@endpush
