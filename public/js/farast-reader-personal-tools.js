(() => {
  'use strict';
  const root = document.querySelector('[data-reader-root]');
  if (!root) return;
  const toolbar = root.querySelector('.farast-reader-toolbar');
  if (!toolbar) return;
  const product = root.dataset.productId || location.pathname;
  const storageKey = 'farast.reader.personal-tools.v1:' + product;
  const defaults = { light: false, font: 'system', size: 100, highlights: [], notes: [] };
  let state = { ...defaults };
  try {
    const saved = JSON.parse(localStorage.getItem(storageKey) || '{}');
    if (saved && typeof saved === 'object') state = { ...defaults, ...saved };
  } catch (_) {}
  const persist = () => { try { localStorage.setItem(storageKey, JSON.stringify(state)); } catch (_) { announce('فضای ذخیره‌سازی مرورگر در دسترس نیست.'); } };
  const announce = message => { status.textContent = message; };
  const group = document.createElement('div');
  group.className = 'farast-reader-toolgroup farast-reader-personal-tools';
  group.setAttribute('aria-label', 'ابزارهای شخصی مطالعه');
  group.innerHTML = '<button type="button" class="reader-tool reader-tool-wide" data-reading-light aria-pressed="false"><i class="fa-solid fa-lightbulb"></i><span>نور مطالعه</span></button><label class="reader-personal-label">قلم <select data-reader-font aria-label="نوع قلم"><option value="system">قلم سیستم</option><option value="sans">بدون سریف</option><option value="serif">سریف</option><option value="mono">تک‌فاصله</option><option value="dyslexic">OpenDyslexic (در صورت نصب)</option></select></label><label class="reader-personal-label">اندازه <input data-reader-font-size type="range" min="80" max="160" step="5" aria-label="اندازه قلم"></label><button type="button" class="reader-tool" data-reader-highlight aria-label="هایلایت متن انتخاب‌شده" title="هایلایت انتخاب‌شده"><i class="fa-solid fa-highlighter"></i></button><button type="button" class="reader-tool reader-tool-wide" data-reader-note><i class="fa-regular fa-note-sticky"></i><span>حاشیه‌نویسی</span></button><button type="button" class="reader-tool reader-tool-wide" data-reader-notes aria-expanded="false"><i class="fa-solid fa-list"></i><span>یادداشت‌ها</span></button><button type="button" class="reader-tool reader-tool-wide" data-reader-export><i class="fa-solid fa-file-export"></i><span>خروجی یادداشت‌ها</span></button><button type="button" class="reader-tool reader-tool-wide" data-reader-import><i class="fa-solid fa-file-import"></i><span>بازیابی</span></button><input type="file" accept="application/json,.json" data-reader-import-file hidden aria-label="انتخاب پشتیبان یادداشت‌ها"></div>';
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
    .farast-reader-local-highlight{background:rgba(250,204,21,.55);color:inherit;border-radius:2px}\n    .farast-reader-personal-tools [data-reader-import-file]{display:none}
    @media(max-width:760px){.farast-reader-personal-tools{justify-content:center}.reader-personal-label{font-size:.65rem}}
  `;
  document.head.append(css);
  const lightButton = group.querySelector('[data-reading-light]');
  const fontSelect = group.querySelector('[data-reader-font]');
  const sizeInput = group.querySelector('[data-reader-font-size]');
  const applyPrefs = () => {
    root.classList.toggle('is-reading-light', !!state.light);
    root.dataset.readerFont = state.font;
    root.style.setProperty('--reader-personal-font-size', state.size + '%');
    root.style.setProperty('--reader-font-scale', String(state.size / 100));
    lightButton.setAttribute('aria-pressed', String(!!state.light));
    fontSelect.value = state.font;
    sizeInput.value = String(state.size);
  };
  applyPrefs();
  lightButton.addEventListener('click', () => { state.light = !state.light; applyPrefs(); persist(); announce(state.light ? 'نور مطالعه روشن شد.' : 'نور مطالعه خاموش شد.'); });
  fontSelect.addEventListener('change', () => { state.font = fontSelect.value; applyPrefs(); persist(); announce('قلم مطالعه تغییر کرد. قلم‌های نصب‌شدهٔ سیستم قابل استفاده‌اند.'); });
  sizeInput.addEventListener('input', () => { state.size = Number(sizeInput.value); applyPrefs(); persist(); });
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
      if (!Array.isArray(incoming.highlights) || !Array.isArray(incoming.notes) || incoming.highlights.length > 500 || incoming.notes.length > 500) throw new Error('محتوای فایل پشتیبان معتبر نیست.');
      if (!window.confirm('یادداشت‌ها و هایلایت‌های فعلی با نسخهٔ پشتیبان جایگزین شوند؟')) return;
      state = { ...defaults, light: !!incoming.light, font: ['system','sans','serif','mono','dyslexic'].includes(incoming.font) ? incoming.font : 'system', size: Math.min(160, Math.max(80, Number(incoming.size) || 100)), highlights: incoming.highlights.filter(x => x && typeof x.quote === 'string' && x.quote.length <= 2000 && Number.isFinite(Number(x.page))), notes: incoming.notes.filter(x => x && typeof x.quote === 'string' && x.quote.length <= 2000 && typeof x.note === 'string' && x.note.length <= 4000 && Number.isFinite(Number(x.page))) };
      persist(); applyPrefs(); renderNotes(); renderHighlights(); announce('نسخهٔ پشتیبان در همین مرورگر بازیابی شد.');
    } catch (error) { announce(error.message || 'بازیابی فایل ناموفق بود.'); }
    finally { importFile.value = ''; }
  });
  highlightButton.addEventListener('click', () => {
    const context = getContext();
    if (!context) return announce('ابتدا بخشی از متن قابل انتخاب را انتخاب کنید.');
    const record = { id: Date.now().toString(36) + Math.random().toString(36).slice(2), quote: context.text, page: context.page, created: Date.now() };
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
        let remaining = item.quote;
        for (const node of nodes) {
          if (!remaining) break;
          const at = node.nodeValue.indexOf(remaining);
          if (at < 0) continue;
          const range = document.createRange(); range.setStart(node, at); range.setEnd(node, at + remaining.length);
          const mark = document.createElement('mark'); mark.className = 'farast-reader-local-highlight';
          try { range.surroundContents(mark); remaining = ''; } catch (_) {}
        }
      });
    });
  };
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
  renderNotes();
  renderHighlights();
})();