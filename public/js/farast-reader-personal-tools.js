(() => {
  'use strict';
  const root = document.querySelector('[data-reader-root]');
  if (!root) return;
  const toolbar = root.querySelector('.farast-reader-toolbar');
  if (!toolbar) return;
  const product = root.dataset.productId || location.pathname;
  const storageKey = 'farast.reader.personal-tools.v1:' + product;
  const defaults = { light: false, font: 'system', size: 100, highlightColor: 'yellow', highlights: [], notes: [], inkStrokes: [] };
  let state = { ...defaults };
  try {
    const saved = JSON.parse(localStorage.getItem(storageKey) || '{}');
    if (saved && typeof saved === 'object') state = { ...defaults, ...saved };
  } catch (_) {}
  state.light = !!state.light;
  state.font = ['system','sans','serif','mono','dyslexic'].includes(state.font) ? state.font : 'system';
  state.size = Math.min(160, Math.max(80, Number(state.size) || 100));
  state.highlights = Array.isArray(state.highlights) ? state.highlights.filter(x => x && typeof x.quote === 'string' && x.quote.length <= 2000 && Number.isFinite(Number(x.page))).slice(-500) : [];
  state.notes = Array.isArray(state.notes) ? state.notes.filter(x => x && typeof x.quote === 'string' && x.quote.length <= 2000 && typeof x.note === 'string' && x.note.length <= 4000 && Number.isFinite(Number(x.page))).slice(-500) : [];
  state.inkStrokes = Array.isArray(state.inkStrokes) ? state.inkStrokes.filter(validInkStroke).slice(-500) : [];
  const validInkStroke = stroke => stroke && (stroke.tool === 'pen' || stroke.tool === 'eraser') && Number.isFinite(Number(stroke.page)) && Number(stroke.page) > 0 && Number.isFinite(Number(stroke.width)) && stroke.width >= 1 && stroke.width <= 32 && Array.isArray(stroke.points) && stroke.points.length >= 2 && stroke.points.length <= 2000 && stroke.points.every(point => point && Number.isFinite(point.x) && Number.isFinite(point.y) && point.x >= 0 && point.x <= 1 && point.y >= 0 && point.y <= 1);
  const persist = () => { try { localStorage.setItem(storageKey, JSON.stringify(state)); } catch (_) { announce('فضای ذخیره‌سازی مرورگر در دسترس نیست.'); } };
  const announce = message => { status.textContent = message; };
  const group = document.createElement('div');
  group.className = 'farast-reader-toolgroup farast-reader-personal-tools';
  group.setAttribute('aria-label', 'ابزارهای شخصی مطالعه');
  group.innerHTML = '<button type="button" class="reader-tool reader-tool-wide" data-reading-light aria-pressed="false"><i class="fa-solid fa-lightbulb"></i><span>نور مطالعه</span></button><label class="reader-personal-label">قلم <select data-reader-font aria-label="نوع قلم"><option value="system">قلم سیستم</option><option value="sans">بدون سریف</option><option value="serif">سریف</option><option value="mono">تک‌فاصله</option><option value="dyslexic">OpenDyslexic (در صورت نصب)</option></select></label><label class="reader-personal-label">اندازه <input data-reader-font-size type="range" min="80" max="160" step="5" aria-label="اندازه قلم"></label><label class="reader-personal-label">رنگ <select data-reader-highlight-color aria-label="رنگ هایلایت"><option value="yellow">زرد</option><option value="green">سبز</option><option value="blue">آبی</option><option value="pink">صورتی</option></select></label><button type="button" class="reader-tool" data-reader-highlight aria-label="هایلایت متن انتخاب‌شده" title="هایلایت انتخاب‌شده"><i class="fa-solid fa-highlighter"></i></button><button type="button" class="reader-tool reader-tool-wide" data-reader-note><i class="fa-regular fa-note-sticky"></i><span>حاشیه‌نویسی</span></button><button type="button" class="reader-tool reader-tool-wide" data-reader-notes aria-expanded="false"><i class="fa-solid fa-list"></i><span>یادداشت‌ها</span></button><button type="button" class="reader-tool reader-tool-wide" data-reader-export><i class="fa-solid fa-file-export"></i><span>خروجی یادداشت‌ها</span></button><button type="button" class="reader-tool reader-tool-wide" data-reader-import><i class="fa-solid fa-file-import"></i><span>بازیابی</span></button><input type="file" accept="application/json,.json" data-reader-import-file hidden aria-label="انتخاب پشتیبان یادداشت‌ها"></div>';
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
    .farast-reader-note button{align-self:start;border:1px solid var(--reader-line);border-radius:6px;background:transparent;color:var(--reader-text);padding:4px 7px;cursor:pointer}
    .farast-reader-local-highlight{color:inherit;border-radius:2px;cursor:pointer}
    .farast-reader-personal-tools [data-reader-import-file]{display:none}
    @media(max-width:760px){.farast-reader-personal-tools{justify-content:center}.reader-personal-label{font-size:.65rem}}
  `;
  document.head.append(css);
  const lightButton = group.querySelector('[data-reading-light]');
  const fontSelect = group.querySelector('[data-reader-font]');
  const sizeInput = group.querySelector('[data-reader-font-size]');
  const highlightColorSelect = group.querySelector('[data-reader-highlight-color]');
  const highlightColors = { yellow: 'rgba(250,204,21,.55)', green: 'rgba(74,222,128,.38)', blue: 'rgba(96,165,250,.42)', pink: 'rgba(244,114,182,.4)' };
  const applyPrefs = () => {
    root.classList.toggle('is-reading-light', !!state.light);
    root.dataset.readerFont = state.font;
    root.dataset.readerFontSize = String(state.size);
    root.style.setProperty('--reader-personal-font-size', state.size + '%');
    root.style.setProperty('--reader-font-scale', String(state.size / 100));
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
  highlightColorSelect.addEventListener('change', () => { state.highlightColor = highlightColorSelect.value; persist(); announce('رنگ هایلایت تغییر کرد.'); });
  const getContext = () => {
    const selection = window.getSelection();
    const text = selection ? selection.toString().trim() : '';
    if (!text || !root.contains(selection.anchorNode)) return null;
    const page = selection.anchorNode.parentElement?.closest('.farast-reader-page-item');
    return { text: text.slice(0, 2000), page: Number(page?.dataset.readerPage || Number(page?.dataset.index || 0) + 1), node: selection.anchorNode };
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
      if (!Array.isArray(incoming.highlights) || !Array.isArray(incoming.notes) || incoming.highlights.length > 500 || incoming.notes.length > 500 || (incoming.inkStrokes !== undefined && (!Array.isArray(incoming.inkStrokes) || incoming.inkStrokes.length > 500))) throw new Error('محتوای فایل پشتیبان معتبر نیست.');
      if (!window.confirm('یادداشت‌ها و هایلایت‌های فعلی با نسخهٔ پشتیبان جایگزین شوند؟')) return;
      state = { ...defaults, light: !!incoming.light, font: ['system','sans','serif','mono','dyslexic'].includes(incoming.font) ? incoming.font : 'system', highlightColor: highlightColors[incoming.highlightColor] ? incoming.highlightColor : 'yellow', size: Math.min(160, Math.max(80, Number(incoming.size) || 100)), highlights: incoming.highlights.filter(x => x && typeof x.quote === 'string' && x.quote.length <= 2000 && Number.isFinite(Number(x.page))), notes: incoming.notes.filter(x => x && typeof x.quote === 'string' && x.quote.length <= 2000 && typeof x.note === 'string' && x.note.length <= 4000 && Number.isFinite(Number(x.page))), inkStrokes: (incoming.inkStrokes || []).filter(validInkStroke).slice(-500) };
      persist(); applyPrefs(); renderNotes(); renderHighlights(); announce('نسخهٔ پشتیبان در همین مرورگر بازیابی شد.');
    } catch (error) { announce(error.message || 'بازیابی فایل ناموفق بود.'); }
    finally { importFile.value = ''; }
  });
  highlightButton.addEventListener('click', () => {
    const context = getContext();
    if (!context) return announce('ابتدا بخشی از متن قابل انتخاب را انتخاب کنید.');
    const record = { id: Date.now().toString(36) + Math.random().toString(36).slice(2), quote: context.text, page: context.page, color: state.highlightColor, created: Date.now() };
    state.highlights.push(record);
    persist(); renderHighlights(); announce('هایلایت در همین مرورگر ذخیره شد.');
    window.getSelection()?.removeAllRanges();
  });
  noteButton.addEventListener('click', () => {
    const context = getContext();
    if (!context) return announce('برای حاشیه‌نویسی ابتدا متن موردنظر را انتخاب کنید.');
    const note = window.prompt('یادداشت شخصی برای این بخش:');
    if (note === null || !note.trim()) return;
    state.notes.push({ id: Date.now().toString(36) + Math.random().toString(36).slice(2), quote: context.text, page: context.page, note: note.trim().slice(0, 4000), created: Date.now() });
    persist(); renderNotes(); notesPanel.hidden = false; notesButton.setAttribute('aria-expanded', 'true'); announce('یادداشت فقط در مرورگر شما ذخیره شد.');
  });
  const escapeRegExp = value => value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  const renderHighlights = () => {
    root.querySelectorAll('.farast-reader-local-highlight').forEach(mark => mark.replaceWith(document.createTextNode(mark.textContent || '')));
    if (!state.highlights.length) return;
    const records = state.highlights.slice(-100);
    root.querySelectorAll('.farast-reader-page-item').forEach((page, index) => {
      const pageNo = Number(page.dataset.readerPage || index + 1);
      const walker = document.createTreeWalker(page, NodeFilter.SHOW_TEXT, { acceptNode(node) {
        if (!node.nodeValue.trim() || node.parentElement.closest('button,script,style,textarea,select,.farast-reader-local-highlight')) return NodeFilter.FILTER_REJECT;
        return NodeFilter.FILTER_ACCEPT;
      }});
      const nodes = []; while (walker.nextNode()) nodes.push(walker.currentNode);
      records.filter(item => item.page === pageNo).forEach(item => {
        const fullText = nodes.map(node => node.nodeValue).join('');
        const start = fullText.indexOf(item.quote);
        if (start < 0) return;
        const end = start + item.quote.length;
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
    persist(); renderHighlights(); announce('هایلایت حذف شد.');
  });
  const renderNotes = () => {
    notesPanel.replaceChildren();
    if (!state.notes.length) { const empty = document.createElement('p'); empty.textContent = 'هنوز یادداشتی ثبت نشده است.'; notesPanel.append(empty); return; }
    state.notes.forEach(item => {
      const row = document.createElement('article'); row.className = 'farast-reader-note';
      const content = document.createElement('div');
      const quote = document.createElement('blockquote'); quote.textContent = '«' + item.quote + '»';
      const body = document.createElement('p'); body.textContent = item.note;
      const meta = document.createElement('small'); meta.textContent = 'صفحه ' + item.page + ' · ذخیره‌شده در این مرورگر';
      content.append(quote, body, meta);
      const remove = document.createElement('button'); remove.type = 'button'; remove.textContent = 'حذف';
      remove.setAttribute('aria-label', 'حذف یادداشت صفحه ' + item.page);
      remove.addEventListener('click', () => { state.notes = state.notes.filter(note => note.id !== item.id); persist(); renderNotes(); });
      row.append(content, remove); notesPanel.append(row);
    });
  };
  notesButton.addEventListener('click', () => {
    notesPanel.hidden = !notesPanel.hidden;
    notesButton.setAttribute('aria-expanded', String(!notesPanel.hidden));
    renderNotes();
  });
  root.addEventListener('farast:reader-section-change', () => { window.setTimeout(renderHighlights, 100); });
  root.addEventListener('farast:reader-page-loaded', () => { window.setTimeout(renderHighlights, 80); });
  root.addEventListener('farast:reader-page-change', () => { window.setTimeout(renderHighlights, 120); });
  // Local ink layer: normalized coordinates keep strokes aligned when pages resize.
  const inkControls = document.createElement('div');
  inkControls.className = 'farast-reader-ink-tools';
  inkControls.setAttribute('aria-label', 'قلم نوری و ترسیم روی صفحه');
  inkControls.innerHTML = '<button type="button" class="reader-tool reader-tool-wide" data-ink-toggle aria-pressed="false">قلم نوری</button><button type="button" class="reader-tool" data-ink-pen aria-pressed="true">قلم</button><button type="button" class="reader-tool" data-ink-eraser aria-pressed="false">پاک‌کن</button><label class="reader-personal-label">رنگ قلم <input type="color" data-ink-color value="#e11d48" aria-label="رنگ قلم"></label><label class="reader-personal-label">ضخامت <input type="range" data-ink-width min="1" max="16" value="3" aria-label="ضخامت قلم"></label><button type="button" class="reader-tool" data-ink-undo>واگرد</button><button type="button" class="reader-tool" data-ink-clear>پاک‌کردن صفحه</button>';
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
        activeStroke = { id: Date.now().toString(36) + Math.random().toString(36).slice(2), page: pageNumber(page), tool: inkTool, color: inkColor.value, width: Number(inkWidth.value), points: [{ x: Math.max(0, Math.min(1, (event.clientX - bounds.left) / bounds.width)), y: Math.max(0, Math.min(1, (event.clientY - bounds.top) / bounds.height)), p: event.pressure || 0.5 }] };
      });
      canvas.addEventListener('pointermove', event => {
        if (!activeStroke || activeStroke.page !== pageNumber(page)) return;
        const bounds = canvas.getBoundingClientRect();
        activeStroke.points.push({ x: Math.max(0, Math.min(1, (event.clientX - bounds.left) / bounds.width)), y: Math.max(0, Math.min(1, (event.clientY - bounds.top) / bounds.height)), p: event.pressure || 0.5 });
        if (activeStroke.points.length > 2000) activeStroke.points = activeStroke.points.filter((_, index) => index % 2 === 0);
        drawInk(page, canvas);
      });
      const finish = () => {
        if (!activeStroke || activeStroke.page !== pageNumber(page)) return;
        if (activeStroke.points.length === 1) activeStroke.points.push({ ...activeStroke.points[0] });
        state.inkStrokes.push(activeStroke);
        state.inkStrokes = state.inkStrokes.slice(-500);
        activeStroke = null;
        persist();
        drawInk(page, canvas);
        announce('ترسیم در همین مرورگر ذخیره شد.');
      };
      canvas.addEventListener('pointerup', finish);
      canvas.addEventListener('pointercancel', finish);
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
  const inkObserver = new ResizeObserver(refreshInk);
  root.querySelectorAll('.farast-reader-page-item').forEach(page => inkObserver.observe(page));
  root.addEventListener('farast:reader-section-change', refreshInk);
  root.addEventListener('farast:reader-page-loaded', refreshInk);
  root.addEventListener('farast:reader-page-change', refreshInk);
  const inkStyle = document.createElement('style');
  inkStyle.textContent = '.farast-reader-ink-tools{display:flex;align-items:center;gap:5px;flex-wrap:wrap;width:100%}.farast-reader-ink-tools [data-ink-color]{width:30px;height:28px;padding:0;border:0;background:transparent}.farast-reader-ink-tools button[aria-pressed="true"]{outline:2px solid var(--reader-accent,#60a5fa);outline-offset:1px}.farast-reader-ink-canvas{max-width:100%}';
  document.head.append(inkStyle);
  refreshInk();

  renderNotes();
  renderHighlights();
})();