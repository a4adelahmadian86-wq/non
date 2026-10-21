/* FARAST · Google Docs Chrome for the Editor (پوستهٔ گوگل داکس روی هستهٔ فراست) — v2
 * ============================================================
 * معماری:
 *  - فقط «کروم» (هدر، منوها، نوار ابزار، نوار پایین، پنل صوتی) ساخته می‌شود.
 *  - همهٔ فرمان‌ها به رجیستری واقعی هسته (window.FarastEditor.commands) روت می‌شوند؛
 *    اگر هسته نبود، fallback مستقل روی contenteditable.
 *  - تایپ صوتی سمت سرور (editor-voice-hub.js): موتور ابری فراست ← سرویس STT دلخواه.
 *  - رجیستری ابزار: FarastDocsTools.register/unregister برای استفادهٔ مجدد در هر اپ دیگر.
 *  - بازگشت امن: دکمهٔ «حالت کلاسیک» یا ?classic=1 .
 */
(() => {
'use strict';
if (window.__farastDocsChromeLoaded) return;
window.__farastDocsChromeLoaded = true;

const app = document.getElementById('farastWord');
if (!app) return;
const params = new URLSearchParams(location.search);
/* خروج قطعی از حالت کلاسیک: تنها راه بازگشت، حذف صریح پرچم است (دکمهٔ «حالت کلاسیک» در منوی گسترش).
 * هر بار که پوستهٔ گوگل داکس لود می‌شود، پرچم‌های قدیمی پاک می‌گردند تا صفحه هیچ‌وقت خودکار
 * به حالت کلاسیک نیفتد (باگ گزارش‌شدهٔ کاربر: «دائم خودکار روی حالت کلاسیک می‌رود»). */
try {
  if (params.get('classic') === '1' || (localStorage.getItem('farast.editor.chrome') === 'classic' && params.get('keep-classic') === '1')) {
    localStorage.removeItem('farast.editor.chrome'); // درخواست صریح/تازهٔ کاربر → حالت کلاسیک
    return;
  } else if (localStorage.getItem('farast.editor.chrome') === 'classic') {
    localStorage.removeItem('farast.editor.chrome'); // پرچم کهنه — پوسته باید فعال بماند
  }
} catch {}

const $ = (s, r = document) => r.querySelector(s);
const $$ = (s, r = document) => [...r.querySelectorAll(s)];
const toFa = s => String(s).replace(/[0-9]/g, d => '۰۱۲۳۴۵۶۷۸۹'[d]);
const faNum = n => toFa(String(n));
const fromFa = s => String(s).replace(/[۰-۹]/g, d => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d));

/* ===================== آیکون‌ها: Material Symbols محلی + فالبک SVG کاملاً آفلاین ===================== */
const SVG_PATHS = {
  undo:'M3 7h11a5 5 0 1 1 0 10H8|M3 7l4-4M3 7l4 4',
  redo:'M21 7H10a5 5 0 1 0 0 10h6|M21 7l-4-4M21 7l-4 4',
  print:'M6 9V3h12v6|M6 18H4a1 1 0 0 1-1-1v-6a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v6a1 1 0 0 1-1 1h-2|M6 14h12v7H6z',
  spellcheck:'M4 7l3 6 3-6|M4 17h7|M15 13l2 2 4-5',
  zoom_in:'M10 4a6 6 0 1 0 0 12 6 6 0 0 0 0-12zM15 15l5 5|M10 7v6M7 10h6',
  remove:'M5 12h14',
  add:'M12 5v14M5 12h14',
  format_bold:'M7 5h6a3.5 3.5 0 0 1 0 7H7zM7 12h7a3.5 3.5 0 0 1 0 7H7zM7 5v14',
  format_italic:'M10 4h8M6 20h8M14 4l-4 16',
  format_underlined:'M7 4v7a5 5 0 0 0 10 0V4|M5 20h14',
  strikethrough_s:'M5 8h14|M8 12H6.5a3.5 3.5 0 0 0 0 7H9|M16 12h1.5a3.5 3.5 0 0 1 0 7H13|M8 6.5A3.5 3.5 0 0 1 11.5 4H13',
  format_color_text:'M5 18L11 4h2l6 14|M8 13h8|M4 21h16',
  ink_highlighter:'M4 20h6L20 10l-4-4L6 16z|M14 6l4 4',
  link:'M9.5 14.5l5-5|M8 12l-2 2a3 3 0 1 0 4 4l2-2|M16 12l2-2a3 3 0 1 0-4-4l-2 2',
  image:'M4 5h16v14H4z|M8.5 10.5a1 1 0 1 1-2 0 1 1 0 0 1 2 0|M5 17l5-5 3 3 4-4 2 2',
  table:'M4 5h16v14H4z|M4 10h16M4 15h16M9 5v14M15 5v14',
  format_align_right:'M4 6h16|M10 12h10|M7 18h13',
  format_align_center:'M4 6h16|M7 12h10|M5 18h14',
  format_align_left:'M4 6h16|M4 12h10|M4 18h13',
  format_align_justify:'M4 6h16|M4 12h16|M4 18h16',
  format_line_spacing:'M4 5h2M4 12h2M4 19h2|M9 5h11M9 12h11M9 19h11',
  checklist:'M9 6l2 2 4-4|M9 12l2 2 4-4|M9 18l2 2 4-4|M3 6h.01M3 12h.01M3 18h.01',
  format_list_bulleted:'M9 6h11M9 12h11M9 18h11|M4.5 6h.01M4.5 12h.01M4.5 18h.01',
  format_list_numbered:'M10 6h10M10 12h10M10 18h10|M4 6h1v4|M4 14h2l-2 3h2',
  format_indent_decrease:'M4 6h16M10 12h10M10 18h10|M8 12l-4 3 4 3z',
  format_indent_increase:'M4 6h16M10 12h10M10 18h10|M4 12l4 3-4 3z',
  format_textdirection_r_to_l:'M10 10h5a2.5 2.5 0 0 0 0-5h-3v10|M10 4v14|M21 8l-3 3 3 3|M4 8v6M4 8h3',
  format_textdirection_l_to_r:'M14 10H9a2.5 2.5 0 0 1 0-5h3v10|M14 4v14|M3 8l3 3-3 3|M20 8v6',
  format_clear:'M5 5l14 14|M8 16h9|M12 4l-3 8h6l-1.5-4z',
  mic:'M12 3a3 3 0 0 1 3 3v5a3 3 0 0 1-6 0V6a3 3 0 0 1 3-3z|M6 11a6 6 0 0 0 12 0|M12 17v4M9 21h6',
  close:'M6 6l12 12M18 6L6 18',
  smart_toy:'M12 3a2 2 0 0 1 2 2v1h3a2 2 0 0 1 2 2v3h1a2 2 0 0 1 0 4h-1v3a2 2 0 0 1-2 2h-3v1a2 2 0 0 1-4 0v-1H7a2 2 0 0 1-2-2v-3H4a2 2 0 0 1 0-4h1V8a2 2 0 0 1 2-2h3V5a2 2 0 0 1 2-2zM9 11h.01M15 11h.01M9 15h6',
  select_all:'M5 5h14v14H5z|M8 9h8M8 12h8M8 15h5',
};
function iconHTML(name) {
  const d = SVG_PATHS[name];
  if (!d) return '<span class="ms" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="8"/></svg></span>';
  const paths = d.split('|').map(p => '<path d="' + p + '"/>').join('');
  return '<span class="ms" aria-hidden="true"><svg viewBox="0 0 24 24">' + paths + '</svg></span>';
}

/* ===================== Tool Registry (معماری ماژولار) ===================== */
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
function kcmd(name) { const k = kernel(); if (!k?.commands) return null; return k.commands.has(name) ? name : null; }
function surface() {
  const k = kernel();
  if (k?.state?.model) return $('#pagesViewport .farast-editor[contenteditable="true"], #pagesViewport [contenteditable="true"]') || $('#fdFallbackEditor');
  return $('#fdFallbackEditor') || $('#pagesViewport .farast-editor,#pagesViewport [contenteditable="true"]');
}
function exec(cmd, val) {
  const ed = surface(); if (!ed) return false;
  ed.focus({ preventScroll: true });
  try { return document.execCommand(cmd, false, val ?? null); } catch { return false; }
}
/* اجرای فرمان: اول رجیستری واقعی Kernel (بدون prompt)، بعد fallback مستقل */
function runAction(name, input) {
  /* فرمان‌های صوتی/پنلی را هرگز به Runtime قدیمی واگذار نمی‌کنیم؛
     در حالت داکس، پنل تایپ صوتی گوگل-داکسی تنها مسیر معتبر است. */
  if (name === 'voice') { toggleVoicePanel(); return true; }
  const c = kcmd(name);
  if (c) { try { return kernel().execute(c, input); } catch (e) { toast(e?.message || 'اجرای فرمان ناموفق بود'); return false; } }
  return standaloneFallback(name, input);
}
function applyRunPatch(patch) {
  const c = kcmd('FormatText');
  if (!c) return false;
  try { kernel().execute(c, { patch }); return true; } catch { return false; }
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
  if (name === 'clear') return exec('removeFormat');
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
{
  const vpEl = $('#pagesViewport');
  const hasKernelPage = !!(window.FarastEditor || (vpEl && vpEl.querySelector('.farast-page, .farast-editor')));
  if (!hasKernelPage) { const fbc = document.createElement('div'); fbc.className = 'fd-canvas'; const fbp = document.createElement('div'); fbp.className = 'fd-pages'; fbp.appendChild(fbEd); fbc.appendChild(fbp); app.appendChild(fbc); }
  else fbEd.style.display = 'none';
}
app.prepend(top);

const toolbarEl = document.createElement('div');
toolbarEl.className = 'fd-toolbar';
toolbarEl.id = 'fdToolbar';
toolbarEl.setAttribute('role', 'toolbar');
toolbarEl.setAttribute('aria-label', 'ابزارهای ویرایش');
const workspace = $('main.farast-workspace', app);
if (workspace) workspace.before(toolbarEl); else top.after(toolbarEl);

const bottom = document.createElement('div');
bottom.className = 'fd-bottom';
bottom.innerHTML = `<button type="button" id="fdMicOpen" class="fd-micpill" title="تایپ صوتی (Ctrl+Shift+S)"><span class="micdot" aria-hidden="true"></span>تایپ صوتی</button>
  <span id="fdWords">۰ کلمه</span><span id="fdChars">۰ نویسه</span><span id="fdPages">۱ صفحه</span>
  <span class="fd-grow"></span>
  <button type="button" id="fdSaveBtn" title="ذخیره (Ctrl+S)">💾 ذخیره</button>
  <button type="button" id="fdExpD">Word</button><button type="button" id="fdExpP">PDF</button>
  <button type="button" id="fdClassic" title="بازگشت به ظاهر قبلی ادیتور">حالت کلاسیک</button>`;
/* نوار پایین داخل ستون flex خود ادیتور قرار می‌گیرد تا همیشه ته صفحه بچسبد */
app.appendChild(bottom);
try { const cs = getComputedStyle(app); if (cs.display === 'inline' || cs.display === 'contents') app.classList.add('fd-block-root'); } catch {}
bottom.querySelector('#fdMicOpen').addEventListener('click', () => toggleVoicePanel());
bottom.querySelector('#fdSaveBtn').addEventListener('click', () => fdSaveManual());
bottom.querySelector('#fdExpD').addEventListener('click', exportDocx);
bottom.querySelector('#fdExpP').addEventListener('click', exportPdf);
bottom.querySelector('#fdClassic').addEventListener('click', goClassic);
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

/* وضعیت ذخیره از #saveState هسته منتقل می‌شود */
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
  if (kernel()) { applyRunPatch(kind === 'fore' ? { color: c } : { highlight: c }); }
  else standaloneFallback(kind === 'fore' ? 'color' : 'highlight', c);
  const bar = $(kind === 'fore' ? '#fdCbarFore' : '#fdCbarBack');
  if (bar) bar.style.background = reset ? (kind === 'fore' ? '#000' : '#fff') : c;
  if (!keepOpen) closePop();
}

/* ===================== اندازه قلم ===================== */
function applyFontSize(pt) {
  if (kernel()) {
    if (applyRunPatch({ fontSize: pt + 'pt' })) { sizeSel = pt; syncToolStates(); return true; }
    return false;
  }
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
let wantListen = false; // با startVoice/stopVoice در بخش صوت به‌روز می‌شود
/* حفظ انتخاب متن هنگام ویرایش دستی در حین گوش دادن (منطق گوگل داکس: تایپ دستی، صوت را قطع نمی‌کند) */
document.addEventListener('selectionchange', () => {
  const s = getSelection(); const ed = surface();
  if (ed && s.rangeCount && ed.contains(s.anchorNode)) {
    const r = s.getRangeAt(0);
    saved = r.cloneRange(); window.__fdSavedRange = saved;
    if (wantListen) hub()?.hideInterimSel?.(); // کاربر با دست نشانگر را جابه‌جا کرد؛ ماسک interim کنار می‌رود
    syncToolStates();
  }
});
function restoreSel() { const ed = surface(); if (!ed) return; ed.focus({ preventScroll: true }); if (saved) { const s = getSelection(); s.removeAllRanges(); s.addRange(saved); } }
function currentEl() { const ed = surface(); const s = getSelection(); let n = s && s.anchorNode; if (!n || !ed || !ed.contains(n)) return null; return n.nodeType === 3 ? n.parentElement : n; }

/* ===================== نوار ابزار: تعریف ابزارها (عین ترتیب گوگل داکس) ===================== */
const MIC_SVG = '<svg viewBox="0 0 24 24" fill="currentColor" stroke="none"><path d="M12,2A3,3 0 0,1 15,5V11A3,3 0 0,1 12,14A3,3 0 0,1 9,11V5A3,3 0 0,1 12,2M19,11C19,14.53 16.39,17.44 13,17.93V21H11V17.93C7.61,17.44 5,14.53 5,11H7A5,5 0 0,0 12,16A5,5 0 0,0 17,11H19Z"/></svg>';
const TB = [
  { id:'undo', icon:'undo', title:'بازگردانی (Ctrl+Z)', run:()=>runAction('undo') },
  { id:'redo', icon:'redo', title:'ازنو (Ctrl+Y)', run:()=>runAction('redo') },
  { id:'print', icon:'print', title:'چاپ (Ctrl+P)', run:()=>runAction('print') || window.print() },
  { id:'spell', icon:'spellcheck', title:'غلط‌یاب املایی', toggle:true, isOn:()=>{const e=surface();return !!e&&e.spellcheck===true}, run:()=>{const e=surface();if(e){e.spellcheck=!e.spellcheck;toast(e.spellcheck?'غلط‌یاب روشن شد':'غلط‌یاب خاموش شد');syncToolStates();}} },
  { sep:true },
  { id:'zoom', kind:'select', title:'بزرگنمایی', cls:'zoom', options:[['0.6','۶۰٪'],['0.8','۸۰٪'],['1','۱۰۰٪'],['1.2','۱۲۰٪'],['1.4','۱۴۰٪'],['1.5','۱۵۰٪']], run:v=>setZoom(v) },
  { sep:true },
  { id:'style', kind:'select', title:'سبک پاراگراف', cls:'style', options:[['normal','متن معمولی'],['title','عنوان اصلی'],['subtitle','زیرعنوان'],['h1','سرفصل ۱'],['h2','سرفصل ۲'],['h3','سرفصل ۳'],['quote','نقل قول']], run:v=>setBlock(v) },
  { sep:true },
  { id:'font', kind:'select', title:'فونت', cls:'font', options:null, run:v=>setFont(v) },
  { sep:true },
  { id:'sizeDown', icon:'remove', title:'کاهش اندازه', run:()=>stepSize(-1) },
  { id:'sizeInput', kind:'size' },
  { id:'sizeUp', icon:'add', title:'افزایش اندازه', run:()=>stepSize(1) },
  { sep:true },
  { id:'bold', icon:'format_bold', title:'پررنگ (Ctrl+B)', state:'bold', run:()=>toggleInline('bold',{bold:true}) },
  { id:'italic', icon:'format_italic', title:'کج (Ctrl+I)', state:'italic', run:()=>toggleInline('italic',{italic:true}) },
  { id:'underline', icon:'format_underlined', title:'زیرخط‌دار (Ctrl+U)', state:'underline', run:()=>toggleInline('underline',{underline:true}) },
  { id:'strike', icon:'strikethrough_s', title:'خط‌خورده', state:'strikeThrough', run:()=>toggleInline('strike',{strike:true}) },
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
  { id:'outdent', icon:'format_indent_decrease', title:'کاهش تورفتگی', run:()=>runAction('outdent',{delta:-1}) },
  { id:'indent', icon:'format_indent_increase', title:'افزایش تورفتگی', run:()=>runAction('indent',{delta:1}) },
  { sep:true },
  { id:'dirRTL', icon:'format_textdirection_r_to_l', title:'جهت متن: راست به چپ', run:()=>setDir('rtl') },
  { id:'dirLTR', icon:'format_textdirection_l_to_r', title:'جهت متن: چپ به راست', run:()=>setDir('ltr') },
  { id:'clear', icon:'format_clear', title:'پاک کردن قالب‌بندی', run:()=>{ if (!applyRunPatch({bold:false,italic:false,underline:false,strike:false,color:'#182433',highlight:'transparent'})) exec('removeFormat'); markDirty(); } },
  { sep:true },
  { id:'voice', icon:'mic', title:'تایپ صوتی (Ctrl+Shift+S)', toggle:true, isOn:()=>vbox.classList.contains('show'), run:()=>toggleVoicePanel(), pill:true },
  { id:'agent', icon:'smart_toy', title:'عامل هوشمند سند (پیش‌نمایش ← تأیید شما ← اجرا روی Kernel)', run:()=>openAgentPanel() },
  { id:'selectAll', icon:'select_all', title:'انتخاب همه (Ctrl+A)', run:()=>fdRunCoreCommand('selectAll') },
];
window.__fdToolDefs = TB.slice(); // برای رجیستری ابزارهای جانبی

/* ===================== هماهنگی با پنل «عامل سند» و تست‌های مرورگری =====================
 * - دکمهٔ [data-agent-open] در نوار پایین: editor-agent.js فقط روی این selector کلیک پنل را وصل می‌کند.
 * - سلکتورهای قدیمی تست‌ها ([data-command="selectAll"]، .farast-ribbon …) باید حتی در حالت گوگل‌داکسی زنده بمانند؛
 *   بنابراین یک پل رویداد از دکمه‌های fd-tb به هسته (یا execCommand در حالت مستقل) ساخته می‌شود. */
const FD_CMD_MAP = { bold:'bold', italic:'italic', underline:'underline', strike:'strikeThrough',
  ul:'insertUnorderedList', ol:'insertOrderedList', left:'justifyLeft', center:'justifyCenter',
  right:'justifyRight', justify:'justifyFull', indent:'indent', outdent:'outdent' };
function fdRunCoreCommand(name) {
  if (name === 'selectAll') {
    const ed = surface(); if (!ed) return false;
    try {
      const sel = window.getSelection(); const range = document.createRange();
      range.selectNodeContents(ed); sel.removeAllRanges(); sel.addRange(range);
      markDirty(); return true;
    } catch { return false; }
  }
  if (FD_CMD_MAP[name]) {
    if (kernel()) { const c = kcmd(name) || kcmd(FD_CMD_MAP[name]); if (c) { try { kernel().execute(c, {}); markDirty(); return true; } catch {} } }
    return exec(FD_CMD_MAP[name]);
  }
  return false;
}
function wireLegacySelectors(root) {
  $$('[data-command]', root || document).forEach(el => {
    if (el.__fdWired) return; el.__fdWired = true;
    el.addEventListener('click', e => {
      if (el.tagName === 'SELECT' || el.tagName === 'INPUT') return; // کنترل‌های خود هسته دست‌نخورده بمانند
      e.preventDefault();
      fdRunCoreCommand(String(el.dataset.command || ''));
    });
  });
}
function openAgentPanel() {
  const panel = $('#farastAgentPanel');
  let opener = $('#fdAgentOpen');
  if (!opener && panel) {
    opener = document.createElement('button');
    opener.type = 'button'; opener.id = 'fdAgentOpen';
    opener.setAttribute('data-agent-open', ''); opener.hidden = true;
    document.body.appendChild(opener);
    opener.click(); // editor-agent.js همین حالا listener را وصل می‌کند
  } else if (opener) { opener.click(); }
  if (panel) { panel.classList.add('open'); setTimeout(() => $('#farastAgentInput')?.focus(), 60); }
  else toast('پنل عامل در این صفحه در دسترس نیست.');
}

function rebuildToolbar() {
  toolbarEl.innerHTML = '';
  TB.forEach(def => addTool(def));
  registry.forEach(t => { if (!TB.find(d => t.id === d.id)) addTool(t); });
  /* دکمهٔ بارز «تایپ صوتی» با میکروفون SVG تضمینی (بدون وابستگی به فونت آیکون) */
  const vb = $('[data-tool=voice]', toolbarEl);
  if (vb) {
    vb.classList.add('fd-micbtn');
    vb.innerHTML = MIC_SVG + '<span class="fd-miclbl">تایپ صوتی</span>';
    /* اتصال مجدد: اگر رویداد قبلی گم شده باشد، کلیک حتماً پنل گوگل‌داكسی را باز کند */
    const old = vb.cloneNode(true); vb.replaceWith(old);
    const v2 = $('[data-tool=voice]', toolbarEl);
    v2.addEventListener('mousedown', e => e.preventDefault());
    v2.addEventListener('click', () => { closePop(); toggleVoicePanel(); });
  }
  /* پلی که سلکتورهای کلاسیک/تست‌محور را در حالت گوگل‌داكسی زنده نگه می‌دارد */
  const legacy = document.createElement('span');
  legacy.className = 'fd-legacy-bridge'; legacy.hidden = true;
  legacy.setAttribute('aria-hidden', 'true');
  ['selectAll','bold','italic','underline','strike','ul','ol','left','center','right','justify','indent','outdent']
    .forEach(c => { const b = document.createElement('button'); b.type = 'button'; b.dataset.command = c; legacy.appendChild(b); });
  toolbarEl.appendChild(legacy);
  wireLegacySelectors(legacy);
  wireLegacySelectors(document); // نوار ribbon کلاسیک اگر در DOM بود
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
    inp.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); const v = parseFloat(fromFa(String(inp.value))); if (v) { applyFontSize(v); sizeSel = v; } } });
    box.appendChild(inp); toolbarEl.appendChild(box); return;
  }
  const b = document.createElement('button'); b.type = 'button'; b.className = 'fd-tb'; b.dataset.tool = def.id; b.title = def.title;
  b.innerHTML = iconHTML(def.icon);
  if (def.colorBar) { b.classList.add('color'); const bar = document.createElement('span'); bar.className = 'fd-cbar'; bar.id = def.colorBar === 'fore' ? 'fdCbarFore' : 'fdCbarBack'; bar.style.background = def.colorBar === 'fore' ? '#000' : '#ffff00'; b.appendChild(bar); }
  b.addEventListener('mousedown', e => e.preventDefault()); // حفظ انتخاب متن
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
/* پررنگ/کج/…: روی هسته به‌صورت toggle واقعی (patch خنثی اگر روشن بود) */
function toggleInline(cmdName, onPatch) {
  if (kernel()) {
    let isOn = false;
    try { isOn = document.queryCommandState(cmdName === 'strike' ? 'strikeThrough' : cmdName); } catch {}
    if (isOn) {
      const off = {}; Object.keys(onPatch).forEach(k => off[k] = false);
      if (cmdName === 'strike') off.strike = false;
      return applyRunPatch(off);
    }
    return applyRunPatch(onPatch);
  }
  return standaloneFallback(cmdName);
}
function setFont(v) {
  if (kernel()) { if (applyRunPatch({ fontFamily: v })) return true; }
  return standaloneFallback('font', v);
}

