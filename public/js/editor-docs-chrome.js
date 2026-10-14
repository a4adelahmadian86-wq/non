/* FARAST · Google Docs Chrome for the Editor (پوستهٔ گوگل داکس روی هستهٔ فراست)
 * ============================================================
 * معماری (قابل استفادهٔ مجدد در اپ‌های دیگر):
 *  - این اسکریپت فقط «کروم» (هدر، منوها، نوار ابزار، نوار پایین، پنل صوتی) را می‌سازد.
 *  - هیچ داده‌ای را خودش ذخیره نمی‌کند؛ همهٔ کارها از طریق فرمان‌های هستهٔ سند
 *    (window.FarastEditor.commands) و موتور صوت (window.FarastVoiceRuntime) انجام می‌شود.
 *  - اگر هستهٔ فراست موجود نباشد، به‌صورت مستقل (standalone) با یک surface
 *    contenteditable کار می‌کند؛ پس همین فایل + docs-chrome.css را می‌توان در هر
 *    اپلیکیشن دیگری هم به‌کار برد.
 *  - حالت قدیمی سایت با دکمهٔ «حالت کلاسیک» یا ?classic=1 بدون تغییر برمی‌گردد.
 */
(() => {
'use strict';
if (window.__farastDocsChromeLoaded) return;
window.__farastDocsChromeLoaded = true;

const app = document.getElementById('farastWord');
if (!app) return;
const params = new URLSearchParams(location.search);
if (params.get('classic') === '1') return; // درخواست صریح حالت کلاسیک

const $ = (s, r = document) => r.querySelector(s);
const $$ = (s, r = document) => [...r.querySelectorAll(s)];
const toFa = s => String(s).replace(/[0-9]/g, d => '۰۱۲۳۴۵۶۷۸۹'[d]);
const faNum = n => toFa(String(n));

/* ===================== آیکون‌ها: Material Symbols محلی + فالبک SVG کاملاً آفلاین =====================
 * لایهٔ ۱: فونت Material Symbols از /fonts (خودمیزبان، بدون CDN، بدون هزینه).
 * لایهٔ ۲: اگر فونت در دسترس نبود (سیستم/مرورگر عجیب، شبکهٔ محدود)، همان آیکون به‌صورت
 *          SVG داخلی رندر می‌شود — نتیجه در همه جا یکسان و بدون هیچ سرویس خارجی. */
const SVG_PATHS = {
  undo:'M3 7h11a5 5 0 1 1 0 10H8|M3 7l4-4M3 7l4 4',
  redo:'M21 7H10a5 5 0 1 0 0 10h6|M21 7l-4-4M21 7l-4 4',
  print:'M6 9V3h12v6|M6 18H4a1 1 0 0 1-1-1v-6a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v6a1 1 0 0 1-1 1h-2|M6 14h12v7H6z',
  format_bold:'M7 5h6a3.5 3.5 0 0 1 0 7H7zM7 12h7a3.5 3.5 0 0 1 0 7H7zM7 5v14',
  format_italic:'M10 4h8M6 20h8M14 4l-4 16',
  format_underlined:'M7 4v7a5 5 0 0 0 10 0V4|M5 20h14',
  strikethrough_s:'M5 8h14|M8 12H6.5a3.5 3.5 0 0 0 0 7H9|M16 12h1.5a3.5 3.5 0 0 1 0 7H13|M8 6.5A3.5 3.5 0 0 1 11.5 4H13',
  ink_highlighter:'M4 20h6L20 10l-4-4L6 16z|M14 6l4 4',
  format_color_text:'M5 18L11 4h2l6 14|M8 13h8|M4 21h16',
  format_align_left:'M4 6h16|M4 12h10|M4 18h13',
  format_align_center:'M4 6h16|M7 12h10|M5 18h14',
  format_align_right:'M4 6h16|M10 12h10|M7 18h13',
  format_align_justify:'M4 6h16|M4 12h16|M4 18h16',
  format_list_bulleted:'M9 6h11M9 12h11M9 18h11|M4.5 6h.01M4.5 12h.01M4.5 18h.01',
  format_list_numbered:'M10 6h10M10 12h10M10 18h10|M4 6h1v4|M4 14h2l-2 3h2',
  format_indent_increase:'M4 6h16M10 12h10M10 18h10|M4 12l4 3-4 3z',
  format_indent_decrease:'M4 6h16M10 12h10M10 18h10|M8 12l-4 3 4 3z',
  format_line_spacing:'M4 5h2M4 12h2M4 19h2|M9 5h11M9 12h11M9 19h11',
  format_textdirection_r_to_l:'M10 10h5a2.5 2.5 0 0 0 0-5h-3v10|M10 4v14|M21 8l-3 3 3 3|M4 8v6M4 8h3',
  format_textdirection_l_to_r:'M14 10H9a2.5 2.5 0 0 1 0-5h3v10|M14 4v14|M3 8l3 3-3 3|M20 8v6',
  format_clear:'M5 5l14 14|M8 16h9|M12 4l-3 8h6l-1.5-4z',
  image:'M4 5h16v14H4z|M8.5 10.5a1 1 0 1 1-2 0 1 1 0 0 1 2 0|M5 17l5-5 3 3 4-4 2 2',
  table:'M4 5h16v14H4z|M4 10h16M4 15h16M9 5v14M15 5v14',
  link:'M9.5 14.5l5-5|M8 12l-2 2a3 3 0 1 0 4 4l2-2|M16 12l2-2a3 3 0 1 0-4-4l-2 2',
  checklist:'M9 6l2 2 4-4|M9 12l2 2 4-4|M9 18l2 2 4-4|M3 6h.01M3 12h.01M3 18h.01',
  mic:'M12 3a3 3 0 0 1 3 3v5a3 3 0 0 1-6 0V6a3 3 0 0 1 3-3z|M6 11a6 6 0 0 0 12 0|M12 17v4M9 21h6',
  spellcheck:'M4 7l3 6 3-6|M4 17h7|M15 13l2 2 4-5',
  description:'M6 3h9l4 4v14H6z|M14 3v5h5|M9 12h7M9 16h7',
  close:'M6 6l12 12M18 6L6 18',
};
function iconHTML(name) {
  const d = SVG_PATHS[name];
  if (!d) return '<i class="ms">' + name + '</i>';
  const paths = d.split('|').map(p => '<path d="' + p + '"/>').join('');
  return '<span class="ms" aria-hidden="true"><svg viewBox="0 0 24 24">' + paths + '</svg></span>';
}

/* ===================== Tool Registry (معماری ماژولار) =====================
 * هر ابزار = یک شیء ثبت‌شده. برای افزودن ابزار جدید در هر اپ دیگر کافی است:
 *   window.FarastDocsTools.register({id,title,icon,onRun,isOn})
 */
const registry = [];
const byId = {};
window.FarastDocsTools = {
  register(tool) { if (!tool || !tool.id || byId[tool.id]) return false; registry.push(tool); byId[tool.id] = tool; rebuildToolbar(); return true; },
  unregister(id) { const i = registry.findIndex(t => t.id === id); if (i < 0) return false; registry.splice(i, 1); delete byId[id]; rebuildToolbar(); return true; },
  get: id => byId[id],
  list: () => registry.slice(),
};

/* ===================== اتصال به هستهٔ فراست / سطح مستقل ===================== */
function kernel() { return window.FarastEditor || null; }
/* نگاشت نام فرمان‌های کروم گوگل ← دستورات واقعی Kernel فراست */
const KERNEL_ALIASES = {
  undo:'undo', redo:'redo', bold:'bold', italic:'italic', underline:'underline', strike:'strike',
  right:'right', center:'center', left:'left', justify:'justify', ul:'ul', ol:'ol',
  indent:'indent', outdent:'outdent', normal:'normal', h1:'h1', h2:'h2', h3:'h3', quote:'quote',
  clear:'clear', link:'link', image:'image', table:'table', copy:'copy', cut:'cut', paste:'paste',
  selectAll:'selectAll', find:'find', comment:'comment', pageBreak:'pageBreak', header:'header',
  footer:'footer', pageNumber:'pageNumber', navigation:'navigation', aiPanel:'aiPanel',
  versions:'versions', paper:'paper', orientation:'orientation', margin:'margin',
  lineHeight:'lineHeight', paragraphSpacing:'paragraphSpacing', docRtl:'docRtl', docLtr:'docLtr',
  rtl:'rtl', ltr:'ltr', font:'font', fontSize:'fontSize', color:'color', highlight:'highlight',
  insertText:'insertText', deleteRange:'deleteRange', FormatText:'FormatText', InsertText:'InsertText',
  insertLink:'insertLink', insertTable:'insertTable', insertImage:'insertImage'
};
function kcmd(name) { const k = kernel(); if (!k?.commands) return null; const n = KERNEL_ALIASES[name] || name; return k.commands.has(n) ? n : null; }
function surface() {
  const k = kernel();
  if (k?.state?.model) {
    const ed = app.querySelector('.farast-editor[contenteditable="true"], .farast-page [contenteditable="true"], [contenteditable="true"]');
    return ed;
  }
  return $('#fdFallbackEditor');
}
function exec(cmd, val) {
  const c = kcmd(cmd);
  if (c) { try { return kernel().execute(c, val); } catch { return false; } }
  // رفتار مستقل: روی surface با execCommand مرورگر
  const ed = surface(); if (!ed) return false;
  ed.focus({ preventScroll: true });
  try { document.execCommand(cmd, false, val ?? null); ed.dispatchEvent(new Event('input', { bubbles: true })); return true; } catch { return false; }
}
function runAction(name, input) {
  const c = kcmd(name);
  if (c) { try { return kernel().execute(c, input); } catch (e) { toast(e?.message || 'اجرای فرمان ناموفق بود'); return false; } }
  return standaloneFallback(name, input);
}
function standaloneFallback(name, input) {
  const map = { undo: ['undo'], redo: ['redo'], bold: ['bold'], italic: ['italic'], underline: ['underline'], strike: ['strikeThrough'],
    right: ['justifyRight'], center: ['justifyCenter'], left: ['justifyLeft'], justify: ['justifyFull'],
    ul: ['insertUnorderedList'], ol: ['insertOrderedList'], indent: ['indent'], outdent: ['outdent'],
    normal: ['formatBlock', 'p'], h1: ['formatBlock', 'h1'], h2: ['formatBlock', 'h2'], h3: ['formatBlock', 'h3'], quote: ['formatBlock', 'blockquote'] };
  if (map[name]) return exec(map[name][0], map[name][1]);
  if (name === 'link') { const u = prompt('نشانی پیوند', 'https://'); if (u) return exec('createLink', u); }
  if (name === 'image') { const u = prompt('نشانی تصویر', 'https://'); if (u) return exec('insertHTML', '<img src="' + u.replace(/"/g, '&quot;') + '" alt="">'); }
  if (name === 'font' || name === 'fontName') return exec('fontName', typeof input === 'string' ? input : input?.value);
  if (name === 'fontSize') return applyFontSize(input);
  if (name === 'color') return exec('foreColor', input);
  if (name === 'highlight') return exec('hiliteColor', input);
  return false;
}

/* ===================== کروم: هدر / منوها ===================== */
app.classList.add('farast-docs-mode');
const top = document.createElement('header');
top.className = 'fd-top';
top.innerHTML = `
  <div class="fd-logo" aria-hidden="true"><span class="ms"><svg viewBox="0 0 24 24"><path d="M6 3h9l4 4v14H6z"/><path d="M14 3v5h5"/><path d="M9 12h7M9 16h7"/></svg></span></div>
  <div class="fd-meta">
    <input class="fd-title" id="fdTitle" type="text" autocomplete="off" aria-label="عنوان سند">
    <nav class="fd-menus" id="fdMenus" aria-label="منوها"></nav>
  </div>
  <span class="fd-save" id="fdSaveState" role="status">آماده</span>`;
const fbEd = document.createElement('div');
fbEd.id = 'fdFallbackEditor'; fbEd.className = 'farast-editor'; fbEd.contentEditable = 'true'; fbEd.spellcheck = true; fbEd.dir = 'rtl';
fbEd.innerHTML = '<p><br></p>';
if (!$('#pagesViewport')) { const fbc = document.createElement('div'); fbc.className = 'fd-canvas'; const fbp = document.createElement('div'); fbp.className = 'fd-pages'; fbp.appendChild(fbEd); fbc.appendChild(fbp); app.appendChild(fbc); } else fbEd.style.display = 'none';
app.prepend(top);

const toolbarEl = document.createElement('div');
toolbarEl.className = 'fd-toolbar';
toolbarEl.id = 'fdToolbar';
toolbarEl.setAttribute('role', 'toolbar');
toolbarEl.setAttribute('aria-label', 'ابزارهای ویرایش');

const workspace = $('main.farast-workspace', app);
if (workspace) workspace.before(toolbarEl); else top.after(toolbarEl);

const canvasWrap = document.createElement('div');
// برگه‌های هسته داخل pagesViewport باقی می‌مانند؛ فقط ظاهر بوم عوض می‌شود
$('#pagesViewport')?.closest('.farast-canvas-shell')?.classList.add('fd-canvas-host');

const bottom = document.createElement('div');
bottom.className = 'fd-bottom';
bottom.innerHTML = `<span id="fdWords">۰ کلمه</span><span id="fdChars">۰ نویسه</span><span id="fdPages">۱ صفحه</span>
  <button type="button" id="fdClassic" title="بازگشت به ظاهر قبلی ادیتور">حالت کلاسیک</button>`;
document.body.appendChild(bottom);
const fdMicOpen = document.createElement('button'); fdMicOpen.type='button'; fdMicOpen.textContent='تایپ صوتی';
bottom.insertBefore(fdMicOpen, bottom.firstChild);
const fdExpD = document.createElement('button'); fdExpD.type='button'; fdExpD.textContent='DOCX';
const fdExpP = document.createElement('button'); fdExpP.type='button'; fdExpP.textContent='PDF';
bottom.appendChild(fdExpD); bottom.appendChild(fdExpP);
/* دکمه‌های اصلی نوار پایین (#mic/#exportDocx/#exportPdf) در DOM هسته دست‌نخورده می‌مانند
   تا رویدادهای هسته نشکند؛ کروم جدید تابع یکسانی را صدا می‌زند. */
fdMicOpen.addEventListener('click', () => toggleVoicePanel());
fdExpD.addEventListener('click', exportDocx);
fdExpP.addEventListener('click', exportPdf);
const billing = $('#projectBillingState');
if (billing) { const b2 = document.createElement('span'); b2.className = 'fd-save'; b2.id = 'fdBilling'; b2.style.marginTop = '12px'; b2.textContent = billing.textContent.trim(); top.append(b2); }

/* عنوان سند را با #docTitle همگام نگه می‌داریم (هسته از همان ذخیره می‌کند) */
const coreTitle = $('#docTitle');
const fdTitle = $('#fdTitle');
if (coreTitle && fdTitle) {
  fdTitle.value = coreTitle.value || 'سند بدون عنوان';
  coreTitle.addEventListener('input', () => { if (document.activeElement !== fdTitle) fdTitle.value = coreTitle.value; });
  fdTitle.addEventListener('input', () => { coreTitle.value = fdTitle.value; coreTitle.dispatchEvent(new Event('input', { bubbles: true })); });
  document.title = fdTitle.value || 'فراست — ویرایشگر سند';
}

/* وضعیت ذخیره از setText/saveState هسته منتقل می‌شود */
const fdSave = $('#fdSaveState');
const coreSave = $('#saveState');
function syncSaveState() {
  if (!coreSave || !fdSave) return;
  fdSave.textContent = coreSave.textContent || 'آماده';
  fdSave.dataset.state = coreSave.dataset.state || 'info';
}
if (coreSave) new MutationObserver(syncSaveState).observe(coreSave, { childList: true, characterData: true, subtree: true, attributes: true });
setInterval(syncSaveState, 3000);

/* شمارنده‌ها از statusbar هسته منتقل می‌شود */
function syncCounts() {
  const w = $('#statusWords'), p = $('#totalPages');
  if (w) $('#fdWords').textContent = faNum(w.textContent) + ' کلمه';
  const ed = surface();
  if (ed) { const t = (ed.innerText || '').trim(); $('#fdChars').textContent = faNum(t.length) + ' نویسه'; }
  if (p) $('#fdPages').textContent = faNum(p.textContent) + ' صفحه';
}
setInterval(syncCounts, 2000);
app.addEventListener('input', syncCounts, true);

/* ===================== toast / dialog / popover ===================== */
let toastT;
function toast(msg) {
  let t = $('.fd-toast'); if (!t) { t = document.createElement('div'); t.className = 'fd-toast'; t.setAttribute('role', 'status'); document.body.appendChild(t); }
  t.textContent = msg; t.classList.add('show'); clearTimeout(toastT); toastT = setTimeout(() => t.classList.remove('show'), 2800);
}
function dialog(title, body, buttons) {
  const d = document.createElement('dialog'); d.className = 'fd-dlg';
  const h = document.createElement('h2'); h.textContent = title;
  const b = document.createElement('div'); b.className = 'dbody';
  if (typeof body === 'string') b.innerHTML = body; else b.appendChild(body);
  const act = document.createElement('div'); act.className = 'dact';
  d.append(h, b, act); document.body.appendChild(d);
  (buttons || []).forEach(bt => {
    const x = document.createElement('button'); x.className = 'fd-btn' + (bt.primary ? ' primary' : ''); x.type = 'button'; x.textContent = bt.label;
    x.onclick = () => { const r = bt.fn ? bt.fn(d) : undefined; if (r !== false && !bt.stay) d.close(); };
    act.appendChild(x);
  });
  d.addEventListener('close', () => d.remove());
  d.addEventListener('keydown', e => { if (e.key === 'Enter' && e.target.tagName === 'INPUT' && e.target.type !== 'file') { const p = act.querySelector('.primary'); if (p) p.click(); } });
  d.showModal();
  const f = d.querySelector('input:not([type=file]),select'); if (f) f.focus();
  return d;
}
let popEl = null;
function closePop() { if (popEl) { popEl.remove(); popEl = null; } }
function showPop(anchor, node) {
  closePop();
  const p = document.createElement('div'); p.className = 'fd-pop'; p.appendChild(node); document.body.appendChild(p);
  const r = anchor.getBoundingClientRect();
  p.style.top = (r.bottom + 6) + 'px';
  const w = p.offsetWidth; let left = r.left + r.width / 2 - w / 2;
  left = Math.max(8, Math.min(left, innerWidth - w - 8)); p.style.left = left + 'px';
  p.addEventListener('mousedown', e => { if (!/INPUT|SELECT/.test(e.target.tagName)) e.preventDefault(); });
  popEl = p; return p;
}

/* ===================== palette رنگ ===================== */
function palette(kind, anchor) {
  const wrap = document.createElement('div');
  const grid = document.createElement('div'); grid.className = 'fd-pal';
  const gray = ['#000000','#434343','#666666','#999999','#b7b7b7','#cccccc','#d9d9d9','#efefef','#f3f3f3','#ffffff'];
  const hues = [0,25,50,110,150,185,215,255,285,330];
  const cols = [...gray];
  [28,45,65,82].forEach(l => hues.forEach(h => cols.push(`hsl(${h} 80% ${l}%)`)));
  cols.forEach(c => { const b = document.createElement('button'); b.type = 'button'; b.style.background = c; b.title = c;
    b.onclick = () => applyColor(kind, c); grid.appendChild(b); });
  wrap.appendChild(grid);
  const row = document.createElement('div'); row.className = 'fd-row';
  const reset = document.createElement('button'); reset.type = 'button'; reset.className = 'fd-btn';
  reset.textContent = kind === 'fore' ? 'رنگ پیش‌فرض' : 'بدون هایلایت';
  reset.onclick = () => applyColor(kind, kind === 'fore' ? '#000000' : 'transparent', true);
  const custom = document.createElement('input'); custom.type = 'color'; custom.title = 'رنگ دلخواه';
  custom.oninput = () => applyColor(kind, custom.value, false, true);
  row.append(reset, custom); wrap.appendChild(row);
  showPop(anchor, wrap);
}
function applyColor(kind, c, reset, keepOpen) {
  runAction(kind === 'fore' ? 'color' : 'highlight', c);
  const bar = $(kind === 'fore' ? '#fdCbarFore' : '#fdCbarBack');
  if (bar) bar.style.background = reset ? (kind === 'fore' ? '#000' : '#fff') : c;
  if (!keepOpen) closePop();
}

/* ===================== اندازه قلم ===================== */
function applyFontSize(pt) {
  const c = kcmd('FormatText');
  if (c) { try { kernel().execute(c, { patch: { fontSize: pt + 'pt' } }); return true; } catch {} }
  restoreSel();
  const sel = getSelection(); if (!sel.rangeCount) return false;
  const r = sel.getRangeAt(0);
  if (r.collapsed) { const span = document.createElement('span'); span.style.fontSize = pt + 'pt'; span.textContent = '\u200b'; r.insertNode(span); const nr = document.createRange(); nr.setStart(span.firstChild, 1); nr.collapse(true); sel.removeAllRanges(); sel.addRange(nr); }
  else { document.execCommand('styleWithCSS', false, false); document.execCommand('fontSize', false, '7');
    $$('font[size="7"]', surface() || document).forEach(f => { const s = document.createElement('span'); s.style.fontSize = pt + 'pt'; while (f.firstChild) s.appendChild(f.firstChild); f.replaceWith(s); }); }
  markDirty(); return true;
}
const SIZES = [8,9,10,11,12,14,16,18,20,24,28,32,36,48,72];

/* ===================== انتخاب متن محفوظ ===================== */
let saved = null;
document.addEventListener('selectionchange', () => {
  const s = getSelection(); const ed = surface();
  if (ed && s.rangeCount && ed.contains(s.anchorNode)) { saved = s.getRangeAt(0).cloneRange(); syncToolStates(); }
});
function restoreSel() { const ed = surface(); if (!ed) return; ed.focus({ preventScroll: true }); if (saved) { const s = getSelection(); s.removeAllRanges(); s.addRange(saved); } }
function currentEl() { const ed = surface(); const s = getSelection(); let n = s && s.anchorNode; if (!n || !ed || !ed.contains(n)) return null; return n.nodeType === 3 ? n.parentElement : n; }

/* ===================== نوار ابزار: تعریف ابزارها ===================== */
const TB = [
  { id:'undo', icon:'undo', title:'بازگردانی (Ctrl+Z)', run:()=>runAction('undo') },
  { id:'redo', icon:'redo', title:'ازنو (Ctrl+Y)', run:()=>runAction('redo') },
  { id:'print', icon:'print', title:'چاپ (Ctrl+P)', run:()=>window.print() },
  { id:'spell', icon:'spellcheck', title:'غلط‌یاب املایی', toggle:true, isOn:()=>{const e=surface();return !!e&&e.spellcheck===true}, run:(btn)=>{const e=surface();if(e){e.spellcheck=!e.spellcheck;toast(e.spellcheck?'غلط‌یاب روشن شد':'غلط‌یاب خاموش شد');syncToolStates();}} },
  { sep:true },
  { id:'zoom', kind:'select', title:'بزرگنمایی', cls:'zoom', options:[['0.5','۵۰٪'],['0.75','۷۵٪'],['0.9','۹۰٪'],['1','۱۰۰٪'],['1.25','۱۲۵٪'],['1.5','۱۵۰٪'],['2','۲۰۰٪']], run:v=>setZoom(v) },
  { sep:true },
  { id:'style', kind:'select', title:'سبک پاراگراف', cls:'style', options:[['normal','متن معمولی'],['title','عنوان اصلی'],['subtitle','زیرعنوان'],['h1','سرفصل ۱'],['h2','سرفصل ۲'],['h3','سرفصل ۳']], run:v=>setBlock(v) },
  { sep:true },
  { id:'font', kind:'select', title:'فونت', cls:'font', options:null, run:v=>runAction('font', v) },
  { sep:true },
  { id:'sizeDown', icon:'remove', title:'کاهش اندازه', run:()=>stepSize(-1) },
  { id:'sizeInput', kind:'size' },
  { id:'sizeUp', icon:'add', title:'افزایش اندازه', run:()=>stepSize(1) },
  { sep:true },
  { id:'bold', icon:'format_bold', title:'پررنگ (Ctrl+B)', state:'bold', run:()=>runAction('bold') },
  { id:'italic', icon:'format_italic', title:'کج (Ctrl+I)', state:'italic', run:()=>runAction('italic') },
  { id:'underline', icon:'format_underlined', title:'زیرخط‌دار (Ctrl+U)', state:'underline', run:()=>runAction('underline') },
  { id:'strike', icon:'strikethrough_s', title:'خط‌خورده', state:'strikeThrough', run:()=>runAction('strike') },
  { id:'foreColor', icon:'format_color_text', title:'رنگ متن', colorBar:'fore', run:b=>palette('fore', b) },
  { id:'backColor', icon:'ink_highlighter', title:'رنگ پس‌زمینهٔ متن (هایلایت)', colorBar:'back', run:b=>palette('back', b) },
  { sep:true },
  { id:'link', icon:'link', title:'درج پیوند (Ctrl+K)', run:()=>openLink() },
  { id:'image', icon:'image', title:'درج تصویر', run:()=>openImage() },
  { id:'table', icon:'table', title:'درج جدول', run:()=>openTable() },
  { sep:true },
  { id:'alignR', icon:'format_align_right', title:'راست‌چین', state:'justifyRight', run:()=>runAction('right') },
  { id:'alignC', icon:'format_align_center', title:'وسط‌چین', state:'justifyCenter', run:()=>runAction('center') },
  { id:'alignL', icon:'format_align_left', title:'چپ‌چین', state:'justifyLeft', run:()=>runAction('left') },
  { id:'alignJ', icon:'format_align_justify', title:'تراز دوطرفه', state:'justifyFull', run:()=>runAction('justify') },
  { id:'spacing', icon:'format_line_spacing', title:'فاصلهٔ خطوط و پاراگراف', run:b=>spacingPop(b) },
  { sep:true },
  { id:'check', icon:'checklist', title:'فهرست کارها', run:()=>toggleChecklist() },
  { id:'ul', icon:'format_list_bulleted', title:'فهرست نقطه‌ای', state:'insertUnorderedList', run:()=>runAction('ul') },
  { id:'ol', icon:'format_list_numbered', title:'فهرست شماره‌دار', state:'insertOrderedList', run:()=>runAction('ol') },
  { id:'outdent', icon:'format_indent_decrease', title:'کاهش تورفتگی', run:()=>runAction('outdent') },
  { id:'indent', icon:'format_indent_increase', title:'افزایش تورفتگی', run:()=>runAction('indent') },
  { sep:true },
  { id:'dirRTL', icon:'format_textdirection_r_to_l', title:'جهت متن: راست به چپ', run:()=>setDir('rtl') },
  { id:'dirLTR', icon:'format_textdirection_l_to_r', title:'جهت متن: چپ به راست', run:()=>setDir('ltr') },
  { id:'clear', icon:'format_clear', title:'پاک کردن قالب‌بندی', run:()=>runAction('clear') || exec('removeFormat') },
  { sep:true },
  { id:'voice', icon:'mic', title:'تایپ صوتی (Ctrl+Shift+S)', toggle:true, isOn:()=>vbox.classList.contains('show'), run:()=>toggleVoicePanel() },
];
window.__fdToolDefs = TB.slice(); // برای رجیستری ابزارهای جانبی

function rebuildToolbar() {
  toolbarEl.innerHTML = '';
  TB.forEach(def => addTool(def));
  registry.forEach(t => { if (!TB.find(d => d.id === t.id)) addTool(t); });
}
function addTool(def) {
  if (def.sep) { const s = document.createElement('span'); s.className = 'fd-sep'; toolbarEl.appendChild(s); return; }
  if (def.kind === 'select') {
    const sel = document.createElement('select'); sel.className = 'fd-sel ' + (def.cls || ''); sel.title = def.title; sel.setAttribute('aria-label', def.title); sel.dataset.tool = def.id;
    (def.options || []).forEach(([v, l]) => { const o = document.createElement('option'); o.value = v; o.textContent = l; sel.appendChild(o); });
    sel.addEventListener('change', () => def.run(sel.value, sel));
    toolbarEl.appendChild(sel); return;
  }
  if (def.kind === 'size') {
    const box = document.createElement('div'); box.className = 'fd-size';
    const inp = document.createElement('input'); inp.id = 'fdSizeInput'; inp.type = 'text'; inp.inputMode = 'numeric'; inp.value = '16'; inp.setAttribute('aria-label', 'اندازه فونت');
    inp.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); const v = parseFloat(String(inp.value).replace(/[۰-۹]/g, d => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d))); if (v) { applyFontSize(v); sizeSel = v; } } });
    box.appendChild(inp); toolbarEl.appendChild(box); return;
  }
  const b = document.createElement('button'); b.type = 'button'; b.className = 'fd-tb'; b.dataset.tool = def.id; b.title = def.title;
  b.innerHTML = iconHTML(def.icon);
  if (def.colorBar) { b.classList.add('color'); const bar = document.createElement('span'); bar.className = 'fd-cbar'; bar.id = def.colorBar === 'fore' ? 'fdCbarFore' : 'fdCbarBack'; bar.style.background = def.colorBar === 'fore' ? '#000' : '#ffff00'; b.appendChild(bar); }
  b.addEventListener('click', () => { closePop(); def.run(b); if (def.toggle) syncToolStates(); });
  toolbarEl.appendChild(b);
}
let sizeSel = 16;
function stepSize(dir) {
  const cur = currentFontSize();
  let idx = SIZES.findIndex(s => s >= cur - 0.5);
  idx = Math.max(0, Math.min(SIZES.length - 1, idx + dir));
  applyFontSize(SIZES[idx]); sizeSel = SIZES[idx]; syncToolStates();
}
function currentFontSize() {
  const n = currentEl(); if (!n) return sizeSel;
  const px = parseFloat(getComputedStyle(n).fontSize) || 16;
  return Math.round(px * 0.75 * 2) / 2; // px→pt تقریبی
}

