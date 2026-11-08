@extends('layouts.app')
@section('content')
@php
  $readerWatermark = trim((string) ($readerIdentity ?? ('کاربر #'.auth()->id())));
@endphp
<section class="farast-reader" dir="rtl" data-reader-root data-theme="dark" data-product-id="{{ $product->id }}" data-reader-mode="{{ $readerMode ?? 'sample' }}" data-reader-audio-url="{{ $readerAudioUrl ?? '' }}" data-reader-audio-timing="{{ json_encode($readerAudioTiming ?? [], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) }}" data-reader-audio-poll-url="{{ $readerAudioPollUrl ?? '' }}" data-reader-audio-status="{{ $readerAudioStatus ?? '' }}" @auth data-reader-state-url="{{ route('store.reader.state.show', $product) }}" @endauth>
  <header class="farast-reader-header">
    <a class="farast-reader-back" href="{{ route('store.product', $product->slug) }}" aria-label="بازگشت به محصول"><i class="fa-solid fa-arrow-right"></i><span>بازگشت</span></a>
    <div class="farast-reader-heading">
      <strong>{{ $product->title }}</strong>
      <span><i class="fa-solid fa-shield-halved"></i> مطالعه امن · {{ $previews->count() }} بخش</span>
    </div>
    <div class="farast-reader-account"><i class="fa-solid fa-user-shield"></i><span>{{ $readerWatermark }}</span></div>
  </header>
  <div class="farast-reader-audio-pending" data-reader-audio-pending role="status" aria-live="polite" hidden></div>

  <nav class="farast-reader-toolbar" aria-label="ابزارهای مطالعه">
    <div class="farast-reader-toolgroup">
      <button type="button" id="reader-prev" class="reader-tool" title="صفحه قبل" aria-label="صفحه قبل"><i class="fa-solid fa-chevron-right"></i></button>
      <label class="reader-page-input"><span class="sr-only">شماره صفحه</span><input id="reader-page-number" type="number" min="1" max="{{ $previews->count() }}" value="1" inputmode="numeric"><span>/ {{ $previews->count() }}</span></label>
      <button type="button" id="reader-next" class="reader-tool" title="صفحه بعد" aria-label="صفحه بعد"><i class="fa-solid fa-chevron-left"></i></button>
      <button type="button" id="reader-resume" class="reader-tool reader-tool-wide" hidden><i class="fa-solid fa-clock-rotate-left"></i><span>ادامه مطالعه</span></button>
    </div>
    <div class="farast-reader-toolgroup">
      <button type="button" id="reader-bookmark-toggle" class="reader-tool reader-tool-wide" aria-pressed="false" title="ثبت نشانک این صفحه"><i class="fa-regular fa-bookmark"></i><span>نشانک</span></button>
      <button type="button" id="reader-bookmarks-open" class="reader-tool reader-tool-wide" aria-expanded="false" aria-controls="reader-bookmarks-panel"><i class="fa-solid fa-book-bookmark"></i><span>نشانک‌ها</span></button>
    </div>
    <div id="reader-bookmarks-panel" class="reader-bookmarks-panel" role="group" aria-label="نشانک‌های ذخیره‌شده" hidden></div>
    <div class="farast-reader-toolgroup">
      <button type="button" id="reader-zoom-out" class="reader-tool" title="کوچک‌نمایی" aria-label="کوچک‌نمایی"><i class="fa-solid fa-minus"></i></button>
      <output id="reader-zoom-label" class="reader-zoom-label">۱۰۰٪</output>
      <button type="button" id="reader-zoom-in" class="reader-tool" title="بزرگ‌نمایی" aria-label="بزرگ‌نمایی"><i class="fa-solid fa-plus"></i></button>
      <button type="button" id="reader-fit" class="reader-tool reader-tool-wide"><i class="fa-solid fa-arrows-left-right-to-line"></i><span>تناسب عرض</span></button>
      <button type="button" id="reader-rotate" class="reader-tool" title="چرخش صفحه" aria-label="چرخش صفحه"><i class="fa-solid fa-rotate"></i></button>
      <button type="button" id="reader-scroll-mode" class="reader-tool reader-tool-wide" aria-pressed="false" title="پیمایش پیوسته"><i class="fa-solid fa-scroll"></i><span>پیوسته</span></button>
    </div>
    <div class="farast-reader-toolgroup reader-search-group">
      <label class="sr-only" for="reader-search-input">جست‌وجو در سند</label>
      <input id="reader-search-input" type="search" placeholder="جست‌وجو در سند…" autocomplete="off">
      <button type="button" id="reader-search" class="reader-tool" aria-label="جست‌وجو"><i class="fa-solid fa-magnifying-glass"></i></button>
      <button type="button" id="reader-search-prev" class="reader-tool" title="نتیجه قبلی" aria-label="نتیجه قبلی"><i class="fa-solid fa-chevron-right"></i></button>
      <button type="button" id="reader-search-next" class="reader-tool" title="نتیجه بعدی" aria-label="نتیجه بعدی"><i class="fa-solid fa-chevron-left"></i></button>
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
      <div class="farast-reader-sidebar-head"><strong>فهرست اسناد</strong><span>{{ $previews->count() }} بخش</span></div>
      <div class="farast-reader-thumbs">
        @foreach($previews as $i => $preview)
          <button type="button" class="farast-reader-thumb {{ $i===0 ? 'is-active' : '' }}" data-goto="{{ $i }}" aria-label="رفتن به بخش {{ $i+1 }}" aria-current="{{ $i===0 ? 'page' : 'false' }}">
            <span class="farast-reader-thumb-preview">
              @if(($preview->kind ?? '') === 'pdf' || str_contains((string)$preview->mime, 'pdf'))
                <i class="fa-solid fa-file-pdf"></i><small>PDF</small>
              @elseif(($preview->kind ?? '') === 'reader_document')
                <i class="fa-solid fa-file-lines" style="color:#4c8dff"></i><small>{{ $preview->page_label ?? 'متن' }}</small>
              @elseif(($preview->kind ?? '') === 'docx')
                <i class="fa-solid fa-file-word"></i><small>Word</small>
              @elseif(in_array(($preview->kind ?? ''), ['xlsx', 'html'], true))
                <i class="fa-solid fa-file-excel" style="color:#27834e"></i><small>Excel</small>
              @else
                <img src="{{ route('store.preview',$preview) }}" alt="" loading="lazy">
              @endif
            </span>
            <span>{{ ($preview->kind ?? '') === 'reader_document' ? ($preview->page_label ?? ('بخش '.($i+1))) : 'بخش '.($preview->page_number ?: $i+1) }}</span>
          </button>
        @endforeach
      </div>
      <section class="farast-reader-outline" id="reader-outline" hidden aria-label="فهرست مطالب PDF">
        <strong>فهرست مطالب</strong>
        <div id="reader-outline-list"></div>
      </section>
    </aside>

    <main class="farast-reader-canvas" id="reader-stage" aria-label="صفحه سند">
      <div class="farast-reader-pages" id="reader-pages">
        @foreach($previews as $i => $preview)
          <article class="farast-reader-page-item {{ $i===0 ? 'is-active' : '' }} {{ in_array(($preview->kind ?? ''), ['pdf', 'docx'], true) || str_contains((string) $preview->mime, 'pdf') ? 'is-pdf' : '' }}" data-index="{{ $i }}" data-watermark="{{ $readerWatermark }}" @if(($preview->kind ?? '') === 'reader_document') data-reader-page="{{ $preview->page_number }}" data-reader-page-url="{{ $preview->reader_page_url }}" @endif @if(($readerMode ?? 'sample') === 'document' && !empty($readerAudioUrl)) data-audio-url="{{ $readerAudioUrl }}" @elseif(!empty($preview->audio_path)) data-audio-url="{{ route('store.preview.audio',$preview) }}" data-audio-timing="{{ json_encode($preview->audio_timing ?? [], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) }}" @endif>
            <div class="farast-reader-page-content">
              @if(($preview->kind ?? '') === 'reader_document')
                <div class="farast-pdf-document farast-reader-document" data-reader-document-page aria-label="محتوای متنی صفحه">
                  <div class="farast-pdf-loading" role="status">در حال دریافت محتوای مجاز این صفحه…</div>
                  <div class="farast-pdf-text-layer" data-reader-text-layer></div>
                </div>
              @elseif(($preview->kind ?? '') === 'pdf' || str_contains((string)$preview->mime, 'pdf'))
                <div class="farast-pdf-document" data-pdf-url="{{ route('store.preview',$preview) }}" aria-label="نمایش سند PDF">
                  <canvas class="farast-pdf-canvas"></canvas>
                  <div class="farast-pdf-text-layer"></div>
                  <div class="farast-pdf-continuous-pages" hidden></div>
                  <div class="farast-pdf-loading" role="status">در حال آماده‌سازی سند PDF…</div>
                </div>
              @elseif(($preview->kind ?? '') === 'docx')
                <div class="farast-pdf-document" data-pdf-url="{{ route('store.preview',$preview) }}" aria-label="نمایش سند Word تبدیل‌شده به PDF">
                  <canvas class="farast-pdf-canvas"></canvas>
                  <div class="farast-pdf-text-layer"></div>
                  <div class="farast-pdf-continuous-pages" hidden></div>
                  <div class="farast-pdf-loading" role="status">در حال آماده‌سازی سند Word…</div>
                </div>
              @elseif(in_array(($preview->kind ?? ''), ['xlsx', 'html'], true))
                <iframe class="farast-xlsx-frame" src="{{ route('store.preview',$preview) }}" title="پیش‌نمایش فایل Excel" sandbox="" loading="lazy" referrerpolicy="no-referrer"></iframe>
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
      <div class="farast-reader-shortcuts"><kbd>←</kbd><kbd>→</kbd> جابه‌جایی صفحه <span>·</span> <kbd>+</kbd><kbd>−</kbd> بزرگ‌نمایی <span>·</span> <kbd>F</kbd> تمام‌صفحه <span>·</span> <kbd>B</kbd> نشانک</div>
    </main>
  </div>
  <div class="farast-reader-security-note"><i class="fa-solid fa-lock"></i><span>شناسهٔ حساب یا IP بازدیدکننده به‌صورت شناور روی محتوای مطالعه نمایش داده می‌شود؛ فایل ذخیره‌شده تغییر نمی‌کند.</span></div>
  <div class="farast-reader-print-block" hidden role="status">این پیش‌نمایش فقط برای مطالعهٔ آنلاین ارائه شده است؛ چاپ و ذخیره‌سازی از محیط خوانشگر غیرفعال است.</div>
