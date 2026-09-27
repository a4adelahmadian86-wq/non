@extends('layouts.app')
@section('content')
<div class="word-app" id="farastWord" dir="rtl" data-authenticated="{{ auth()->check() ? '1' : '0' }}">
  <header class="word-titlebar">
    <div class="word-brand"><span class="word-mark" aria-hidden="true">✦</span><div><strong>فراست</strong><small>ویرایشگر هوشمند تایپ</small></div></div>
    <div class="doc-title"><input id="docTitle" value="سند جدید" aria-label="عنوان سند"><span id="saveState">ذخیره نشده</span></div>
    <div class="title-actions"><button type="button" id="saveNow" class="title-btn">ذخیره</button><a href="/dashboard" class="title-btn">بازگشت</a></div>
  </header>

  <nav class="word-tabs" aria-label="نوار ابزار اصلی">
    <button class="word-tab active" data-tab="home">خانه</button>
    <button class="word-tab" data-tab="insert">درج</button>
    <button class="word-tab" data-tab="layout">طرح</button>
    <button class="word-tab" data-tab="design">طراحی</button>
    <button class="word-tab" data-tab="references">مراجع</button>
    <button class="word-tab" data-tab="review">بازبینی</button>
    <button class="word-tab" data-tab="view">نمایش</button>
    <button class="word-tab ai-tab" data-tab="ai">هوش مصنوعی</button>
  </nav>

  <section class="ribbon" id="ribbon-home">
    <div class="ribbon-group" data-group="کلیپ‌بورد">
      <button type="button" data-cmd="cut" data-tip="برش">برش</button>
      <button type="button" id="pasteText" data-tip="چسباندن متن از کلیپ‌بورد">چسباندن</button>
      <button type="button" data-cmd="copy" data-tip="کپی متن انتخاب‌شده">کپی</button>
      <button type="button" data-cmd="selectAll" data-tip="انتخاب همه متن">انتخاب همه</button>
    </div>
    <div class="ribbon-group" data-group="تاریخچه">
      <button type="button" data-cmd="undo" data-tip="واگرد آخرین تغییر">↶</button>
      <button type="button" data-cmd="redo" data-tip="انجام دوباره تغییر">↷</button>
      <button type="button" data-cmd="removeFormat" data-tip="حذف قالب‌بندی متن">پاک‌کردن قالب</button>
    </div>
    <div class="ribbon-group" data-group="قلم">
      <select id="fontName" aria-label="قلم"><option value="Vazirmatn">Vazirmatn</option><option value="B Nazanin">B Nazanin</option><option value="B Titr">B Titr</option><option value="B Mitra">B Mitra</option><option value="Tahoma">Tahoma</option><option value="Arial">Arial</option></select>
      <select id="fontSize" aria-label="اندازه قلم"><option value="12">12</option><option value="14">14</option><option value="16" selected>16</option><option value="18">18</option><option value="20">20</option><option value="24">24</option><option value="28">28</option><option value="32">32</option><option value="36">36</option><option value="48">48</option></select>
      <button type="button" data-cmd="bold" data-tip="پررنگ">ن</button><button type="button" data-cmd="italic" data-tip="کج">ک</button><button type="button" data-cmd="underline" data-tip="زیرخط">ز</button><button type="button" data-cmd="strikeThrough" data-tip="خط‌خورده">abc</button>
      <button type="button" data-cmd="superscript" data-tip="بالانویس">x²</button><button type="button" data-cmd="subscript" data-tip="پایین‌نویس">x₂</button>
      <label class="ribbon-label" data-tip="رنگ قلم"><i class="fa-solid fa-font"></i><input id="fontColor" type="color" value="#202b3a" aria-label="رنگ قلم"></label>
      <label class="ribbon-label" data-tip="رنگ پس‌زمینه متن"><i class="fa-solid fa-highlighter"></i><input id="highlightColor" type="color" value="#fff2a8" aria-label="رنگ برجسته‌سازی"></label>
    </div>
    <div class="ribbon-group" data-group="پاراگراف">
      <button type="button" data-cmd="justifyRight" data-tip="تراز راست">≡→</button><button type="button" data-cmd="justifyCenter" data-tip="تراز وسط">≡↔</button><button type="button" data-cmd="justifyLeft" data-tip="تراز چپ">←≡</button><button type="button" data-cmd="justifyFull" data-tip="تراز دوطرفه">☰</button>
      <button type="button" data-cmd="insertUnorderedList" data-tip="فهرست نشانه‌دار">•☰</button><button type="button" data-cmd="insertOrderedList" data-tip="فهرست شماره‌دار">1☰</button><button type="button" data-cmd="indent" data-tip="افزایش تورفتگی">⇥</button><button type="button" data-cmd="outdent" data-tip="کاهش تورفتگی">⇤</button>
      <button type="button" id="lineSpacing" data-tip="فاصله خطوط">↕</button>
    </div>
    <div class="ribbon-group" data-group="سبک‌ها">
      <button type="button" data-style="p" data-tip="متن عادی">متن عادی</button><button type="button" data-style="h1" data-tip="عنوان سطح یک">عنوان ۱</button><button type="button" data-style="h2" data-tip="عنوان سطح دو">عنوان ۲</button><button type="button" data-style="h3" data-tip="عنوان سطح سه">عنوان ۳</button><button type="button" data-style="blockquote" data-tip="نقل‌قول">نقل‌قول</button>
    </div>
    <div class="ribbon-group" data-group="ویرایش و تایپ">
      <button type="button" id="findText" data-tip="یافتن در سند">یافتن</button><button type="button" id="replaceText" data-tip="یافتن و جایگزینی">جایگزینی</button><button type="button" id="mic" data-tip="تایپ صوتی فارسی">🎙 صوت</button>
      <label class="ribbon-file" data-tip="باز کردن تصویر، PDF یا ZIP">باز کردن فایل<input id="source" type="file" accept="image/*,.pdf,.zip"></label><button type="button" id="analyze" class="ai-run" disabled data-tip="پردازش فایل با هوش مصنوعی">تایپ با AI</button>
    </div>
  </section>

  <section class="ribbon hidden" id="ribbon-insert">
    <div class="ribbon-group" data-group="صفحه">
      <button type="button" id="pageBreak" class="ribbon-large" data-tip="ایجاد صفحه جدید"><i class="fa-regular fa-file-lines"></i><span>صفحه جدید</span></button><button type="button" data-cmd="insertHorizontalRule" data-tip="خط افقی">خط افقی</button>
    </div>
    <div class="ribbon-group" data-group="جدول و عناصر">
      <button type="button" id="insertTable" class="ribbon-large" data-tip="درج جدول"><i class="fa-solid fa-table-cells"></i><span>جدول</span></button><button type="button" id="insertSymbol" data-tip="درج نماد">Ω نماد</button><button type="button" id="insertDate" data-tip="درج تاریخ امروز">تاریخ</button><button type="button" id="insertTime" data-tip="درج تاریخ و ساعت">ساعت</button>
    </div>
    <div class="ribbon-group" data-group="پیوند">
      <button type="button" id="insertLink" data-tip="ایجاد پیوند">پیوند</button><button type="button" id="clearLink" data-tip="حذف پیوند">حذف پیوند</button>
    </div>
    <div class="ribbon-group" data-group="پاورقی و نشانه">
      <button type="button" id="insertFootnoteInline" data-tip="درج پاورقی">پاورقی</button><button type="button" id="insertBookmark" data-tip="نشانه‌گذاری محل">نشانه</button>
    </div>
  </section>

  <section class="ribbon hidden" id="ribbon-layout">
    <div class="ribbon-group" data-group="تنظیم صفحه">
      <label class="ribbon-label">اندازه <select id="paperSize"><option value="A4" selected>A4</option><option value="A5">A5</option><option value="Letter">Letter</option></select></label>
      <label class="ribbon-label">جهت <select id="orientation"><option value="portrait" selected>عمودی</option><option value="landscape">افقی</option></select></label>
      <label class="ribbon-label">حاشیه <select id="margin"><option value="normal" selected>عادی</option><option value="narrow">کم</option><option value="wide">زیاد</option></select></label>
    </div>
    <div class="ribbon-group" data-group="ستون و شکست">
      <label class="ribbon-label">ستون <select id="columns"><option value="1" selected>یک</option><option value="2">دو</option><option value="3">سه</option></select></label><button type="button" id="layoutPageBreak" data-tip="درج شکست صفحه">شکست صفحه</button><button type="button" id="direction" data-tip="تغییر جهت نوشتار">راست به چپ</button>
    </div>
  </section>

  <section class="ribbon hidden" id="ribbon-design">
    <div class="ribbon-group" data-group="پس‌زمینه صفحه">
      <label class="ribbon-label" data-tip="رنگ صفحه"><i class="fa-solid fa-fill-drip"></i><input id="pageColor" type="color" value="#ffffff" aria-label="رنگ صفحه"></label><button type="button" id="clearPageColor" data-tip="حذف رنگ صفحه">بدون رنگ</button>
    </div>
    <div class="ribbon-group" data-group="کادر صفحه"><button type="button" data-border="none">بدون کادر</button><button type="button" data-border="simple">کادر ساده</button><button type="button" data-border="double">کادر دوخط</button></div>
    <div class="ribbon-group" data-group="قالب سند"><button type="button" id="resetPageStyle" data-tip="بازگردانی ظاهر صفحه">بازگردانی صفحه</button></div>
  </section>

  <section class="ribbon hidden" id="ribbon-references">
    <div class="ribbon-group" data-group="فهرست مطالب"><button type="button" id="insertToc" class="ribbon-large" data-tip="ساخت فهرست از عنوان‌های سند"><i class="fa-solid fa-list-ol"></i><span>فهرست مطالب</span></button></div>
    <div class="ribbon-group" data-group="پاورقی"><button type="button" id="insertFootnote" class="ribbon-large" data-tip="افزودن پاورقی"><i class="fa-solid fa-note-sticky"></i><span>پاورقی</span></button></div>
    <div class="ribbon-group" data-group="مرجع سریع"><button type="button" id="insertCitation" data-tip="درج ارجاع متنی">ارجاع</button><button type="button" id="insertCaption" data-tip="افزودن عنوان زیر شکل یا جدول">عنوان شکل/جدول</button></div>
  </section>

  <section class="ribbon hidden" id="ribbon-review">
    <div class="ribbon-group" data-group="بازبینی متن"><button type="button" id="wordStats" data-tip="آمار دقیق سند">آمار سند</button><button type="button" id="spellReview" data-tip="بررسی واژه‌های مشکوک">بررسی واژه‌ها</button><button type="button" id="feedbackGood">خروجی خوب بود</button><button type="button" id="feedbackBad">این خروجی اشتباه است</button></div>
    <div class="ribbon-group" data-group="بازخورد AI"><button type="button" id="feedbackPanelBtn">ثبت بازخورد</button><span class="review-hint">اصلاحات کاربر بدون تغییر خودکار محتوای سند ثبت می‌شوند.</span></div>
  </section>

  <section class="ribbon hidden" id="ribbon-view">
    <div class="ribbon-group" data-group="بزرگ‌نمایی"><button type="button" data-zoom=".7">۷۰٪</button><button type="button" data-zoom=".85">۸۵٪</button><button type="button" data-zoom="1">۱۰۰٪</button><button type="button" data-zoom="1.15">۱۱۵٪</button><button type="button" data-zoom="1.3">۱۳۰٪</button></div>
    <div class="ribbon-group" data-group="پنل‌ها"><button type="button" id="toggleAiPanel">پنل AI</button><button type="button" id="toggleNavigation">پنل پیمایش</button><button type="button" id="toggleGuides">خطوط راهنما</button></div>
    <div class="ribbon-group" data-group="نمایش"><button type="button" id="toggleFullscreen">تمام‌صفحه</button><button type="button" id="focusMode">حالت تمرکز</button><button type="button" id="resetZoom">بازنشانی نمایش</button></div>
  </section>

  <section class="ribbon hidden" id="ribbon-ai">
    <div class="ribbon-group" data-group="وضعیت هوش مصنوعی"><span class="status-dot"></span><b>Gemini / OCR</b><span id="aiStatus">آماده</span></div>
    <div class="ribbon-group" data-group="تعامل"><span>شناسه:</span><code id="interactionId">—</code><span>درخواست:</span><code id="requestId">—</code></div>
    <div class="ribbon-group" data-group="عملیات"><button type="button" id="aiCorrect">بررسی با AI</button><button type="button" id="aiRecount">تحلیل دوباره</button><button type="button" id="feedbackPanelBtn2">ثبت بازخورد</button></div>
  </section>

  <main class="word-body">
    <aside class="ai-sidebar" id="aiSidebar">
      <div class="side-head"><div><strong>دستیار تایپ</strong><small>OCR • Gemini • بازخورد</small></div><button id="closeAi" type="button" aria-label="بستن پنل">×</button></div>
      <div class="source-card"><div class="source-icon">AI</div><div><b id="sourceName">هنوز فایلی انتخاب نشده</b><small id="sourceMeta">فایل را بکشید و رها کنید یا از «باز کردن فایل» استفاده کنید.</small></div></div>
      <div class="ai-stats"><div><small>صفحه</small><b id="pages">۰</b></div><div><small>کلمه</small><b id="words">۰</b></div><div><small>هزینه</small><b id="price">۰ ریال</b></div></div>
      <div class="ai-notice"><b>تست رایگان</b><p>برآورد قبل از OCR انجام می‌شود و اعتبار رایگان در مرحله تحلیل مصرف نمی‌شود.</p></div>
      <div id="issues" class="issues"></div>
      <div class="feedback-box"><b>کیفیت خروجی</b><p>اگر بخشی اشتباه بود همان بخش را اصلاح کنید تا به‌عنوان بازخورد ثبت شود.</p><div class="feedback-actions"><button id="rate5">✓ درست</button><button id="rate1">✕ اشتباه</button></div></div>
    </aside>
    <aside class="navigation-pane hidden" id="navigationPane"><strong>پیمایش سند</strong><div id="navigationItems"></div></aside>
    <section class="document-area" id="documentArea">
      <div class="document-toolbar"><span>نمایش</span><button id="zoomOut" type="button">−</button><span id="zoomValue">100%</span><button id="zoomIn" type="button">+</button><span class="divider"></span><button id="showAi" type="button">پنل هوش مصنوعی</button><span class="autosave" id="autosave">ذخیره خودکار فعال</span></div>
      <div class="upload-zone" id="dropZone"><div class="upload-zone-icon">↑</div><strong>فایل را اینجا بکشید و رها کنید</strong><span>تصویر، PDF یا ZIP تصاویر — تشخیص فرمت خودکار است</span><button type="button" id="chooseFile">انتخاب فایل</button></div>
      <div class="pages-viewport" id="pagesViewport"><div class="word-page" data-page="1"><div id="editor" class="word-editor" contenteditable="true" spellcheck="false" dir="rtl" lang="fa"><p><br></p></div></div></div>
    </section>
  </main>

  <footer class="word-status"><span id="statusText">آماده</span><span>صفحه <b id="currentPage">۱</b> از <b id="totalPages">۱</b></span><span>کلمات <b id="statusWords">۰</b></span><span>زبان: فارسی</span><button id="exportDocx" type="button">Word</button><button id="exportPdf" type="button">PDF</button></footer>