/* ===================== سبک پاراگراف / جهت / فاصله ===================== */
function setBlock(kind) {
  const cmdMap = { normal:'normal', h1:'h1', h2:'h2', h3:'h3' };
  if (cmdMap[kind] && kcmd(cmdMap[kind])) return runAction(cmdMap[kind]);
  restoreSel();
  const tag = { normal:'p', title:'p', subtitle:'p', h1:'h1', h2:'h2', h3:'h3' }[kind] || 'p';
  document.execCommand('formatBlock', false, tag);
  const ed = surface();
  if (ed && (kind === 'title' || kind === 'subtitle')) {
    const s = getSelection(); if (s.rangeCount) { let n = s.anchorNode; const blk = n && (n.nodeType === 3 ? n.parentElement.closest('p,h1,h2,h3') : n.closest?.('p,h1,h2,h3'));
      if (blk) { blk.classList.remove('title-s','subtitle-s'); blk.classList.add(kind === 'title' ? 'title-s' : 'subtitle-s'); } }
  }
  markDirty();
}
function spacingPop(anchor) {
  const wrap = document.createElement('div'); wrap.className = 'fd-list';
  [['۱٫۰','1'],['۱٫۳','1.3'],['۱٫۵','1.5'],['۱٫۹ (پیش‌فرض)','1.9'],['۲٫۲','2.2'],['۳٫۰','3']].forEach(([l, v]) => {
    const b = document.createElement('button'); b.type = 'button'; b.textContent = 'فاصلهٔ خطوط ' + l;
    b.onclick = () => { if (!runAction('lineHeight', v)) { restoreSel(); const el = currentEl(); const blk = el?.closest?.('p,h1,h2,h3,li,td'); if (blk) blk.style.lineHeight = v; markDirty(); } closePop(); };
    wrap.appendChild(b);
  });
  [['افزودن فاصله قبل از پاراگراف','marginTop','14px'],['حذف فاصله قبل از پاراگراف','marginTop','0px'],['افزودن فاصله بعد از پاراگراف','marginBottom','14px'],['حذف فاصله بعد از پاراگراف','marginBottom','0px']].forEach(([l, p, v]) => {
    const b = document.createElement('button'); b.type = 'button'; b.textContent = l;
    b.onclick = () => { restoreSel(); const el = currentEl(); const blk = el?.closest?.('p,h1,h2,h3,li'); if (blk) blk.style[p] = v; markDirty(); closePop(); };
    wrap.appendChild(b);
  });
  showPop(anchor, wrap);
}
function setDir(d) {
  if (runAction(d === 'rtl' ? 'rtl' : 'ltr')) return;
  restoreSel(); const el = currentEl(); const blk = el?.closest?.('p,h1,h2,h3,li,td'); if (blk) { blk.setAttribute('dir', d); blk.style.textAlign = ''; } markDirty();
}
function toggleChecklist() {
  const el = currentEl(); const ul = el?.closest?.('ul');
  if (ul && ul.classList.contains('fd-checklist')) { ul.classList.remove('fd-checklist'); markDirty(); return; }
  runAction('ul');
  const ul2 = currentEl()?.closest?.('ul'); if (ul2) { ul2.classList.add('fd-checklist'); injectChecklistStyle(); }
  markDirty();
}
function injectChecklistStyle() {
  if ($('#fdCheckStyle')) return;
  const st = document.createElement('style'); st.id = 'fdCheckStyle';
  st.textContent = '.fd-checklist{list-style:none;padding-inline-start:6px}.fd-checklist li{position:relative;padding-inline-start:26px;margin:2px 0}.fd-checklist li::before{content:"";position:absolute;inset-inline-start:0;top:.28em;width:15px;height:15px;border:2px solid #5f6368;border-radius:3px;cursor:pointer}.fd-checklist li.done::before{content:"✓";display:grid;place-items:center;background:#0b57d0;border-color:#0b57d0;color:#fff;font-size:11px}.fd-checklist li.done{color:#5f6368;text-decoration:line-through}';
  document.head.appendChild(st);
}
document.addEventListener('click', e => {
  const ed = surface(); if (!ed) return;
  const li = e.target.closest && e.target.closest('ul.fd-checklist > li');
  if (li && ed.contains(li)) {
    const r = li.getBoundingClientRect(), rtl = getComputedStyle(li).direction === 'rtl';
    if (rtl ? e.clientX > r.right - 30 : e.clientX < r.left + 30) { li.classList.toggle('done'); markDirty(); }
  }
});

