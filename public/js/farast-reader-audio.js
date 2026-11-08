(() => {
  'use strict';
  const root = document.querySelector('[data-reader-root]');
  if (!root) return;

  const articles = [...root.querySelectorAll('.farast-reader-page-item')];
  if (!articles.some(article => article.dataset.audioUrl)) return;

  const fa = value => Number(value || 0).toLocaleString('fa-IR');
  const parseTracks = () => articles.map((article, section) => {
    let timings = [];
    try { timings = JSON.parse(article.dataset.audioTiming || '[]'); } catch (_) {}
    if (!Array.isArray(timings)) timings = [];
    return {
      section,
      url: article.dataset.audioUrl || '',
      timings: timings.filter(item => item && typeof item.text === 'string'
        && Number.isFinite(Number(item.start)) && Number.isFinite(Number(item.end))
        && Number(item.start) >= 0 && Number(item.end) > Number(item.start))
        .map((item, index) => ({ ...item, start: Number(item.start), end: Number(item.end), page: Math.max(1, Number(item.page) || 1), index }))
        .sort((a, b) => a.start - b.start)
    };
  });
  const tracks = parseTracks();
  const available = tracks.filter(track => track.url && track.timings.length);
  if (!available.length) return;

  const dock = document.createElement('section');
  dock.className = 'farast-audio-dock';
  dock.setAttribute('aria-label', 'همراهی صوتی');
  dock.innerHTML = `
    <div class="farast-audio-top">
      <div class="farast-audio-title"><span class="farast-audio-pulse"></span><div><strong>همراهی صوتی</strong><small data-audio-status>صوت آمادهٔ مطالعه است</small></div></div>
      <div class="farast-audio-caption" data-audio-caption aria-live="polite">برای شروع، پخش را انتخاب کنید.</div>
      <button type="button" class="farast-audio-collapse" data-audio-collapse aria-expanded="true" aria-label="جمع‌کردن پنل صوت"><i class="fa-solid fa-chevron-down"></i></button>
    </div>
    <div class="farast-audio-controls">
      <button type="button" class="farast-audio-play" data-audio-play aria-label="پخش"><i class="fa-solid fa-play"></i></button>
      <div class="farast-audio-timeline">
        <input type="range" min="0" max="1000" value="0" step="1" data-audio-seek aria-label="موقعیت پخش">
        <div class="farast-audio-time"><span data-audio-current>۰۰:۰۰</span><span data-audio-duration>۰۰:۰۰</span></div>
      </div>
      <label class="farast-audio-speed"><span>سرعت</span><select data-audio-speed aria-label="سرعت پخش"><option value="0.75">۰٫۷۵×</option><option value="1" selected>۱×</option><option value="1.25">۱٫۲۵×</option><option value="1.5">۱٫۵×</option><option value="1.75">۱٫۷۵×</option><option value="2">۲×</option></select></label>
      <label class="farast-audio-volume"><i class="fa-solid fa-volume-high"></i><input type="range" min="0" max="1" value="1" step="0.05" data-audio-volume aria-label="میزان صدا"></label>
    </div>
    <div class="farast-audio-foot"><span data-audio-progress>همگام‌سازی متن فعال است</span><span>کلیک روی کلمه برای رفتن به همان لحظه</span></div>
  `;
  root.append(dock);

  const style = document.createElement('style');
  style.textContent = `
    .farast-audio-dock{position:sticky;bottom:0;z-index:30;margin-top:auto;padding:12px clamp(12px,3vw,28px) 10px;background:rgba(13,24,42,.97);color:#edf4ff;border-top:1px solid rgba(148,180,225,.2);box-shadow:0 -12px 34px rgba(0,0,0,.22);backdrop-filter:blur(16px)}
    .farast-audio-top,.farast-audio-controls,.farast-audio-foot{display:flex;align-items:center;gap:14px}.farast-audio-top{min-height:34px}.farast-audio-title{display:flex;align-items:center;gap:10px;min-width:150px}.farast-audio-title strong{display:block;font-size:.78rem}.farast-audio-title small{display:block;color:#9db0ca;font-size:.65rem;margin-top:2px}.farast-audio-pulse{width:9px;height:9px;border-radius:50%;background:#60a5fa;box-shadow:0 0 0 4px rgba(96,165,250,.13)}.farast-audio-dock.is-playing .farast-audio-pulse{background:#34d399;animation:farast-audio-pulse 1.5s infinite}
    @keyframes farast-audio-pulse{50%{box-shadow:0 0 0 8px rgba(52,211,153,0)}}
    .farast-audio-caption{flex:1;min-width:0;text-align:center;color:#dce8fa;font-size:.78rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.farast-audio-collapse{border:0;background:transparent;color:#9db0ca;cursor:pointer;padding:7px}.farast-audio-controls{margin-top:8px}.farast-audio-play{width:38px;height:38px;border:0;border-radius:50%;background:#3b82f6;color:white;cursor:pointer;flex:0 0 auto}.farast-audio-timeline{flex:1;min-width:100px}.farast-audio-timeline input{width:100%;accent-color:#60a5fa;cursor:pointer}.farast-audio-time{display:flex;justify-content:space-between;color:#9db0ca;font-size:.63rem;font-variant-numeric:tabular-nums}.farast-audio-speed,.farast-audio-volume{display:flex;align-items:center;gap:7px;color:#aebfd7;font-size:.65rem;white-space:nowrap}.farast-audio-speed select{border:1px solid rgba(148,180,225,.22);border-radius:7px;background:#172941;color:#edf4ff;padding:5px}.farast-audio-volume input{width:76px;accent-color:#60a5fa}.farast-audio-foot{justify-content:space-between;margin-top:6px;color:#8298b7;font-size:.6rem}.farast-audio-dock.is-collapsed .farast-audio-controls,.farast-audio-dock.is-collapsed .farast-audio-foot{display:none}.farast-audio-dock.is-collapsed .farast-audio-collapse i{transform:rotate(180deg)}
    .farast-pdf-text-layer .reader-audio-word,.farast-pdf-cont-text .reader-audio-word{position:absolute;display:block;z-index:8;color:transparent;background:transparent;border:0;border-radius:3px;padding:0;margin:0;cursor:pointer;pointer-events:auto;user-select:none;-webkit-user-select:none;outline-offset:1px}.farast-pdf-text-layer .reader-audio-word.is-current,.farast-pdf-cont-text .reader-audio-word.is-current{background:rgba(250,204,21,.58);box-shadow:0 0 0 1px rgba(217,150,0,.28);border-radius:3px}.farast-pdf-text-layer .reader-audio-word.is-read,.farast-pdf-cont-text .reader-audio-word.is-read{background:rgba(52,211,153,.13)}.farast-audio-dock[hidden]{display:none}
    @media(max-width:700px){.farast-audio-top{gap:7px}.farast-audio-title{min-width:112px}.farast-audio-caption{font-size:.68rem}.farast-audio-volume{display:none}.farast-audio-foot span:last-child{display:none}.farast-audio-controls{gap:8px}.farast-audio-speed{font-size:.6rem}}
    @media(prefers-reduced-motion:reduce){.farast-audio-pulse{animation:none!important}}
  `;
  document.head.append(style);

  const audio = new Audio();
  audio.preload = 'metadata';
  audio.setAttribute('playsinline', '');
  const $ = selector => dock.querySelector(selector);
  const playButton = $('[data-audio-play]');
  const seek = $('[data-audio-seek]');
  const currentLabel = $('[data-audio-current]');
  const durationLabel = $('[data-audio-duration]');
  const status = $('[data-audio-status]');
  const caption = $('[data-audio-caption]');
  const progressLabel = $('[data-audio-progress]');
  let activeSection = -1;
  let activeTrack = null;
  let activeSegment = -1;
  let activePage = 1;
  let wordMarks = [];
  let raf = 0;
  let lastPageRequest = 0;

  const formatTime = seconds => {
    const value = Math.max(0, Math.floor(Number(seconds) || 0));
    return String(Math.floor(value / 60)).padStart(2, '0') + ':' + String(value % 60).padStart(2, '0');
  };
  const normalize = value => String(value || '').toLocaleLowerCase()
    .replace(/[يى]/g, 'ی').replace(/ك/g, 'ک')
    .replace(/[\u064B-\u065F\u0670]/g, '').replace(/[\u200c\u200f\u202a-\u202e]/g, '')
    .replace(/[^\p{L}\p{N}]+/gu, '');
  const normalizedWithOffsets = value => {
    let normalized = '';
    const offsets = [];
    for (let i = 0; i < value.length; i++) {
      const part = normalize(value[i]);
      for (let j = 0; j < part.length; j++) { normalized += part[j]; offsets.push(i); }
    }
    return { normalized, offsets };
  };
  const clearMarks = () => {
    wordMarks.forEach(mark => mark.element.remove());
    wordMarks = [];
    activeSegment = -1;
  };
  const getTextLayer = page => {
    const active = root.querySelector('.farast-reader-page-item.is-active');
    if (!active) return null;
    const continuous = active.querySelector('.farast-pdf-continuous-pages');
    if (continuous && !continuous.hidden) {
      return continuous.querySelector('[data-pdf-cont-page="' + page + '"] .farast-pdf-cont-text');
    }
    const pageInput = root.querySelector('#reader-page-number');
    if (pageInput && Number(pageInput.value) !== Number(page)) return null;
    return active.querySelector('.farast-pdf-text-layer');
  };
  const buildMarks = page => {
    clearMarks();
    if (!activeTrack || !activeTrack.timings.length) return;
    const layer = getTextLayer(page);
    if (!layer || !layer.querySelector('span')) return;
    const spans = [...layer.querySelectorAll('span')].filter(span => !span.classList.contains('reader-audio-word'));
    const cursors = new Map();
    const items = activeTrack.timings.filter(item => item.page === page);
    const layerRect = layer.getBoundingClientRect();
    items.forEach(item => {
      const words = String(item.text).trim().split(/\s+/u).filter(Boolean);
      words.forEach(word => {
        const target = normalize(word);
        if (!target) return;
        let found = null;
        for (let spanIndex = 0; spanIndex < spans.length && !found; spanIndex++) {
          const span = spans[spanIndex];
          const node = span.firstChild;
          if (!node || node.nodeType !== Node.TEXT_NODE) continue;
          const raw = node.nodeValue || '';
          const mapped = normalizedWithOffsets(raw);
          const from = cursors.get(spanIndex) || 0;
          let at = mapped.normalized.indexOf(target, from);
          if (at < 0) continue;
          const start = mapped.offsets[at];
          const end = (mapped.offsets[at + target.length - 1] ?? start) + 1;
          cursors.set(spanIndex, at + target.length);
          found = { node, start, end };
        }
        if (!found) return;
        const range = document.createRange();
        range.setStart(found.node, found.start);
        range.setEnd(found.node, found.end);
        [...range.getClientRects()].forEach(rect => {
          if (!rect.width || !rect.height) return;
          const mark = document.createElement('span');
          mark.className = 'reader-audio-word';
          mark.dataset.segment = String(item.index);
          mark.setAttribute('role', 'button');
          mark.setAttribute('aria-label', 'رفتن به '+word);
          mark.tabIndex = 0;
          Object.assign(mark.style, {
            left: (rect.left - layerRect.left) + 'px',
            top: (rect.top - layerRect.top) + 'px',
            width: rect.width + 'px',
            height: rect.height + 'px'
          });
          const jump = () => { audio.currentTime = item.start; if (audio.paused) audio.play().catch(() => {}); };
          mark.addEventListener('click', jump);
          mark.addEventListener('keydown', event => { if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); jump(); } });
          layer.append(mark);
          wordMarks.push({ element: mark, segment: item.index });
        });
      });
    });
    updateHighlight(audio.currentTime || 0);
  };
  const updateHighlight = time => {
    if (!activeTrack) return;
    const timings = activeTrack.timings;
    let low = 0, high = timings.length - 1, found = -1;
    while (low <= high) {
      const mid = (low + high) >> 1;
      if (timings[mid].start <= time) { found = mid; low = mid + 1; } else high = mid - 1;
    }
    if (found >= 0 && time > timings[found].end) {
      const next = timings[found + 1];
      if (next && time >= next.start) found++;
      else if (time > timings[found].end) found = -1;
    }
    const item = found >= 0 ? timings[found] : null;
    if (item && item.page !== activePage) {
      const now = performance.now();
      // Keep the rendered page as the source of truth while throttled. If we
      // assign activePage before the request is sent, a fast page transition
      // can be swallowed permanently by this throttle.
      if (now - lastPageRequest > 350) {
        lastPageRequest = now;
        activePage = item.page;
        const input = root.querySelector('#reader-page-number');
        if (input) { input.value = String(activePage); input.dispatchEvent(new Event('change', { bubbles: true })); }
      }
    }
    if (found !== activeSegment) {
      activeSegment = found;
      wordMarks.forEach(mark => {
        mark.element.classList.toggle('is-current', mark.segment === found);
        mark.element.classList.toggle('is-read', mark.segment < found);
      });
      if (item) {
        caption.textContent = item.text;
        progressLabel.textContent = 'کلمه ' + fa(found + 1) + ' از ' + fa(timings.length);
        const current = wordMarks.find(mark => mark.segment === found)?.element;
        if (current && audio.paused === false) {
          const rect = current.getBoundingClientRect();
          const stage = root.querySelector('#reader-stage');
          const stageRect = stage?.getBoundingClientRect();
          if (stage && stageRect && (rect.top < stageRect.top + 70 || rect.bottom > stageRect.bottom - 70)) {
            current.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'nearest' });
          }
        }
      }
    }
  };

  const selectSection = section => {
    const track = tracks[section];
    if (!track || !track.url || !track.timings.length) {
      audio.pause(); audio.removeAttribute('src'); audio.load();
      activeSection = section; activeTrack = null; clearMarks();
      dock.hidden = true;
      return;
    }
    if (activeSection === section && audio.src) return;
    audio.pause();
    activeSection = section;
    activeTrack = track;
    activePage = 1;
    clearMarks();
    audio.src = track.url;
    audio.load();
    buildMarks(activePage);
    dock.hidden = false;
    status.textContent = 'صوت از پیش آماده · همگام‌سازی کلمه‌به‌کلمه';
    caption.textContent = 'برای شروع، پخش را انتخاب کنید.';
    progressLabel.textContent = fa(track.timings.length) + ' نشانگر زمانی';
    seek.value = '0';
  };

  playButton.addEventListener('click', () => {
    if (!activeTrack) return;
    if (audio.paused) audio.play().catch(() => { status.textContent = 'پخش صوت ممکن نشد؛ دسترسی فایل را بررسی کنید.'; });
    else audio.pause();
  });
  audio.addEventListener('play', () => { playButton.innerHTML = '<i class="fa-solid fa-pause"></i>'; playButton.setAttribute('aria-label', 'مکث'); dock.classList.add('is-playing'); status.textContent = 'در حال همراهی با متن'; startFrameLoop(); });
  audio.addEventListener('pause', () => { playButton.innerHTML = '<i class="fa-solid fa-play"></i>'; playButton.setAttribute('aria-label', 'پخش'); dock.classList.remove('is-playing'); cancelAnimationFrame(raf); });
  audio.addEventListener('loadedmetadata', () => { durationLabel.textContent = formatTime(audio.duration); });
  audio.addEventListener('timeupdate', () => { if (Number.isFinite(audio.duration) && audio.duration > 0) seek.value = String(Math.round((audio.currentTime / audio.duration) * 1000)); currentLabel.textContent = formatTime(audio.currentTime); updateHighlight(audio.currentTime); });
  audio.addEventListener('seeking', () => updateHighlight(audio.currentTime));
  audio.addEventListener('ended', () => { status.textContent = 'این بخش به پایان رسید'; caption.textContent = 'برای ادامه، بخش بعدی را انتخاب کنید.'; });
  audio.addEventListener('error', () => { status.textContent = 'بارگذاری صوت ناموفق بود؛ فایل یا دسترسی آن را بررسی کنید.'; });
  seek.addEventListener('input', () => { if (Number.isFinite(audio.duration)) { audio.currentTime = (Number(seek.value) / 1000) * audio.duration; updateHighlight(audio.currentTime); } });
  $('[data-audio-speed]').addEventListener('change', event => { audio.playbackRate = Number(event.target.value) || 1; });
  $('[data-audio-volume]').addEventListener('input', event => { audio.volume = Math.max(0, Math.min(1, Number(event.target.value))); });
  $('[data-audio-collapse]').addEventListener('click', event => { const collapsed = dock.classList.toggle('is-collapsed'); event.currentTarget.setAttribute('aria-expanded', String(!collapsed)); });

  function startFrameLoop() {
    cancelAnimationFrame(raf);
    const tick = () => { if (audio.paused) return; updateHighlight(audio.currentTime); raf = requestAnimationFrame(tick); };
    raf = requestAnimationFrame(tick);
  }

  root.addEventListener('farast:reader-section-change', event => selectSection(Number(event.detail?.section ?? 0)));
  root.addEventListener('farast:reader-page-change', event => {
    const page = Number(event.detail?.page || 1);
    activePage = page;
    buildMarks(page);
  });
  const observer = new MutationObserver(mutations => {
    const changed = mutations.some(mutation => [...mutation.addedNodes].some(node =>
      node.nodeType === Node.ELEMENT_NODE && ((node.matches?.('.textLayer span') && !node.classList.contains('reader-audio-word')) || node.querySelector?.('.textLayer span:not(.reader-audio-word)'))
    ));
    if (changed && activeTrack) requestAnimationFrame(() => buildMarks(activePage));
  });
  observer.observe(root, { childList: true, subtree: true });

  const initial = root.querySelector('.farast-reader-page-item.is-active');
  selectSection(Number(initial?.dataset.index || 0));
  window.FarastAudioReader = {
    selectSection,
    setPage(page) { activePage = Number(page) || 1; buildMarks(activePage); },
    destroy() { audio.pause(); observer.disconnect(); cancelAnimationFrame(raf); clearMarks(); }
  };
})();