@extends('layouts.app')
@section('content')
<div id="farastWord" class="farast-editor-app" dir="rtl" lang="fa" data-authenticated="{{ auth()->check() ? '1' : '0' }}" data-project-id="{{ $project?->id ?? '' }}" data-document-id="{{ $document?->id ?? '' }}">
  <header class="farast-appbar">
    <div class="farast-brand"><span class="farast-brand-mark" aria-hidden="true">✦</span><strong>فراست</strong></div>
    <button class="farast-mobile-icon" id="mobileNav" type="button" aria-label="پیمایش سند">☰</button>
    <label class="farast-doc-title"><span class="sr-only">عنوان سند</span><input id="docTitle" value="سند جدید" maxlength="255"></label>
    <div class="farast-save-state" id="saveState" aria-live="polite">آماده</div>@if($project)<div class="farast-save-state" id="projectBillingState">@if(($project->output_state['status'] ?? null) === 'unlocked') خروجی مجاز · {{ ($project->billing_state['status'] ?? null) === 'included' ? 'در سهمیه اشتراک' : 'پرداخت‌شده' }} @else خروجی قفل · {{ number_format((int)($project->billing_state['amount_remaining'] ?? $project->estimated_price_rials ?? 0)) }} ریال باقی‌مانده @endif</div>@endif
    <div class="farast-app-actions"><button id="saveNow" type="button" class="primary">ذخیره</button><a href="{{ route('dashboard') }}">بازگشت</a></div>
  </header>
  <style>
