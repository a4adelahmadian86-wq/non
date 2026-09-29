@extends('layouts.app')

@section('content')
<div class="fm-store" dir="rtl">
  <section class="fm-hero">
    <div class="fm-hero-inner">
      <div class="fm-hero-copy">
        <span class="fm-badge">مرجع فایل‌های دیجیتال</span>
        <h1>فایل مورد نیازت را<br><span>سریع و مطمئن پیدا کن</span></h1>
        <p>جستجو کن، پیش‌نمایش را ببین، محتوای واقعی فایل را بررسی کن و با کمک دستیار هوشمند بهترین گزینه را انتخاب کن.</p>
        <form class="fm-search" method="get" action="{{ route('store') }}" role="search">
          <input type="search" name="q" value="{{ $q }}" placeholder="مثلاً فایل اکسل حسابداری، پروژه PHP یا گزارش آماده" aria-label="جستجوی فایل">
          <button type="submit"><i class="fa-solid fa-magnifying-glass"></i> جستجو</button>
        </form>
        <div class="fm-features">
          <div class="fm-feature"><i class="fa-solid fa-magnifying-glass"></i><strong>جستجوی هوشمند</strong><span>جستجو در عنوان و محتوای واقعی فایل</span></div>
          <div class="fm-feature"><i class="fa-solid fa-file-lines"></i><strong>فایل‌های تازه</strong><span>محصولات تازه منتشرشده</span></div>
          <div class="fm-feature"><i class="fa-solid fa-wand-magic-sparkles"></i><strong>مشاور هوشمند</strong><span>با صدا نیازت را توضیح بده</span></div>
          <div class="fm-feature"><i class="fa-solid fa-headset"></i><strong>پشتیبانی</strong><span>پاسخ‌گویی و پیگیری درخواست</span></div>
        </div>
      </div>
      <div class="fm-hero-preview">
        <div class="fm-preview-card">
          <div class="fm-preview-label">فایل Word</div>
          <div class="fm-preview-stage">
            <i class="fa-solid fa-file-word"></i>
            <p>پیش‌نمایش + محتوای واقعی</p>
            <small>فایل را قبل از خرید بررسی کن</small>
          </div>
        </div>
      </div>
    </div>
  </section>

  <div class="fm-container">
    <section class="fm-section">
      <div class="fm-section-head">
        <h2>دسته‌بندی‌ها</h2>
        <a href="{{ route('store') }}">مشاهده همه</a>
      </div>
      <div class="fm-categories">
        <a class="fm-cat {{ !$category ? 'active' : '' }}" href="{{ route('store') }}"><span>◈</span><strong>همه</strong></a>
        @foreach($categories as $cat)
          <a class="fm-cat {{ $category === $cat->slug ? 'active' : '' }}" href="{{ route('store.category', $cat->slug) }}">
            <span>◈</span><strong>{{ $cat->name }}</strong>
          </a>
        @endforeach
      </div>
    </section>

    <section class="fm-section">
      <div class="fm-section-head">
        <h2>{{ $q ? 'نتایج جستجو' : 'جدیدترین فایل‌ها' }}</h2>
        <span class="fm-muted">{{ $products->total() }} فایل</span>
      </div>

      <div class="fm-products">
        @forelse($products as $product)
          <article class="fm-card">
            <a class="fm-card-link" href="{{ route('store.product', $product->slug) }}">
              <div class="fm-card-img">
                @if($product->cover_path)
                  <img src="{{ asset($product->cover_path) }}" alt="{{ $product->title }}" loading="lazy">
                @else
                  <i class="fa-solid fa-folder-open"></i>
                @endif
              </div>
              <div class="fm-card-body">
                <small>{{ $product->category?->name }}</small>
                <h3>{{ $product->title }}</h3>
                <p>{{ \Illuminate\Support\Str::limit($product->short_description ?: $product->description, 90) }}</p>
                <div class="fm-price">{{ number_format((int) $product->price_rials / 10) }} تومان</div>
              </div>
            </a>
            <button type="button" class="fm-add" data-product-id="{{ $product->id }}" title="افزودن به سبد" aria-label="افزودن به سبد">
              <i class="fa-solid fa-cart-shopping"></i>
            </button>
            <a class="fm-view" href="{{ route('store.product', $product->slug) }}" title="مشاهده محصول" aria-label="مشاهده محصول">
              <i class="fa-solid fa-arrow-up-left"></i>
            </a>
          </article>
        @empty
          <div class="fm-empty">
            <strong>محصولی پیدا نشد.</strong>
            <span>عبارت جستجو یا دسته‌بندی را تغییر دهید.</span>
          </div>
        @endforelse
      </div>

      <div class="fm-pagination">{{ $products->links() }}</div>
    </section>
  </div>
