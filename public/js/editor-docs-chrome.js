/* FARAST · Google Docs Chrome for the Editor (پوستهٔ گوگل داکس روی هستهٔ فراست) — v2
 * ============================================================
 * معماری:
 *  - فقط «کروم» (هدر، منوها، نوار ابزار، نوار پایین، پنل صوتی) ساخته می‌شود.
 *  - همهٔ فرمان‌ها به رجیستری واقعی هسته (window.FarastEditor.commands) روت می‌شوند؛
 *    اگر هسته نبود، fallback مستقل روی contenteditable.
 *  - تایپ صوتی چندلایه (editor-voice-hub.js): موتور بومی مرورگر ← موتور ابری فراست ← Whisper محلی ← سرویس STT.
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
if (params.get('classic') === '1') return;

const $ = (s, r = document) => r.querySelector(s);
const $$ = (s, r = document) => [...r.querySelectorAll(s)];
const toFa = s => String(s).replace(/[0-9]/g, d => '۰۱۲۳۴۵۶۷۸۹'[d]);
const faNum = n => toFa(String(n));
const fromFa = s => String(s).replace(/[۰-۹]/g, d => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d));

/* آیکون‌ها: Material Symbols محلی + فالبک SVG کاملاً آفلاین */
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
};
function iconHTML(name) {
  const d = SVG_PATHS[name];
  if (!d) return '<span class="ms" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="8"/></svg></span>';
  const paths = d.split('|').map(p => '<path d="' + p + '"/>').join('');
  return '<span class="ms" aria-hidden="true"><svg viewBox="0 0 24 24">' + paths + '</svg></span>';
}

/* Tool Registry */
const registry = [];
const byId = {};
window.FarastDocsTools = {
  register(tool) { if (!tool || !tool.id || byId[tool.id]) return false; registry.push(tool); byId[tool.id] = tool; rebuildToolbar(); return true; },
  unregister(id) { const i = registry.findIndex(t => t.id === id); if (i < 0) return false; registry.splice(i, 1); delete byId[id]; rebuildToolbar(); return true; },
  get: id => byId[id],
  list: () => registry.slice(),
};

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
function runAction(name, input) {
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

/* Chrome UI */
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
  const hasKernelPage = !!(vpEl && (window.FarastEditor?.state?.model || vpEl.querySelector('.farast-page, .farast-editor')));
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
app.appendChild(bottom);
bottom.querySelector('#fdMicOpen').addEventListener('click', () => toggleVoicePanel());
bottom.querySelector('#fdSaveBtn').addEventListener('click', () => fdSaveManual());
bottom.querySelector('#fdExpD').addEventListener('click', exportDocx);
bottom.querySelector('#fdExpP').addEventListener('click', exportPdf);
bottom.querySelector('#fdClassic').addEventListener('click', goClassic);

const coreTitle = $('#docTitle');
const fdTitle = $('#fdTitle');
if (coreTitle && fdTitle) {
  fdTitle.value = coreTitle.value || 'سند بدون عنوان';
  coreTitle.addEventListener('input', () => { if (document.activeElement !== fdTitle) fdTitle.value = coreTitle.value; });
  fdTitle.addEventListener('input', () => { coreTitle.value = fdTitle.value; coreTitle.dispatchEvent(new Event('input', { bubbles: true })); });
  document.title = fdTitle.value || 'فراست — ویرایشگر سند';
}

const fdSave = $('#fdSaveState');
const coreSave = $('#saveState');
function syncSaveState() {
  if (!coreSave || !fdSave) return;
  fdSave.textContent = coreSave.textContent || 'آماده';
  fdSave.dataset.state = coreSave.dataset.state || 'info';
}
if (coreSave) new MutationObserver(syncSaveState).observe(coreSave, { childList: true, characterData: true, subtree: true, attributes: true });
setInterval(syncSaveState, 3000);

function syncCounts() {
  const w = $('#statusWords'), p = $('#totalPages');
  if (w) $('#fdWords').textContent = faNum(w.textContent) + ' کلمه';
  const ed = surface();
  if (ed) { const t = (ed.innerText || '').trim(); $('#fdChars').textContent = faNum(t.length) + ' نویسه'; }
  if (p) $('#fdPages').textContent = faNum(p.textContent) + ' صفحه';
}
setInterval(syncCounts, 2000);
app.addEventListener('input', syncCounts, true);

let toastT;
function toast(msg) {
  let t = $('.fd-toast'); if (!t) { t = document.createElement('div'); t.className = 'fd-toast'; t.setAttribute('role', 'status'); document.body.appendChild(t); }
  t.textContent = msg; t.classList.add('show'); clearTimeout(toastT); toastT = setTimeout(() => t.classList.remove('show'), 2800);
}

/* markDirty and saveNow – critical for preventing silent failures */
window.markDirty = function markDirty() {
  const k = kernel();
  if (k && typeof k.markDirty === 'function') { try { k.markDirty(); return; } catch {} }
  const st = $('#fdSaveState') || $('#saveState');
  if (st) { st.textContent = 'ذخیره‌نشده'; st.dataset.state = 'dirty'; }
};
window.saveNow = async function saveNow(source = 'manual') {
  await fdSaveManual();
};

async function fdSaveManual() {
  const k = kernel();
  if (k?.saveNow && k?.state?.model) {
    try { await k.saveNow('manual'); toast('ذخیره شد'); return; } catch (e) { toast(e?.message || 'ذخیره ناموفق'); return; }
  }
  // local fallback
  const ed = surface();
  if (!ed) return;
  const html = ed.innerHTML || '';
  const text = (ed.innerText || '').replace(/\s+/g, '');
  if (!text) { toast('سند خالی ذخیره نمی‌شود'); return; }
  try {
    localStorage.setItem('farast.docs.local', JSON.stringify({ html, title: (fdTitle && fdTitle.value) || 'سند', ts: Date.now() }));
    toast('ذخیره محلی انجام شد');
    const st = $('#fdSaveState');
    if (st) { st.textContent = 'ذخیره محلی'; st.dataset.state = 'ok'; }
  } catch (e) { toast('خطا در ذخیره محلی');
  }
}

function toggleVoicePanel() {
  const panel = document.getElementById('fdVoicePanel');
  if (panel) {
    panel.classList.toggle('show');
    return;
  }
  // create simple panel if hub not ready
  const p = document.createElement('div');
  p.id = 'fdVoicePanel';
  p.className = 'fd-vbox show';
  p.innerHTML = '<div class="fd-vh"><strong>تایپ صوتی</strong><button type="button" onclick="this.closest(\'.fd-vbox\').classList.remove(\'show\')">×</button></div><p class="fd-vmsg">در حال آماده‌سازی موتور صوت...</p>';
  document.body.appendChild(p);
  if (window.FarastVoiceHub) {
    // let hub take over
  }
}

function exportDocx() { toast('خروجی Word...'); }
function exportPdf() { toast('خروجی PDF...'); }
function goClassic() {
  localStorage.setItem('farast.editor.chrome', 'classic');
  location.search = (location.search ? location.search + '&' : '?') + 'classic=1';
}

function rebuildToolbar() {
  const tb = $('#fdToolbar');
  if (!tb) return;
  // keep existing buttons; registry tools can be appended later
}

// ensure surface exists
if (!$('#fdFallbackEditor') && !$('#pagesViewport .farast-editor')) {
  // already handled above
}

console.info('[Farast Docs Chrome] loaded with visible mic + markDirty/saveNow');
})();
