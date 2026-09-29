@extends('layouts.app')
@section('content')
<section class="store-reader" dir="rtl">
  <header class="store-reader-bar">
    <div class="store-reader-bar-inner">
      <a class="store-reader-back" href="{{ route('store.product', $product->slug) }}"><i class="fa-solid fa-arrow-right"></i> بازگشت به محصول</a>
      <div class="store-reader-title">
        <strong>{{ $product->title }}</strong>
        <small>پیش‌نمایش · {{ $previews->count() }} صفحه</small>
      </div>
      <div class="store-reader-actions">
        <button type="button" id="reader-prev" class="store-reader-nav" title="قبلی"><i class="fa-solid fa-chevron-right"></i></button>
        <span id="reader-page-label" class="store-reader-page">۱ / {{ $previews->count() }}</span>
        <button type="button" id="reader-next" class="store-reader-nav" title="بعدی"><i class="fa-solid fa-chevron-left"></i></button>
        <button type="button" id="reader-fs" class="store-reader-nav" title="تمام‌صفحه"><i class="fa-solid fa-expand"></i></button>
        <a class="store-primary-btn" href="{{ route('store.product', $product->slug) }}">خرید</a>
      </div>
    </div>
  </header>

  <div class="store-reader-stage" id="reader-stage">
    @foreach($previews as $i => $preview)
      <div class="store-reader-page-item {{ $i === 0 ? 'is-active' : '' }}" data-index="{{ $i }}">
        @if(($preview->kind ?? '') === 'pdf' || str_contains((string)$preview->mime, 'pdf'))
          <iframe src="{{ route('store.preview', $preview) }}#toolbar=0" title="صفحه {{ $i+1 }}" loading="{{ $i===0 ? 'eager' : 'lazy' }}"></iframe>
        @else
          <img src="{{ route('store.preview', $preview) }}" alt="صفحه {{ $i+1 }}" loading="{{ $i===0 ? 'eager' : 'lazy' }}">
        @endif
      </div>
    @endforeach
  </div>

  <nav class="store-reader-thumbs" aria-label="صفحات پیش‌نمایش">
    @foreach($previews as $i => $preview)
      <button type="button" class="store-reader-thumb {{ $i===0 ? 'is-active' : '' }}" data-goto="{{ $i }}">
        @if(($preview->kind ?? '') === 'pdf' || str_contains((string)$preview->mime, 'pdf'))
          <span>{{ $i+1 }}</span>
        @else
          <img src="{{ route('store.preview', $preview) }}" alt="" loading="lazy">
        @endif
      </button>
    @endforeach
  </nav>
</section>
@endsection
@push('styles')
<link rel="stylesheet" href="/css/store.css?v=20260929r">
<style>
.store-reader{min-height:calc(100vh - 70px);background:#0b1220;color:#e8eef8;display:flex;flex-direction:column}
.store-reader-bar{position:sticky;top:0;z-index:20;background:rgba(11,18,32,.92);backdrop-filter:blur(10px);border-bottom:1px solid rgba(255,255,255,.08)}
.store-reader-bar-inner{width:min(1100px,calc(100% - 24px));margin:0 auto;display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 0;flex-wrap:wrap}
.store-reader-back{color:#9eb6d8;text-decoration:none;font-size:.78rem;display:inline-flex;align-items:center;gap:6px}
.store-reader-title{text-align:center;flex:1;min-width:140px}
.store-reader-title strong{display:block;font-size:.9rem}
.store-reader-title small{color:#8aa0c0;font-size:.7rem}
.store-reader-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.store-reader-nav{width:36px;height:36px;border-radius:10px;border:1px solid rgba(255,255,255,.12);background:rgba(255,255,255,.06);color:#e8eef8;cursor:pointer}
.store-reader-nav:hover{background:rgba(255,255,255,.12)}
.store-reader-page{font-size:.78rem;min-width:4.5rem;text-align:center;color:#b8c8e0}
.store-reader-stage{flex:1;display:grid;place-items:center;padding:18px 12px 8px;min-height:60vh}
.store-reader-page-item{display:none;width:min(820px,100%);max-height:calc(100vh - 200px)}
.store-reader-page-item.is-active{display:grid;place-items:center}
.store-reader-page-item img,.store-reader-page-item iframe{
  width:100%;max-height:calc(100vh - 200px);object-fit:contain;border-radius:12px;
  box-shadow:0 20px 50px rgba(0,0,0,.45);background:#111;border:0
}
.store-reader-page-item iframe{height:min(78vh,900px);min-height:420px}
.store-reader-thumbs{display:flex;gap:8px;overflow:auto;padding:10px 12px 20px;justify-content:center;scrollbar-width:thin}
.store-reader-thumb{flex:0 0 auto;width:56px;height:72px;border-radius:8px;border:2px solid transparent;background:#1a2438;overflow:hidden;cursor:pointer;padding:0;color:#9eb6d8;font-size:.75rem}
.store-reader-thumb img{width:100%;height:100%;object-fit:cover;display:block}
.store-reader-thumb.is-active{border-color:#1769ff}
.store-reader-stage:fullscreen,.store-reader-stage:-webkit-full-screen{background:#000;padding:24px}
.store-reader-stage:fullscreen .store-reader-page-item img,
.store-reader-stage:fullscreen .store-reader-page-item iframe,
.store-reader-stage:-webkit-full-screen .store-reader-page-item img,
.store-reader-stage:-webkit-full-screen .store-reader-page-item iframe{max-height:95vh}
@media(max-width:640px){
  .store-reader-title{order:3;width:100%}
  .store-reader-page-item iframe{min-height:320px}
}
</style>
@endpush
@push('scripts')
<script>
(()=>{
  const pages=[...document.querySelectorAll('.store-reader-page-item')];
  const thumbs=[...document.querySelectorAll('.store-reader-thumb')];
  const label=document.getElementById('reader-page-label');
  const stage=document.getElementById('reader-stage');
  let i=0;
  const show=(n)=>{
    i=Math.max(0,Math.min(pages.length-1,n));
    pages.forEach((p,idx)=>p.classList.toggle('is-active',idx===i));
    thumbs.forEach((t,idx)=>t.classList.toggle('is-active',idx===i));
    if(label) label.textContent=(i+1)+' / '+pages.length;
  };
  document.getElementById('reader-prev')?.addEventListener('click',()=>show(i-1));
  document.getElementById('reader-next')?.addEventListener('click',()=>show(i+1));
  thumbs.forEach(t=>t.addEventListener('click',()=>show(+t.dataset.goto)));
  document.getElementById('reader-fs')?.addEventListener('click',()=>{
    if(!document.fullscreenElement) stage.requestFullscreen?.() || stage.webkitRequestFullscreen?.();
    else document.exitFullscreen?.();
  });
  document.addEventListener('keydown',e=>{
    if(e.key==='ArrowLeft'||e.key==='ArrowDown') show(i+1);
    if(e.key==='ArrowRight'||e.key==='ArrowUp') show(i-1);
  });
})();
</script>
@endpush