.farast-menubar{display:flex;align-items:center;gap:2px;padding:0 12px;height:38px;background:#fff;border-bottom:1px solid #e2e8f0;overflow-x:auto;white-space:nowrap}
.farast-menu{position:relative}
.farast-menu>button{border:0;background:transparent;border-radius:7px;padding:7px 11px;color:#334155;cursor:pointer;font:inherit}
.farast-menu>button:hover,.farast-menu>button:focus-visible{background:#f1f5f9;outline:none}
.farast-menu-panel{display:none;position:absolute;z-index:80;top:34px;right:0;min-width:210px;padding:6px;background:#fff;border:1px solid #e2e8f0;border-radius:10px;box-shadow:0 16px 32px rgba(15,23,42,.14)}
.farast-menu.is-open .farast-menu-panel{display:grid;gap:2px}
.farast-menu-panel button,.farast-menu-panel a{display:flex;align-items:center;gap:10px;width:100%;border:0;background:transparent;text-decoration:none;color:#1e293b;text-align:right;border-radius:7px;padding:9px 10px;cursor:pointer;font:inherit}
.farast-menu-panel button:hover,.farast-menu-panel a:hover{background:#f8fafc}
.farast-menu-panel .menu-sep{height:1px;background:#e2e8f0;margin:4px 2px}
@media(max-width:800px){.farast-menubar{height:36px}.farast-menu>button{padding:6px 9px;font-size:13px}.farast-menu-panel{position:fixed;top:84px;right:10px;left:10px;min-width:0}}
</style>
<nav class="farast-menubar" aria-label="منوی سند">
 @foreach([
  'file'=>[['saveNow','ذخیره'],['versions','تاریخچه نسخه‌ها'],['exportDocx','خروجی DOCX'],['exportPdf','خروجی PDF']],
  'edit'=>[['undo','واگرد'],['redo','انجام دوباره'],['find','یافتن و جایگزینی']],
  'view'=>[['navigation','پیمایش سند'],['zoomOut','کاهش بزرگ‌نمایی'],['zoomIn','افزایش بزرگ‌نمایی'],['fullscreen','تمام‌صفحه']],
  'insert'=>[['pageBreak','شکست صفحه'],['table','جدول'],['image','تصویر'],['link','پیوند'],['comment','نظر']],
  'format'=>[['normal','عادی'],['h1','عنوان ۱'],['h2','عنوان ۲'],['h3','عنوان ۳'],['bold','پررنگ'],['italic','کج'],['underline','زیرخط'],['strike','خط‌خورده'],['rtl','راست‌به‌چپ'],['ltr','چپ‌به‌راست'],['right','راست‌چین'],['center','وسط‌چین'],['left','چپ‌چین'],['justify','دوطرفه']],
  'tools'=>[['aiPanel','دستیار AI']]
 ] as $menu=>$items)
 <div class="farast-menu">
  <button type="button" aria-haspopup="true" aria-expanded="false">{{ match($menu){'file'=>'فایل','edit'=>'ویرایش','view'=>'نمایش','insert'=>'درج','format'=>'قالب','tools'=>'ابزارها',default=>$menu} }}</button>
  <div class="farast-menu-panel" role="menu">
   @foreach($items as $item)<button type="button" role="menuitem" data-menu-command="{{ $item[0] }}">{{ $item[1] }}</button>@endforeach
  </div>
 </div>
 @endforeach
 <div class="farast-menu"><button type="button" aria-haspopup="true" aria-expanded="false">راهنما</button><div class="farast-menu-panel" role="menu"><a role="menuitem" href="{{ route('support') }}">راهنما و پشتیبانی</a><a role="menuitem" href="{{ route('pricing') }}">قیمت‌گذاری</a></div></div>
</nav>
  <style>
.farast-appbar,.farast-menubar,.farast-tabs,.farast-ribbon{position:relative;z-index:20}
.farast-workspace{position:relative;z-index:1}
.farast-ribbon{min-height:56px;pointer-events:none}
.farast-ribbon *{pointer-events:none}
.farast-ribbon button,.farast-ribbon select,.farast-ribbon input,.farast-ribbon a,[data-command]{pointer-events:auto}
#farastAgentPanel{position:fixed;inset-inline-end:24px;bottom:72px;z-index:2000;pointer-events:none}
#farastAgentPanel.open{pointer-events:auto}
#farastAgentPanel.open *{pointer-events:auto}
</style>
<nav class="farast-tabs" aria-label="نوار فرمان">
    @foreach(['home'=>'خانه','insert'=>'درج','layout'=>'طرح','design'=>'طراحی','review'=>'بازبینی','view'=>'نمایش','ai'=>'هوش مصنوعی'] as $tab=>$label)
      <button type="button" class="farast-tab {{ $loop->first ? 'active' : '' }}" data-tab="{{ $tab }}" @if($tab === 'ai') data-agent-open="1" @endif>{{ $label }}</button>
    @endforeach
  </nav>
  <section class="farast-ribbon" id="ribbon" aria-label="ابزارهای ویرایش"></section>
  <main class="farast-workspace">
    <aside class="farast-nav-panel" id="navigationPane" aria-label="پیمایش سند">
      <div class="panel-heading"><strong>پیمایش</strong><button type="button" data-close-panel="navigationPane" aria-label="بستن">×</button></div>
      <div id="navigationItems"><span class="muted">عنوان‌های سند اینجا نمایش داده می‌شوند.</span></div>
    </aside>
    <section class="farast-canvas-shell">
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
<aside id="farastAgentPanel" dir="rtl" aria-label="عامل سند">
 <div class="farast-agent-head"><strong>عامل سند فراست</strong><button id="farastAgentClose" class="farast-agent-close" type="button" aria-label="بستن">×</button></div>
 <textarea id="farastAgentInput" maxlength="4000" placeholder="مثلاً: این پاراگراف را رسمی‌تر کن"></textarea>
 <div class="farast-agent-actions"><button id="farastAgentRun" class="primary" type="button">تحلیل و پیش‌نمایش</button><button id="farastAgentApprove" type="button" hidden>تأیید عملیات پرریسک</button><button id="farastAgentAccept" type="button" disabled>پذیرش</button><button id="farastAgentReject" type="button">رد</button><button id="farastAgentUndo" type="button">واگرد</button></div>
 <div id="farastAgentStatus" class="farast-agent-status" aria-live="polite">سند فضای اصلی کار است؛ عامل فقط فرمان‌های Kernel را پیشنهاد و اجرا می‌کند.</div>
 <div id="farastAgentPlan" class="farast-agent-plan" hidden></div><div id="farastAgentPreview" class="farast-agent-preview" hidden></div>
</aside>
<input id="source" type="file" hidden accept="image/*,.pdf,.zip">
<div id="findDialog" class="farast-dialog" hidden><div class="dialog-card" role="dialog" aria-modal="true" aria-labelledby="findTitle"><header><strong id="findTitle">یافتن و جایگزینی</strong><button type="button" data-dialog-close>×</button></header><label>یافتن<input id="findInput" autocomplete="off"></label><label>جایگزین با<input id="replaceInput" autocomplete="off"></label><div class="dialog-actions"><button id="findNext" type="button">بعدی</button><button id="replaceOne" type="button">جایگزینی</button><button id="replaceAll" type="button" class="primary">جایگزینی همه</button></div></div></div>
<div id="versionDialog" class="farast-dialog" hidden><div class="dialog-card" role="dialog" aria-modal="true"><header><strong>تاریخچه نسخه‌ها</strong><button type="button" data-dialog-close>×</button></header><div id="versionList"></div></div></div>
<div id="commentDialog" class="farast-dialog" hidden><div class="dialog-card" role="dialog" aria-modal="true"><header><strong>نظر جدید</strong><button type="button" data-dialog-close>×</button></header><textarea id="commentText" rows="5" placeholder="نظر خود را بنویسید"></textarea><div class="dialog-actions"><button id="commentSave" class="primary" type="button">ثبت نظر</button></div></div></div>
@endsection
