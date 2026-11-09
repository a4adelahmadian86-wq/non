(() => {
  'use strict';
  const root = document.querySelector('[data-reader-root]');
  if (!root) return;
  const toolbar = root.querySelector('.farast-reader-toolbar');
  if (!toolbar) return;
  const product = root.dataset.productId || location.pathname;
  const storageKey = 'farast.reader.personal-tools.v1:' + product;
  const defaults = { light: false, font: 'system', size: 100, lineHeight: 1.7, measure: 'normal', paragraphSpacing: 1, focusMode: false, readingRuler: false, highlightColor: 'yellow', highlights: [], notes: [], inkStrokes: [] };
  let state = { ...defaults };
  try {
    const saved = JSON.parse(localStorage.getItem(storageKey) || '{}');
    if (saved && typeof saved === 'object') state = { ...defaults, ...saved };
  } catch (_) {}
  const validInkStroke = stroke => stroke && (stroke.tool === 'pen' || stroke.tool === 'eraser') && Number.isFinite(Number(stroke.page)) && Number(stroke.page) > 0 && Number.isFinite(Number(stroke.width)) && stroke.width >= 1 && stroke.width <= 32 && Array.isArray(stroke.points) && stroke.points.length >= 2 && stroke.points.length <= 1000 && stroke.points.every(point => point && Number.isFinite(point.x) && Number.isFinite(point.y) && point.x >= 0 && point.x <= 1 && point.y >= 0 && point.y <= 1);
  const limitInkStrokes = strokes => {
    const limited = (Array.isArray(strokes) ? strokes.filter(validInkStroke) : []).slice(-250);
    let points = limited.reduce((total, stroke) => total + stroke.points.length, 0);
    while (points > 60000 && limited.length > 1) points -= limited.shift().points.length;
    return limited;
  };
  state.light = !!state.light;
  state.font = ['system','sans','serif','mono','dyslexic'].includes(state.font) ? state.font : 'system';
  state.size = Math.min(160, Math.max(80, Number(state.size) || 100));
  state.lineHeight = [1.4, 1.6, 1.7, 1.9, 2.1].includes(Number(state.lineHeight)) ? Number(state.lineHeight) : 1.7;
  state.measure = ['narrow', 'normal', 'wide'].includes(state.measure) ? state.measure : 'normal';
  state.paragraphSpacing = [0.5, 1, 1.5, 2].includes(Number(state.paragraphSpacing)) ? Number(state.paragraphSpacing) : 1;
  state.focusMode = !!state.focusMode;
  state.readingRuler = !!state.readingRuler;
  state.highlights = Array.isArray(state.highlights) ? state.highlights.filter(x => x && typeof x.quote === 'string' && x.quote.length <= 2000 && Number.isFinite(Number(x.page))).slice(-500) : [];
  state.notes = Array.isArray(state.notes) ? state.notes.filter(x => x && typeof x.quote === 'string' && x.quote.length <= 2000 && typeof x.note === 'string' && x.note.length <= 4000 && Number.isFinite(Number(x.page))).slice(-500) : [];
  state.inkStrokes = limitInkStrokes(state.inkStrokes);
  const persist = () => { try { localStorage.setItem(storageKey, JSON.stringify(state)); } catch (_) { announce('فضای ذخیره‌سازی مرورگر در دسترس نیست.'); } };
  const announce = message => { status.textContent = message; };
  const group = document.createElement('div');
  group.className = 'farast-reader-toolgroup farast-reader-personal-tools';
  group.setAttribute('aria-label', 'ابزارهای شخصی مطالعه');
  group.innerHTML = '<button type="button" class="reader-tool reader-tool-wide" data-reading-light aria-pressed="false"><i class="fa-solid fa-lightbulb"></i><span>نور مطالعه</span></button><label class="reader-personal-label">قلم <select data-reader-font aria-label="نوع قلم"><option value="system">قلم سیستم</option><option value="sans">بدون سریف</option><option value="serif">سریف</option><option value="mono">تک‌فاصله</option><option value="dyslexic">OpenDyslexic (در صورت نصب)</option></select></label><label class="reader-personal-label">اندازه <input data-reader-font-size type="range" min="80" max="160" step="5" aria-label="اندازه قلم"></label><label class="reader-personal-label">فاصله خطوط <select data-reader-line-height aria-label="فاصله خطوط"><option value="1.4">فشرده</option><option value="1.6">کم</option><option value="1.7">معمولی</option><option value="1.9">باز</option><option value="2.1">خیلی باز</option></select></label><label class="reader-personal-label">عرض متن <select data-reader-measure aria-label="عرض ستون متن"><option value="narrow">باریک</option><option value="normal">معمولی</option><option value="wide">عریض</option></select></label><label class="reader-personal-label">فاصله بندها <select data-reader-paragraph-spacing aria-label="فاصله بندها"><option value="0.5">کم</option><option value="1">معمولی</option><option value="1.5">زیاد</option><option value="2">خیلی زیاد</option></select></label><button type="button" class="reader-tool reader-tool-wide" data-reader-focus aria-pressed="false">حالت تمرکز</button><button type="button" class="reader-tool reader-tool-wide" data-reader-ruler aria-pressed="false">خط‌نمای مطالعه</button><label class="reader-personal-label">رنگ <select data-reader-highlight-color aria-label="رنگ هایلایت"><option value="yellow">زرد</option><option value="green">سبز</option><option value="blue">آبی</option><option value="pink">صورتی</option></select></label><button type="button" class="reader-tool" data-reader-highlight aria-label="هایلایت متن انتخاب‌شده" title="هایلایت انتخاب‌شده"><i class="fa-solid fa-highlighter"></i></button><button type="button" class="reader-tool reader-tool-wide" data-reader-note><i class="fa-regular fa-note-sticky"></i><span>حاشیه‌نویسی</span></button><button type="button" class="reader-tool reader-tool-wide" data-reader-notes aria-expanded="false"><i class="fa-solid fa-list"></i><span>یادداشت‌ها</span></button><button type="button" class="reader-tool reader-tool-wide" data-reader-export><i class="fa-solid fa-file-export"></i><span>خروجی یادداشت‌ها</span></button><button type="button" class="reader-tool reader-tool-wide" data-reader-import><i class="fa-solid fa-file-import"></i><span>بازیابی</span></button><input type="file" accept="application/json,.json" data-reader-import-file hidden aria-label="انتخاب پشتیبان یادداشت‌ها"></div>';
  const status = document.createElement('span');
  status.className = 'sr-only';
  status.setAttribute('role', 'status');
  status.setAttribute('aria-live', 'polite');
  group.append(status);
  toolbar.append(group);
  const notesPanel = document.createElement('section');
  notesPanel.className = 'farast-reader-local-notes';
  notesPanel.hidden = true;
  notesPanel.setAttribute('aria-label', 'حاشیه‌نویسی‌های ذخیره‌شده در این مرورگر');
  toolbar.append(notesPanel);
  const css = document.createElement('style');
  css.textContent = `
    .farast-reader-personal-tools{flex-wrap:wrap}
    .farast-reader-document{line-height:var(--reader-personal-line-height,1.7)}
    .farast-reader-document p,.farast-reader-document li{margin-block-end:calc(1em * var(--reader-personal-paragraph-spacing,1))}
    .farast-reader[data-reader-measure="narrow"] .farast-reader-document{max-width:min(100%,48rem);margin-inline:auto}
    .farast-reader[data-reader-measure="normal"] .farast-reader-document{max-width:min(100%,68rem);margin-inline:auto}
    .farast-reader[data-reader-measure="wide"] .farast-reader-document{max-width:100%}
    .farast-reader.is-reader-focus .farast-reader-toolbar>*:not(.farast-reader-personal-tools),.farast-reader.is-reader-focus #reader-sidebar{opacity:.18;transition:opacity .2s ease}
    .farast-reader.is-reader-focus .farast-reader-toolbar:hover>*:not(.farast-reader-personal-tools),.farast-reader.is-reader-focus #reader-sidebar:hover{opacity:1}
    .farast-reader.is-reader-focus .farast-reader-canvas{max-width:100%}
    .farast-reader-reading-ruler{position:fixed;left:0;right:0;top:0;height:28px;z-index:9998;pointer-events:none;border-block:1px solid rgba(59,130,246,.6);background:rgba(96,165,250,.12);box-shadow:0 0 0 9999px rgba(15,23,42,.13)}
    .farast-reader-reading-ruler[hidden]{display:none}
    .reader-personal-label{display:inline-flex;align-items:center;gap:5px;color:var(--reader-muted);font-size:.7rem}
    .reader-personal-label select,.reader-personal-label input{max-width:125px;border:1px solid var(--reader-line);border-radius:7px;background:var(--reader-panel);color:var(--reader-text);padding:5px;font:inherit}
    .farast-reader.is-reading-light{--reader-bg:#f4ecd8;--reader-panel:#fff8e8;--reader-text:#392f22;--reader-muted:#786b58;--reader-line:rgba(90,70,40,.2);background:var(--reader-bg);color:var(--reader-text)}
    .farast-reader.is-reading-light .farast-reader-document,.farast-reader.is-reading-light .farast-pdf-document{background:#fff8e8;color:#392f22}
    .farast-reader.is-reading-light .farast-reader-canvas{background:#eee3ca}
    .farast-reader.is-reading-light .farast-reader-page-content img{filter:sepia(.12) brightness(1.04)}
    .farast-reader[data-reader-font="sans"] .farast-reader-document{font-family:Arial,"Segoe UI",sans-serif}
    .farast-reader[data-reader-font="serif"] .farast-reader-document{font-family:Georgia,"Times New Roman",serif}
    .farast-reader[data-reader-font="mono"] .farast-reader-document{font-family:ui-monospace,"Cascadia Code",monospace}
    .farast-reader[data-reader-font="dyslexic"] .farast-reader-document{font-family:"OpenDyslexic",Arial,sans-serif}
    .farast-reader[data-reader-font="system"] .farast-reader-document{font-family:system-ui,-apple-system,"Segoe UI",sans-serif}
    .farast-reader[data-reader-font-size] .farast-reader-document{font-size:var(--reader-personal-font-size,100%)}
    .farast-reader[data-reader-font-size] .farast-reader-document .reader-document-line{font-size:calc(1rem * var(--reader-font-scale,1))}
    .farast-reader-local-notes{width:100%;max-height:230px;overflow:auto;padding:9px 12px;border-top:1px solid var(--reader-line);background:var(--reader-panel);color:var(--reader-text)}
    .farast-reader-local-notes[hidden]{display:none}
    .farast-reader-note{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:8px;padding:8px 0;border-bottom:1px solid var(--reader-line);font-size:.75rem}
    .farast-reader-note blockquote{margin:4px 0;color:var(--reader-muted);border-right:2px solid #60a5fa;padding-right:8px}
    .farast-reader-note-actions{display:flex;gap:5px;align-items:start}.farast-reader-note button{align-self:start;border:1px solid var(--reader-line);border-radius:6px;background:transparent;color:var(--reader-text);padding:4px 7px;cursor:pointer}
    .farast-reader-local-highlight{color:inherit;border-radius:2px;cursor:pointer}
    .farast-reader-personal-tools [data-reader-import-file]{display:none}
    @media(max-width:760px){.farast-reader-personal-tools{justify-content:center}.reader-personal-label{font-size:.65rem}}
  `;
  document.head.append(css);
  const lightButton = group.querySelector('[data-reading-light]');
  const fontSelect = group.querySelector('[data-reader-font]');
  const sizeInput = group.querySelector('[data-reader-font-size]');
  const lineHeightSelect = group.querySelector('[data-reader-line-height]');
  const measureSelect = group.querySelector('[data-reader-measure]');
  const paragraphSpacingSelect = group.querySelector('[data-reader-paragraph-spacing]');
  const focusButton = group.querySelector('[data-reader-focus]');
  const rulerButton = group.querySelector('[data-reader-ruler]');
  const highlightColorSelect = group.querySelector('[data-reader-highlight-color]');
  let ruler = null;
  const highlightColors = { yellow: 'rgba(250,204,21,.55)', green: 'rgba(74,222,128,.38)', blue: 'rgba(96,165,250,.42)', pink: 'rgba(244,114,182,.4)' };
  const applyPrefs = () => {
    root.classList.toggle('is-reading-light', !!state.light);
    root.dataset.readerFont = state.font;
    root.dataset.readerFontSize = String(state.size);
    root.style.setProperty('--reader-personal-font-size', state.size + '%');
    root.style.setProperty('--reader-font-scale', String(state.size / 100));
    root.style.setProperty('--reader-personal-line-height', String(state.lineHeight));
    root.style.setProperty('--reader-personal-paragraph-spacing', String(state.paragraphSpacing));
    root.dataset.readerMeasure = state.measure;
    root.classList.toggle('is-reader-focus', state.focusMode);
    root.classList.toggle('has-reading-ruler', state.readingRuler);
    rulerButton.setAttribute('aria-pressed', String(state.readingRuler));
    if (ruler) ruler.hidden = !state.readingRuler;
    lineHeightSelect.value = String(state.lineHeight);
    measureSelect.value = state.measure;
    paragraphSpacingSelect.value = String(state.paragraphSpacing);
    focusButton.setAttribute('aria-pressed', String(state.focusMode));
    lightButton.setAttribute('aria-pressed', String(!!state.light));
    fontSelect.value = state.font;
    sizeInput.value = String(state.size);
    state.highlightColor = highlightColors[state.highlightColor] ? state.highlightColor : 'yellow';
    highlightColorSelect.value = state.highlightColor;
  };
  applyPrefs();
  lightButton.addEventListener('click', () => { state.light = !state.light; applyPrefs(); persist(); announce(state.light ? 'نور مطالعه روشن شد.' : 'نور مطالعه خاموش شد.'); });
  fontSelect.addEventListener('change', () => { state.font = fontSelect.value; applyPrefs(); persist(); announce('قلم مطالعه تغییر کرد. قلم‌های نصب‌شدهٔ سیستم قابل استفاده‌اند.'); });
  sizeInput.addEventListener('input', () => { state.size = Number(sizeInput.value); applyPrefs(); persist(); });
  lineHeightSelect.addEventListener('change', () => { state.lineHeight = Number(lineHeightSelect.value); applyPrefs(); persist(); announce('فاصلهٔ خطوط تغییر کرد.'); });
  measureSelect.addEventListener('change', () => { state.measure = measureSelect.value; applyPrefs(); persist(); announce('عرض ستون مطالعه تغییر کرد.'); });
  paragraphSpacingSelect.addEventListener('change', () => { state.paragraphSpacing = Number(paragraphSpacingSelect.value); applyPrefs(); persist(); announce('فاصلهٔ بندها تغییر کرد.'); });
  focusButton.addEventListener('click', () => { state.focusMode = !state.focusMode; applyPrefs(); persist(); announce(state.focusMode ? 'حالت تمرکز روشن شد.' : 'حالت تمرکز خاموش شد.'); });
  ruler = document.createElement('div');
  ruler.className = 'farast-reader-reading-ruler';
  ruler.setAttribute('aria-hidden', 'true');
  document.body.append(ruler);
  const moveRuler = event => { if (state.readingRuler) ruler.style.top = Math.max(0, Math.min(window.innerHeight - 28, event.clientY - 14)) + 'px'; };
  root.addEventListener('pointermove', moveRuler, { passive: true });
  rulerButton.addEventListener('click', () => { state.readingRuler = !state.readingRuler; ruler.hidden = !state.readingRuler; applyPrefs(); persist(); announce(state.readingRuler ? 'خط‌نمای مطالعه فعال شد.' : 'خط‌نمای مطالعه غیرفعال شد.'); });
  ruler.hidden = !state.readingRuler;
  highlightColorSelect.addEventListener('change', () => { state.highlightColor = highlightColorSelect.value; persist(); announce('رنگ هایلایت تغییر کرد.'); });
  const getPageTextMap = page => {
    const walker = document.createTreeWalker(page, NodeFilter.SHOW_TEXT, { acceptNode(node) {
      if (!node.nodeValue || !node.nodeValue.trim() || node.parentElement.closest('button,script,style,textarea,select,.farast-reader-ink-tools')) return NodeFilter.FILTER_REJECT;
      return NodeFilter.FILTER_ACCEPT;
    }});
    const nodes = [];
    const offsets = new Map();
    let fullText = '';
    while (walker.nextNode()) {
      const node = walker.currentNode;
      offsets.set(node, fullText.length);
      nodes.push(node);
      fullText += node.nodeValue;
    }
    return { nodes, offsets, fullText };
  };
  const getContext = () => {
    const selection = window.getSelection();
    if (!selection || !selection.rangeCount || !selection.toString().trim() || !root.contains(selection.anchorNode) || !root.contains(selection.focusNode)) return null;
    const range = selection.getRangeAt(0);
    const page = selection.anchorNode.parentElement?.closest('.farast-reader-page-item');
    const endPage = selection.focusNode.parentElement?.closest('.farast-reader-page-item');
    if (!page || page !== endPage) return null;
    const map = getPageTextMap(page);
    if (!map.offsets.has(range.startContainer) || !map.offsets.has(range.endContainer)) return null;
    let start = map.offsets.get(range.startContainer) + range.startOffset;
    let end = map.offsets.get(range.endContainer) + range.endOffset;
    if (end < start) [start, end] = [end, start];
    const raw = map.fullText.slice(start, end);
    const quote = raw.trim().slice(0, 2000);
    if (!quote) return null;
    start += raw.length - raw.trimStart().length;
    end = start + quote.length;
    return { text: quote, page: Number(page.dataset.readerPage || Number(page.dataset.index || 0) + 1), startOffset: start, endOffset: end };
  };
  const highlightButton = group.querySelector('[data-reader-highlight]');
  const noteButton = group.querySelector('[data-reader-note]');
  const notesButton = group.querySelector('[data-reader-notes]');
  const exportButton = group.querySelector('[data-reader-export]');
  const importButton = group.querySelector('[data-reader-import]');
  const importFile = group.querySelector('[data-reader-import-file]');
  exportButton.addEventListener('click', () => {
    const payload = { format: 'farast-reader-personal-tools', version: 1, product, exportedAt: new Date().toISOString(), data: state };
    const blob = new Blob([JSON.stringify(payload, null, 2)], { type: 'application/json' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a'); link.href = url; link.download = 'farast-reader-notes-' + String(product).replace(/[^a-z0-9_-]/gi, '-') + '.json';
    document.body.append(link); link.click(); link.remove(); URL.revokeObjectURL(url);
    announce('نسخهٔ پشتیبان شخصی آماده شد.');
  });
  importButton.addEventListener('click', () => importFile.click());
  importFile.addEventListener('change', async () => {
    const file = importFile.files?.[0]; if (!file) return;
    try {
      if (file.size > 2 * 1024 * 1024) throw new Error('حجم فایل پشتیبان بیش از ۲ مگابایت است.');
      const payload = JSON.parse(await file.text());
      if (payload.format !== 'farast-reader-personal-tools' || payload.version !== 1 || !payload.data || typeof payload.data !== 'object') throw new Error('ساختار فایل پشتیبان معتبر نیست.');
      const incoming = payload.data;
      if (!Array.isArray(incoming.highlights) || !Array.isArray(incoming.notes) || incoming.highlights.length > 500 || incoming.notes.length > 500 || (incoming.inkStrokes !== undefined && (!Array.isArray(incoming.inkStrokes) || incoming.inkStrokes.length > 250))) throw new Error('محتوای فایل پشتیبان معتبر نیست.');
      if (!window.confirm('یادداشت‌ها و هایلایت‌های فعلی با نسخهٔ پشتیبان جایگزین شوند؟')) return;
      state = { ...defaults, light: !!incoming.light, font: ['system','sans','serif','mono','dyslexic'].includes(incoming.font) ? incoming.font : 'system', lineHeight: [1.4,1.6,1.7,1.9,2.1].includes(Number(incoming.lineHeight)) ? Number(incoming.lineHeight) : 1.7, measure: ['narrow','normal','wide'].includes(incoming.measure) ? incoming.measure : 'normal', paragraphSpacing: [0.5,1,1.5,2].includes(Number(incoming.paragraphSpacing)) ? Number(incoming.paragraphSpacing) : 1, focusMode: !!incoming.focusMode, readingRuler: !!incoming.readingRuler, highlightColor: highlightColors[incoming.highlightColor] ? incoming.highlightColor : 'yellow', size: Math.min(160, Math.max(80, Number(incoming.size) || 100)), highlights: incoming.highlights.filter(x => x && typeof x.quote === 'string' && x.quote.length <= 2000 && Number.isFinite(Number(x.page))), notes: incoming.notes.filter(x => x && typeof x.quote === 'string' && x.quote.length <= 2000 && typeof x.note === 'string' && x.note.length <= 4000 && Number.isFinite(Number(x.page))), inkStrokes: limitInkStrokes(incoming.inkStrokes) };
      persist(); applyPrefs(); renderNotes(); renderHighlights(); announce('نسخهٔ پشتیبان در همین مرورگر بازیابی شد.');
    } catch (error) { announce(error.message || 'بازیابی فایل ناموفق بود.'); }
    finally { importFile.value = ''; }
  });
  highlightButton.addEventListener('click', () => {
    const context = getContext();
    if (!context) return announce('ابتدا بخشی از متن قابل انتخاب را انتخاب کنید.');
    const record = { id: Date.now().toString(36) + Math.random().toString(36).slice(2), quote: context.text, page: context.page, startOffset: context.startOffset, endOffset: context.endOffset, color: state.highlightColor, created: Date.now() };
    state.highlights.push(record);
    persist(); renderHighlights(); if (!notesPanel.hidden) renderNotes(); announce('هایلایت در همین مرورگر ذخیره شد.');
    window.getSelection()?.removeAllRanges();
  });
  noteButton.addEventListener('click', () => {
    const context = getContext();
    if (!context) return announce('برای حاشیه‌نویسی ابتدا متن موردنظر را انتخاب کنید.');
    const note = window.prompt('یادداشت شخصی برای این بخش:');
    if (note === null || !note.trim()) return;
    state.notes.push({ id: Date.now().toString(36) + Math.random().toString(36).slice(2), quote: context.text, page: context.page, startOffset: context.startOffset, endOffset: context.endOffset, note: note.trim().slice(0, 4000), created: Date.now() });
    persist(); renderNotes(); notesPanel.hidden = false; notesButton.setAttribute('aria-expanded', 'true'); announce('یادداشت فقط در مرورگر شما ذخیره شد.');
  });
  const escapeRegExp = value => value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  const renderHighlights = () => {
    root.querySelectorAll('.farast-reader-local-highlight').forEach(mark => mark.replaceWith(document.createTextNode(mark.textContent || '')));
    if (!state.highlights.length) return;
    const records = state.highlights.slice(-100);
    root.querySelectorAll('.farast-reader-page-item').forEach((page, index) => {
      const pageNo = Number(page.dataset.readerPage || index + 1);
      records.filter(item => item.page === pageNo).forEach(item => {
        const map = getPageTextMap(page);
        const nodes = map.nodes;
        const fullText = map.fullText;
        const anchored = Number.isInteger(item.startOffset) && Number.isInteger(item.endOffset) && item.startOffset >= 0 && item.endOffset > item.startOffset && fullText.slice(item.startOffset, item.endOffset) === item.quote;
        const start = anchored ? item.startOffset : fullText.indexOf(item.quote);
        if (start < 0) return;
        const end = anchored ? item.endOffset : start + item.quote.length;
        let cursor = 0;
        const segments = [];
        nodes.forEach(node => {
          const nodeStart = cursor;
          const nodeEnd = cursor + node.nodeValue.length;
          cursor = nodeEnd;
          const from = Math.max(start, nodeStart);
          const to = Math.min(end, nodeEnd);
          if (from < to) segments.push({ node, from: from - nodeStart, to: to - nodeStart });
        });
        segments.reverse().forEach(segment => {
          const range = document.createRange();
          range.setStart(segment.node, segment.from);
          range.setEnd(segment.node, segment.to);
          const mark = document.createElement('mark');
          mark.className = 'farast-reader-local-highlight';
          mark.dataset.highlightId = item.id;
          mark.style.backgroundColor = highlightColors[item.color] || highlightColors.yellow;
          mark.title = 'برای حذف این هایلایت کلیک کنید';
          try { range.surroundContents(mark); } catch (_) {}
        });
      });
    });
  };
  root.addEventListener('click', event => {
    const mark = event.target.closest('mark[data-highlight-id]');
    if (!mark) return;
    const id = mark.dataset.highlightId;
    state.highlights = state.highlights.filter(item => item.id !== id);
    persist(); renderHighlights(); if (!notesPanel.hidden) renderNotes(); announce('هایلایت حذف شد.');
  });
  const jumpToRecord = record => {
    const page = [...root.querySelectorAll('.farast-reader-page-item')].find((item, index) => Number(item.dataset.readerPage || index + 1) === Number(record.page));
    if (!page) return announce('صفحهٔ مربوط به این یادداشت در سند فعلی پیدا نشد.');
    const pageIndex = Number(page.dataset.index || Math.max(0, Number(record.page) - 1));
    const pageButton = root.querySelector('[data-goto="' + pageIndex + '"]');
    if (pageButton && !page.classList.contains('is-active')) pageButton.click();
    window.setTimeout(() => {
      const map = getPageTextMap(page);
      const anchored = Number.isInteger(record.startOffset) && Number.isInteger(record.endOffset)
        && record.startOffset >= 0 && record.endOffset > record.startOffset
        && map.fullText.slice(record.startOffset, record.endOffset) === record.quote;
      const start = anchored ? record.startOffset : map.fullText.indexOf(record.quote);
      if (start < 0) return announce('متن این یادداشت در نسخهٔ فعلی سند پیدا نشد.');
      const end = start + record.quote.length;
      let cursor = 0, first = null, last = null;
      map.nodes.forEach(node => {
        const nodeStart = cursor, nodeEnd = cursor + node.nodeValue.length;
        cursor = nodeEnd;
        if (start >= nodeStart && start < nodeEnd) first = { node, offset: start - nodeStart };
        if (end > nodeStart && end <= nodeEnd) last = { node, offset: end - nodeStart };
      });
      if (!first || !last) return announce('محدودهٔ متن این یادداشت قابل بازیابی نیست.');
      const range = document.createRange();
      range.setStart(first.node, first.offset);
      range.setEnd(last.node, last.offset);
      page.scrollIntoView({ behavior: 'smooth', block: 'center' });
      const selection = window.getSelection();
      selection?.removeAllRanges();
      selection?.addRange(range);
      announce('به محل یادداشت در سند رفتید.');
    }, pageButton ? 80 : 0);
  };
  const renderNotes = () => {
    notesPanel.replaceChildren();
    if (!state.notes.length && !state.highlights.length) { const empty = document.createElement('p'); empty.textContent = 'هنوز یادداشت یا هایلایتی ثبت نشده است.'; notesPanel.append(empty); return; }
    if (state.notes.length) {
      const heading = document.createElement('h4'); heading.textContent = 'یادداشت‌ها'; notesPanel.append(heading);
    }
    state.notes.forEach(item => {
      const row = document.createElement('article'); row.className = 'farast-reader-note';
      const content = document.createElement('div');
      const quote = document.createElement('blockquote'); quote.textContent = '«' + item.quote + '»';
      const body = document.createElement('p'); body.textContent = item.note;
      const meta = document.createElement('small'); meta.textContent = 'صفحه ' + item.page + ' · ذخیره‌شده در این مرورگر';
      content.append(quote, body, meta);
      const actions = document.createElement('div'); actions.className = 'farast-reader-note-actions';
      const jump = document.createElement('button'); jump.type = 'button'; jump.textContent = 'رفتن به متن';
      jump.setAttribute('aria-label', 'رفتن به محل یادداشت صفحه ' + item.page);
      jump.addEventListener('click', () => jumpToRecord(item));
      const remove = document.createElement('button'); remove.type = 'button'; remove.textContent = 'حذف';
      remove.setAttribute('aria-label', 'حذف یادداشت صفحه ' + item.page);
      remove.addEventListener('click', () => { state.notes = state.notes.filter(note => note.id !== item.id); persist(); renderNotes(); });
      actions.append(jump, remove);
      row.append(content, actions); notesPanel.append(row);
    });
    if (state.highlights.length) {
      const heading = document.createElement('h4'); heading.textContent = 'هایلایت‌ها'; notesPanel.append(heading);
      state.highlights.slice().reverse().forEach(item => {
        const row = document.createElement('article'); row.className = 'farast-reader-note farast-reader-highlight-entry';
        const content = document.createElement('div');
        const quote = document.createElement('blockquote'); quote.textContent = '«' + item.quote + '»';
        const meta = document.createElement('small'); meta.textContent = 'صفحه ' + item.page + ' · هایلایت شخصی';
        content.append(quote, meta);
        const actions = document.createElement('div'); actions.className = 'farast-reader-note-actions';
        const jump = document.createElement('button'); jump.type = 'button'; jump.textContent = 'رفتن به متن';
        jump.addEventListener('click', () => jumpToRecord(item));
        const remove = document.createElement('button'); remove.type = 'button'; remove.textContent = 'حذف';
        remove.setAttribute('aria-label', 'حذف هایلایت صفحه ' + item.page);
        remove.addEventListener('click', () => { state.highlights = state.highlights.filter(highlight => highlight.id !== item.id); persist(); renderNotes(); renderHighlights(); });
        actions.append(jump, remove); row.append(content, actions); notesPanel.append(row);
      });
    }
  };
  notesButton.addEventListener('click', () => {
    notesPanel.hidden = !notesPanel.hidden;
    notesButton.setAttribute('aria-expanded', String(!notesPanel.hidden));
    renderNotes();
  });
  root.addEventListener('keydown', event => {
    if (!event.altKey || !event.shiftKey || event.ctrlKey || event.metaKey) return;
    const key = event.key.toLowerCase();
    if (key === 'h' || key === 'n' || key === 'f' || key === 'r') {
      const target = event.target;
      if (target && /^(INPUT|TEXTAREA|SELECT)$/.test(target.tagName)) return;
      event.preventDefault();
      ({ h: highlightButton, n: noteButton, f: focusButton, r: rulerButton })[key]?.click();
    }
  });
  root.addEventListener('farast:reader-section-change', () => { window.setTimeout(renderHighlights, 100); });
  root.addEventListener('farast:reader-page-loaded', () => { window.setTimeout(renderHighlights, 80); });
  root.addEventListener('farast:reader-page-change', () => { window.setTimeout(renderHighlights, 120); });
  // Local ink layer: normalized coordinates keep strokes aligned when pages resize.
  const inkControls = document.createElement('div');
  inkControls.className = 'farast-reader-ink-tools';
  inkControls.setAttribute('aria-label', 'قلم نوری و ترسیم روی صفحه');
  inkControls.innerHTML = '<button type="button" class="reader-tool reader-tool-wide" data-ink-toggle aria-pressed="false">قلم نوری</button><button type="button" class="reader-tool" data-ink-pen aria-pressed="true">قلم</button><button type="button" class="reader-tool" data-ink-eraser aria-pressed="false">پاک‌کن</button><label class="reader-personal-label">رنگ قلم <input type="color" data-ink-color value="#e11d48" aria-label="رنگ قلم"></label><label class="reader-personal-label">ضخامت <input type="range" data-ink-width min="1" max="16" value="3" aria-label="ضخامت قلم"></label><button type="button" class="reader-tool" data-ink-undo>واگرد</button><button type="button" class="reader-tool" data-ink-clear>پاک‌کردن صفحه</button>';
  const inkHelp = document.createElement('p');
  inkHelp.className = 'farast-reader-ink-help';
  inkHelp.id = 'farast-reader-ink-help-' + Math.random().toString(36).slice(2);
  inkHelp.textContent = 'برای ترسیم با ماوس، لمس یا قلم نوری، حالت قلم نوری را فعال کنید. برای کار با صفحه‌کلید از ابزارهای واگرد و پاک‌کردن استفاده کنید؛ ترسیم مستقیم با صفحه‌کلید پشتیبانی نمی‌شود.';
  inkControls.append(inkHelp);
  group.append(inkControls);
  const inkToggle = inkControls.querySelector('[data-ink-toggle]');
  const inkPen = inkControls.querySelector('[data-ink-pen]');
  const inkEraser = inkControls.querySelector('[data-ink-eraser]');
  const inkColor = inkControls.querySelector('[data-ink-color]');
  const inkWidth = inkControls.querySelector('[data-ink-width]');
  let inkEnabled = false;
  let inkTool = 'pen';
  let activeStroke = null;
  const pageNumber = page => Number(page.dataset.readerPage || Number(page.dataset.index || 0) + 1);
  const pageCanvases = new WeakMap();
  const paintPage = page => {
    const rect = page.getBoundingClientRect();
    if (!rect.width || !rect.height) return;
    let canvas = pageCanvases.get(page);
    if (!canvas) {
      if (getComputedStyle(page).position === 'static') page.style.position = 'relative';
      canvas = document.createElement('canvas');
      canvas.className = 'farast-reader-ink-canvas';
      canvas.setAttribute('aria-label', 'لایهٔ ترسیم شخصی صفحه ' + pageNumber(page));
      canvas.setAttribute('role', 'img');
      canvas.setAttribute('aria-describedby', inkHelp.id);
      canvas.width = Math.max(1, Math.round(rect.width * (window.devicePixelRatio || 1)));
      canvas.height = Math.max(1, Math.round(rect.height * (window.devicePixelRatio || 1)));
      Object.assign(canvas.style, { position: 'absolute', inset: '0', width: '100%', height: '100%', zIndex: '8', pointerEvents: inkEnabled ? 'auto' : 'none', touchAction: inkEnabled ? 'none' : 'auto', cursor: inkEnabled ? (inkTool === 'eraser' ? 'cell' : 'crosshair') : 'default' });
      page.append(canvas);
      pageCanvases.set(page, canvas);
      canvas.addEventListener('pointerdown', event => {
        if (!inkEnabled || event.button !== 0) return;
        event.preventDefault();
        canvas.setPointerCapture(event.pointerId);
        const bounds = canvas.getBoundingClientRect();
        if (activeStroke) return;
        activeStroke = { pointerId: event.pointerId, id: Date.now().toString(36) + Math.random().toString(36).slice(2), page: pageNumber(page), tool: inkTool, color: inkColor.value, width: Number(inkWidth.value), points: [{ x: Math.max(0, Math.min(1, (event.clientX - bounds.left) / bounds.width)), y: Math.max(0, Math.min(1, (event.clientY - bounds.top) / bounds.height)), p: event.pressure || 0.5 }] };
      });
      canvas.addEventListener('pointermove', event => {
        if (!activeStroke || activeStroke.pointerId !== event.pointerId || activeStroke.page !== pageNumber(page)) return;
        const bounds = canvas.getBoundingClientRect();
        activeStroke.points.push({ x: Math.max(0, Math.min(1, (event.clientX - bounds.left) / bounds.width)), y: Math.max(0, Math.min(1, (event.clientY - bounds.top) / bounds.height)), p: event.pressure || 0.5 });
        if (activeStroke.points.length > 1000) activeStroke.points = activeStroke.points.filter((_, index) => index % 2 === 0);
        drawInk(page, canvas);
      });
      const finish = event => {
        if (!activeStroke || activeStroke.pointerId !== event.pointerId || activeStroke.page !== pageNumber(page)) return;
        if (activeStroke.points.length === 1) activeStroke.points.push({ ...activeStroke.points[0] });
        state.inkStrokes.push(activeStroke);
        state.inkStrokes = state.inkStrokes.slice(-250);
        let storedPointCount = state.inkStrokes.reduce((total, stroke) => total + stroke.points.length, 0);
        while (storedPointCount > 60000 && state.inkStrokes.length > 1) storedPointCount -= state.inkStrokes.shift().points.length;
        activeStroke = null;
        persist();
        drawInk(page, canvas);
        announce('ترسیم در همین مرورگر ذخیره شد.');
      };
      canvas.addEventListener('pointerup', finish);
      canvas.addEventListener('pointercancel', event => {
        if (!activeStroke || activeStroke.pointerId !== event.pointerId) return;
        activeStroke = null;
        drawInk(page, canvas);
      });
      canvas.addEventListener('lostpointercapture', event => {
        if (!activeStroke || activeStroke.pointerId !== event.pointerId) return;
        activeStroke = null;
        drawInk(page, canvas);
      });
    }
    const dpr = window.devicePixelRatio || 1;
    const width = Math.max(1, Math.round(rect.width * dpr));
    const height = Math.max(1, Math.round(rect.height * dpr));
    if (canvas.width !== width || canvas.height !== height) { canvas.width = width; canvas.height = height; }
    canvas.style.pointerEvents = inkEnabled ? 'auto' : 'none';
    canvas.style.touchAction = inkEnabled ? 'none' : 'auto';
    canvas.style.cursor = inkEnabled ? (inkTool === 'eraser' ? 'cell' : 'crosshair') : 'default';
    drawInk(page, canvas);
  };
  const drawInk = (page, canvas) => {
    if (!canvas) return;
    const ctx = canvas.getContext('2d');
    const rect = canvas.getBoundingClientRect();
    const dpr = window.devicePixelRatio || 1;
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    const strokes = state.inkStrokes.filter(stroke => stroke.page === pageNumber(page));
    if (activeStroke && activeStroke.page === pageNumber(page)) strokes.push(activeStroke);
    strokes.forEach(stroke => {
      if (stroke.points.length < 2) return;
      ctx.save();
      ctx.globalCompositeOperation = stroke.tool === 'eraser' ? 'destination-out' : 'source-over';
      ctx.strokeStyle = stroke.color || '#e11d48';
      ctx.lineWidth = Number(stroke.width) * dpr;
      ctx.lineCap = 'round'; ctx.lineJoin = 'round';
      ctx.beginPath();
      stroke.points.forEach((point, index) => {
        const x = point.x * rect.width * dpr, y = point.y * rect.height * dpr;
        if (index === 0) ctx.moveTo(x, y); else ctx.lineTo(x, y);
      });
      ctx.stroke(); ctx.restore();
    });
  };
  const refreshInk = () => root.querySelectorAll('.farast-reader-page-item').forEach(paintPage);
  const updateInkControls = () => {
    inkToggle.setAttribute('aria-pressed', String(inkEnabled));
    inkPen.setAttribute('aria-pressed', String(inkTool === 'pen'));
    inkEraser.setAttribute('aria-pressed', String(inkTool === 'eraser'));
    refreshInk();
  };
  inkToggle.addEventListener('click', () => { inkEnabled = !inkEnabled; updateInkControls(); announce(inkEnabled ? 'قلم نوری فعال شد؛ انتخاب متن موقتاً غیرفعال است.' : 'قلم نوری غیرفعال شد.'); });
  inkPen.addEventListener('click', () => { inkTool = 'pen'; updateInkControls(); });
  inkEraser.addEventListener('click', () => { inkTool = 'eraser'; updateInkControls(); });
  inkControls.querySelector('[data-ink-undo]').addEventListener('click', () => {
    const current = Number(root.querySelector('.farast-reader-page-item.is-active')?.dataset.readerPage || 1);
    const index = state.inkStrokes.map(stroke => stroke.page).lastIndexOf(current);
    if (index < 0) return announce('ترسیمی برای واگرد در این صفحه وجود ندارد.');
    state.inkStrokes.splice(index, 1); persist(); refreshInk(); announce('آخرین ترسیم صفحه واگرد شد.');
  });
  inkControls.querySelector('[data-ink-clear]').addEventListener('click', () => {
    const current = Number(root.querySelector('.farast-reader-page-item.is-active')?.dataset.readerPage || 1);
    if (!window.confirm('تمام ترسیم‌های این صفحه پاک شوند؟')) return;
    state.inkStrokes = state.inkStrokes.filter(stroke => stroke.page !== current);
    persist(); refreshInk(); announce('ترسیم‌های صفحه پاک شدند.');
  });
  const inkObserver = typeof ResizeObserver !== 'undefined' ? new ResizeObserver(refreshInk) : null;
  root.querySelectorAll('.farast-reader-page-item').forEach(page => { if (inkObserver) inkObserver.observe(page); });
  if (!inkObserver) window.addEventListener('resize', refreshInk, { passive: true });
  root.addEventListener('farast:reader-section-change', refreshInk);
  root.addEventListener('farast:reader-page-loaded', refreshInk);
  root.addEventListener('farast:reader-page-change', refreshInk);
  const inkStyle = document.createElement('style');
  inkStyle.textContent = '.farast-reader-ink-tools{display:flex;align-items:center;gap:5px;flex-wrap:wrap;width:100%}.farast-reader-ink-tools [data-ink-color]{width:30px;height:28px;padding:0;border:0;background:transparent}.farast-reader-ink-tools button[aria-pressed="true"]{outline:2px solid var(--reader-accent,#60a5fa);outline-offset:1px}.farast-reader-ink-canvas{max-width:100%}.farast-reader-ink-help{flex-basis:100%;margin:2px 0;font-size:.875em}';
  document.head.append(inkStyle);
  refreshInk();

  renderNotes();
  renderHighlights();
})();