</div>

<div class="ai-popover hidden" id="aiPopover"><b>واژه مشکوک</b><div id="aiWord"></div><div id="aiSuggestions"></div><button id="keepWord" type="button">نگه‌داشتن واژه فعلی</button></div>
<div class="find-dialog hidden" id="findDialog"><div class="dialog-card"><button class="dialog-close" data-close="findDialog" type="button">×</button><h3>یافتن و جایگزینی</h3><label>یافتن<input id="findInput" autocomplete="off"></label><label>جایگزین با<input id="replaceInput" autocomplete="off"></label><div><button id="findNext" type="button">بعدی</button><button id="replaceOne" type="button">جایگزینی</button><button id="replaceAll" type="button">جایگزینی همه</button></div></div></div>
<div class="editor-preflight-modal" id="editorPreflightModal" hidden><div class="editor-preflight-card" role="dialog" aria-modal="true"><button type="button" class="editor-preflight-close" id="preflightClose">×</button><h3>برآورد پیش از شروع تایپ</h3><p id="editorPreflightLead"></p><div id="editorPreflightLines" class="editor-preflight-lines"></div><div class="editor-preflight-actions" id="editorPreflightActions"><button type="button" class="editor-preflight-accept" id="editorPreflightAccept">تأیید و ادامه</button><button type="button" class="editor-preflight-decline" id="editorPreflightDecline">فعلاً ادامه نمی‌دهم</button></div><div class="editor-preflight-message" id="editorPreflightMessage" hidden></div></div></div>
<div class="farast-context-menu hidden" id="farastContextMenu" role="menu"><button type="button" data-context="undo">واگرد</button><button type="button" data-context="redo">انجام دوباره</button><hr><button type="button" data-context="cut">برش</button><button type="button" data-context="copy">کپی</button><button type="button" data-context="paste">چسباندن</button><button type="button" data-context="selectAll">انتخاب همه</button><hr><button type="button" data-context="bold">پررنگ</button><button type="button" data-context="italic">کج</button><button type="button" data-context="underline">زیرخط</button><button type="button" data-context="link">ایجاد پیوند</button></div>
@endsection

