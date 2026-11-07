@extends('layouts.app')
@section('content')
@php
  $readerWatermark = trim((string) ($readerIdentity ?? ('کاربر #'.auth()->id())));
@endphp
<section class="farast-reader" dir="rtl" data-reader-root data-theme="dark">
  <header class="farast-reader-header">
    <a class="farast-reader-back" href="{{ route('store.product', $product->slug) }}" aria-label="بازگشت به محصول"><i class="fa-solid fa-arrow-right"></i><span>بازگشت</span></a>
    <div class="farast-reader-heading">
      <strong>{{ $product->title }}</strong>
      <span><i class="fa-solid fa-shield-halved"></i> مطالعه امن · {{ $previews->count() }} صفحه نمونه</span>
    </div>
    <div class="farast-reader-account"><i class="fa-solid fa-user-shield"></i><span>{{ $readerWatermark }}</span></div>
  </header>

  <nav class="farast-reader-toolbar" aria-label="ابزارهای مطالعه">
    <div class="farast-reader-toolgroup">
      <button type="button" id="reader-prev" class="reader-tool" title="صفحه قبل" aria-label="صفحه قبل"><i class="fa-solid fa-chevron-right"></i></button>
      <label class="reader-page-input"><span class="sr-only">شماره صفحه</span><input id="reader-page-number" type="number" min="1" max="{{ $previews->count() }}" value="1" inputmode="numeric"><span>/ {{ $previews->count() }}</span></label>
      <button type="button" id="reader-next" class="reader-tool" title="صفحه بعد" aria-label="صفحه بعد"><i class="fa-solid fa-chevron-left"></i></button>
    </div>
    <div class="farast-reader-toolgroup">
      <button type="button" id="reader-zoom-out" class="reader-tool" title="کوچک‌نمایی" aria-label="کوچک‌نمایی"><i class="fa-solid fa-minus"></i></button>
      <output id="reader-zoom-label" class="reader-zoom-label">۱۰۰٪</output>
      <button type="button" id="reader-zoom-in" class="reader-tool" title="بزرگ‌نمایی" aria-label="بزرگ‌نمایی"><i class="fa-solid fa-plus"></i></button>
      <button type="button" id="reader-fit" class="reader-tool reader-tool-wide"><i class="fa-solid fa-arrows-left-right-to-line"></i><span>تناسب عرض</span></button>
      <button type="button" id="reader-rotate" class="reader-tool" title="چرخش صفحه" aria-label="چرخش صفحه"><i class="fa-solid fa-rotate"></i></button>
    </div>
    <div class="farast-reader-toolgroup reader-search-group">
      <label class="sr-only" for="reader-search-input">جست‌وجو در سند</label>
      <input id="reader-search-input" type="search" placeholder="جست‌وجو در سند…" autocomplete="off">
      <button type="button" id="reader-search" class="reader-tool" aria-label="جست‌وجو"><i class="fa-solid fa-magnifying-glass"></i></button>
      <output id="reader-search-count" class="reader-search-count" aria-live="polite"></output>
    </div>
    <div class="farast-reader-toolgroup">
      <button type="button" id="reader-thumbs-toggle" class="reader-tool reader-tool-wide" aria-expanded="true"><i class="fa-solid fa-table-cells"></i><span>تصاویر صفحات</span></button>
      <button type="button" id="reader-theme" class="reader-tool" title="تغییر پوسته" aria-label="تغییر پوسته"><i class="fa-solid fa-sun"></i></button>
      <button type="button" id="reader-fs" class="reader-tool" title="تمام‌صفحه" aria-label="تمام‌صفحه"><i class="fa-solid fa-expand"></i></button>
    </div>
  </nav>

  <div class="farast-reader-progress" role="progressbar" aria-label="پیشرفت مطالعه" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><span id="reader-progress"></span></div>

  <div class="farast-reader-workspace" id="reader-workspace">
    <aside class="farast-reader-sidebar" id="reader-sidebar" aria-label="فهرست صفحات">
      <div class="farast-reader-sidebar-head"><strong>فهرست اسناد</strong><span>{{ $previews->count() }} مورد</span></div>
      <div class="farast-reader-thumbs">
        @foreach($previews as $i => $preview)
          <button type="button" class="farast-reader-thumb {{ $i===0 ? 'is-active' : '' }}" data-goto="{{ $i }}" aria-label="رفتن به صفحه {{ $i+1 }}" aria-current="{{ $i===0 ? 'page' : 'false' }}">
            <span class="farast-reader-thumb-preview">
              @if(($preview->kind ?? '') === 'pdf' || str_contains((string)$preview->mime, 'pdf'))
                <i class="fa-solid fa-file-pdf"></i><small>PDF</small>
              @elseif(($preview->kind ?? '') === 'docx')
                <i class="fa-solid fa-file-word"></i><small>Word</small>
              @else
                <img src="{{ route('store.preview',$preview) }}" alt="" loading="lazy">
              @endif
            </span>
            <span>صفحه {{ $preview->page_number ?: $i+1 }}</span>
          </button>
        @endforeach
      </div>
    </aside>

    <main class="farast-reader-canvas" id="reader-stage" aria-label="صفحه سند">
      <div class="farast-reader-pages" id="reader-pages">
        @foreach($previews as $i => $preview)
          <article class="farast-reader-page-item {{ $i===0 ? 'is-active' : '' }}" data-index="{{ $i }}" data-watermark="{{ $readerWatermark }}">
            <div class="farast-reader-page-content">
              @if(($preview->kind ?? '') === 'pdf' || str_contains((string)$preview->mime, 'pdf'))
                <div class="farast-pdf-document" data-pdf-url="{{ route('store.preview',$preview) }}" aria-label="نمایش سند PDF">
                  <canvas class="farast-pdf-canvas"></canvas>
                  <div class="farast-pdf-text-layer"></div>
                  <div class="farast-pdf-loading" role="status">در حال آماده‌سازی سند PDF…</div>
                </div>
              @elseif(($preview->kind ?? '') === 'docx')
                <iframe class="farast-docx-frame" src="{{ route('store.preview',$preview) }}" title="سند Word نمونه {{ $i+1 }}" loading="{{ $i===0 ? 'eager' : 'lazy' }}" referrerpolicy="same-origin"></iframe>
              @else
                <img src="{{ route('store.preview',$preview) }}" alt="صفحه نمونه {{ $i+1 }}" draggable="false" loading="{{ $i===0 ? 'eager' : 'lazy' }}">
              @endif
            </div>
            <div class="farast-reader-watermark" aria-hidden="true">
              @for($mark=0;$mark<8;$mark++)<span>{{ $readerWatermark }} · پیش‌نمایش فراست</span>@endfor
            </div>
            <span class="farast-reader-page-caption">FARAST · نسخه مطالعه</span>
          </article>
        @endforeach
      </div>
      <div class="farast-reader-shortcuts"><kbd>←</kbd><kbd>→</kbd> جابه‌جایی صفحه <span>·</span> <kbd>+</kbd><kbd>−</kbd> بزرگ‌نمایی <span>·</span> <kbd>F</kbd> تمام‌صفحه</div>
    </main>
  </div>
  <div class="farast-reader-security-note"><i class="fa-solid fa-lock"></i><span>این پیش‌نمایش با شناسه حساب شما نشان‌گذاری شده است تا بازنشر بدون مجوز دشوارتر و قابل پیگیری‌تر باشد.</span></div>