</section>
@endsection

@push('styles')
<link rel="stylesheet" href="/css/store.css?v=20261107reader1">
<link rel="stylesheet" href="/vendor/pdfjs/web/pdf_viewer.css?v=6.5.438">
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
.farast-reader-canvas{position:relative;min-width:0;min-height:65vh;padding:24px 16px 48px;overflow:auto;display:flex;justify-content:center;align-items:flex-start;background:radial-gradient(ellipse at top,rgba(80,110,160,.12),transparent 60%),var(--reader-bg)}.farast-reader-pages{width:100%;display:flex;justify-content:center}.farast-reader-page-item{display:none;position:relative;width:min(900px,100%);min-height:60vh;isolation:isolate}.farast-reader-page-item.is-active{display:flex;justify-content:center;align-items:flex-start}.farast-reader-page-content{width:100%;display:flex;justify-content:center;overflow:visible}.farast-reader-page-content img,.farast-reader-page-content iframe{display:block;width:min(100%,900px);height:min(78vh,1000px);min-height:480px;object-fit:contain;border:0;border-radius:5px;background:#fff;box-shadow:0 18px 60px rgba(0,0,0,.32);transform:scale(var(--reader-zoom,1));transform-origin:top center}.farast-reader-page-content .farast-xlsx-frame{width:min(100%,1200px);height:min(78vh,1000px);min-height:620px}.farast-reader-page-content img{height:auto;max-height:calc(100vh - 230px);user-select:none;-webkit-user-drag:none}.farast-reader-page-item.is-pdf .farast-reader-watermark{display:none}.farast-reader-page-item.is-server-watermarked .farast-reader-watermark{display:none}.farast-reader-watermark{position:absolute;inset:0;z-index:3;pointer-events:none;display:grid;grid-template-columns:repeat(2,1fr);grid-template-rows:repeat(4,1fr);align-items:center;justify-items:center;overflow:hidden;opacity:.24;mix-blend-mode:multiply}.farast-reader-watermark span{color:#26364b;background:rgba(255,255,255,.18);border:1px solid rgba(30,50,80,.12);padding:5px 9px;border-radius:4px;font-size:clamp(9px,1.1vw,13px);font-weight:800;white-space:nowrap;transform:rotate(-24deg);text-shadow:0 1px 1px rgba(255,255,255,.6)}.farast-reader-page-caption{position:absolute;bottom:8px;left:12px;z-index:4;color:#334155;background:rgba(255,255,255,.7);padding:3px 7px;border-radius:4px;font-size:9px;pointer-events:none}
.farast-reader-outline{margin-top:16px;padding-top:12px;border-top:1px solid var(--reader-line);font-size:.72rem}.farast-reader-outline strong{display:block;margin-bottom:8px}.farast-reader-outline button{display:block;width:100%;padding:7px 8px;border:0;border-radius:7px;background:transparent;color:var(--reader-muted);text-align:right;font:inherit;cursor:pointer}.farast-reader-outline button:hover{background:rgba(76,141,255,.12);color:var(--reader-text)}.reader-search-group input{width:clamp(120px,18vw,220px);height:36px;padding:7px 10px;border:1px solid var(--reader-line);border-radius:9px;background:transparent;color:var(--reader-text);font:inherit;font-size:.72rem}.reader-search-group input::placeholder{color:var(--reader-muted)}.reader-search-count{font-size:.68rem;color:var(--reader-muted);min-width:42px}.farast-pdf-document{position:relative;width:min(100%,1000px);min-height:60vh;display:flex;justify-content:center;align-items:flex-start;background:#fff;box-shadow:0 18px 60px rgba(0,0,0,.32);overflow:hidden}.farast-pdf-canvas{display:block;max-width:100%;height:auto}.farast-pdf-text-layer{position:absolute;inset:0;overflow:hidden;line-height:1;opacity:1;user-select:none;-webkit-user-select:none;pointer-events:none;z-index:1}.farast-pdf-text-layer span{position:absolute;white-space:pre;color:transparent;transform-origin:0 0;pointer-events:none}.farast-pdf-text-layer span.reader-search-hit{background:rgba(255,210,40,.5)}.farast-pdf-document.is-continuous{display:block;width:100%;min-height:0;background:transparent;box-shadow:none;overflow:visible}.farast-pdf-document.is-continuous .farast-pdf-canvas,.farast-pdf-document.is-continuous .farast-pdf-text-layer{display:none}.farast-pdf-continuous-pages{display:none;width:100%;gap:20px}.farast-pdf-document.is-continuous .farast-pdf-continuous-pages{display:grid}.farast-pdf-cont-page{position:relative;display:grid;place-items:center;width:max-content;max-width:100%;min-height:420px;margin:0 auto 20px;overflow:hidden;background:#fff;box-shadow:0 14px 42px rgba(0,0,0,.28)}.farast-pdf-cont-page canvas{display:block;max-width:100%;height:auto}.farast-pdf-cont-text{position:absolute;inset:0;overflow:hidden;line-height:1;z-index:1;pointer-events:none;user-select:none;-webkit-user-select:none}.farast-pdf-cont-text span{position:absolute;color:transparent;white-space:pre;transform-origin:0 0;pointer-events:none}.farast-pdf-cont-text span.reader-search-hit{background:rgba(255,210,40,.5)}.farast-pdf-cont-watermark{position:absolute;inset:0;z-index:2;display:grid;grid-template-columns:repeat(2,1fr);grid-template-rows:repeat(4,1fr);align-items:center;justify-items:center;pointer-events:none;opacity:.23;overflow:hidden}.farast-pdf-cont-watermark span{font-size:clamp(9px,1vw,13px);font-weight:800;color:#26364b;background:rgba(255,255,255,.2);padding:4px 7px;white-space:nowrap;transform:rotate(-24deg)}.farast-pdf-loading{position:absolute;inset:0;display:grid;place-items:center;background:#fff;color:#475569;font-size:.8rem}.farast-pdf-loading[hidden],.farast-pdf-continuous-pages[hidden]{display:none!important}.farast-reader-shortcuts{position:absolute;bottom:9px;left:50%;transform:translateX(-50%);white-space:nowrap;color:var(--reader-muted);font-size:.65rem;background:var(--reader-panel);border:1px solid var(--reader-line);border-radius:20px;padding:5px 12px}.farast-reader-shortcuts kbd{font:inherit;border:1px solid var(--reader-line);border-radius:4px;padding:1px 4px}.farast-reader-toolbar{position:relative}.reader-bookmarks-panel{display:flex;gap:6px;flex-wrap:wrap;align-items:center;width:100%;padding:8px 12px;border-top:1px solid var(--reader-line);background:var(--reader-panel)}.reader-bookmarks-panel[hidden]{display:none}.reader-bookmarks-panel button{border:1px solid var(--reader-line);border-radius:8px;background:transparent;color:var(--reader-text);padding:6px 10px;font:inherit;font-size:.72rem;cursor:pointer}.reader-bookmarks-panel button:hover,.reader-bookmarks-panel button:focus-visible{background:rgba(90,140,230,.14);outline:2px solid #568cf0;outline-offset:1px}.farast-reader-bookmarks-empty{color:var(--reader-muted);font-size:.72rem}.farast-reader-bookmark-item{display:inline-flex;align-items:center;gap:4px}.reader-bookmarks-panel .farast-reader-bookmark-remove{min-width:28px;padding:4px 7px;color:var(--reader-muted)}.farast-reader-security-note{display:flex;align-items:center;justify-content:center;gap:8px;padding:9px 12px;color:var(--reader-muted);font-size:.68rem;border-top:1px solid var(--reader-line);background:var(--reader-panel)}.farast-reader-security-note i{color:#5c9bff}.farast-reader-print-block{display:none}
.farast-reader.is-sidebar-hidden .farast-reader-workspace{grid-template-columns:minmax(0,1fr)}.farast-reader.is-sidebar-hidden .farast-reader-sidebar{display:none}.farast-reader:fullscreen{min-height:100vh}.farast-reader:fullscreen .farast-reader-canvas{min-height:calc(100vh - 150px)}.farast-reader:fullscreen .farast-reader-sidebar{max-height:calc(100vh - 150px)}
@media(max-width:760px){.reader-search-group{order:3;width:100%;justify-content:center}.reader-search-group input{flex:1;max-width:280px}.farast-reader-header{grid-template-columns:auto 1fr;gap:8px}.farast-reader-heading{text-align:right}.farast-reader-account{grid-column:1/-1;justify-content:flex-start;font-size:.68rem}.farast-reader-toolbar{justify-content:center}.reader-tool-wide span{display:none}.farast-reader-workspace{grid-template-columns:1fr}.farast-reader-sidebar{max-height:150px;border-left:0;border-bottom:1px solid var(--reader-line)}.farast-reader-thumbs{display:flex;overflow:auto}.farast-reader-thumb{flex:0 0 112px}.farast-reader-canvas{min-height:60vh;padding:12px 8px 42px}.farast-reader-page-content img,.farast-reader-page-content iframe{min-height:360px;height:70vh}.farast-reader-shortcuts{display:none}.farast-reader-security-note{font-size:.62rem;text-align:center}}
@media(prefers-reduced-motion:reduce){.farast-reader-progress span{transition:none}}
@media print{body *{visibility:hidden!important}.farast-reader-print-block{display:block!important;visibility:visible!important;position:fixed;inset:0;padding:48px 28px;background:#fff;color:#111;text-align:center;font-size:20px;line-height:2}.farast-reader-print-block *{visibility:visible!important}}
.sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
.farast-reader-document{width:min(100%,900px);min-height:70vh;padding:clamp(22px,5vw,64px);display:block;overflow:visible;border-radius:5px;background:#fff;color:#1f2937;box-shadow:0 18px 60px rgba(0,0,0,.32)}.farast-reader-document .farast-pdf-loading{position:static;min-height:280px}.farast-reader-document .farast-pdf-text-layer{pointer-events:auto;opacity:1}.farast-reader-document .reader-document-line{font-family:inherit;font-size:clamp(14px,1.2vw,17px);margin:0 0 7px;text-align:justify}.farast-reader-document .reader-document-line.is-audio-active{background:rgba(255,216,80,.34);border-radius:3px}.farast-reader-document .reader-document-empty{color:#64748b;font-size:.9rem}.farast-document-html-heading{font-weight:800;color:#64748b;font-size:.78rem;margin-bottom:18px;border-bottom:1px solid #e2e8f0;padding-bottom:8px}.farast-reader-audio-pending{padding:9px 16px;background:rgba(59,130,246,.08);border-bottom:1px solid var(--reader-line);color:var(--reader-muted);font-size:.76rem}.farast-reader-audio-pending[hidden]{display:none}.farast-reader-audio{display:flex;align-items:center;gap:10px;padding:9px 16px;background:var(--reader-panel);border-bottom:1px solid var(--reader-line);color:var(--reader-text);font-size:.72rem}.farast-reader-audio[hidden]{display:none}.farast-reader-audio button{width:36px;height:36px;border:0;border-radius:50%;background:#3478d0;color:#fff;cursor:pointer}.farast-reader-audio input[type=range]{flex:1;min-width:70px;accent-color:#3478d0}.farast-reader-audio select{border:1px solid var(--reader-line);border-radius:8px;background:transparent;color:var(--reader-text);padding:6px}.farast-reader-audio-status{color:var(--reader-muted);white-space:nowrap}.farast-reader-audio [data-audio-current],.farast-reader-audio [data-audio-duration]{font-variant-numeric:tabular-nums;min-width:42px;text-align:center}


.farast-reader-sheet-table-wrap{max-width:100%;overflow:auto;margin:1rem 0;border:1px solid rgba(100,116,139,.25);border-radius:12px;background:#fff;overscroll-behavior:contain;-webkit-overflow-scrolling:touch}.farast-reader-sheet-table{width:max-content;min-width:100%;border-collapse:separate;border-spacing:0;color:#1f2937;font-size:.9rem;direction:rtl}.farast-reader-sheet-table caption{caption-side:top;text-align:right;padding:.8rem 1rem;font-weight:700;color:#334155;background:#f1f5f9}.farast-reader-sheet-table th,.farast-reader-sheet-table td{min-width:105px;max-width:280px;padding:.65rem .8rem;border-left:1px solid #e2e8f0;border-top:1px solid #e2e8f0;text-align:start;vertical-align:top;white-space:pre-wrap;overflow-wrap:anywhere}.farast-reader-sheet-table th{position:sticky;right:0;z-index:1;min-width:48px;background:#e2e8f0;color:#334155;text-align:center;font-variant-numeric:tabular-nums}.farast-reader-sheet-table tr:first-child td{background:#f8fafc;font-weight:600}.farast-reader-sheet-table tr:nth-child(even) td{background:#f8fafc}.farast-reader-sheet-note{font-size:.82rem;color:#64748b;margin:.5rem 0}
</style>
@endpush

@push('scripts')
<script src="/js/farast-reader.js?v=20261108keyboard1"></script>
<script src="/js/farast-reader-personal-tools.js?v=20261108personal1" defer></script>
<script src="/js/farast-reader-audio.js?v=20261108granular3" defer></script>
<script src="/js/farast-reader-document.js?v=20261108reader-pages2" defer></script>
<script src="/js/farast-reader-document-audio.js?v=20261108readiness1" defer></script>
@endpush
