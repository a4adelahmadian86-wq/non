@extends('layouts.app')
@section('content')
@php
  $readerWatermark = trim((string) ($readerIdentity ?? ('کاربر #'.auth()->id())));
@endphp
<section class="farast-reader" dir="rtl" data-reader-root data-theme="dark" data-product-id="{{ $product->id }}" data-reader-pdf-engine="{{ !empty($readerPdfEngineReady) ? 'ready' : 'missing' }}" data-reader-mode="{{ $readerMode ?? 'sample' }}" data-reader-audio-url="{{ $readerAudioUrl ?? '' }}" data-reader-audio-timing="{{ json_encode($readerAudioTiming ?? [], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) }}" data-reader-audio-poll-url="{{ $readerAudioPollUrl ?? '' }}" data-reader-audio-status="{{ $readerAudioStatus ?? '' }}" @auth data-reader-state-url="{{ route('store.reader.state.show', $product) }}" @endauth>
  <header class="farast-reader-header">
    <a class="farast-reader-back" href="{{ route('store.product', $product->slug) }}" aria-label="بازگشت به محصول"><i class="fa-solid fa-arrow-right"></i><span>بازگشت</span></a>
    <div class="farast-reader-heading">
      <strong>{{ $product->title }}</strong>
      <span><i class="fa-solid fa-shield-halved"></i> FARAST PDF Studio · مطالعه امن · {{ $previews->count() }} بخش</span>
    </div>
    <div class="farast-reader-account"><i class="fa-solid fa-user-shield"></i><span>{{ $readerWatermark }}</span></div>
  </header>
  <div class="farast-reader-audio-pending" data-reader-audio-pending role="status" aria-live="polite" hidden></div>

  <nav class="farast-reader-toolbar" aria-label="ابزارهای مطالعه">
    <div class="farast-reader-toolgroup">
      <button type="button" id="reader-prev" class="reader-tool" title="صفحه قبل" aria-label="صفحه قبل"><i class="fa-solid fa-chevron-right"></i></button>
      <label class="reader-page-input"><span class="sr-only">شماره صفحه</span><input id="reader-page-number" type="number" min="1" max="{{ $previews->count() }}" value="1" inputmode="numeric"><span>/ {{ $previews->count() }}</span></label>
      <button type="button" id="reader-next" class="reader-tool" title="صفحه بعد" aria-label="صفحه بعد"><i class="fa-solid fa-chevron-left"></i></button>
      <button type="button" id="reader-resume" class="reader-tool reader-tool-wide" aria-label="ادامه مطالعه" title="ادامه مطالعه" hidden><i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i><span class="reader-tool-label">ادامه مطالعه</span></button>
    </div>
    <div class="farast-reader-toolgroup">
      <button type="button" id="reader-bookmark-toggle" class="reader-tool reader-tool-wide" aria-label="ثبت نشانک این صفحه" aria-pressed="false" title="ثبت نشانک این صفحه"><i class="fa-regular fa-bookmark" aria-hidden="true"></i><span class="reader-tool-label">نشانک</span></button>
      <button type="button" id="reader-bookmarks-open" class="reader-tool reader-tool-wide" aria-label="نمایش نشانک‌ها" title="نمایش نشانک‌ها" aria-expanded="false" aria-controls="reader-bookmarks-panel"><i class="fa-solid fa-book-bookmark" aria-hidden="true"></i><span class="reader-tool-label">نشانک‌ها</span></button>
    </div>
    <div id="reader-bookmarks-panel" class="reader-bookmarks-panel" role="group" aria-label="نشانک‌های ذخیره‌شده" hidden></div>
    <div class="farast-reader-toolgroup">
      <button type="button" id="reader-zoom-out" class="reader-tool" title="کوچک‌نمایی" aria-label="کوچک‌نمایی"><i class="fa-solid fa-minus"></i></button>
      <output id="reader-zoom-label" class="reader-zoom-label">۱۰۰٪</output>
      <button type="button" id="reader-zoom-in" class="reader-tool" title="بزرگ‌نمایی" aria-label="بزرگ‌نمایی"><i class="fa-solid fa-plus"></i></button>
      <button type="button" id="reader-fit" class="reader-tool reader-tool-wide" aria-label="تناسب عرض" title="تناسب عرض"><i class="fa-solid fa-arrows-left-right-to-line" aria-hidden="true"></i><span class="reader-tool-label">تناسب عرض</span></button><button type="button" id="reader-fit-page" class="reader-tool reader-tool-wide" aria-label="تناسب صفحه" title="تناسب صفحه"><i class="fa-solid fa-expand" aria-hidden="true"></i><span class="reader-tool-label">تناسب صفحه</span></button>
      <button type="button" id="reader-rotate" class="reader-tool" title="چرخش صفحه" aria-label="چرخش صفحه"><i class="fa-solid fa-rotate"></i></button>
      <button type="button" id="reader-scroll-mode" class="reader-tool reader-tool-wide" aria-label="پیمایش پیوسته" aria-pressed="false" title="پیمایش پیوسته"><i class="fa-solid fa-scroll" aria-hidden="true"></i><span class="reader-tool-label">پیوسته</span></button>
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
      <button type="button" id="reader-thumbs-toggle" class="reader-tool reader-tool-wide" aria-label="پنهان‌کردن فهرست صفحات" title="پنهان‌کردن فهرست صفحات" aria-expanded="true"><i class="fa-solid fa-table-cells" aria-hidden="true"></i><span class="reader-tool-label">تصاویر صفحات</span></button>
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
      <section class="farast-reader-layers" id="reader-layers" hidden aria-label="لایه‌های اختیاری PDF">
        <strong>لایه‌های سند</strong>
        <div id="reader-layers-list"></div>
      </section>
    </aside>

    <main class="farast-reader-canvas" id="reader-stage" aria-label="صفحه سند">
      <div class="farast-reader-pages" id="reader-pages">
        @foreach($previews as $i => $preview)
          <article class="farast-reader-page-item {{ $i===0 ? 'is-active' : '' }} {{ in_array(($preview->kind ?? ''), ['pdf', 'docx'], true) || str_contains((string) $preview->mime, 'pdf') ? 'is-pdf' : '' }}" data-index="{{ $i }}" data-watermark="{{ $readerWatermark }}" @if(($preview->kind ?? '') === 'reader_document') data-reader-page="{{ $preview->page_number }}" data-reader-page-url="{{ $preview->reader_page_url }}" @endif @if(($readerMode ?? 'sample') === 'document' && !empty($readerAudioUrl)) data-audio-url="{{ $readerAudioUrl }}" @elseif(!empty($preview->audio_path)) data-audio-url="{{ route('store.preview.audio',$preview) }}" data-audio-timing="{{ json_encode($preview->audio_timing ?? [], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) }}" @endif>
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
                  <div class="farast-pdf-annotation-layer annotationLayer"></div>
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
  @include('shared.reader-discussions', ['readerDiscussions' => $readerDiscussions, 'product' => $product])
  <div class="farast-reader-security-note"><i class="fa-solid fa-lock"></i><span>شناسهٔ حساب یا IP بازدیدکننده به‌صورت شناور روی محتوای مطالعه نمایش داده می‌شود؛ فایل ذخیره‌شده تغییر نمی‌کند.</span></div>
  <div class="farast-reader-print-block" hidden role="status">این پیش‌نمایش فقط برای مطالعهٔ آنلاین ارائه شده است؛ چاپ و ذخیره‌سازی از محیط خوانشگر غیرفعال است.</div>
</section>
@endsection

@push('styles')
<link rel="stylesheet" href="/css/store.css?v=20261107reader1">
<link rel="stylesheet" href="/vendor/pdfjs/legacy/web/pdf_viewer.css?v=6.5.438">
<link rel="stylesheet" href="/css/farast-reader.css?v=20261115shortcutguide1">
@endpush

@push('scripts')
<script src="/js/farast-reader-storage.js?v=20261111durable4"></script>
<script src="/js/farast-reader.js?v=20261114ocg1"></script>
<script src="/js/farast-reader-personal-tools.js?v=20261111prefs1" defer></script>
<script src="/js/farast-reader-audio.js?v=20261116stop1" defer></script>
<script src="/js/farast-reader-document.js?v=20261108reader-pages2" defer></script>
<script src="/js/farast-reader-document-audio.js?v=20261116timing1" defer></script>
<script src="/js/farast-reader-annotations.js?v=20261111compact1" defer></script>
<script src="/js/farast-reader-studio.js?v=20261111compact2" defer></script>
<script src="/js/farast-reader-ink-pressure.js?v=20261110durable4" defer></script>
<script src="/js/farast-reader-help.js?v=20261115shortcuts1" defer></script>
@endpush
