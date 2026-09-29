@extends('layouts.app')
@section('content')
<div id="farastWord" class="farast-editor-app" dir="rtl" lang="fa" data-authenticated="{{ auth()->check() ? '1' : '0' }}">
  <header class="farast-appbar">
    <div class="farast-brand"><span class="farast-brand-mark" aria-hidden="true">✦</span><strong>فراست</strong></div>
    <button class="farast-mobile-icon" id="mobileNav" type="button" aria-label="پیمایش سند">☰</button>
    <label class="farast-doc-title"><span class="sr-only">عنوان سند</span><input id="docTitle" value="سند جدید" maxlength="255"></label>
    <div class="farast-save-state" id="saveState" aria-live="polite">آماده</div>
    <div class="farast-app-actions"><button id="saveNow" type="button" class="primary">ذخیره</button><a href="{{ route('dashboard') }}">بازگشت</a></div>
  </header>
  <nav class="farast-tabs" aria-label="نوار فرمان">
    @foreach(['home'=>'خانه','insert'=>'درج','layout'=>'طرح','design'=>'طراحی','references'=>'مراجع','review'=>'بازبینی','view'=>'نمایش','ai'=>'هوش مصنوعی'] as $tab=>$label)
      <button type="button" class="farast-tab {{ $loop->first ? 'active' : '' }}" data-tab="{{ $tab }}">{{ $label }}</button>
    @endforeach
  </nav>
  <section class="farast-ribbon" id="ribbon" aria-label="ابزارهای ویرایش"></section><div class="farast-advanced-tools" role="toolbar" aria-label="ابزارهای پیشرفته"><button type="button" data-farast-command="track">ردگیری تغییرات</button><button type="button" data-farast-command="accept">پذیرش تغییر</button><button type="button" data-farast-command="reject">رد تغییر</button><button type="button" data-farast-command="footnote">پاورقی</button><button type="button" data-farast-command="bookmark">نشانک</button><button type="button" data-farast-command="watermark">واترمارک</button><button type="button" data-farast-open-comments>نظرات</button><button type="button" data-farast-command="review">بازبینی</button></div>
  <main class="farast-workspace">
    <aside class="farast-nav-panel" id="navigationPane" aria-label="پیمایش سند">
      <div class="panel-heading"><strong>پیمایش</strong><button type="button" data-close-panel="navigationPane" aria-label="بستن">×</button></div>
      <div id="navigationItems"><span class="muted">عنوان‌های سند اینجا نمایش داده می‌شوند.</span></div>
    </aside>
    <section class="farast-canvas-shell">
      <div class="farast-canvas-toolbar"><button id="zoomOut" type="button" aria-label="کاهش بزرگ‌نمایی">−</button><span id="zoomValue">100%</span><button id="zoomIn" type="button" aria-label="افزایش بزرگ‌نمایی">+</button><span class="toolbar-separator"></span><button id="showAi" type="button">دستیار AI</button><button id="showSearch" type="button">جستجو</button><button id="printDocument" type="button">چاپ</button></div>
      <div class="farast-page-viewport" id="pagesViewport" tabindex="0" aria-label="صفحات سند"></div>
    </section>
    <aside class="farast-ai-panel" id="aiSidebar" aria-label="دستیار هوش مصنوعی">
      <div class="panel-heading"><div><strong>دستیار هوشمند</strong><small>انتخاب → پیشنهاد → پیش‌نمایش → پذیرش</small></div><button type="button" data-close-panel="aiSidebar" aria-label="بستن">×</button></div>
      <div class="ai-scope"><span>زمینه</span><select id="aiScope"><option value="selection">متن انتخاب‌شده</option><option value="paragraph">پاراگراف</option><option value="page">صفحه</option><option value="document">سند</option></select></div>
      <div class="ai-actions" id="aiActions"><button data-ai-operation="selection.proofread">اصلاح</button><button data-ai-operation="selection.rewrite">بازنویسی</button><button data-ai-operation="selection.tone">رسمی‌سازی</button><button data-ai-operation="selection.shorten">ساده‌سازی</button><button data-ai-operation="selection.summarize">خلاصه</button><button data-ai-operation="selection.translate">ترجمه</button></div>
      <div id="aiPreview" class="ai-preview" hidden></div><div id="aiStatus" class="ai-status" aria-live="polite">برای شروع، متن را انتخاب کنید.</div>
    </aside>
  </main>
  <footer class="farast-statusbar"><span id="statusText">آماده</span><span>صفحه <b id="currentPage">۱</b> از <b id="totalPages">۱</b></span><span>کلمات <b id="statusWords">۰</b></span><span id="directionStatus">RTL · فارسی</span><span class="status-spacer"></span><button id="exportDocx" type="button">DOCX</button><button id="exportPdf" type="button">PDF</button></footer>
</div>
<input id="source" type="file" hidden accept="image/*,.pdf,.zip">
<div id="findDialog" class="farast-dialog" hidden><div class="dialog-card" role="dialog" aria-modal="true" aria-labelledby="findTitle"><header><strong id="findTitle">یافتن و جایگزینی</strong><button type="button" data-dialog-close>×</button></header><label>یافتن<input id="findInput" autocomplete="off"></label><label>جایگزین با<input id="replaceInput" autocomplete="off"></label><div class="dialog-actions"><button id="findNext" type="button">بعدی</button><button id="replaceOne" type="button">جایگزینی</button><button id="replaceAll" type="button" class="primary">جایگزینی همه</button></div></div></div>
<div id="versionDialog" class="farast-dialog" hidden><div class="dialog-card" role="dialog" aria-modal="true"><header><strong>تاریخچه نسخه‌ها</strong><button type="button" data-dialog-close>×</button></header><div id="versionList"></div></div></div>
<div id="commentDialog" class="farast-dialog" hidden><div class="dialog-card" role="dialog" aria-modal="true"><header><strong>نظر جدید</strong><button type="button" data-dialog-close>×</button></header><textarea id="commentText" rows="5" placeholder="نظر خود را بنویسید"></textarea><div class="dialog-actions"><button id="commentSave" class="primary" type="button">ثبت نظر</button></div></div></div><div id="reviewDialog" class="farast-dialog" hidden><div class="dialog-card" role="dialog" aria-modal="true"><header><strong>بازبینی تغییرات</strong><button type="button" data-dialog-close>×</button></header><div id="reviewList"></div></div></div><div id="commentsDialog" class="farast-dialog" hidden><div class="dialog-card" role="dialog" aria-modal="true"><header><strong>نظرات سند</strong><button type="button" data-dialog-close>×</button></header><div id="commentList"></div></div></div>
<script src="{{ asset('js/editor-tools-real.js') }}" defer></script><script src="{{ asset('js/editor-watermark-view.js') }}" defer></script><script src="{{ asset('js/editor-bookmark-nav.js') }}" defer></script>
@endsection