</section>
@endsection

@push('styles')
<link rel="stylesheet" href="/css/store.css?v=20261107reader1">
<style>
.farast-reader{--reader-bg:#0b1220;--reader-panel:#111d30;--reader-line:rgba(255,255,255,.09);--reader-text:#e8eef8;--reader-muted:#91a4c1;min-height:calc(100vh - 70px);background:var(--reader-bg);color:var(--reader-text);display:flex;flex-direction:column;font-family:inherit}
.farast-reader[data-theme="light"]{--reader-bg:#e9eef6;--reader-panel:#fff;--reader-line:rgba(20,40,70,.12);--reader-text:#1c2b43;--reader-muted:#64748b}
.farast-reader-header{height:auto;min-height:64px;padding:10px clamp(12px,3vw,32px);display:grid;grid-template-columns:1fr minmax(180px,2fr) 1fr;align-items:center;gap:16px;border-bottom:1px solid var(--reader-line);background:var(--reader-panel)}
.farast-reader-back,.farast-reader-account{display:flex;align-items:center;gap:9px;color:var(--reader-muted);text-decoration:none;font-size:.78rem}
.farast-reader-account{justify-content:flex-end;direction:ltr}
.farast-reader-account span{direction:ltr;unicode-bidi:plaintext}
.farast-reader-heading{text-align:center;min-width:0}.farast-reader-heading strong{display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-size:.9rem}.farast-reader-heading span{display:block;color:var(--reader-muted);font-size:.7rem;margin-top:3px}
.farast-reader-toolbar{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:9px clamp(10px,2vw,24px);background:var(--reader-panel);border-bottom:1px solid var(--reader-line);flex-wrap:wrap}
.farast-reader-toolgroup{display:flex;align-items:center;gap:6px}.reader-tool{height:36px;min-width:36px;padding:0 10px;border:1px solid var(--reader-line);border-radius:9px;background:transparent;color:var(--reader-text);cursor:pointer;display:inline-flex;align-items:center;justify-content:center;gap:7px}.reader-tool:hover,.reader-tool:focus-visible{background:rgba(90,140,230,.14);outline:2px solid #568cf0;outline-offset:1px}.reader-tool-wide{font-size:.73rem}.reader-page-input{display:flex;align-items:center;gap:5px;color:var(--reader-muted);font-size:.76rem}.reader-page-input input{width:44px;text-align:center;padding:6px;border:1px solid var(--reader-line);border-radius:7px;background:transparent;color:var(--reader-text);font:inherit}.reader-zoom-label{min-width:42px;text-align:center;font-size:.72rem;color:var(--reader-muted);font-variant-numeric:tabular-nums}
.farast-reader-progress{height:3px;background:rgba(110,140,180,.15)}.farast-reader-progress span{display:block;height:100%;width:0;background:#4c8dff;transition:width .2s}
.farast-reader-workspace{display:grid;grid-template-columns:190px minmax(0,1fr);flex:1;min-height:65vh}.farast-reader-sidebar{background:var(--reader-panel);border-left:1px solid var(--reader-line);padding:12px;overflow:auto;max-height:calc(100vh - 190px)}.farast-reader-sidebar-head{display:flex;justify-content:space-between;gap:8px;font-size:.75rem;margin:2px 0 12px}.farast-reader-sidebar-head span{color:var(--reader-muted)}.farast-reader-thumbs{display:grid;grid-template-columns:1fr;gap:9px}.farast-reader-thumb{border:1px solid var(--reader-line);border-radius:10px;background:transparent;color:var(--reader-muted);padding:7px;display:flex;align-items:center;gap:9px;text-align:right;cursor:pointer;font:inherit;font-size:.7rem}.farast-reader-thumb.is-active{border-color:#4c8dff;background:rgba(76,141,255,.12);color:var(--reader-text)}.farast-reader-thumb-preview{width:44px;height:56px;border-radius:5px;background:rgba(100,130,170,.15);display:grid;place-items:center;overflow:hidden;flex:0 0 auto}.farast-reader-thumb-preview img{width:100%;height:100%;object-fit:cover}.farast-reader-thumb-preview i{font-size:1.2rem;color:#e45d66}.farast-reader-thumb-preview small{font-size:.55rem}
.farast-reader-canvas{position:relative;min-width:0;min-height:65vh;padding:24px 16px 48px;overflow:auto;display:flex;justify-content:center;align-items:flex-start;background:radial-gradient(ellipse at top,rgba(80,110,160,.12),transparent 60%),var(--reader-bg)}.farast-reader-pages{width:100%;display:flex;justify-content:center}.farast-reader-page-item{display:none;position:relative;width:min(900px,100%);min-height:60vh;isolation:isolate}.farast-reader-page-item.is-active{display:flex;justify-content:center;align-items:flex-start}.farast-reader-page-content{width:100%;display:flex;justify-content:center;overflow:visible}.farast-reader-page-content img,.farast-reader-page-content iframe{display:block;width:min(100%,900px);height:min(78vh,1000px);min-height:480px;object-fit:contain;border:0;border-radius:5px;background:#fff;box-shadow:0 18px 60px rgba(0,0,0,.32);transform:scale(var(--reader-zoom,1));transform-origin:top center}.farast-reader-page-content img{height:auto;max-height:calc(100vh - 230px);user-select:none;-webkit-user-drag:none}.farast-reader-watermark{position:absolute;inset:0;z-index:3;pointer-events:none;display:grid;grid-template-columns:repeat(2,1fr);grid-template-rows:repeat(4,1fr);align-items:center;justify-items:center;overflow:hidden;opacity:.24;mix-blend-mode:multiply}.farast-reader-watermark span{color:#26364b;background:rgba(255,255,255,.18);border:1px solid rgba(30,50,80,.12);padding:5px 9px;border-radius:4px;font-size:clamp(9px,1.1vw,13px);font-weight:800;white-space:nowrap;transform:rotate(-24deg);text-shadow:0 1px 1px rgba(255,255,255,.6)}.farast-reader-page-caption{position:absolute;bottom:8px;left:12px;z-index:4;color:#334155;background:rgba(255,255,255,.7);padding:3px 7px;border-radius:4px;font-size:9px;pointer-events:none}
.reader-search-group input{width:clamp(120px,18vw,220px);height:36px;padding:7px 10px;border:1px solid var(--reader-line);border-radius:9px;background:transparent;color:var(--reader-text);font:inherit;font-size:.72rem}.reader-search-group input::placeholder{color:var(--reader-muted)}.reader-search-count{font-size:.68rem;color:var(--reader-muted);min-width:42px}.farast-pdf-document{position:relative;width:min(100%,1000px);min-height:60vh;display:flex;justify-content:center;align-items:flex-start;background:#fff;box-shadow:0 18px 60px rgba(0,0,0,.32);overflow:hidden}.farast-pdf-canvas{display:block;max-width:100%;height:auto}.farast-pdf-text-layer{position:absolute;inset:0;overflow:hidden;line-height:1;opacity:0;user-select:none;-webkit-user-select:none;pointer-events:none}.farast-pdf-text-layer span{position:absolute;white-space:pre;color:transparent;transform-origin:0 0}.farast-pdf-loading{position:absolute;inset:0;display:grid;place-items:center;background:#fff;color:#475569;font-size:.8rem}.farast-docx-frame{display:block;width:min(100%,1000px);height:78vh;min-height:560px;border:0;background:#fff;box-shadow:0 18px 60px rgba(0,0,0,.32)}.farast-reader-shortcuts{position:absolute;bottom:9px;left:50%;transform:translateX(-50%);white-space:nowrap;color:var(--reader-muted);font-size:.65rem;background:var(--reader-panel);border:1px solid var(--reader-line);border-radius:20px;padding:5px 12px}.farast-reader-shortcuts kbd{font:inherit;border:1px solid var(--reader-line);border-radius:4px;padding:1px 4px}.farast-reader-security-note{display:flex;align-items:center;justify-content:center;gap:8px;padding:9px 12px;color:var(--reader-muted);font-size:.68rem;border-top:1px solid var(--reader-line);background:var(--reader-panel)}.farast-reader-security-note i{color:#5c9bff}
.farast-reader.is-sidebar-hidden .farast-reader-workspace{grid-template-columns:minmax(0,1fr)}.farast-reader.is-sidebar-hidden .farast-reader-sidebar{display:none}.farast-reader:fullscreen{min-height:100vh}.farast-reader:fullscreen .farast-reader-canvas{min-height:calc(100vh - 150px)}.farast-reader:fullscreen .farast-reader-sidebar{max-height:calc(100vh - 150px)}
@media(max-width:760px){.reader-search-group{order:3;width:100%;justify-content:center}.reader-search-group input{flex:1;max-width:280px}.farast-docx-frame{height:70vh;min-height:420px}.farast-reader-header{grid-template-columns:auto 1fr;gap:8px}.farast-reader-heading{text-align:right}.farast-reader-account{grid-column:1/-1;justify-content:flex-start;font-size:.68rem}.farast-reader-toolbar{justify-content:center}.reader-tool-wide span{display:none}.farast-reader-workspace{grid-template-columns:1fr}.farast-reader-sidebar{max-height:150px;border-left:0;border-bottom:1px solid var(--reader-line)}.farast-reader-thumbs{display:flex;overflow:auto}.farast-reader-thumb{flex:0 0 112px}.farast-reader-canvas{min-height:60vh;padding:12px 8px 42px}.farast-reader-page-content img,.farast-reader-page-content iframe{min-height:360px;height:70vh}.farast-reader-shortcuts{display:none}.farast-reader-security-note{font-size:.62rem;text-align:center}}
@media(prefers-reduced-motion:reduce){.farast-reader-progress span{transition:none}}
.sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
</style>
@endpush

@push('scripts')
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js" referrerpolicy="no-referrer"></script>
<script src="/js/farast-reader.js?v=20261107phase2"></script>
@endpush