@push('scripts')
<script>
window.FARAST_EDITOR=true;window.FARAST_AUTHENTICATED={{ auth()->check() ? 'true' : 'false' }};
(()=>{
'use strict';
const $=s=>document.querySelector(s),$$=s=>[...document.querySelectorAll(s)],csrf=$('meta[name="csrf-token"]')?.content||'',editor=$('#editor'),source=$('#source'),analyze=$('#analyze'),dropZone=$('#dropZone');
let docId=null,interactionId=null,requestId=null,saveTimer=null,zoom=1,uploadedPath=null,uploadedMime=null,uploadedName=null,preflightBusy=false;
const nf=n=>new Intl.NumberFormat('fa-IR').format(Number(n||0));
const esc=s=>String(s??'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));
function setStatus(t,ok=false){$('#statusText').textContent=t;$('#saveState').textContent=ok?'ذخیره شد':t}
function api(url,opts={}){opts.headers={...(opts.headers||{}),'X-CSRF-TOKEN':csrf,'Accept':'application/json'};return fetch(url,opts).then(async r=>{let j={};try{j=await r.json()}catch{}if(!r.ok){const e=new Error(j.message||'خطا در ارتباط با سرور');e.status=r.status;throw e}return j})}
function fireInput(){editor.dispatchEvent(new Event('input',{bubbles:true}))}
function cmd(c,v=null){editor.focus();document.execCommand(c,false,v);fireInput();scheduleSave()}
$$('[data-cmd]').forEach(b=>b.addEventListener('click',()=>cmd(b.dataset.cmd)));
$('#fontName').addEventListener('change',e=>cmd('fontName',e.target.value));$('#fontSize').addEventListener('change',e=>{editor.focus();document.execCommand('fontSize',false,7);document.queryCommandState('fontSize');const sel=window.getSelection();if(sel&&sel.rangeCount){const r=sel.getRangeAt(0);if(!r.collapsed){const span=document.createElement('span');span.style.fontSize=e.target.value+'px';span.appendChild(r.extractContents());r.insertNode(span);sel.removeAllRanges();sel.addRange(r)}}fireInput();scheduleSave()});
$('#fontColor').addEventListener('input',e=>cmd('foreColor',e.target.value));$('#highlightColor').addEventListener('input',e=>cmd('hiliteColor',e.target.value));
$$('[data-style]').forEach(b=>b.addEventListener('click',()=>cmd('formatBlock','<'+b.dataset.style+'>')));
$('#lineSpacing').addEventListener('click',()=>{editor.style.lineHeight=editor.style.lineHeight==='1.15'?'1.5':'1.15';fireInput();scheduleSave()});
function showRibbon(name){$$('.word-tab').forEach(x=>x.classList.toggle('active',x.dataset.tab===name));$$('.ribbon').forEach(x=>x.classList.add('hidden'));$('#ribbon-'+name)?.classList.remove('hidden');if(name==='references')refreshNavigation()}
$$('.word-tab').forEach(tab=>tab.addEventListener('click',()=>showRibbon(tab.dataset.tab)));
function setSourceState(name,mime,size){uploadedName=name;uploadedMime=mime;$('#sourceName').textContent=name||'فایل آماده است';$('#sourceMeta').textContent=(size?((size/1048576).toFixed(2)+' MB • '):'')+'آماده پردازش';dropZone.classList.add('has-file');analyze.disabled=false;analyze.textContent=window.FARAST_AUTHENTICATED?'تایپ با AI':'ورود و ادامه تایپ'}
async function uploadFile(file){setStatus('در حال آماده‌سازی فایل…');dropZone.classList.add('busy');const fd=new FormData();fd.append('source',file);try{const up=await fetch('/editor/upload',{method:'POST',headers:{'X-CSRF-TOKEN':csrf,'Accept':'application/json'},body:fd});let j={};try{j=await up.json()}catch{}if(!up.ok)throw new Error(j.message||'آپلود ناموفق بود');uploadedPath=j.path;uploadedMime=j.mime;uploadedName=j.name;setSourceState(j.name,j.mime,file.size);setStatus(window.FARAST_AUTHENTICATED?'فایل آماده پردازش است':'فایل نگه‌داری شد؛ برای شروع وارد شوید',true)}catch(e){setStatus(e.message);alert(e.message)}finally{dropZone.classList.remove('busy')}}
source.addEventListener('change',()=>{if(source.files[0])uploadFile(source.files[0])});$('#chooseFile').addEventListener('click',()=>source.click());
['dragenter','dragover'].forEach(ev=>dropZone.addEventListener(ev,e=>{e.preventDefault();dropZone.classList.add('dragover')}));['dragleave','drop'].forEach(ev=>dropZone.addEventListener(ev,e=>{e.preventDefault();dropZone.classList.remove('dragover')}));dropZone.addEventListener('drop',e=>{if(e.dataTransfer.files[0])uploadFile(e.dataTransfer.files[0])});
async function restorePending(){try{const j=await api('/editor/pending');if(j.pending){uploadedPath=j.pending.path;uploadedMime=j.pending.mime;uploadedName=j.pending.name;setSourceState(j.pending.name,j.pending.mime);setStatus(window.FARAST_AUTHENTICATED?'فایل قبلی شما آماده ادامه است':'فایل شما حفظ شده است',true)}}catch(e){}}
restorePending();
function money(rials){return new Intl.NumberFormat('fa-IR').format(Math.round(Number(rials||0)/10))+' تومان'}
function openPreflight(q){const m=$('#editorPreflightModal');$('#editorPreflightLead').textContent='حدود '+nf(q.pages)+' صفحه شناسایی شد.';let h='<div class="editor-preflight-line"><span>برآورد اولیه</span><strong>'+money(q.estimate_rials)+'</strong></div>';if(q.discount_rials>0)h+='<div class="editor-preflight-line discount"><span>اعتبار هفتگی فراست</span><strong>− '+money(q.discount_rials)+'</strong></div>';h+='<div class="editor-preflight-line"><span>برآورد پس از اعتبار</span><strong>'+money(q.payable_estimate_rials)+'</strong></div>';if(q.deposit_rials>0)h+='<div class="editor-preflight-line deposit"><span>مبلغ لازم برای شروع سفارش</span><strong>'+money(q.deposit_rials)+'</strong></div>';$('#editorPreflightLines').innerHTML=h;$('#editorPreflightAccept').textContent=q.deposit_rials>0?'پرداخت و ادامه':'تأیید و ادامه';$('#editorPreflightMessage').hidden=true;$('#editorPreflightActions').hidden=false;m.hidden=false}
async function startPreflight(){if(preflightBusy)return;if(!window.FARAST_AUTHENTICATED){location.href='/login?continue='+encodeURIComponent('/editor');return}preflightBusy=true;try{const q=await api('/editor/preflight/estimate');if(q.mode==='accepted'||q.mode==='free'){const a=await api('/editor/preflight/accept',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({accept:true})});if(a.action==='deposit'&&a.checkout_url){location.href=a.checkout_url;return}await actualAnalyze();return}openPreflight(q)}catch(e){if(e.status===401)location.href='/login?continue='+encodeURIComponent('/editor');else alert(e.message)}finally{preflightBusy=false}}
async function actualAnalyze(){if(!uploadedPath)return;setStatus('در حال ارسال به Gemini…');$('#aiStatus').textContent='در حال پردازش';analyze.disabled=true;try{const j=await api('/editor/analyze',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({path:uploadedPath,mime:uploadedMime,source_name:uploadedName})});docId=j.document_id;interactionId=j.interaction_id;requestId=j.request_id;$('#interactionId').textContent=interactionId||'—';$('#requestId').textContent=requestId||'—';$('#aiStatus').textContent='تکمیل شد';renderAi(j);setStatus('رونویسی کامل شد',true);await saveNow()}catch(e){$('#aiStatus').textContent='خطا';setStatus(e.message);alert(e.message)}finally{analyze.disabled=!uploadedPath}}
analyze.addEventListener('click',async()=>{if(!uploadedPath){if(source.files[0])await uploadFile(source.files[0]);else return}await startPreflight()});
$('#editorPreflightAccept').addEventListener('click',async()=>{const b=$('#editorPreflightAccept');b.disabled=true;try{const a=await api('/editor/preflight/accept',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({accept:true})});if(a.action==='deposit'&&a.checkout_url){location.href=a.checkout_url;return}$('#editorPreflightModal').hidden=true;await actualAnalyze()}catch(e){alert(e.message)}finally{b.disabled=false}});
$('#editorPreflightDecline').addEventListener('click',async()=>{try{const j=await api('/editor/preflight/decline',{method:'POST'});$('#editorPreflightActions').hidden=true;$('#editorPreflightMessage').hidden=false;$('#editorPreflightMessage').textContent=j.message||'فعلاً ادامه ندادیم.'}catch(e){alert(e.message)}});$('#preflightClose').addEventListener('click',()=>$('#editorPreflightModal').hidden=true);
function renderAi(j){editor.innerHTML=j.html||'<p><br></p>';$('#pages').textContent=nf(j.pages);$('#words').textContent=nf((j.text||'').trim().split(/\s+/u).filter(Boolean).length);$('#statusWords').textContent=$('#words').textContent;$('#price').textContent=nf(j.price)+' ریال';renderIssues(j.issues||[]);bindUncertain();updatePages();refreshNavigation();dropZone.classList.add('hidden')}
function renderIssues(xs){$('#issues').innerHTML=xs.length?xs.map(x=>'<div class="issue-card"><mark>'+esc(x.word)+'</mark><div>'+(x.suggestions||[]).map(s=>'<button type="button" data-word="'+esc(x.word)+'" data-s="'+esc(s)+'">'+esc(s)+'</button>').join('')+'</div></div>').join(''):'<div class="empty-issues">مورد مشکوکی ثبت نشده است.</div>';$$('.issue-card button').forEach(b=>b.addEventListener('click',()=>replaceUncertainValue(b.dataset.word,b.dataset.s)))}
function bindUncertain(){$$('.ai-uncertain').forEach(el=>el.addEventListener('click',e=>{e.stopPropagation();$('#aiWord').textContent=el.dataset.original;let ss=[];try{ss=JSON.parse(el.dataset.suggestions||'[]')}catch{}$('#aiSuggestions').innerHTML=ss.map(s=>'<button type="button" data-s="'+esc(s)+'">'+esc(s)+'</button>').join('');$$('#aiSuggestions button').forEach(b=>b.addEventListener('click',()=>applySuggestion(el,b.dataset.s)));$('#aiPopover').classList.remove('hidden');const r=el.getBoundingClientRect();$('#aiPopover').style.top=(r.bottom+8)+'px';$('#aiPopover').style.left=Math.max(10,r.left-120)+'px'}))}
function applySuggestion(el,s){const original=el.dataset.original;el.replaceWith(document.createTextNode(s));sendFeedback('word_correction',original,s,'ocr_uncertain');$('#aiPopover').classList.add('hidden');fireInput()}
function replaceUncertainValue(word,s){const el=$$('.ai-uncertain').find(x=>x.dataset.original===word);if(el)applySuggestion(el,s)}
$('#keepWord').addEventListener('click',()=>$('#aiPopover').classList.add('hidden'));document.addEventListener('click',e=>{if(!e.target.closest('#aiPopover')&&!e.target.closest('.ai-uncertain'))$('#aiPopover').classList.add('hidden')});
function sendFeedback(type,original='',corrected='',category='',note='',rating=null){if(!docId)return;api('/editor/feedback',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({document_id:docId,ai_interaction_id:interactionId,type,rating,category,original_text:original,corrected_text:corrected,note,context:{request_id:requestId,editor_version:'word-ribbon-v4'}})}).catch(()=>{})}
['#rate5','#feedbackGood'].forEach(s=>$(s)?.addEventListener('click',()=>{sendFeedback('rating','','','','',5);setStatus('بازخورد شما ثبت شد',true)}));['#rate1','#feedbackBad'].forEach(s=>$(s)?.addEventListener('click',()=>{sendFeedback('wrong_output','','','','خروجی نیاز به بررسی انسانی دارد',1);setStatus('بازخورد ثبت شد',true)}));['#feedbackPanelBtn','#feedbackPanelBtn2'].forEach(s=>$(s)?.addEventListener('click',()=>$('#aiSidebar').classList.remove('collapsed')));
function scheduleSave(){if(!docId)return;$('#saveState').textContent='در حال تغییر…';clearTimeout(saveTimer);saveTimer=setTimeout(saveNow,900)}
async function saveNow(){if(!docId)return;try{const j=await api('/editor/save',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({document_id:docId,content:editor.innerHTML})});setStatus('ذخیره شد',true);return j}catch(e){setStatus('ذخیره ناموفق')}}$('#saveNow').addEventListener('click',saveNow);editor.addEventListener('input',()=>{updateWords();scheduleSave()});
function updateWords(){const t=editor.innerText.trim();$('#statusWords').textContent=nf(t?t.split(/\s+/u).filter(Boolean).length:0)}function updatePages(){const count=Math.max(1,Math.ceil(editor.scrollHeight/1050));$('#totalPages').textContent=nf(count);$('#pages').textContent=nf(count)}
function setZoom(z){zoom=Math.min(1.3,Math.max(.7,z));$('#zoomValue').textContent=Math.round(zoom*100)+'%';const p=$('.word-page');if(p){p.style.transform='scale('+zoom+')';p.style.transformOrigin='top center';p.style.marginBottom=(28+(1123*(zoom-1)))+'px'}}$('#zoomIn').addEventListener('click',()=>setZoom(zoom+.1));$('#zoomOut').addEventListener('click',()=>setZoom(zoom-.1));$('#resetZoom').addEventListener('click',()=>setZoom(1));$$('[data-zoom]').forEach(b=>b.addEventListener('click',()=>setZoom(Number(b.dataset.zoom))));
$('#showAi').addEventListener('click',()=>$('#aiSidebar').classList.toggle('collapsed'));$('#closeAi').addEventListener('click',()=>$('#aiSidebar').classList.add('collapsed'));$('#toggleAiPanel').addEventListener('click',()=>$('#aiSidebar').classList.toggle('collapsed'));$('#toggleNavigation').addEventListener('click',()=>$('#navigationPane').classList.toggle('hidden'));
function insertBreak(){editor.focus();document.execCommand('insertHTML',false,'<div class="page-break" contenteditable="false"><span>صفحه جدید</span></div><p><br></p>');fireInput()}$('#pageBreak').addEventListener('click',insertBreak);$('#layoutPageBreak').addEventListener('click',insertBreak);
$('#insertDate').addEventListener('click',()=>cmd('insertText',new Intl.DateTimeFormat('fa-IR').format(new Date())));$('#insertTime').addEventListener('click',()=>cmd('insertText',new Intl.DateTimeFormat('fa-IR',{dateStyle:'medium',timeStyle:'short'}).format(new Date())));$('#insertSymbol').addEventListener('click',()=>{const s=prompt('نماد را وارد کنید','©');if(s)cmd('insertText',s)});
$('#insertLink').addEventListener('click',()=>{const u=prompt('نشانی پیوند را وارد کنید');if(u)cmd('createLink',u)});$('#clearLink').addEventListener('click',()=>cmd('unlink'));
$('#pasteText').addEventListener('click',async()=>{try{cmd('insertText',await navigator.clipboard.readText())}catch{alert('دسترسی به کلیپ‌بورد توسط مرورگر مسدود شده است.')}});
$('#insertTable').addEventListener('click',()=>{const rows=Math.max(1,Math.min(12,Number(prompt('تعداد ردیف‌ها','3')||0))),cols=Math.max(1,Math.min(8,Number(prompt('تعداد ستون‌ها','3')||0)));if(!rows||!cols)return;let h='<table><tbody>';for(let r=0;r<rows;r++){h+='<tr>';for(let c=0;c<cols;c++)h+='<td><br></td>';h+='</tr>'}h+='</tbody></table><p><br></p>';cmd('insertHTML',h)});
$('#orientation').addEventListener('change',e=>$('.word-page').classList.toggle('page-landscape',e.target.value==='landscape'));$('#paperSize').addEventListener('change',e=>{const p=$('.word-page');p.classList.toggle('page-a5',e.target.value==='A5');p.classList.toggle('page-letter',e.target.value==='Letter')});$('#columns').addEventListener('change',e=>{editor.classList.remove('columns-2','columns-3');if(e.target.value==='2')editor.classList.add('columns-2');if(e.target.value==='3')editor.classList.add('columns-3')});$('#margin').addEventListener('change',e=>{const p=$('.word-page');p.classList.remove('margin-narrow','margin-wide');if(e.target.value==='narrow')p.classList.add('margin-narrow');if(e.target.value==='wide')p.classList.add('margin-wide')});$('#direction').addEventListener('click',()=>{editor.dir=editor.dir==='rtl'?'ltr':'rtl';editor.style.textAlign=editor.dir==='rtl'?'right':'left';$('#direction').textContent=editor.dir==='rtl'?'راست به چپ':'چپ به راست';fireInput()});
$('#pageColor').addEventListener('input',e=>$('.word-page').style.backgroundColor=e.target.value);$('#clearPageColor').addEventListener('click',()=>$('.word-page').style.backgroundColor='');$('#resetPageStyle').addEventListener('click',()=>{const p=$('.word-page');p.style.backgroundColor='';p.classList.remove('page-border-simple','page-border-double','margin-narrow','margin-wide','page-landscape','page-a5','page-letter');editor.classList.remove('columns-2','columns-3')});$$('[data-border]').forEach(b=>b.addEventListener('click',()=>{const p=$('.word-page');p.classList.remove('page-border-simple','page-border-double');if(b.dataset.border==='simple')p.classList.add('page-border-simple');if(b.dataset.border==='double')p.classList.add('page-border-double')}));
function refreshNavigation(){const box=$('#navigationItems');if(!box)return;const hs=[...editor.querySelectorAll('h1,h2,h3')];box.innerHTML=hs.length?hs.map((h,i)=>'<button type="button" data-nav="'+i+'" class="nav-item level-'+h.tagName.slice(1)+'">'+esc(h.textContent||'عنوان')+'</button>').join(''):'<small>هنوز عنوانی وجود ندارد.</small>';box.querySelectorAll('[data-nav]').forEach((b,i)=>b.addEventListener('click',()=>hs[i]?.scrollIntoView({behavior:'smooth',block:'center'})))}
$('#insertToc').addEventListener('click',()=>{const hs=[...editor.querySelectorAll('h1,h2,h3')];if(!hs.length){alert('ابتدا عنوان‌های سند را با سبک‌های عنوان ۱ تا ۳ ایجاد کنید.');return}const items=hs.map(h=>'<li>'+esc(h.textContent||'')+'</li>').join('');cmd('insertHTML','<div class="farast-toc"><strong>فهرست مطالب</strong><ol>'+items+'</ol></div><p><br></p>')});
function insertFootnote(){const text=prompt('متن پاورقی را وارد کنید');if(!text)return;const n=editor.querySelectorAll('.farast-footnote').length+1;cmd('insertHTML','<sup>'+n+'</sup><span class="farast-footnote"> ['+n+'] '+esc(text)+'</span>')}$('#insertFootnote').addEventListener('click',insertFootnote);$('#insertFootnoteInline').addEventListener('click',insertFootnote);
$('#insertCitation').addEventListener('click',()=>{const t=prompt('ارجاع کوتاه را وارد کنید','نام نویسنده، سال');if(t)cmd('insertText','['+t+']')});$('#insertCaption').addEventListener('click',()=>{const t=prompt('عنوان شکل یا جدول');if(t)cmd('insertHTML','<p><strong>'+esc(t)+'</strong></p>')});$('#insertBookmark').addEventListener('click',()=>{const t=prompt('نام نشانه');if(t)cmd('insertHTML','<span id="bookmark-'+Date.now()+'"></span>')});
$('#wordStats').addEventListener('click',()=>{const text=(editor.innerText||'').trim(),words=text?text.split(/\s+/u).filter(Boolean).length:0,chars=text.length,paras=[...editor.querySelectorAll('p,h1,h2,h3,li,blockquote')].filter(x=>x.innerText.trim()).length;alert('کلمات: '+nf(words)+'\nنویسه‌ها: '+nf(chars)+'\nپاراگراف‌ها: '+nf(paras))});$('#spellReview').addEventListener('click',()=>showRibbon('ai'));
$('#toggleGuides').addEventListener('click',()=>editor.classList.toggle('show-guides'));$('#toggleFullscreen').addEventListener('click',async()=>{try{if(!document.fullscreenElement)await $('.word-app').requestFullscreen();else await document.exitFullscreen()}catch{}});$('#focusMode').addEventListener('click',()=>document.body.classList.toggle('farast-focus-mode'));
$$('[data-close]').forEach(b=>b.addEventListener('click',()=>$('#'+b.dataset.close).classList.add('hidden')));$('#findText').addEventListener('click',()=>{$('#findDialog').classList.remove('hidden');$('#findInput').focus()});$('#replaceText').addEventListener('click',()=>{$('#findDialog').classList.remove('hidden');$('#replaceInput').focus()});$('#findNext').addEventListener('click',()=>{const q=$('#findInput').value;if(q)window.find(q)});$('#replaceOne').addEventListener('click',()=>{const q=$('#findInput').value,r=$('#replaceInput').value;if(q&&window.find(q))cmd('insertText',r)});$('#replaceAll').addEventListener('click',()=>{const q=$('#findInput').value,r=$('#replaceInput').value;if(!q)return;const walker=document.createTreeWalker(editor,NodeFilter.SHOW_TEXT);const nodes=[];while(walker.nextNode())nodes.push(walker.currentNode);nodes.forEach(n=>{if(n.nodeValue.includes(q))n.nodeValue=n.nodeValue.split(q).join(r)});fireInput()});
$('#exportDocx').addEventListener('click',()=>exportDoc('docx'));$('#exportPdf').addEventListener('click',()=>exportDoc('pdf'));async function exportDoc(format){if(!docId)return alert('ابتدا یک فایل را با AI پردازش کنید');await saveNow();const f=document.createElement('form');f.method='POST';f.action='/editor/export/'+format;f.innerHTML='<input type="hidden" name="_token" value="'+csrf+'"><input type="hidden" name="document_id" value="'+docId+'">';document.body.appendChild(f);f.submit()}
const SR=window.SpeechRecognition||window.webkitSpeechRecognition;if(SR){const rec=new SR();rec.lang='fa-IR';rec.continuous=true;rec.interimResults=false;$('#mic').addEventListener('click',()=>{if($('#mic').classList.toggle('recording')){rec.start();$('#mic').textContent='⏹ توقف صوت'}else rec.stop()});rec.onresult=e=>{let t='';for(let i=e.resultIndex;i<e.results.length;i++)if(e.results[i].isFinal)t+=e.results[i][0].transcript+' ';if(t)cmd('insertText',t)};rec.onend=()=>{$('#mic').classList.remove('recording');$('#mic').textContent='🎙 صوت'}}else $('#mic').setAttribute('data-tip','تایپ صوتی داخلی مرورگر در این مرورگر در دسترس نیست');
function contextMenu(){const menu=$('#farastContextMenu');editor.addEventListener('contextmenu',e=>{e.preventDefault();menu.classList.remove('hidden');menu.style.left=Math.min(e.clientX,window.innerWidth-205)+'px';menu.style.top=Math.min(e.clientY,window.innerHeight-340)+'px'});document.addEventListener('click',e=>{if(!e.target.closest('#farastContextMenu'))menu.classList.add('hidden')});menu.querySelectorAll('[data-context]').forEach(b=>b.addEventListener('click',async()=>{const c=b.dataset.context;menu.classList.add('hidden');if(c==='paste'){try{cmd('insertText',await navigator.clipboard.readText())}catch{alert('دسترسی به کلیپ‌بورد توسط مرورگر مسدود شده است.')}}else if(c==='link'){const u=prompt('نشانی پیوند را وارد کنید');if(u)cmd('createLink',u)}else cmd(c)}))}contextMenu();
function tooltips(){document.querySelectorAll('.ribbon button,.ribbon select,.ribbon label').forEach(el=>{if(!el.dataset.tip){const t=el.getAttribute('title')||el.textContent.trim();if(t)el.dataset.tip=t}})}tooltips();
setInterval(()=>{if(window.FARAST_AUTHENTICATED)fetch('/editor/heartbeat',{method:'POST',headers:{'X-CSRF-TOKEN':csrf}})},60000);updateWords();refreshNavigation();
})();
</script>
@endpush