</div>
@endsection

@push('styles')
<style>
.fm-store{background:#f7f8fc;color:#20242d;min-height:calc(100vh - 80px);padding-bottom:70px}
.fm-hero{background:linear-gradient(160deg,#f3f0ff 0%,#ffffff 55%,#f7f9fc 100%);border-bottom:1px solid #eceaf6}
.fm-hero-inner{width:min(1180px,calc(100% - 32px));margin:0 auto;padding:48px 0 42px;display:grid;grid-template-columns:minmax(0,1.15fr) minmax(260px,.85fr);gap:28px;align-items:center}
.fm-badge{display:inline-flex;padding:6px 12px;border-radius:999px;background:#eeeaff;color:#5b3fd0;font-size:.72rem;font-weight:700}
.fm-hero h1{margin:12px 0 10px;font-size:clamp(1.7rem,3.6vw,2.7rem);line-height:1.45;color:#1d2330}
.fm-hero h1 span{color:#5b3fd0}
.fm-hero p{margin:0 0 18px;color:#667085;max-width:560px;font-size:.92rem;line-height:1.9}
.fm-search{display:flex;gap:8px;max-width:620px;background:#fff;border:1px solid #e8e6f2;border-radius:16px;padding:6px;box-shadow:0 10px 30px rgba(30,20,80,.06)}
.fm-search input{flex:1;min-width:0;border:0;outline:0;background:transparent;padding:12px 14px;font:inherit}
.fm-search button{border:0;border-radius:12px;background:#5b3fd0;color:#fff;padding:0 18px;font:inherit;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:8px}
.fm-search button:hover{background:#4a31b5}
.fm-features{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-top:22px}
.fm-feature{background:#fff;border:1px solid #eeeaf8;border-radius:14px;padding:12px;display:grid;gap:4px}
.fm-feature i{color:#5b3fd0;font-size:.95rem}
.fm-feature strong{font-size:.78rem}
.fm-feature span{font-size:.68rem;color:#7a8498;line-height:1.6}
.fm-preview-card{background:#fff;border:1px solid #ebe8f6;border-radius:22px;padding:18px;box-shadow:0 18px 50px rgba(40,30,90,.08)}
.fm-preview-label{font-size:.75rem;color:#6b7280;margin-bottom:10px}
.fm-preview-stage{min-height:260px;border-radius:16px;background:linear-gradient(160deg,#f4f2ff,#f8fafc);display:grid;place-items:center;align-content:center;gap:8px;color:#5b3fd0;text-align:center}
.fm-preview-stage i{font-size:2.6rem}
.fm-preview-stage p{margin:0;font-weight:800;color:#1f2937}
.fm-preview-stage small{color:#6b7280}
.fm-container{width:min(1180px,calc(100% - 32px));margin:0 auto}
.fm-section{margin-top:34px}
.fm-section-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px}
.fm-section-head h2{margin:0;font-size:1.15rem}
.fm-section-head a,.fm-muted{color:#6b7280;font-size:.8rem;text-decoration:none}
.fm-section-head a:hover{color:#5b3fd0}
.fm-categories{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:10px}
.fm-cat{background:#fff;border:1px solid #eee;border-radius:14px;padding:14px;text-align:center;text-decoration:none;color:inherit;transition:.25s}
.fm-cat span{display:block;color:#5b3fd0;margin-bottom:4px}
.fm-cat strong{font-size:.82rem}
.fm-cat:hover,.fm-cat.active{transform:translateY(-3px);border-color:#d9d1ff;box-shadow:0 10px 24px rgba(90,60,200,.08);background:#f8f6ff}
.fm-products{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px}
.fm-card{position:relative;background:#fff;border:1px solid #eee;border-radius:18px;overflow:hidden;box-shadow:0 6px 20px rgba(0,0,0,.04);transition:.28s}
.fm-card:hover{transform:translateY(-6px);box-shadow:0 16px 36px rgba(40,30,90,.1);border-color:#ddd5ff}
.fm-card-link{display:block;color:inherit;text-decoration:none}
.fm-card-img{height:168px;background:linear-gradient(135deg,#eeeaff,#f8f7ff);display:grid;place-items:center;color:#5b3fd0;font-size:2.2rem;overflow:hidden}
.fm-card-img img{width:100%;height:100%;object-fit:cover}
.fm-card-body{padding:14px 15px 16px}
.fm-card-body small{color:#7a8498;font-size:.68rem}
.fm-card-body h3{margin:6px 0;font-size:.95rem;line-height:1.6}
.fm-card-body p{margin:0;color:#6b7280;font-size:.75rem;line-height:1.75;min-height:42px}
.fm-price{margin-top:12px;font-weight:900;color:#5b3fd0;font-size:.95rem}
.fm-add,.fm-view{position:absolute;z-index:2;width:40px;height:40px;border:0;border-radius:12px;display:grid;place-items:center;cursor:pointer;transition:.2s;text-decoration:none}
.fm-add{left:12px;bottom:12px;background:#5b3fd0;color:#fff}
.fm-add:hover{transform:scale(1.08);box-shadow:0 8px 18px rgba(91,63,208,.35)}
.fm-view{left:12px;top:12px;background:#fff;color:#5b3fd0;box-shadow:0 4px 14px rgba(0,0,0,.1)}
.fm-view:hover{transform:rotate(-8deg) scale(1.08)}
.fm-empty{grid-column:1/-1;background:#fff;border:1px dashed #d8dce6;border-radius:16px;padding:50px;text-align:center;display:grid;gap:8px;color:#6b7280}
.fm-pagination{margin-top:22px}
@media(max-width:980px){.fm-hero-inner{grid-template-columns:1fr}.fm-features{grid-template-columns:repeat(2,minmax(0,1fr))}.fm-products{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:560px){.fm-features,.fm-products{grid-template-columns:1fr}.fm-search{flex-direction:column}.fm-search button{height:44px;justify-content:center}}
</style>
@endpush

@push('scripts')
<script>
(() => {
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
  document.querySelectorAll('.fm-add').forEach(btn => {
    btn.addEventListener('click', async () => {
      btn.disabled = true;
      try {
        const r = await fetch('/cart/products/' + btn.dataset.productId, {
          method: 'POST',
          headers: {'X-CSRF-TOKEN': csrf, 'Accept': 'application/json', 'Content-Type': 'application/json'},
          body: JSON.stringify({quantity: 1})
        });
        const j = await r.json();
        if (!r.ok) throw new Error(j.message || 'افزودن به سبد ناموفق بود');
        if (window.FarastCart?.refresh) window.FarastCart.refresh();
        btn.innerHTML = '<i class="fa-solid fa-check"></i>';
        setTimeout(() => { btn.innerHTML = '<i class="fa-solid fa-cart-shopping"></i>'; }, 1200);
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