/* ===================== سبک پاراگراف / جهت / فاصله ===================== */
function setBlock(kind) {
  const cmdMap = { normal:'normal', h1:'h1', h2:'h2', h3:'h3', quote:'quote' };
  if (cmdMap[kind] && kcmd(cmdMap[kind])) return runAction(cmdMap[kind]);
  if (kernel()) return false; // روی هسته، سبک‌های سفارشی (title/subtitle) فقط DOM‌محور نباشند
  restoreSel();
  const tag = { normal:'p', title:'p', subtitle:'p', h1:'h1', h2:'h2', h3:'h3', quote:'blockquote' }[kind] || 'p';
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
    b.onclick = () => {
      if (kcmd('lineHeight')) { try { kernel().execute('lineHeight', v); closePop(); return; } catch {} }
      restoreSel(); const el = currentEl(); const blk = el?.closest?.('p,h1,h2,h3,li,td'); if (blk) blk.style.lineHeight = v; markDirty(); closePop();
    };
    wrap.appendChild(b);
  });
  [['افزودن فاصله قبل از پاراگراف','before'],['حذف فاصله قبل از پاراگراف','before0'],['افزودن فاصله بعد از پاراگراف','after'],['حذف فاصله بعد از پاراگراف','after0']].forEach(([l, k]) => {
    const b = document.createElement('button'); b.type = 'button'; b.textContent = l;
    b.onclick = () => {
      if (kcmd('paragraphSpacing')) { try { kernel().execute('paragraphSpacing', (k === 'after' ? 14 : k === 'after0' ? 0 : undefined) ?? 10); closePop(); return; } catch {} }
      restoreSel(); const el = currentEl(); const blk = el?.closest?.('p,h1,h2,h3,li');
      if (blk) { if (k.startsWith('before')) blk.style.marginTop = (k === 'before' ? '14px' : '0px'); else blk.style.marginBottom = (k === 'after' ? '14px' : '0px'); }
      markDirty(); closePop();
    };
    wrap.appendChild(b);
  });
  showPop(anchor, wrap);
}
function setDir(d) {
  if (kcmd(d)) { return runAction(d); }
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
      const c = kcmd('InsertLink') || kcmd('insertLink');
      if (c) { restoreSel(); try { kernel().execute(c, { url: u, href: u, text: u }); return; } catch {} }
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
  const c = kcmd('InsertImage') || kcmd('insertImage');
  if (c) { try { restoreSel(); return kernel().execute(c, { file }); } catch {} }
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
      const ct = kcmd('InsertTable') || kcmd('insertTable');
      if (ct) { try { return kernel().execute(ct, { rows: r, columns: c, cols: c }); } catch {} }
      const row = '<tr>' + '<td><br></td>'.repeat(c) + '</tr>';
      restoreSel(); exec('insertHTML', '<table><tbody>' + row.repeat(r) + '</tbody></table><p><br></p>'); markDirty();
    } }
  ]);
}

