@extends('layouts.app')
@section('content')
<section class="store-page" dir="rtl">
  <div class="store-container">

    <div class="store-hero store-hero--market store-hero--focused">
      <div class="store-hero-content">
        <div class="store-hero-kicker">فروشگاه فراست</div>
        <h1>فایل‌ها و دوره‌های دیجیتال</h1>
        <p>محصول موردنظرتان را جستجو کنید، جزئیات و پیش‌نمایش آن را ببینید و سپس تصمیم بگیرید.</p>
        <form method="get" action="{{ route('store') }}" class="store-search" role="search">
          <input name="q" value="{{ $q }}" placeholder="نام محصول یا موضوع موردنظر…" aria-label="جستجوی محصولات فروشگاه">
          @if($category)<input type="hidden" name="category" value="{{ $category }}">@endif
          @if($sort !== 'newest')<input type="hidden" name="sort" value="{{ $sort }}">@endif
          @if($price !== 'all')<input type="hidden" name="price" value="{{ $price }}">@endif
          @if($type !== 'all')<input type="hidden" name="type" value="{{ $type }}">@endif
          <button type="submit"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i> جستجو</button>
        </form>
        <div class="store-hero-links">
          <a href="{{ route('cart') }}"><i class="fa-solid fa-bag-shopping" aria-hidden="true"></i> سبد خرید</a>
          @auth
            <a href="{{ route('library') }}"><i class="fa-solid fa-book" aria-hidden="true"></i> کتابخانه من</a>
            <a href="{{ route('wishlist') }}"><i class="fa-regular fa-heart" aria-hidden="true"></i> علاقه‌مندی‌ها</a>
          @else
            <a href="{{ route('login') }}"><i class="fa-solid fa-right-to-bracket" aria-hidden="true"></i> ورود</a>
          @endauth
        </div>
      </div>
    </div>

    @if(session('status'))
      <div class="store-alert success"><i class="fa-solid fa-circle-check"></i>{{ session('status') }}</div>
    @endif

    <nav class="store-chips" aria-label="دسته‌بندی محصولات">
      <a class="{{ !$category ? 'active' : '' }}" href="{{ route('store', request()->only(['q','sort','price','type'])) }}">همه</a>
      @foreach($categories as $cat)
        <a class="{{ $category===$cat->slug ? 'active' : '' }}" href="{{ route('store', array_merge(request()->only(['q','sort','price','type']), ['category'=>$cat->slug])) }}">{{ $cat->name }} <small>{{ $cat->published_products_count }}</small></a>
      @endforeach
    </nav>

    @php($activeCategoryName = $categories->firstWhere('slug', $category)?->name)
    @if($q !== '' || $category || $price !== 'all' || $type !== 'all')
      <section class="store-active-filters" aria-label="فیلترهای فعال">
        <div class="store-active-filters__summary"><i class="fa-solid fa-sliders" aria-hidden="true"></i><strong>فیلترهای انتخاب‌شده</strong></div>
        <div class="store-active-filters__chips">
          @if($q !== '')<span><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i> {{ $q }}</span>@endif
          @if($activeCategoryName)<span><i class="fa-solid fa-folder" aria-hidden="true"></i> {{ $activeCategoryName }}</span>@endif
          @if($price === 'free')<span><i class="fa-solid fa-gift" aria-hidden="true"></i> فقط رایگان</span>@elseif($price === 'paid')<span><i class="fa-solid fa-tag" aria-hidden="true"></i> فقط پولی</span>@endif
          @if($type === 'course')<span><i class="fa-solid fa-graduation-cap" aria-hidden="true"></i> دوره‌های آموزشی</span>@elseif($type === 'digital')<span><i class="fa-solid fa-file-lines" aria-hidden="true"></i> فایل‌های دیجیتال</span>@endif
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
        <label class="store-filter-label">نوع محصول
          <select name="type" onchange="this.form.submit()" class="store-filter-select">
            <option value="all" @selected(($type??'all')==='all')>همه انواع</option>
            <option value="digital" @selected(($type??'')==='digital') >فایل دیجیتال ({{ $digitalCount }})</option>
            <option value="course" @selected(($type??'')==='course') >دوره آموزشی ({{ $courseCount }})</option>
          </select>
        </label>
        <span class="store-results-total">{{ $products->total() }} مورد</span>
      </div>
    </form>

    <section class="store-shelf store-shelf--grid store-catalog">
      <div class="store-shelf-head">
        <h2>{{ $q ? 'نتایج جستجو' : ($category ? 'محصولات این دسته' : 'محصولات فروشگاه') }}</h2>
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
<link rel="stylesheet" href="/css/store.css?v=20261110focused1">
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