/* ===================== لینک / تصویر / جدول ===================== */
function openLink() {
  const el = currentEl(); const a = el?.closest?.('a');
  const body = `<label>نشانی (URL)</label><input type="text" id="fdLkUrl" dir="ltr" placeholder="https://example.com" value="${a ? (a.getAttribute('href') || '').replace(/"/g, '&quot;') : ''}">`;
  dialog('درج پیوند', body, [
    { label: 'انصراف' },
    { label: a ? 'ویرایش' : 'اعمال', primary: true, fn: d => {
      let u = $('#fdLkUrl', d).value.trim();
      if (!u) { if (a) { restoreSel(); document.execCommand('unlink'); markDirty(); } return; }
      if (!/^(https?:|mailto:|tel:|#|\/)/i.test(u)) u = 'https://' + u;
      const c = kcmd('insertLink');
      if (c) { restoreSel(); try { kernel().execute(c, { url: u }); return; } catch {} }
      restoreSel(); document.execCommand('createLink', false, u); markDirty();
    } }
  ]);
}
function openImage() {
  const body = `<label>انتخاب فایل از دستگاه</label><input type="file" id="fdImFile" accept="image/*"><label>یا نشانی تصویر (URL)</label><input type="text" id="fdImUrl" dir="ltr" placeholder="https://…">`;
  dialog('درج تصویر', body, [
    { label: 'انصراف' },
    { label: 'درج', primary: true, fn: d => {
      const f = $('#fdImFile', d).files[0], u = $('#fdImUrl', d).value.trim();
      if (f) { insertImageFile(f); return; }
      if (/^https?:\/\//i.test(u)) { restoreSel(); if (!exec('insertHTML', '<img src="' + u.replace(/"/g, '&quot;') + '" alt="">')) runAction('image', u); markDirty(); return; }
      toast('فایل یا نشانی معتبر وارد کنید'); return false;
    } }
  ]);
}
async function insertImageFile(file) {
  if (kcmd('image')) { try { return runAction('image', file); } catch {} }
  const ed = surface(); if (!ed) return;
  try {
    const fr = new FileReader();
    await new Promise(res => { fr.onload = res; fr.readAsDataURL(file); });
    restoreSel(); exec('insertHTML', '<img src="' + fr.result + '" alt="" style="max-width:100%">'); markDirty();
  } catch { toast('خواندن تصویر ممکن نشد'); }
}
function openTable() {
  const body = `<label>تعداد سطر</label><input type="number" id="fdTbR" min="1" max="50" value="3"><label>تعداد ستون</label><input type="number" id="fdTbC" min="1" max="12" value="3">`;
  dialog('درج جدول', body, [
    { label: 'انصراف' },
    { label: 'درج', primary: true, fn: d => {
      const r = Math.min(50, Math.max(1, +$('#fdTbR', d).value || 3)), c = Math.min(12, Math.max(1, +$('#fdTbC', d).value || 3));
      const ct = kcmd('insertTable');
      if (ct) { try { return kernel().execute(ct, { rows: r, columns: c }); } catch {} }
      if (runAction('table', { rows: r, cols: c })) return;
      const row = '<tr>' + '<td><br></td>'.repeat(c) + '</tr>';
      restoreSel(); exec('insertHTML', '<table><tbody>' + row.repeat(r) + '</tbody></table><p><br></p>'); markDirty();
    } }
  ]);
}

/* ===================== جستجو و جایگزینی (Ctrl+H/F) ===================== */
function openFind() {
  const cf = kcmd('find');
  if (cf) { try { return kernel().execute(cf); } catch {} }
  const body = `<label>جستجو</label><input type="text" id="fdFA"><label>جایگزینی با</label><input type="text" id="fdFB"><div id="fdFM" style="font-size:12px;color:#5f6368;min-height:18px"></div>`;
  const d = dialog('جستجو و جایگزینی', body, [
    { label: 'بستن' },
    { label: 'جایگزینی همه', stay: true, fn: dd => {
      const a = $('#fdFA', dd).value, b = $('#fdFB', dd).value; if (!a) return false;
      const ed = surface(); let n = 0;
      const w = document.createTreeWalker(ed, NodeFilter.SHOW_TEXT), list = [];
      while (w.nextNode()) list.push(w.currentNode);
      list.forEach(t => { if (t.nodeValue.includes(a)) { n += t.nodeValue.split(a).length - 1; t.nodeValue = t.nodeValue.split(a).join(b); } });
      $('#fdFM', dd).textContent = faNum(n) + ' مورد جایگزین شد'; markDirty(); return false;
    } },
    { label: 'بعدی', primary: true, stay: true, fn: dd => { const a = $('#fdFA', dd).value; if (a) { const ok = window.find(a, false, false, true, false, false, false); $('#fdFM', dd).textContent = ok ? '' : 'موردی پیدا نشد'; } return false; } },
  ]);
  d.addEventListener('keydown', e => { if (e.key === 'Enter') e.preventDefault(); });
  const s = getSelection(); if (s && s.rangeCount && surface()?.contains(s.anchorNode) && s.toString()) $('#fdFA', d).value = s.toString();
}

/* ===================== نویسه‌های ویژه / تاریخ ===================== */
function openChars() {
  const chars = ['\u200c','،','؛','؟','«','»','٪','٫','٬','…','–','—','“','”','°','±','×','÷','≈','≠','≤','≥','∞','√','π','©','®','™','€','£','$','¥','½','¼','¾','←','→','↑','↓','✓','★','♥','•','۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
  const g = document.createElement('div'); g.className = 'fd-chars';
  chars.forEach(c => { const b = document.createElement('button'); b.type = 'button'; b.textContent = c === '\u200c' ? 'نیم‌فاصله' : c; b.title = c === '\u200c' ? 'نیم‌فاصله (Shift+Space)' : c; if (c === '\u200c') b.style.fontSize = '10px';
    b.onclick = () => { restoreSel(); if (!exec('insertText', c)) runAction('insertText', c); markDirty(); };
    g.appendChild(b); });
  dialog('نویسه‌های ویژه', g, [{ label: 'بستن', primary: true }]);
}
function insertDate() {
  const s = new Intl.DateTimeFormat('fa-IR-u-ca-persian', { dateStyle: 'full' }).format(new Date());
  restoreSel(); if (!exec('insertText', s)) runAction('insertText', s); markDirty();
}

/* ===================== ابزارهای تک‌کلیک ===================== */
function walkText(fn) { const ed = surface(); if (!ed) return; const w = document.createTreeWalker(ed, NodeFilter.SHOW_TEXT), l = []; while (w.nextNode()) l.push(w.currentNode); l.forEach(fn); markDirty(); }
const digitsToFa = () => { walkText(t => { t.nodeValue = toFa(t.nodeValue); }); toast('ارقام به فارسی تبدیل شد'); };
const fixArabic = () => { walkText(t => { t.nodeValue = t.nodeValue.replace(/ي/g, 'ی').replace(/ك/g, 'ک'); }); toast('«ی» و «ک» عربی اصلاح شد'); };
function wordCountDialog() {
  const ed = surface(); const t = (ed?.innerText || '').trim(); const w = t ? t.split(/\s+/).length : 0;
  dialog('شمارش کلمات', `<p>کلمات: <b>${faNum(w)}</b></p><p>نویسه (با فاصله): <b>${faNum(t.length)}</b></p><p>نویسه (بدون فاصله): <b>${faNum(t.replace(/\s/g, '').length)}</b></p>`, [{ label: 'بستن', primary: true }]);
}
function setZoom(v) {
  const k = kernel();
  if (k) { const z = Number(v); if (k.commands.has('zoomIn') ) { /* از طریق viewport */ }
    const vp = $('#pagesViewport'); if (vp) { vp.style.zoom = v; $('#fdZoomStatus') && ($('#fdZoomStatus').textContent = 'بزرگ‌نمایی ' + faNum(Math.round(z * 100)) + '٪'); } }
  else { const pages = $('.fd-pages') || $('#pagesViewport'); if (pages) pages.style.zoom = v; }
}
function fullscreen() { if (!document.fullscreenElement) document.documentElement.requestFullscreen?.(); else document.exitFullscreen?.(); }

/* ===================== ذخیره / خروجی (همه از طریق هسته و سرور فراست) ===================== */
function saveNow() { const k = kernel(); if (k?.saveNow) { k.saveNow('manual').then(() => toast('ذخیره شد')).catch(e => toast(e?.message || 'ذخیره ناموفق')); return; } fallbackLocalSave(); }
function fallbackLocalSave() {
  const ed = surface(); if (!ed) return;
  try { localStorage.setItem('farast.docs.local', JSON.stringify({ title: fdTitle.value, html: ed.innerHTML, t: Date.now() })); toast('در این مرورگر ذخیره شد'); } catch { toast('ذخیره محلی ممکن نشد'); }
}
function markDirty() { const k = kernel(); if (k?.state) { try { k.state.dirty = true; } catch {} } }
function exportDocx() { const k = kernel(); if (k?.saveNow) { doServerExport('docx'); return; } downloadDoc('doc'); }
function exportPdf() { const k = kernel(); if (k?.saveNow) { doServerExport('pdf'); return; } window.print(); }
async function doServerExport(fmt) {
  try {
    const k = kernel(); await k.saveNow('manual');
    const csrf = $('meta[name="csrf-token"]')?.content || '';
    const form = document.createElement('form'); form.method = 'POST'; form.action = '/editor/export/' + fmt; form.style.display = 'none';
    form.innerHTML = '<input type="hidden" name="_token" value="' + csrf + '"><input type="hidden" name="document_id" value="' + (k?.state?.documentId || '') + '">';
    document.body.appendChild(form); form.submit();
  } catch (e) { toast(e?.message || 'export ناموفق بود'); }
}
function fname() { return ((fdTitle.value || 'سند').replace(/[\\/:*?"<>|]/g, '-')); }
function downloadDoc(ext) {
  const ed = surface(); if (!ed) return;
  const wrap = '<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><title>' + fname() + '</title><style>body{font-family:Vazirmatn,Tahoma,sans-serif;font-size:12pt;line-height:1.9;direction:rtl;max-width:800px;margin:24px auto}table{border-collapse:collapse;width:100%}td,th{border:1px solid #888;padding:6px}img{max-width:100%}</style></head><body>' + ed.innerHTML + '</body></html>';
  const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob(['\ufeff' + wrap], { type: ext === 'doc' ? 'application/msword' : 'text/html' })); a.download = fname() + '.' + ext; document.body.appendChild(a); a.click(); a.remove();
}
function newDoc() {
  const ed = surface(); if (!ed) return;
  if ((ed.innerText || '').trim() && !confirm('سند فعلی پاک شود و سند جدید ساخته شود؟')) return;
  ed.innerHTML = '<p><br></p>'; if (fdTitle) fdTitle.value = 'سند بدون عنوان';
  if (coreTitle) { coreTitle.value = 'سند جدید'; coreTitle.dispatchEvent(new Event('input', { bubbles: true })); }
  markDirty(); ed.focus();
}
function openFileLocal() {
  const inp = document.createElement('input'); inp.type = 'file'; inp.accept = '.html,.htm,.txt,.md';
  inp.onchange = () => {
    const f = inp.files[0]; if (!f) return;
    const fr = new FileReader();
    fr.onload = () => {
      const txt = String(fr.result); const ed = surface(); if (!ed) return;
      if (/\.html?$/i.test(f.name) || f.type === 'text/html') ed.innerHTML = sanitizeLite(txt) || '<p><br></p>';
      else ed.innerHTML = txt.split(/\n{2,}/).map(p => '<p>' + p.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/\n/g, '<br>') + '</p>').join('');
      markDirty(); toast('فایل بارگذاری شد');
    };
    fr.readAsText(f);
  };
  inp.click();
}
function sanitizeLite(html) {
  const doc = new DOMParser().parseFromString(html, 'text/html');
  doc.querySelectorAll('script,style,iframe,object,embed,meta,link,base,form,input,button,textarea,select,noscript,svg').forEach(n => n.remove());
  doc.body.querySelectorAll('*').forEach(el => { [...el.attributes].forEach(a => { const n = a.name.toLowerCase(), v = a.value.trim().toLowerCase();
    if (n.startsWith('on') || ((n === 'href' || n === 'src') && (v.startsWith('javascript:') || v.startsWith('vbscript:')))) el.removeAttribute(a.name); }); });
  return doc.body.innerHTML;
}
/* آپلود فایل به خط لولهٔ OCR فراست (دکمهٔ «آپلود» در منوی فایل → همان مسیر امن /editor/upload) */
function uploadToPipeline() { const src = $('#source'); if (src) src.click(); else openFileLocal(); }

/* ===================== منوهای بالا (عین ساختار گوگل داکس) ===================== */
const MENUS = {
  'فایل': [
    ['سند جدید', '', newDoc],
    ['باز کردن…', '', openFileLocal],
    ['آپلود فایل برای تایپ/OCR', '', uploadToPipeline],
    '-',
    ['ذخیره', 'Ctrl+S', saveNow],
    '-',
    ['دانلود Word (سرور)', '', exportDocx],
    ['دانلود PDF (سرور)', '', exportPdf],
    ['چاپ', 'Ctrl+P', () => window.print()],
  ],
  'ویرایش': [
    ['بازگردانی', 'Ctrl+Z', () => runAction('undo')],
    ['ازنو', 'Ctrl+Y', () => runAction('redo')],
    '-',
    ['برش', 'Ctrl+X', () => runAction('cut')],
    ['کپی', 'Ctrl+C', () => runAction('copy')],
    ['چسباندن', 'Ctrl+V', () => runAction('paste')],
    ['انتخاب همه', 'Ctrl+A', () => runAction('selectAll')],
    '-',
    ['جستجو', 'Ctrl+F', openFind],
    ['جایگزینی', 'Ctrl+H', openFind],
  ],
  'نمایش': [
    ['تمام‌صفحه', '', fullscreen],
    ['بزرگنمایی ۱۰۰٪', '', () => { setZoom(1); syncZoomSelect(1); }],
    ['بزرگنمایی ۱۲۵٪', '', () => { setZoom(1.25); syncZoomSelect(1.25); }],
    ['خطکش/پیمایش سند', '', () => runAction('navigation')],
  ],
  'درج': [
    ['تصویر…', '', openImage],
    ['جدول…', '', openTable],
    ['پیوند…', 'Ctrl+K', openLink],
    ['شکست صفحه', '', () => runAction('pageBreak')],
    ['سربرگ', '', () => runAction('header')],
    ['پابرگ', '', () => runAction('footer')],
    ['شماره صفحه', '', () => runAction('pageNumber')],
    '-',
    ['نویسه‌های ویژه…', '', openChars],
    ['تاریخ امروز (شمسی)', '', insertDate],
    ['نظر (Comment)', '', () => runAction('comment')],
  ],
  'قالب': [
    ['متن معمولی', 'Ctrl+Alt+0', () => setBlock('normal')],
    ['عنوان اصلی', '', () => setBlock('title')],
    ['زیرعنوان', '', () => setBlock('subtitle')],
    ['سرفصل ۱', 'Ctrl+Alt+1', () => setBlock('h1')],
    ['سرفصل ۲', 'Ctrl+Alt+2', () => setBlock('h2')],
    ['سرفصل ۳', 'Ctrl+Alt+3', () => setBlock('h3')],
    ['نقل قول', '', () => runAction('quote')],
    '-',
    ['پررنگ', 'Ctrl+B', () => runAction('bold')],
    ['کج', 'Ctrl+I', () => runAction('italic')],
    ['زیرخط‌دار', 'Ctrl+U', () => runAction('underline')],
    ['خط‌خورده', '', () => runAction('strike')],
    ['پاک کردن قالب‌بندی', '', () => runAction('clear') || exec('removeFormat')],
  ],
  'ابزارها': [
    ['تایپ صوتی', 'Ctrl+Shift+S', toggleVoicePanel],
    ['شمارش کلمات', '', wordCountDialog],
    ['دستیار هوش مصنوعی', '', () => runAction('aiPanel')],
    ['عامل سند', '', () => $('#farastAgentPanel')?.classList.add('open')],
    ['تاریخچه نسخه‌ها', '', () => runAction('versions')],
    '-',
    ['تبدیل ارقام به فارسی', '', digitsToFa],
    ['اصلاح «ی» و «ک» عربی', '', fixArabic],
    ['نیم‌فاصله: Shift+Space', '', () => { restoreSel(); exec('insertText', '\u200c'); }],
  ],
  'گسترش': [
    ['افزونه‌ها و ابزارهای قابل نصب', '', () => dialog('گسترش', '<p>معماری ابزارها رجیستری است: هر ابزار با <code>FarastDocsTools.register()</code> قابل افزودن/حذف است و در هر اپ دیگری قابل استفاده مجدد.</p>', [{ label: 'بستن', primary: true }])],
    ['حالت کلاسیک ادیتور', '', goClassic],
  ],
};
function goClassic() { localStorage.setItem('farast.editor.chrome', 'classic'); location.search = '?classic=1' + (location.search ? '&' + location.search.slice(1) : ''); }
const menusEl = $('#fdMenus'); let openBtn = null, ddEl = null;
Object.keys(MENUS).forEach(name => {
  const b = document.createElement('button'); b.type = 'button'; b.className = 'fd-mbtn'; b.textContent = name; b.setAttribute('aria-haspopup', 'true');
  b.addEventListener('mousedown', e => e.preventDefault());
  b.addEventListener('click', () => { if (openBtn === b) return closeMenu(); openMenu(b, name); });
  b.addEventListener('mouseenter', () => { if (openBtn && openBtn !== b) openMenu(b, name); });
  menusEl.appendChild(b);
});
function openMenu(btn, name) {
  closeMenu(); closePop(); openBtn = btn; btn.classList.add('open');
  const dd = document.createElement('div'); dd.className = 'fd-dd'; dd.setAttribute('role', 'menu');
  MENUS[name].forEach(it => {
    if (it === '-') { dd.appendChild(document.createElement('hr')); return; }
    const b = document.createElement('button'); b.type = 'button'; b.className = 'it'; b.setAttribute('role', 'menuitem');
    b.innerHTML = '<span></span><bdi></bdi>'; b.firstChild.textContent = it[0]; b.lastChild.textContent = it[1];
    b.addEventListener('mousedown', e => e.preventDefault());
    b.addEventListener('click', () => { closeMenu(); setTimeout(it[2], 0); });
    dd.appendChild(b);
  });
  document.body.appendChild(dd);
  const r = btn.getBoundingClientRect();
  dd.style.top = r.bottom + 2 + 'px';
  dd.style.left = Math.max(8, Math.min(r.right - dd.offsetWidth, innerWidth - dd.offsetWidth - 8)) + 'px';
  ddEl = dd;
}
function closeMenu() { if (openBtn) openBtn.classList.remove('open'); openBtn = null; if (ddEl) { ddEl.remove(); ddEl = null; } }
document.addEventListener('mousedown', e => {
  if (popEl && !popEl.contains(e.target) && !e.target.closest('[data-tool]')) closePop();
  if (!e.target.closest('.fd-dd') && !e.target.closest('.fd-mbtn')) closeMenu();
});
document.addEventListener('keydown', e => { if (e.key === 'Escape' && !e.ctrlKey && !e.metaKey) { closePop(); closeMenu(); } });

/* ===================== font select ===================== */
const FONTS = [['وزیرمتن','Vazirmatn'],['بی‌نازنین','B Nazanin'],['بی‌تیتر','B Titr'],['بی‌میتر','B Mitra'],['ایران‌سنس','IRANSansX'],['نوتو نسخ','Noto Naskh Arabic'],['امیری','Amiri'],['Tahoma','Tahoma'],['Arial','Arial'],['Times New Roman','Times New Roman'],['Courier New','Courier New']];
(function fillFontSel() {
  const sel = $('.fd-sel.font'); if (!sel) return;
  sel.innerHTML = '';
  FONTS.forEach(([label, val]) => { const o = document.createElement('option'); o.value = val; o.textContent = label; o.style.fontFamily = val; sel.appendChild(o); });
})();

/* ===================== sync UI states ===================== */
function syncToolStates() {
  $$('[data-tool]', toolbarEl).forEach(b => {
    const def = TB.find(t => t.id === b.dataset.tool) || byId[b.dataset.tool];
    if (!def) return;
    if (def.state) { try { b.classList.toggle('on', document.queryCommandState(def.state)); } catch {} }
    else if (def.toggle && def.isOn) b.classList.toggle('on', def.isOn());
  });
  const n = currentEl(); if (!n) return;
  const si = $('#fdSizeInput'); if (si && document.activeElement !== si) si.value = faNum(currentFontSize());
  const fam = getComputedStyle(n).fontFamily.split(',')[0].replace(/["']/g, '').trim().toLowerCase();
  const fs = $('.fd-sel.font');
  if (fs) { const m = FONTS.find(f => f[1].toLowerCase() === fam); if (m) fs.value = m[1]; }
  const blk = n.closest && n.closest('h1,h2,h3,p,blockquote');
  const ss = $('.fd-sel.style');
  if (ss && blk) { let sv = 'normal'; const t = blk.tagName; if (t === 'H1') sv = 'h1'; else if (t === 'H2') sv = 'h2'; else if (t === 'H3') sv = 'h3'; else if (blk.classList.contains('title-s')) sv = 'title'; else if (blk.classList.contains('subtitle-s')) sv = 'subtitle'; ss.value = sv; }
}
function syncZoomSelect(v) { const zs = $('.fd-sel.zoom'); if (zs) zs.value = String(v); }

/* ===================== میان‌برها ===================== */
document.addEventListener('keydown', e => {
  const k = (e.key || '').toLowerCase(), mod = e.ctrlKey || e.metaKey;
  if (!mod) {
    if (e.shiftKey && e.code === 'Space' && !e.ctrlKey && !e.metaKey && !e.altKey && surface()?.contains(document.activeElement)) { e.preventDefault(); restoreSel(); exec('insertText', '\u200c'); }
    return;
  }
  if (e.shiftKey && k === 's') { e.preventDefault(); toggleVoicePanel(); return; }
  if (k === 's' && !e.shiftKey) { e.preventDefault(); saveNow(); }
  else if (k === 'k') { e.preventDefault(); openLink(); }
  else if (k === 'f') { e.preventDefault(); openFind(); }
  else if (k === 'h') { e.preventDefault(); openFind(); }
  else if (e.altKey && ['0','1','2','3'].includes(e.key)) { e.preventDefault(); setBlock(['normal','h1','h2','h3'][+e.key]); }
});

/* ===================== تایپ صوتی — دقیقاً مشابه Google Voice Typing =====================
 * معماری: موتور native مرورگر (Web Speech API) مثل گوگل داکس؛ متن موقت (interim) خاکستری،
 * متن نهایی در مکان نشانگر commit می‌شود؛ دستورات صوتی فارسی؛ زبان‌های fa/en/ar/tr/az.
 * اگر موتور آنلاین فراست (WebSocket) فعال باشد، کاربر می‌تواند از همان پنل FarastVoice استفاده کند.
 */
const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
/* تشخیص موتور موجود: بومی (رایگان، مثل گوگل داکس) یا موتور ابری فراست */
function srLangSupported(lang) {
  try { return typeof rec?.getCapabilities === 'function' ? rec.getCapabilities().languages.includes(lang) : true; } catch { return true; }
}
const vbox = document.createElement('aside');
vbox.className = 'fd-vbox'; vbox.id = 'fdVoiceBox'; vbox.setAttribute('aria-label', 'تایپ صوتی');
vbox.innerHTML = `
  <div class="fd-vh"><span>تایپ صوتی</span><button type="button" class="fd-tb" id="fdVClose" title="بستن" style="width:26px;height:26px">` + iconHTML('close') + `</button></div>
  <select id="fdVLang" aria-label="زبان گفتار">
    <option value="fa-IR">فارسی (ایران)</option>
    <option value="en-US">English (US)</option>
    <option value="ar-SA">العربية</option>
    <option value="tr-TR">Türkçe</option>
    <option value="az-AZ">Azərbaycan dili</option>
  </select>
  <button type="button" class="fd-mic" id="fdMicBtn" aria-label="شروع یا توقف تایپ صوتی"></button>
  <div class="fd-vmsg" id="fdVMsg">برای شروع کلیک کنید</div>
  <label class="fd-vopt"><input type="checkbox" id="fdVCmd" checked> دستورهای صوتی (نقطه، ویرگول، …)</label>
  <label class="fd-vopt"><input type="checkbox" id="fdVDigits" checked> ارقام فارسی</label>
  <button type="button" class="fd-linkbtn" id="fdVHelp">راهنمای دستورها</button>
  <button type="button" class="fd-linkbtn" id="fdVFarrast" hidden>استفاده از موتور حرفه‌ای فراست</button>`;
document.body.appendChild(vbox);
$('#fdMicBtn').innerHTML = iconHTML('mic');
if ($('#source')) $('#source').setAttribute('accept', 'image/*,.pdf,.zip,.docx,.txt,.md,.html');

let rec = null, wantListen = false, interimEl = null, lastStart = 0, fastEnds = 0;
function toggleVoicePanel() {
  const show = !vbox.classList.contains('show');
  vbox.classList.toggle('show', show);
  const vb = $('[data-tool=voice]', toolbarEl); if (vb) vb.classList.toggle('on', show);
  if (show) { if (!SR) { $('#fdVMsg').textContent = 'این مرورگر موتور تشخیص گفتار ندارد (فایرفاکس). از موتور ابری فراست یا Chrome/Edge/Safari استفاده کنید.'; const b2 = $('#fdVFarrast'); if (b2) b2.hidden = !fvrt(); } else startVoice(); }
  else stopVoice();
  syncToolStates();
}
function voiceUI(on) {
  $('#fdMicBtn').classList.toggle('live', on);
  const vb = $('[data-tool=voice]', toolbarEl); if (vb) vb.classList.toggle('rec', on);
  $('#fdVMsg').textContent = on ? 'در حال گوش دادن…' : 'برای شروع کلیک کنید';
}
function showInterim(t) {
  if (!t) return clearInterim();
  const ed = surface(); if (!ed) return;
  if (!interimEl || !ed.contains(interimEl)) {
    restoreSel(); const s = getSelection(); if (!s.rangeCount) return;
    const r = s.getRangeAt(0); r.collapse(false);
    interimEl = document.createElement('span'); interimEl.className = 'fd-vi'; interimEl.setAttribute('contenteditable', 'false');
    try { r.insertNode(interimEl); } catch { return; }
    const nr = document.createRange(); nr.setStartAfter(interimEl); nr.collapse(true); s.removeAllRanges(); s.addRange(nr);
  }
  interimEl.textContent = t;
}
function clearInterim() { if (interimEl) { try { interimEl.remove(); } catch {} interimEl = null; } }
function prevChar() {
  const s = getSelection(); if (!s || !s.rangeCount) return '';
  const n = s.anchorNode, o = s.anchorOffset; let ch = '';
  if (n && n.nodeType === 3) ch = n.data[o - 1] || '';
  else if (o > 0 && n) { const c = n.childNodes[o - 1]; if (c && c.nodeType === 3) ch = c.data.slice(-1); }
  return ch === '\u200b' ? '' : ch;
}
const CMD = {
  'نقطه': '.', 'ویرگول': '،', 'علامت سوال': '؟', 'علامت سؤال': '؟', 'علامت پرسش': '؟', 'علامت تعجب': '!', 'دو نقطه': ':', 'نقطه ویرگول': '؛', 'سه نقطه': '…',
  'خط تیره': '–', 'باز کردن پرانتز': '(', 'بستن پرانتز': ')', 'باز کردن گیومه': '«', 'بستن گیومه': '»'
};
function normalizeFaText(t) { return t.replace(/ي/g, 'ی').replace(/ك/g, 'ک').replace(/ۀ/g, 'ه‌ی').replace(/\s+/g, ' ').trim(); }
function commitFinal(raw) {
  clearInterim();
  const fa = $('#fdVLang').value.startsWith('fa');
  let t = fa ? normalizeFaText(raw) : raw.trim();
  if (!t) return;
  const cv = kcmd('insertText');
  const insert = text => {
    if (text === '\n') { restoreSel(); try { document.execCommand('insertLineBreak'); return true; } catch {} }
    if (cv) { try { return kernel().execute(cv, text); } catch {} }
    restoreSel();
    try { return document.execCommand('insertText', false, text); } catch { return false; }
  };
  if (fa && $('#fdVCmd').checked) {
    if (/^(خط جدید|خط بعد|سر خط)$/.test(t)) { insert('\n'); markDirty(); return; }
    if (/^(پاراگراف جدید|پاراگراف بعد)$/.test(t)) { if (!exec('insertParagraph')) insert('\n\n'); markDirty(); return; }
    if (/^(بازگردانی|برگردان|لغو)$/.test(t)) { runAction('undo'); return; }
    if (/^(آخرین کلمه را پاک کن|پاک کردن کلمه|حذف کلمه)$/.test(t)) { const s = getSelection(); if (s) { s.collapseToEnd(); if (s.modify) s.modify('extend', 'backward', 'word'); document.execCommand('delete'); } markDirty(); return; }
    if (CMD[t]) { insert(CMD[t]); markDirty(); return; }
    t = t.replace(/\s*(علامت سوال|علامت سؤال|علامت پرسش)\s*/g, '؟ ').replace(/\s*علامت تعجب\s*/g, '! ').replace(/\s*ویرگول\s*/g, '، ').replace(/\s+(سه نقطه)$/, '…')
      .replace(/(^|\s)(?<!دو |سه |یک )نقطه$/, '.').replace(/\s+(دو نقطه)$/, ':').replace(/\s+([.،؛؟!:…)»,;?])/g, '$1').trim();
  }
  if (fa && $('#fdVDigits').checked) t = toFa(t);
  const pc = prevChar();
  if (pc && !/\s/.test(pc) && !/^[\s.،؛؟!:…)»,;?]/.test(t) && !/[(«]/.test(pc)) t = ' ' + t;
  if (t) { insert(t); markDirty(); }
}
function startVoice() {
  if (!SR) { toast('مرورگر شما از تایپ صوتی پشتیبانی نمی‌کند'); return; }
  if (wantListen) return;
  restoreSel();
  rec = new SR(); rec.lang = $('#fdVLang').value; rec.continuous = true; rec.interimResults = true; rec.maxAlternatives = 1;
  rec.onstart = () => { lastStart = Date.now(); voiceUI(true); };
  rec.onresult = e => {
    let interim = '';
    for (let i = e.resultIndex; i < e.results.length; i++) {
      const r = e.results[i];
      if (r.isFinal) commitFinal(r[0].transcript); else interim += r[0].transcript;
    }
    showInterim(interim);
  };
  rec.onerror = e => {
    if (e.error === 'no-speech' || e.error === 'aborted') return;
    if (e.error === 'not-allowed' || e.error === 'service-not-allowed') { wantListen = false; $('#fdVMsg').textContent = 'دسترسی به میکروفون مسدود است'; toast('اجازهٔ میکروفون را در مرورگر فعال کنید (سایت باید با https باز شود)'); }
    else if (e.error === 'audio-capture') { wantListen = false; toast('میکروفونی پیدا نشد'); }
    else if (e.error === 'language-not-supported') { wantListen = false; toast('این زبان در مرورگر شما پشتیبانی نمی‌شود'); }
    else if (e.error === 'network') { toast('برای تشخیص گفتار به اینترنت نیاز است'); }
  };
  rec.onend = () => {
    clearInterim();
    if (wantListen) {
      fastEnds = (Date.now() - lastStart < 500) ? fastEnds + 1 : 0;
      if (fastEnds > 4) { wantListen = false; voiceUI(false); toast('تایپ صوتی متوقف شد'); return; }
      try { rec.start(); } catch {}
    } else voiceUI(false);
  };
  wantListen = true; fastEnds = 0;
  try { rec.start(); } catch { wantListen = false; }
}
function stopVoice() { wantListen = false; clearInterim(); if (rec) { try { rec.stop(); } catch {} } voiceUI(false); }
$('#fdMicBtn').addEventListener('mousedown', e => e.preventDefault());
$('#fdMicBtn').addEventListener('click', () => wantListen ? stopVoice() : startVoice());
$('#fdVClose').addEventListener('click', () => { vbox.classList.remove('show'); stopVoice(); syncToolStates(); });
$('#fdVLang').addEventListener('change', () => { if (wantListen) { stopVoice(); setTimeout(startVoice, 250); } });
vbox.addEventListener('mousedown', e => { if (!/SELECT|OPTION|INPUT/.test(e.target.tagName)) e.preventDefault(); });
$('#fdVHelp').addEventListener('click', () => dialog('دستورهای صوتی (فارسی)',
  `<p style="line-height:2;margin:0">بگویید:<br>
  «<b>نقطه</b>» ← .<br>«<b>ویرگول</b>» ← ،<br>«<b>علامت سوال</b>» ← ؟<br>«<b>علامت تعجب</b>» ← !<br>
  «<b>دو نقطه</b>» ← :<br>«<b>نقطه ویرگول</b>» ← ؛<br>«<b>سه نقطه</b>» ← …<br>
  «<b>خط جدید</b>» ← رفتن به خط بعد<br>«<b>پاراگراف جدید</b>» ← پاراگراف تازه<br>
  «<b>آخرین کلمه را پاک کن</b>» ← حذف کلمهٔ قبل<br>«<b>بازگردانی</b>» ← بازگردانی آخرین تغییر</p>
  <p style="font-size:12px;color:#5f6368">میان‌بر باز و بسته کردن تایپ صوتی: Ctrl+Shift+S</p>`, [{ label: 'بستن', primary: true }]));
/* پل به موتور حرفه‌ای فراست (WebSocket/سرویس ابری) در صورت موجود بودن */
const fvrt = () => window.FarastVoiceRuntime;
if (fvrt()) $('#fdVFarrast').hidden = false;
$('#fdVFarrast').addEventListener('click', () => { const rt = fvrt(); if (rt?.open) { rt.open(); vbox.classList.remove('show'); stopVoice(); } });

/* ===================== رجیستری عمومی ابزارها (برای استفادهٔ مجدد در سایر اپ‌ها) ===================== */
window.FarastDocsTools.registerAll = tools => (tools || []).forEach(t => window.FarastDocsTools.register(t));
window.FarastDocsChrome = {
  version: '1.0.0',
  toast, dialog, showPop, closePop, runAction, exec, setZoom, setBlock, openFind, openLink, openImage, openTable,
  voice: { start: startVoice, stop: stopVoice, toggle: toggleVoicePanel, panel: vbox },
  surface, kernel,
};

/* ===================== شروع ===================== */
rebuildToolbar();
syncToolStates();
/* حفظ ترجیح کاربر */
if (localStorage.getItem('farast.editor.chrome') === 'classic') goClassic();
})();