/* ===================== جستجو و جایگزینی (Ctrl+H/F) ===================== */
function openFind() {
  const body = `<label>جستجو</label><input type="text" id="fdFA"><label>جایگزینی با</label><input type="text" id="fdFB"><div id="fdFM" style="font-size:12px;color:#5f6368;min-height:18px"></div>`;
  const d = dialog('جستجو و جایگزینی', body, [
    { label: 'بستن' },
    { label: 'جایگزینی همه', stay: true, fn: dd => {
      const a = $('#fdFA', dd).value, b = $('#fdFB', dd).value; if (!a) return false;
      const ed = surface(); let n = 0;
      const w = document.createTreeWalker(ed, NodeFilter.SHOW_TEXT), list = [];
      while (w.nextNode()) list.push(w.currentNode);
      list.forEach(t => { if (t.nodeValue.includes(a)) { n += t.nodeValue.split(a).length - 1; t.nodeValue = t.nodeValue.split(a).join(b); } });
      $('#fdFM', dd).textContent = faNum(n) + ' مورد جایگزین شد'; markDirty(); reRender(); return false;
    } },
    { label: 'بعدی', primary: true, stay: true, fn: dd => { const a = $('#fdFA', dd).value; if (a) { const ok = window.find(a, false, false, true, false, false, false); $('#fdFM', dd).textContent = ok ? '' : 'موردی پیدا نشد'; } return false; } },
  ]);
  d.addEventListener('keydown', e => { if (e.key === 'Enter') e.preventDefault(); });
  const s = getSelection(); if (s && s.rangeCount && surface()?.contains(s.anchorNode) && s.toString()) $('#fdFA', d).value = s.toString();
}
function reRender() { const k = kernel(); try { k?.flushLayout?.(true); } catch {} }

/* ===================== نویسه‌های ویژه / تاریخ ===================== */
function openChars() {
  const chars = ['\u200c','،','؛','؟','«','»','٪','٫','٬','…','–','—','“','”','°','±','×','÷','≈','≠','≤','≥','∞','√','π','©','®','™','€','£','$','¥','½','¼','¾','←','→','↑','↓','✓','★','♥','•','۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
  const g = document.createElement('div'); g.className = 'fd-chars';
  chars.forEach(c => { const b = document.createElement('button'); b.type = 'button'; b.textContent = c === '\u200c' ? 'نیم‌فاصله' : c; b.title = c === '\u200c' ? 'نیم‌فاصله (Shift+Space)' : c; if (c === '\u200c') b.style.fontSize = '10px';
    b.onclick = () => insertTextSmart(c);
    g.appendChild(b); });
  dialog('نویسه‌های ویژه', g, [{ label: 'بستن', primary: true }]);
}
function insertTextSmart(text) {
  const cv = kcmd('InsertText') || kcmd('insertText');
  if (cv) { try { restoreSel(); return kernel().execute(cv, text); } catch {} }
  restoreSel(); exec('insertText', text); markDirty();
}
function insertDate() {
  const s = new Intl.DateTimeFormat('fa-IR-u-ca-persian', { dateStyle: 'full' }).format(new Date());
  insertTextSmart(s);
}

/* ===================== ابزارهای تک‌کلیک ===================== */
function walkText(fn) { const ed = surface(); if (!ed) return; const w = document.createTreeWalker(ed, NodeFilter.SHOW_TEXT), l = []; while (w.nextNode()) l.push(w.currentNode); l.forEach(fn); markDirty(); reRender(); }
const digitsToFa = () => { walkText(t => { t.nodeValue = toFa(t.nodeValue); }); toast('ارقام به فارسی تبدیل شد'); };
const fixArabic = () => { walkText(t => { t.nodeValue = t.nodeValue.replace(/ي/g, 'ی').replace(/ك/g, 'ک'); }); toast('«ی» و «ک» عربی اصلاح شد'); };
function wordCountDialog() {
  const ed = surface(); const t = (ed?.innerText || '').trim(); const w = t ? t.split(/\s+/).length : 0;
  dialog('شمارش کلمات', `<p>کلمات: <b>${faNum(w)}</b></p><p>نویسه (با فاصله): <b>${faNum(t.length)}</b></p><p>نویسه (بدون فاصله): <b>${faNum(t.replace(/\s/g, '').length)}</b></p>`, [{ label: 'بستن', primary: true }]);
}
function setZoom(v) {
  const z = Math.min(1.5, Math.max(0.6, Number(v)));
  const vp = $('#pagesViewport');
  if (vp) { vp.style.zoom = z; vp.style.transformOrigin = 'top center'; }
  const zs = $('.fd-sel.zoom'); if (zs) zs.value = String(v);
  const k = kernel();
  if (k?.state) { try { k.state.zoom = z; } catch {} }
}
function fullscreen() { if (!document.fullscreenElement) document.documentElement.requestFullscreen?.(); else document.exitFullscreen?.(); }

/* ===================== ذخیره‌سازی با فرمت اختصاصی فراست (farast-v1) — مثل Google Docs =====================
 * قوانین (درخواست کاربر):
 *  ۱) سند خالی ذخیره نمی‌شود؛ به‌محض نوشتن اولین نویسه، autosave فعال می‌شود.
 *  ۲) «پیش‌نویس» حین نوشتن: autosave هر ۱٫۵ ثانیه پس از آخرین تغییر + بکاپ محلی always.
 *  ۳) دکمهٔ «ذخیره»: commit دستی با برچسب نسخه (Ctrl+S).
 *  ۴) خروج DOCX/PDF: «تدابیر ویژه» — flush کامل مدل، حذف گره‌های موقت، صبر تا پایان ذخیرهٔ سرور. */
const FD_EMPTY_RE = /[\s\u200b\u200c\ufeff]+/g;
function docHasContent() {
  const k = kernel();
  if (k?.state?.model) {
    const blocks = k.state.model.sections?.[0]?.blocks || [];
    for (const b of blocks) {
      if (b.type === 'image' && b.resourceId) return true;
      const txt = (runs => (runs || []).map(r => r.text || '').join(''))(b.runs);
      if (txt.replace(FD_EMPTY_RE, '')) return true;
      for (const it of b.items || []) if ((it.runs || []).map(r => r.text || '').join('').replace(FD_EMPTY_RE, '')) return true;
      for (const row of b.rows || []) for (const c of row.cells || []) if ((c.runs || []).map(r => r.text || '').join('').replace(FD_EMPTY_RE, '')) return true;
    }
    return false;
  }
  const ed = surface();
  return !!ed && (ed.innerText || '').replace(FD_EMPTY_RE, '').length > 0;
}
let fdEverSaved = false; // آیا همین سند قبلاً روی سرور ذخیره شده؟
function fdSetSaveState(text, state) {
  if (fdSave) { fdSave.textContent = text; fdSave.dataset.state = state; }
  const coreSave = $('#saveState');
  if (coreSave) { coreSave.textContent = text; coreSave.dataset.state = state; }
}
function fdScheduleDraft() {
  const k = kernel();
  if (!docHasContent()) { if (!fdEverSaved) fdSetSaveState('بدون محتوا · ذخیره نمی‌شود', 'info'); return; }
  if (k?.scheduleSave) { k.scheduleSave(); return; } // هسته خودش autosave با تأخیر ۱ ثانیه دارد
  clearTimeout(fdScheduleDraft.t);
  fdScheduleDraft.t = setTimeout(() => fdSaveDraft(), 1500);
}
async function fdSaveDraft() {
  if (!docHasContent() && !fdEverSaved) return; // سند خالی → هیچ درخواستی به سرور نه
  hub()?.sweepInterim?.(); // گره‌های interim نباید وارد مدل شوند
  try { await saveNow('autosave'); fdEverSaved = true; } catch {}
}
async function fdSaveManual() {
  if (!docHasContent()) { toast('سند خالی است؛ چیزی برای ذخیره نیست (مثل گوگل داکس)'); return; }
  hub()?.sweepInterim?.();
  const k = kernel();
  if (k?.saveNow && k?.state?.model) { try { await k.saveNow('manual'); fdEverSaved = true; toast('ذخیره شد'); } catch (e) { toast(e?.message || 'ذخیره ناموفق'); } return; }
  // حالت فالبک مستقل (هستهٔ فراست بارگذاری نشده): ذخیره در localStorage با کلید پایدار
  const ed = surface(); if (!ed) return;
  try {
    localStorage.setItem('farast.docs.local', JSON.stringify({ title: (fdTitle && fdTitle.value) || 'سند', html: ed.innerHTML, t: Date.now() }));
    fdEverSaved = true; fdSetSaveState('پیش‌نویس محلی ذخیره شد', 'ok'); toast('در این مرورگر ذخیره شد');
  } catch { toast('ذخیره محلی ممکن نشد'); }
}
window.addEventListener('beforeunload', () => { if (docHasContent() && !kernel()?.state?.model) { try { const ed = surface(); if (ed) localStorage.setItem('farast.docs.local', JSON.stringify({ title: (fdTitle && fdTitle.value) || 'سند', html: ed.innerHTML, t: Date.now() })); } catch {} } });
document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'hidden') fdSaveDraft(); });

/* ===================== خروجی DOCX / PDF — تدابیر ویژه =====================
 * ۱) پیش از ارسال به سرور: پاک‌سازی interim، flush مدل، اطمینان از وجود محتوا
 * ۲) صبر تا پایان ذخیره (تا سرور آخرین revision را داشته باشد)
 * ۳) اگر دسترسی پرداخت/خروجی سرور بسته بود → مسیر محلیِ مطمئن (بی‌اعتباری = دانلود نشود، نه فایل خراب)
 *    - DOCX محلی: MHTML ورد (همان الگوی persian-docs-editor.html) که Word/LibreOffice بازنشانی می‌کنند
 *    - PDF محلی: iframe پنهان با print-to-PDF استاندارد (Chrome/Edge دقیق؛ بقیه مرورگرها پنجرهٔ چاپ) */
async function doServerExport(fmt) {
  if (!docHasContent()) { toast('سند خالی است؛ خروجی گرفتن بی‌معناست'); return; }
  hub()?.sweepInterim?.();
  const k = kernel();
  try { if (k?.saveNow) await k.saveNow('manual'); } catch { toast('خروجی فقط پس از ذخیرهٔ موفق ممکن است'); return; }
  fdSetSaveState('آماده‌سازی خروجی…', 'saving');
  const csrf = $('meta[name="csrf-token"]')?.content || '';
  try {
    // بررسی نرم: آیا اجازهٔ خروجی داریم؟ اگر سرور 402/403 داد، بی‌صدا به فالبک محلی می‌رویم (کاربر گیر نمی‌کند)
    const head = await fetch('/editor/export/' + fmt, { method: 'POST', headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' }, body: new URLSearchParams({ document_id: String(k?.state?.documentId || ''), _probe: '1' }) }).catch(() => null);
    if (head && head.ok && head.headers.get('content-type')?.includes('json')) { /* probe پذیرفت — همان فرم POST اصلی */ }
  } catch {}
  const form = document.createElement('form'); form.method = 'POST'; form.action = '/editor/export/' + fmt; form.style.display = 'none';
  form.innerHTML = '<input type="hidden" name="_token" value="' + csrf + '"><input type="hidden" name="document_id" value="' + (k?.state?.documentId || '') + '">';
  document.body.appendChild(form);
  const onErr = () => { fdSetSaveState(fdEverSaved ? 'ذخیره شد' : 'پیش‌نویس', 'info'); toast('خروجی سرور در دسترس نبود؛ نسخهٔ محلی ساخته شد'); localExport(fmt); };
  form.addEventListener('submit', () => { setTimeout(() => { if (document.body.contains(form)) { form.remove(); onErr(); } }, 8000); });
  form.submit();
  setTimeout(() => { if (document.body.contains(form)) document.body.removeChild(form); }, 9000);
}
function exportDocx() { if (kernel()?.saveNow && kernel()?.state?.model) doServerExport('docx'); else localExport('docx'); }
function exportPdf() { if (kernel()?.saveNow && kernel()?.state?.model) doServerExport('pdf'); else localExport('pdf'); }
function localExport(fmt) {
  if (!docHasContent()) { toast('سند خالی است؛ خروجی گرفته نمی‌شود'); return; }
  hub()?.sweepInterim?.();
  const ed = surface(); if (!ed) return;
  const css = `@page{size:A4;margin:25mm}body{font-family:'B Nazanin',Vazirmatn,Tahoma,sans-serif;font-size:12pt;line-height:1.9;direction:rtl;text-align:right;color:#111;max-width:800px;margin:24px auto}p{margin:0 0 8pt}h1{font-size:20pt}h2{font-size:17pt}h3{font-size:15pt}table{border-collapse:collapse;width:100%}td,th{border:1px solid #888;padding:6px}img{max-width:100%}blockquote{border-right:3px solid #999;margin:10px 0;padding:6px 12px}`;
  if (fmt === 'docx') {
    // MHTML ورد — عین الگوی persian-docs-editor.html؛ Word/LibreOffice/GoogleDocs آن را به .docx واقعی تبدیل می‌کنند
    const html = '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:w="urn:schemas-microsoft-com:office:word" xmlns="http://www.w3.org/TR/REC-html40"><head><meta charset="utf-8"><title>' + fname() + '</title><!--[if gte mso 9]><xml><w:WordDocument><w:View>Print</w:View><w:Zoom>100</w:Zoom></w:WordDocument></xml><![endif]--><style>' + css + '</style></head><body>' + ed.innerHTML + '</body></html>';
    const bnd = '----farast.' + Date.now();
    const mht = 'MIME-Version: 1.0\nContent-Type: multipart/related;boundary="' + bnd + '"\n\n--' + bnd + '\nContent-Type: text/html; charset=utf-8\nContent-Transfer-Encoding: quoted-printable\n\n' + html.replace(/[\x00-\x1f\x80-\uffff]/g, ch => '=' + ('0000' + ch.codePointAt(0).toString(16)).slice(-4).match(/../g).join('=')) + '\n--' + bnd + '--\n';
    const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob([mht], { type: 'application/msword' })); a.download = fname() + '.doc'; document.body.appendChild(a); a.click(); a.remove();
    toast('فایل Word (سازگار با ورد) دانلود شد — در Word باز و Save as DOCX کنید');
    return;
  }
  // PDF محلی: iframe پنهان + چارچوب استاندارد چاپ (در کروم/ایج: Save as PDF با کیفیت کامل)
  const frame = document.createElement('iframe'); frame.setAttribute('aria-hidden', 'true');
  frame.style.cssText = 'position:fixed;left:-9999px;top:0;width:0;height:0;border:0';
  document.body.appendChild(frame);
  const d = frame.contentDocument;
  d.open(); d.write('<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><title>' + fname() + '</title><style>' + css + '</style></head><body>' + ed.innerHTML + '</body></html>'); d.close();
  const go = () => { try { frame.contentWindow.focus(); frame.contentWindow.print(); } catch { downloadDoc('html'); } setTimeout(() => frame.remove(), 3000); };
  if (d.readyState === 'complete') setTimeout(go, 300); else frame.onload = () => setTimeout(go, 300);
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
  const c = kcmd('InsertText');
  if (kernel()) { try { kernel().transaction('سند جدید', m => { m.sections[0].blocks = [{ id: 'p-' + Date.now(), type: 'paragraph', runs: [] }]; }); reRender(); } catch { ed.innerHTML = '<p><br></p>'; } }
  else ed.innerHTML = '<p><br></p>';
  if (fdTitle) fdTitle.value = 'سند بدون عنوان';
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
      markDirty(); reRender(); toast('فایل بارگذاری شد');
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
    ['ذخیره', 'Ctrl+S', fdSaveManual],
    '-',
    ['دانلود Word (.doc)', '', exportDocx],
    ['دانلود PDF (چاپ/ذخیره)', '', exportPdf],
    ['دانلود HTML', '', () => downloadDoc('html')],
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
    ['بزرگنمایی ۱۰۰٪', '', () => setZoom(1)],
    ['بزرگنمایی ۱۲۰٪', '', () => setZoom(1.2)],
    ['پیمایش سند', '', () => runAction('navigation')],
    ['حالت کلاسیک ادیتور', '', goClassic],
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
    ['نقل قول', '', () => setBlock('quote')],
    '-',
    ['پررنگ', 'Ctrl+B', () => toggleInline('bold',{bold:true})],
    ['کج', 'Ctrl+I', () => toggleInline('italic',{italic:true})],
    ['زیرخط‌دار', 'Ctrl+U', () => toggleInline('underline',{underline:true})],
    ['خط‌خورده', '', () => toggleInline('strike',{strike:true})],
    ['پاک کردن قالب‌بندی', '', () => { applyRunPatch({bold:false,italic:false,underline:false,strike:false}) || exec('removeFormat'); }],
  ],
  'تنظیمات صفحه': [
    ['اندازه صفحه A4', '', () => runAction('paper', 'A4')],
    ['اندازه صفحه A5', '', () => runAction('paper', 'A5')],
    ['اندازه صفحه Letter', '', () => runAction('paper', 'Letter')],
    ['افقی / عمودی', '', () => runAction('orientation')],
    ['حاشیه‌ها', '', () => runAction('margin')],
    '-',
    ['جهت سند: راست‌به‌چپ', '', () => runAction('docRtl')],
    ['جهت سند: چپ‌به‌راست', '', () => runAction('docLtr')],
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
    ['نیم‌فاصله: Shift+Space', '', () => insertTextSmart('\u200c')],
  ],
  'گسترش': [
    ['افزونه‌ها و ابزارهای قابل نصب', '', () => dialog('گسترش', '<p>معماری ابزارها رجیستری است: هر ابزار با <code>FarastDocsTools.register()</code> قابل افزودن/حذف است و در هر اپ دیگری قابل استفاده مجدد.</p>', [{ label: 'بستن', primary: true }])],
    ['حالت کلاسیک ادیتور', '', goClassic],
  ],
};
/* بازگشت دستی به حالت کلاسیک: با keep-classic=1 انجام می‌شود تا استارتاپ بعدی
   پوسته، پرچم را پاک نکند و کاربر واقعاً کلاسیک ببیند. برای بازگشت به گوگل داکس
   کافی است آدرس بدون پارامتر باز شود (پرچم خودکار پاک می‌شود). */
function goClassic() { localStorage.setItem('farast.editor.chrome', 'classic'); location.search = '?keep-classic=1'; }
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
  if (ss && blk) { let sv = 'normal'; const t = blk.tagName; if (t === 'H1') sv = 'h1'; else if (t === 'H2') sv = 'h2'; else if (t === 'H3') sv = 'h3'; else if (t === 'BLOCKQUOTE') sv = 'quote'; else if (blk.classList.contains('title-s')) sv = 'title'; else if (blk.classList.contains('subtitle-s')) sv = 'subtitle'; ss.value = sv; }
}

/* ===================== میان‌برها ===================== */
document.addEventListener('keydown', e => {
  const k = (e.key || '').toLowerCase(), mod = e.ctrlKey || e.metaKey;
  if (!mod) {
    if (e.shiftKey && e.code === 'Space' && !e.ctrlKey && !e.metaKey && !e.altKey && surface()?.contains(document.activeElement)) { e.preventDefault(); insertTextSmart('\u200c'); }
    return;
  }
  if (e.shiftKey && k === 's') { e.preventDefault(); toggleVoicePanel(); return; }
  if (k === 's' && !e.shiftKey) { e.preventDefault(); fdSaveManual(); }
  else if (k === 'k') { e.preventDefault(); openLink(); }
  else if (k === 'f') { e.preventDefault(); openFind(); }
  else if (k === 'h') { e.preventDefault(); openFind(); }
  else if (e.altKey && ['0','1','2','3'].includes(e.key)) { e.preventDefault(); setBlock(['normal','h1','h2','h3'][+e.key]); }
});

/* ===================== تایپ صوتی — معماری چندلایه (Hub) =====================
 * اولویت خودکار: موتور گفتار سمت سرور؛ هیچ وزن مدل یا بستهٔ زبان روی دستگاه بارگیری نمی‌شود.
 * مسیرهای بومی مرورگر، ابری و سرویس شخصی فقط با انتخاب صریح کاربر فعال می‌شوند.
 * هیچ صدایی بدون انتخاب کاربر به سرویس بیرونی ارسال نمی‌شود.
 * در همهٔ حالت‌ها: interim زیرخط‌دار در مکان نشانگر، دستورات صوتی فارسی، ارقام فارسی. */
const hub = () => window.FarastVoiceHub;
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
  <div class="fd-vengine" id="fdVEngine">موتور: —</div>
  <button type="button" class="fd-mic" id="fdMicBtn" aria-label="شروع یا توقف تایپ صوتی"></button>
  <div class="fd-vmsg" id="fdVMsg">برای شروع کلیک کنید</div>
  <label class="fd-vopt"><input type="checkbox" id="fdVCmd" checked> دستورهای صوتی (نقطه، ویرگول، …)</label>
  <label class="fd-vopt"><input type="checkbox" id="fdVDigits" checked> ارقام فارسی</label>
  <button type="button" class="fd-linkbtn" id="fdVHelp">راهنمای دستورها</button>
  <button type="button" class="fd-linkbtn" id="fdVEngineBtn" hidden>تغییر موتور تشخیص گفتار…</button>`;
document.body.appendChild(vbox);
$('#fdMicBtn').innerHTML = MIC_SVG;
if ($('#source')) $('#source').setAttribute('accept', 'image/*,.pdf,.zip,.docx,.txt,.md,.html');

let engineMode = localStorage.getItem('farast.voice.engine') || 'auto';
let activeHandle = null;

if (!['auto', 'cloud', 'stt-api'].includes(engineMode)) {
  engineMode = 'auto';
  localStorage.setItem('farast.voice.engine', 'auto');
}

function engineLabel() {
  const el = $('#fdVEngine'); if (!el) return;
  const names = { cloud: 'موتور سروری فراست', 'cloud-chunks': 'موتور سروری فراست · تکه‌ای', 'stt-api': 'سرویس تبدیل گفتار شخصی', runtime: 'پنل حرفه‌ای فراست' };
  el.textContent = 'موتور: ' + (names[activeHandle?.mode || currentEngineId()] || '—');
}
function currentEngineId() {
  return engineMode === 'stt-api' ? 'stt-api' : 'cloud';
}
function pickEngineInteractive() {
  const opts = [
    ['auto', 'خودکار (پردازش سمت سرور فراست)'],
    ['cloud', 'موتور سروری فراست' + (hub()?.isAuthed?.() ? '' : ' — نیازمند ورود به حساب')],
    ['stt-api', 'سرویس تبدیل گفتار شخصی'],
  ];
  const list = document.createElement('div'); list.className = 'fd-list';
  opts.forEach(([id, label]) => {
    const b = document.createElement('button'); b.type = 'button'; b.textContent = label;
    b.onclick = () => { engineMode = id; localStorage.setItem('farast.voice.engine', id); closePop(); stopVoice(); engineLabel(); toast('موتور تغییر کرد: ' + label); };
    list.appendChild(b);
  });
  const ep = document.createElement('div'); ep.className = 'fd-row';
  const inp = document.createElement('input'); inp.type = 'text'; inp.placeholder = 'نشانی سرویس STT شخصی (اختیاری)'; inp.value = localStorage.getItem('farast.stt.endpoint') || ''; inp.dir = 'ltr'; inp.style.flex = '1';
  inp.onchange = () => localStorage.setItem('farast.stt.endpoint', inp.value.trim());
  ep.appendChild(inp);
  const wrap = document.createElement('div'); wrap.className = 'fd-list-wrap'; wrap.append(list, ep);
  showPop($('#fdVEngineBtn'), wrap);
}
function toggleVoicePanel() {
  const show = !vbox.classList.contains('show');
  vbox.classList.toggle('show', show);
  const vb = $('[data-tool=voice]', toolbarEl); if (vb) vb.classList.toggle('on', show);
  $('#fdVEngineBtn').hidden = !show;
  engineLabel();
  if (show) {
    $('#fdVMsg').textContent = hub()?.isAuthed?.()
      ? 'صدا از مسیر سرویس سمت سرور فراست پردازش می‌شود؛ مدل هوش مصنوعی روی دستگاه بارگیری نمی‌شود.'
      : 'برای تایپ صوتی سروری وارد حساب شوید. هیچ مدل هوش مصنوعی روی دستگاه دانلود نمی‌شود.';
  }
  if (!show) stopVoice();
  syncToolStates();
}
function voiceUI(on) {
  $('#fdMicBtn').classList.toggle('live', on);
  const vb = $('[data-tool=voice]', toolbarEl); if (vb) vb.classList.toggle('rec', on);
  if (on || $('#fdVMsg').textContent.includes('کلیک') || $('#fdVMsg').textContent.includes('موتور')) $('#fdVMsg').textContent = on ? 'در حال گوش دادن…' : ($('#fdVMsg').dataset.keep || 'برای شروع کلیک کنید');
}

/* context مشترک همهٔ موتورها */
function hubCtx() {
  return {
    lang: () => $('#fdVLang').value,
    onStatus: t => { const m = $('#fdVMsg'); if (m) m.textContent = t; },
    onError: t => { toast(t); const m = $('#fdVMsg'); if (m) m.textContent = t; },
    onLive: on => { $('#fdMicBtn').classList.toggle('live', !!on); const vb = $('[data-tool=voice]', toolbarEl); if (vb) vb.classList.toggle('rec', !!on); },
    onStopped: () => { wantListen = false; activeHandle = null; $('#fdMicBtn').classList.remove('live'); const vb = $('[data-tool=voice]', toolbarEl); if (vb) vb.classList.remove('rec'); $('#fdVMsg').textContent = 'برای شروع کلیک کنید'; hub()?.clearInterim?.(); },
    onInterim: t => hub()?.showInterim?.(t),
    onFinal: t => handleFinal(t),
  };
}
function handleFinal(raw) {
  hub()?.clearInterim?.();
  const processed = hub()?.processFinalText(raw, { lang: $('#fdVLang').value, commands: $('#fdVCmd').checked, digits: $('#fdVDigits').checked });
  if (processed == null) return;
  if (processed === '__UNDO__') { runAction('undo'); return; }
  if (processed === '__STOP__') { stopVoice(); return; }
  if (processed === '__DELPREV__') { const s = getSelection(); if (s) { s.collapseToEnd(); if (s.modify) s.modify('extend', 'backward', 'word'); document.execCommand('delete'); } markDirty(); return; }
  hub()?.insertIntoDoc?.(processed);
  markDirty();
}

/* ---------- شروع/توقف عمومی؛ تشخیص گفتار فقط سمت سرور ---------- */
const ENGINE_NAMES = { cloud: 'موتور سروری فراست', 'stt-api': 'سرویس تبدیل گفتار شخصی' };
async function startVoice() {
  if (wantListen || activeHandle) return;
  const ctx = hubCtx();
  const prefer = engineMode === 'stt-api' ? 'stt-api' : 'cloud';
  const order = prefer === 'stt-api' ? ['stt-api'] : ['cloud'];
  let lastErr = '';
  for (const id of order) {
    try {
      if (id === 'cloud') {
        if (!hub()?.isAuthed?.()) {
          lastErr = 'برای موتور سروری فراست ابتدا وارد حساب شوید.';
          continue;
        }
        if (!hub()?.hasMic?.()) {
          lastErr = 'دسترسی میکروفون در دسترس نیست؛ سایت را با HTTPS باز کنید و اجازهٔ میکروفون بدهید.';
          continue;
        }
        const h = await hub()?.startCloud?.(ctx);
        if (h) {
          wantListen = true;
          activeHandle = h.mode === 'runtime' ? { mode: 'runtime', stop: () => {} } : h;
          engineLabel();
          return;
        }
      }
      if (id === 'stt-api') {
        if (!localStorage.getItem('farast.stt.endpoint')) {
          lastErr = 'نشانی سرویس STT شخصی را از «تغییر موتور» وارد کنید.';
          continue;
        }
        const h = await hub()?.startSttEndpoint?.(ctx);
        if (h) { wantListen = true; activeHandle = h; engineLabel(); return; }
      }
    } catch (err) {
      lastErr = err?.message || '';
      if (id === order[order.length - 1]) {
        ctx.onError(lastErr || 'سرویس گفتار سمت سرور در دسترس نیست');
        wantListen = false;
        voiceUI(false);
      }
    }
  }
  wantListen = false;
  voiceUI(false);
  $('#fdVMsg').textContent = lastErr || 'موتور گفتار سمت سرور پیکربندی نشده است.';
  toast($('#fdVMsg').textContent);
}
function stopVoice() {
  wantListen = false;
  hub()?.clearInterim?.();
  try { activeHandle?.stop?.(); } catch {}
  activeHandle = null;
  voiceUI(false);
}
$('#fdMicBtn').addEventListener('mousedown', e => e.preventDefault());
$('#fdMicBtn').addEventListener('click', () => (wantListen || activeHandle) ? stopVoice() : startVoice());
$('#fdVClose').addEventListener('click', () => { vbox.classList.remove('show'); stopVoice(); syncToolStates(); });
$('#fdVLang').addEventListener('change', () => { if (wantListen) { stopVoice(); setTimeout(startVoice, 250); } });
$('#fdVEngineBtn').addEventListener('click', () => pickEngineInteractive());
vbox.addEventListener('mousedown', e => { if (!/SELECT|OPTION|INPUT/.test(e.target.tagName)) e.preventDefault(); });
$('#fdVHelp').addEventListener('click', () => dialog('دستورهای صوتی (فارسی)',
  `<p style="line-height:2;margin:0">بگویید:<br>
  «<b>نقطه</b>» ← .<br>«<b>ویرگول</b>» ← ،<br>«<b>علامت سوال</b>» ← ؟<br>«<b>علامت تعجب</b>» ← !<br>
  «<b>دو نقطه</b>» ← :<br>«<b>نقطه ویرگول</b>» ← ؛<br>«<b>سه نقطه</b>» ← …<br>
  «<b>خط جدید</b>» ← رفتن به خط بعد<br>«<b>پاراگراف جدید</b>» ← پاراگراف تازه<br>
  «<b>آخرین کلمه را پاک کن</b>» ← حذف کلمهٔ قبل<br>«<b>بازگردانی</b>» ← بازگردانی آخرین تغییر</p>
  <p style="font-size:12px;color:#5f6368">میان‌بر باز و بسته کردن تایپ صوتی: Ctrl+Shift+S — تشخیص گفتار از مسیر سرویس سمت سرور انجام می‌شود؛ مدل هوش مصنوعی یا بستهٔ زبان روی دستگاه نصب نمی‌شود.</p>`, [{ label: 'بستن', primary: true }]));

/* ===================== رجیستری عمومی ابزارها (برای استفادهٔ مجدد در سایر اپ‌ها) ===================== */
window.FarastDocsTools.registerAll = tools => (tools || []).forEach(t => window.FarastDocsTools.register(t));
window.FarastDocsChrome = {
  version: '2.0.0',
  toast, dialog, showPop, closePop, runAction, exec, setZoom, setBlock, openFind, openLink, openImage, openTable,
  voice: { start: startVoice, stop: stopVoice, toggle: toggleVoicePanel, panel: vbox },
  surface, kernel,
};

/* ===================== شروع ===================== */
rebuildToolbar();
syncToolStates();

/* ===================== توابع عمومی موردنیاز پوسته (markDirty / saveNow) =====================
 * در حالت هسته (FarastEditor): autosave خودکارِ هسته فعال است؛ اینجا فقط hook تغییرات و
 * همگام‌سازی وضعیت ذخیره را انجام می‌دهیم. در حالت فالبک مستقل: autosave با تأخیر + localStorage. */
if (typeof window.markDirty !== 'function') {
  window.markDirty = function markDirty() {
    const k = kernel();
    if (k) { try { k.flush?.(); } catch {} try { k.scheduleSave?.(); } catch {} syncSaveState(); return; }
    fdScheduleDraft();
  };
}
if (typeof window.saveNow !== 'function') {
  window.saveNow = async function saveNow(source = 'manual') {
    const k = kernel();
    if (k?.saveNow && k?.state?.model) { try { await k.saveNow(source); fdEverSaved = true; toast('ذخیره شد'); } catch (e) { toast(e?.message || 'ذخیره ناموفق بود'); } return; }
    await fdSaveManual();
  };
}
/* تریگر تغییر: هر input روی سطح ویرایش →autosave گوگل‌داکسی (سند خالی ذخیره نمی‌شود) */
app.addEventListener('input', () => { if (!kernel()) fdScheduleDraft(); }, true);

/* بارگذاری پیش‌نویس محلی در حالت فالبک (بازیابی بعد از بستن مرورگر) */
(function restoreLocalDraft() {
  if (kernel()) return;
  try {
    const raw = localStorage.getItem('farast.docs.local'); if (!raw) return;
    const d = JSON.parse(raw); const ed = $('#fdFallbackEditor');
    if (ed && d?.html && (ed.innerText || '').replace(FD_EMPTY_RE, '') === '') {
      ed.innerHTML = d.html; if (fdTitle && d.title) fdTitle.value = d.title;
      fdEverSaved = true; fdSetSaveState('پیش‌نویس محلی بازیابی شد', 'ok');
    }
  } catch {}
})();

/* حفظ ترجیح کاربر: حالت کلاسیک فقط با پرچم صریح keep-classic فعال می‌شود (دکمهٔ منوی گسترش).
   در غیر این صورت همیشه پوستهٔ گوگل داکس — بدون هیچ سقوط خودکاری به کلاسیک. */
if (localStorage.getItem('farast.editor.chrome') === 'classic' && params.get('keep-classic') === '1') goClassic();

/* ===================== گارد ضدتداخل با Runtime قدیمی (farast-voice.js) — v3 تضمینی =====================
 * در حالت گوگل‌داكسی تنها پنل صوتی معتبر، پنل همین فایل است. listenerهای قدیمی روی #mic
 * حذف می‌شوند (clone)، باز شدن #farastVoicePanel خنثی می‌شود، و یک capture-click سراسری
 * هر کلیک روی دکمهٔ کلاسیک میکروفون را به پنل جدید هدایت می‌کند. */
(function antiLegacyVoice() {
  const redirect = e => {
    const m = e.target && e.target.closest ? e.target.closest('#mic') : null;
    if (!m) return;
    e.preventDefault(); e.stopPropagation();
    toggleVoicePanel();
  };
  document.addEventListener('click', redirect, true); // capture: پیش از هر اسکریپت دیگری
  const disarmMic = () => {
    const legacyMic = document.getElementById('mic');
    if (!legacyMic || legacyMic.dataset.fdDisarmed === '1') return;
    legacyMic.dataset.fdDisarmed = '1';
    const fresh = legacyMic.cloneNode(true);
    legacyMic.replaceWith(fresh); // تمام listenerهای قدیمی (از جمله opening پنل قدیمی) حذف می‌شود
    fresh.addEventListener('click', e => { e.preventDefault(); e.stopPropagation(); toggleVoicePanel(); });
  };
  disarmMic();
  const killLegacyPanel = () => {
    const p = document.getElementById('farastVoicePanel');
    if (p && p.classList.contains('is-open')) {
      try { window.FarastVoiceRuntime?.close?.(); } catch {}
      p.classList.remove('is-open');
    }
  };
  killLegacyPanel();
  new MutationObserver(() => { killLegacyPanel(); disarmMic(); }).observe(document.body, { attributes: true, attributeFilter: ['class'], subtree: true });
})();
})();
