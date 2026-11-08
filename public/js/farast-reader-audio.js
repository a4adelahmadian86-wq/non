(() => {
  'use strict';
  const root = document.querySelector('[data-reader-root]');
  if (!root) return;

  const articles = [...root.querySelectorAll('.farast-reader-page-item')];
  if (!articles.some(article => article.dataset.audioUrl)) return;
  articles.forEach((article, index) => {
    if (!article.hasAttribute('tabindex')) article.tabIndex = 0;
    if (!article.hasAttribute('aria-label')) {
      const page = Number(article.dataset.readerPage || index + 1);
      article.setAttribute('aria-label', 'خواندن صفحه ' + String(page).replace(/\d/g, digit => '۰۱۲۳۴۵۶۷۸۹'[Number(digit)]));
    }
    article.dataset.readerAudioPageTarget = '1';
  });

  const fa = value => Number(value || 0).toLocaleString('fa-IR');
  const parseTimingJson = raw => {
    try {
      const value = JSON.parse(raw || '[]');
      return Array.isArray(value) ? value : [];
    } catch (_) {
      return [];
    }
  };
  const normalizeTimings = timings => timings.filter(item => item && typeof item.text === 'string'
    && Number.isFinite(Number(item.start)) && Number.isFinite(Number(item.end))
    && Number(item.start) >= 0 && Number(item.end) > Number(item.start))
    .map((item, index) => ({
      ...item,
      start: Number(item.start),
      end: Number(item.end),
      page: Math.max(1, Number(item.page) || 1),
      words: Array.isArray(item.words) ? item.words.filter(word => word && typeof word.text === 'string'
        && Number.isFinite(Number(word.start)) && Number.isFinite(Number(word.end))
        && Number(word.start) >= 0 && Number(word.end) > Number(word.start))
        .map(word => ({ ...word, start: Number(word.start), end: Number(word.end) })) : [],
      index
    }))
    .sort((a, b) => a.start - b.start);
  const sharedDocumentTimings = normalizeTimings(parseTimingJson(root.dataset.readerAudioTiming));
  const useSharedDocumentTimeline = root.dataset.readerMode === 'document' && sharedDocumentTimings.length > 0;
  const parseTracks = () => articles.map((article, section) => {
    const timings = useSharedDocumentTimeline
      ? sharedDocumentTimings
      : normalizeTimings(parseTimingJson(article.dataset.audioTiming));
    return {
      section,
      url: article.dataset.audioUrl || '',
      timings,
      granularity: timings.some(item => item.words.length > 0) ? 'word' : 'segment',
      alignmentQuality: timings.length > 0 && timings.every(item => item.words.length > 0 && item.alignment_quality === 'phoneme') ? 'phoneme' : 'estimated'
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
    .farast-pdf-text-layer .reader-audio-word,.farast-pdf-cont-text .reader-audio-word{position:absolute;display:block;z-index:8;color:transparent;background:transparent;border:0;border-radius:3px;padding:0;margin:0;cursor:pointer;pointer-events:auto;user-select:none;-webkit-user-select:none;outline-offset:1px}.farast-pdf-text-layer .reader-audio-word.is-current,.farast-pdf-cont-text .reader-audio-word.is-current,.reader-document-word.is-current{background:rgba(250,204,21,.58);box-shadow:0 0 0 1px rgba(217,150,0,.28);border-radius:3px}.farast-pdf-text-layer .reader-audio-word.is-read,.farast-pdf-cont-text .reader-audio-word.is-read,.reader-document-word.is-read{background:rgba(52,211,153,.13)}.farast-audio-dock[hidden]{display:none}
    .reader-speech-target-control{display:inline-flex;align-items:center;justify-content:center;margin-inline-start:6px;padding:2px 6px;min-width:24px;min-height:24px;border:1px solid rgba(59,130,246,.35);border-radius:6px;background:rgba(59,130,246,.08);color:#2563eb;font-size:.72em;line-height:1;cursor:pointer;vertical-align:middle;opacity:.58}
    .reader-speech-target-control:hover,.reader-speech-target-control:focus-visible{opacity:1;background:rgba(59,130,246,.16);outline:2px solid #60a5fa;outline-offset:2px}
    .farast-reader[data-theme="dark"] .reader-speech-target-control{color:#93c5fd;border-color:rgba(147,197,253,.38);background:rgba(59,130,246,.16)}
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
  let documentWordMarks = [];
  let activeDocumentWord = null;
  let raf = 0;
  let lastPageRequest = 0;
  let rangeEnd = null;
  let suppressNextClick = false;
  let selectionSuppressTimer = 0;

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
    documentWordMarks.forEach(mark => mark.element.classList.remove('is-current', 'is-read'));
    documentWordMarks = [];
    activeDocumentWord = null;
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
      words.forEach((word, wordIndex) => {
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
          mark.textContent = word;
          mark.dataset.segment = String(item.index);
          mark.setAttribute('role', 'button');
          mark.setAttribute('aria-label', (activeTrack.granularity === 'word' ? 'رفتن به کلمه ' : 'رفتن به بخش ') + word);
          mark.tabIndex = 0;
          Object.assign(mark.style, {
            left: (rect.left - layerRect.left) + 'px',
            top: (rect.top - layerRect.top) + 'px',
            width: rect.width + 'px',
            height: rect.height + 'px',
            font: getComputedStyle(found.node.parentElement || layer).font,
            lineHeight: getComputedStyle(found.node.parentElement || layer).lineHeight,
            direction: getComputedStyle(found.node.parentElement || layer).direction,
            whiteSpace: 'nowrap',
            color: 'transparent',
            userSelect: 'text',
            webkitUserSelect: 'text'
          });
          const indexedWord = Array.isArray(item.words) ? item.words[wordIndex] : null;
          const fallbackStart = item.start + ((item.end - item.start) * (wordIndex / Math.max(1, words.length)));
          const fallbackEnd = item.start + ((item.end - item.start) * ((wordIndex + 1) / Math.max(1, words.length)));
          const wordStart = Number.isFinite(Number(indexedWord?.start)) ? Number(indexedWord.start) : fallbackStart;
          const wordEnd = Number.isFinite(Number(indexedWord?.end)) ? Number(indexedWord.end) : fallbackEnd;
          mark.dataset.word = word;
          mark.dataset.page = String(item.page);
          mark.dataset.start = String(wordStart);
          mark.dataset.end = String(wordEnd);
          const jump = () => { rangeEnd = wordEnd; audio.currentTime = wordStart; if (audio.paused) audio.play().catch(() => {}); };
          mark.addEventListener('click', jump);
          mark.addEventListener('keydown', event => { if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); jump(); } });
          layer.append(mark);
          wordMarks.push({ element: mark, segment: item.index, start: wordStart, end: wordEnd });
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
    wordMarks.forEach(mark => {
      const isCurrent = time >= mark.start && time < mark.end;
      mark.element.classList.toggle('is-current', isCurrent);
      mark.element.classList.toggle('is-read', mark.end <= time);
    });
    let documentLow = 0, documentHigh = documentWordMarks.length - 1, documentFound = -1;
    while (documentLow <= documentHigh) {
      const middle = (documentLow + documentHigh) >> 1;
      if (documentWordMarks[middle].start <= time) { documentFound = middle; documentLow = middle + 1; }
      else documentHigh = middle - 1;
    }
    if (documentFound >= 0 && time >= documentWordMarks[documentFound].end) {
      const nextWord = documentWordMarks[documentFound + 1];
      if (nextWord && time >= nextWord.start) documentFound += 1;
      else documentFound = -1;
    }
    const currentDocumentWord = documentFound >= 0 ? documentWordMarks[documentFound].element : null;
    if (currentDocumentWord !== activeDocumentWord) {
      activeDocumentWord?.classList.remove('is-current');
      currentDocumentWord?.classList.add('is-current');
      activeDocumentWord = currentDocumentWord;
    }
    if (found !== activeSegment) {
      activeSegment = found;
      if (item) {
        caption.textContent = item.text;
        progressLabel.textContent = (activeTrack.granularity === 'word' ? 'جمله ' : 'بخش ') + fa(found + 1) + ' از ' + fa(timings.length);
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
    const article = articles[section];
    const page = Math.max(1, Number(article?.dataset.readerPage || track.timings[0]?.page || 1));
    const resolvedUrl = new URL(track.url, document.baseURI).href;
    const currentUrl = audio.currentSrc || audio.src || '';
    const sameSource = currentUrl === resolvedUrl || currentUrl === track.url;
    if (activeSection === section && sameSource) return;

    activeSection = section;
    activeTrack = track;
    activePage = page;
    clearMarks();

    if (!sameSource) {
      rangeEnd = null;
      audio.pause();
      audio.src = track.url;
      audio.load();
      caption.textContent = 'برای شروع، پخش را انتخاب کنید.';
      seek.value = '0';
    }
    decorateSpeechTargets(article || root);
    buildMarks(activePage);
    buildDocumentWordMarks(activePage);
    dock.hidden = false;
    status.textContent = activeTrack.alignmentQuality === 'phoneme'
      ? 'صوت از پیش آماده · هم‌ترازی واجی واژه‌ها'
      : 'صوت از پیش آماده · زمان‌بندی تقریبی واژه‌ها';
    const totalWordMarks = track.timings.reduce((count, item) => count + item.words.length, 0);
    progressLabel.textContent = track.granularity === 'word' ? fa(totalWordMarks) + ' نشانگر واژهٔ آماده' : fa(track.timings.length) + ' بخش آماده';
  };

  playButton.addEventListener('click', () => {
    if (!activeTrack) return;
    if (audio.paused) audio.play().catch(() => { status.textContent = 'پخش صوت ممکن نشد؛ دسترسی فایل را بررسی کنید.'; });
    else audio.pause();
  });
  audio.addEventListener('play', () => { playButton.innerHTML = '<i class="fa-solid fa-pause"></i>'; playButton.setAttribute('aria-label', 'مکث'); dock.classList.add('is-playing'); status.textContent = 'در حال همراهی با متن'; startFrameLoop(); });
  audio.addEventListener('pause', () => { playButton.innerHTML = '<i class="fa-solid fa-play"></i>'; playButton.setAttribute('aria-label', 'پخش'); dock.classList.remove('is-playing'); cancelAnimationFrame(raf); });
  audio.addEventListener('loadedmetadata', () => { durationLabel.textContent = formatTime(audio.duration); });
  audio.addEventListener('timeupdate', () => {
    if (!audio.seeking && rangeEnd !== null && audio.currentTime >= rangeEnd) {
      const stopAt = rangeEnd;
      rangeEnd = null;
      audio.pause();
      audio.currentTime = stopAt;
    }
    if (Number.isFinite(audio.duration) && audio.duration > 0) seek.value = String(Math.round((audio.currentTime / audio.duration) * 1000));
    currentLabel.textContent = formatTime(audio.currentTime);
    updateHighlight(audio.currentTime);
  });
  audio.addEventListener('seeking', () => updateHighlight(audio.currentTime));
  audio.addEventListener('ended', () => { rangeEnd = null; status.textContent = 'این بخش به پایان رسید'; caption.textContent = 'برای ادامه، بخش بعدی را انتخاب کنید.'; });
  audio.addEventListener('error', () => { status.textContent = 'بارگذاری صوت ناموفق بود؛ فایل یا دسترسی آن را بررسی کنید.'; });
  seek.addEventListener('input', () => { rangeEnd = null; if (Number.isFinite(audio.duration)) { audio.currentTime = (Number(seek.value) / 1000) * audio.duration; updateHighlight(audio.currentTime); } });
  $('[data-audio-speed]').addEventListener('change', event => { audio.playbackRate = Number(event.target.value) || 1; });
  $('[data-audio-volume]').addEventListener('input', event => { audio.volume = Math.max(0, Math.min(1, Number(event.target.value))); });
  $('[data-audio-collapse]').addEventListener('click', event => { const collapsed = dock.classList.toggle('is-collapsed'); event.currentTarget.setAttribute('aria-expanded', String(!collapsed)); });

  const playRange = (start, end, label) => {
    const from = Math.max(0, Number(start) || 0), to = Number(end);
    if (!Number.isFinite(to) || to <= from || !activeTrack) return false;
    rangeEnd = to; audio.currentTime = from;
    if (label) caption.textContent = String(label).slice(0, 180);
    audio.play().catch(() => { status.textContent = 'پخش صوت ممکن نشد؛ دسترسی فایل را بررسی کنید.'; });
    return true;
  };
  const tokens = value => String(value || '').trim().split(/\s+/u).map(normalize).filter(Boolean);
  const buildDocumentWordMarks = page => {
    documentWordMarks.forEach(mark => mark.element.classList.remove('is-current', 'is-read'));
    documentWordMarks = [];
    activeDocumentWord = null;
    if (!activeTrack) return;
    const elements = [...root.querySelectorAll('.reader-document-word')].filter(element => {
      const article = element.closest('.farast-reader-page-item');
      const elementPage = Number(element.dataset.page || article?.dataset.readerPage || 1);
      return elementPage === Number(page);
    });
    if (!elements.length) return;
    let cursor = 0;
    activeTrack.timings.filter(item => Number(item.page) === Number(page)).forEach(item => {
      const words = Array.isArray(item.words) && item.words.length
        ? item.words
        : String(item.text || '').trim().split(/\s+/u).filter(Boolean).map((text, index, all) => ({
            text,
            start: item.start + (item.end - item.start) * index / all.length,
            end: item.start + (item.end - item.start) * (index + 1) / all.length
          }));
      words.forEach(word => {
        const wanted = tokens(word.text)[0] || '';
        if (!wanted) return;
        let found = -1;
        for (let index = cursor; index < elements.length; index += 1) {
          if ((tokens(elements[index].textContent)[0] || '') === wanted) { found = index; break; }
        }
        if (found < 0) return;
        documentWordMarks.push({
          element: elements[found],
          start: Number(word.start),
          end: Number(word.end)
        });
        cursor = found + 1;
      });
    });
    updateHighlight(audio.currentTime || 0);
  };
  const findTextRange = (value, page = null, occurrence = 0) => {
    const wanted = tokens(value);
    if (!wanted.length || !activeTrack) return null;
    const indexedWords = [];
    for (const item of activeTrack.timings) {
      if (page !== null && Number(item.page) !== Number(page)) continue;
      const words = Array.isArray(item.words) && item.words.length
        ? item.words
        : String(item.text).trim().split(/\s+/u).map((text, index, all) => ({
            text,
            start: item.start + (item.end - item.start) * index / all.length,
            end: item.start + (item.end - item.start) * (index + 1) / all.length
          }));
      words.forEach(word => {
        const token = tokens(word.text)[0] || '';
        if (!token) return;
        indexedWords.push({
          token,
          start: Number(word.start),
          end: Number(word.end),
          page: item.page
        });
      });
    }
    let matchedOccurrences = 0;
    for (let i = 0; i <= indexedWords.length - wanted.length; i++) {
      if (wanted.every((word, offset) => indexedWords[i + offset].token === word)) {
        if (matchedOccurrences++ < Math.max(0, Number(occurrence) || 0)) continue;
        return {
          start: indexedWords[i].start,
          end: indexedWords[i + wanted.length - 1].end,
          page: indexedWords[i].page
        };
      }
    }
    return null;
  };

  const sequenceOccurrenceBefore = (prefixText, selectedText) => {
    const prefix = tokens(prefixText);
    const wanted = tokens(selectedText);
    if (!wanted.length || prefix.length < wanted.length) return 0;
    let occurrences = 0;
    for (let index = 0; index <= prefix.length - wanted.length; index += 1) {
      if (wanted.every((word, offset) => prefix[index + offset] === word)) occurrences += 1;
    }
    return occurrences;
  };
  const targetOccurrence = (target, selector, value) => {
    const scope = target.closest('[data-pdf-cont-page]') || target.closest('.farast-reader-page-item') || root;
    const wanted = tokens(value).join(' ');
    const matches = [...scope.querySelectorAll(selector)].filter(item =>
      tokens(item.dataset.readerSpeechText || item.textContent).join(' ') === wanted
    );
    return Math.max(0, matches.indexOf(target));
  };
  const playSelection = selection => {
    if (!selection || selection.isCollapsed || !selection.rangeCount) return false;
    const selectedText = selection.toString().trim();
    if (!selectedText) return false;
    const selectionRange = selection.getRangeAt(0);
    const startNode = selectionRange.startContainer;
    const endNode = selectionRange.endContainer;
    const startElement = startNode.nodeType === Node.ELEMENT_NODE ? startNode : startNode.parentElement;
    const endElement = endNode.nodeType === Node.ELEMENT_NODE ? endNode : endNode.parentElement;
    if (!startElement || !endElement || !root.contains(startElement) || !root.contains(endElement)) return false;
    const startPage = startElement.closest('[data-pdf-cont-page],.farast-reader-page-item');
    const endPage = endElement.closest('[data-pdf-cont-page],.farast-reader-page-item');
    const samePage = Boolean(startPage && startPage === endPage);
    const pageScope = samePage ? startPage : root;
    const page = samePage
      ? Number(startPage.dataset.pdfContPage || startPage.dataset.readerPage || (Number(startPage.dataset.index) + 1) || root.querySelector('#reader-page-number')?.value || 1)
      : null;
    let prefixText = '';
    try {
      const prefixRange = document.createRange();
      prefixRange.selectNodeContents(pageScope);
      prefixRange.setEnd(startNode, selectionRange.startOffset);
      prefixText = prefixRange.toString();
    } catch (_) { prefixText = ''; }
    const occurrence = sequenceOccurrenceBefore(prefixText, selectedText);
    const range = findTextRange(selectedText, page, occurrence);
    if (!range) return false;
    suppressNextClick = true;
    window.clearTimeout(selectionSuppressTimer);
    selectionSuppressTimer = window.setTimeout(() => { suppressNextClick = false; }, 500);
    playRange(range.start, range.end, 'در حال خواندن عبارت انتخاب‌شده');
    return true;
  };
  const speechTargetKinds = [
    ['.reader-document-sentence', 'sentence', 'جمله'],
    ['.reader-document-paragraph', 'paragraph', 'پاراگراف'],
    ['.reader-document-heading', 'heading', 'عنوان'],
    ['.reader-document-cell', 'cell', 'سلول'],
    ['.reader-document-row', 'row', 'ردیف']
  ];
  const speechTargetSelector = Object.fromEntries(speechTargetKinds.map(([selector, kind]) => [kind, selector]));
  const decorateSpeechTargets = (scope = root) => {
    if (!scope?.querySelectorAll) return;
    speechTargetKinds.forEach(([selector, kind, label]) => {
      scope.querySelectorAll(selector).forEach(target => {
        if (target.querySelector('[data-reader-speech-control="' + kind + '"]')) return;
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'reader-speech-target-control';
        button.dataset.readerSpeechControl = kind;
        button.setAttribute('aria-label', 'خواندن ' + label);
        button.title = 'خواندن ' + label;
        button.innerHTML = '<i class="fa-solid fa-volume-high" aria-hidden="true"></i>';
        if (kind === 'row') {
          const cell = target.querySelector('th,td');
          (cell || target).append(button);
        } else {
          target.append(button);
        }
      });
    });
  };
  const playSemanticTarget = (target, kind) => {
    const selector = speechTargetSelector[kind];
    if (!target || !selector) return false;
    const page = Number(target.closest('[data-pdf-cont-page]')?.dataset.pdfContPage
      || target.closest('.farast-reader-page-item')?.dataset.readerPage
      || root.querySelector('#reader-page-number')?.value || 1);
    const text = target.dataset.readerSpeechText || target.textContent;
    const range = findTextRange(text, page, targetOccurrence(target, selector, text));
    if (!range) {
      status.textContent = 'برای این محدوده، زمان‌بندی صوتی آماده نیست.';
      return false;
    }
    return playRange(range.start, range.end, 'در حال خواندن ' + ({
      sentence: 'جمله', paragraph: 'پاراگراف', heading: 'عنوان', cell: 'سلول', row: 'ردیف'
    }[kind] || 'محدوده'));
  };

  const playPage = article => {
    if (!article) return false;
    const section = Math.max(0, Number(article.dataset.index) || 0);
    selectSection(section);
    const page = Number(article.dataset.readerPage || article.dataset.index && Number(article.dataset.index) + 1 || activePage || 1);
    const pageItems = activeTrack?.timings.filter(item => Number(item.page) === page) || [];
    if (!pageItems.length) return false;
    return playRange(pageItems[0].start, pageItems[pageItems.length - 1].end, 'در حال خواندن صفحهٔ ' + fa(page));
  };

  const rangeForDocumentWord = element => {
    const article = element.closest('.farast-reader-page-item');
    const page = Number(element.dataset.page || article?.dataset.readerPage || root.querySelector('#reader-page-number')?.value || 1);
    const token = tokens(element.textContent)[0] || '';
    const scope = element.closest('[data-pdf-cont-page]') || article || root;
    const matches = [...scope.querySelectorAll('.reader-document-word')]
      .filter(item => (tokens(item.textContent)[0] || '') === token);
    return findTextRange(element.textContent, page, Math.max(0, matches.indexOf(element)));
  };

  root.addEventListener('click', event => {
    if (suppressNextClick) { suppressNextClick = false; window.clearTimeout(selectionSuppressTimer); return; }
    if (event.target.closest('.reader-audio-word')) return;
    const speechControl = event.target.closest('[data-reader-speech-control]');
    if (speechControl) {
      event.preventDefault();
      const kind = speechControl.dataset.readerSpeechControl;
      const selector = speechTargetSelector[kind];
      playSemanticTarget(selector ? speechControl.closest(selector) : null, kind);
      return;
    }
    const word = event.target.closest('.reader-document-word');
    if (word) {
      const range = rangeForDocumentWord(word);
      if (range) { event.preventDefault(); playRange(range.start, range.end, 'در حال خواندن واژهٔ ' + word.textContent); return; }
    }
    const sentence = event.target.closest('.reader-document-sentence');
    const paragraph = event.target.closest('.reader-document-paragraph,.reader-document-heading,.reader-document-cell,.reader-document-row');
    const target = sentence || paragraph;
    if (target) {
      const page = Number(target.closest('[data-pdf-cont-page]')?.dataset.pdfContPage || target.closest('.farast-reader-page-item')?.dataset.readerPage || root.querySelector('#reader-page-number')?.value || 1);
      const text = target.dataset.readerSpeechText || target.textContent;
      const selector = target.matches('.reader-document-sentence') ? '.reader-document-sentence'
        : target.matches('.reader-document-cell') ? '.reader-document-cell'
          : target.matches('.reader-document-row') ? '.reader-document-row'
            : target.matches('.reader-document-heading') ? '.reader-document-heading'
              : '.reader-document-paragraph';
      const occurrence = targetOccurrence(target, selector, text);
      const range = findTextRange(text, page, occurrence);
      if (range) { event.preventDefault(); playRange(range.start, range.end, sentence ? 'در حال خواندن جملهٔ انتخاب‌شده' : 'در حال خواندن محدودهٔ انتخاب‌شده'); return; }
    }
    const pdfLayer = event.target.closest('.farast-pdf-text-layer,.farast-pdf-cont-text');
    if (pdfLayer) {
      const caret = document.caretRangeFromPoint?.(event.clientX, event.clientY);
      const position = document.caretPositionFromPoint?.(event.clientX, event.clientY);
      const node = caret?.startContainer || position?.offsetNode;
      if (node?.nodeType === Node.TEXT_NODE) {
        const offset = Number(caret?.startOffset ?? position?.offset ?? 0);
        let before = '', after = '';
        try {
          const prefixRange = document.createRange();
          prefixRange.selectNodeContents(pdfLayer);
          prefixRange.setEnd(node, offset);
          before = prefixRange.toString();
          const suffixRange = document.createRange();
          suffixRange.selectNodeContents(pdfLayer);
          suffixRange.setStart(node, offset);
          after = suffixRange.toString();
        } catch (_) {
          const raw = node.nodeValue || '';
          before = raw.slice(0, offset);
          after = raw.slice(offset);
        }
        const left = before.search(/[^.!؟۔؛]*$/u);
        const sentenceStart = left < 0 ? 0 : left;
        const right = after.match(/^[^.!؟۔؛]*(?:[.!؟۔؛]|$)/u);
        const sentenceText = (before.slice(sentenceStart) + (right?.[0] || '')).trim();
        const page = Number(pdfLayer.closest('[data-pdf-cont-page]')?.dataset.pdfContPage || root.querySelector('#reader-page-number')?.value || 1);
        const occurrence = sequenceOccurrenceBefore(before.slice(0, sentenceStart), sentenceText);
        const range = findTextRange(sentenceText, page, occurrence);
        if (range) { event.preventDefault(); playRange(range.start, range.end, 'در حال خواندن جملهٔ انتخاب‌شده'); return; }
      }
    }
    const article = event.target.closest('.farast-reader-page-item');
    if (article && !event.target.closest('button,input,select,a,.farast-audio-dock')) {
      const continuousPage = event.target.closest('[data-pdf-cont-page]');
      if (continuousPage) {
        const pageNumber = Number(continuousPage.dataset.pdfContPage || 0);
        const pageItems = activeTrack?.timings.filter(item => Number(item.page) === pageNumber) || [];
        if (pageItems.length) {
          event.preventDefault();
          playRange(pageItems[0].start, pageItems[pageItems.length - 1].end, 'در حال خواندن صفحهٔ ' + fa(pageNumber));
        }
        return;
      }
      playPage(article);
    }
  }, true);
  root.addEventListener('keydown', event => {
    if (event.key !== 'Enter' && event.key !== ' ') return;
    if (playSelection(window.getSelection())) { event.preventDefault(); return; }
    const word = event.target.closest('.reader-document-word');
    if (word) {
      const range = rangeForDocumentWord(word);
      if (range) { event.preventDefault(); playRange(range.start, range.end, 'در حال خواندن واژهٔ ' + word.textContent); return; }
    }
    const target = event.target.closest('.reader-document-sentence,.reader-document-paragraph,.reader-document-heading,.reader-document-cell,.reader-document-row');
    if (target) {
      const page = Number(target.closest('[data-pdf-cont-page]')?.dataset.pdfContPage || target.closest('.farast-reader-page-item')?.dataset.readerPage || root.querySelector('#reader-page-number')?.value || 1);
      const text = target.dataset.readerSpeechText || target.textContent;
      const selector = target.matches('.reader-document-sentence') ? '.reader-document-sentence'
        : target.matches('.reader-document-cell') ? '.reader-document-cell'
          : target.matches('.reader-document-row') ? '.reader-document-row'
            : target.matches('.reader-document-heading') ? '.reader-document-heading'
              : '.reader-document-paragraph';
      const range = findTextRange(text, page, targetOccurrence(target, selector, text));
      if (range) { event.preventDefault(); playRange(range.start, range.end, 'در حال خواندن بخش انتخاب‌شده'); return; }
    }
    const pageArticle = event.target.closest('.farast-reader-page-item[data-reader-audio-page-target="1"]');
    if (pageArticle && event.target === pageArticle) { event.preventDefault(); playPage(pageArticle); }
  });
  root.addEventListener('pointerup', () => {
    playSelection(window.getSelection());
  });
  function startFrameLoop() {
    cancelAnimationFrame(raf);
    const tick = () => {
      if (audio.paused) return;
      if (rangeEnd !== null && audio.currentTime >= rangeEnd) {
        const stopAt = rangeEnd;
        rangeEnd = null;
        audio.pause();
        audio.currentTime = stopAt;
        updateHighlight(stopAt);
        return;
      }
      updateHighlight(audio.currentTime);
      raf = requestAnimationFrame(tick);
    };
    raf = requestAnimationFrame(tick);
  }

  root.addEventListener('farast:reader-section-change', event => selectSection(Number(event.detail?.section ?? 0)));
  root.addEventListener('farast:reader-page-change', event => {
    const page = Number(event.detail?.page || 1);
    activePage = page;
    buildMarks(page);
    buildDocumentWordMarks(page);
  });
  root.addEventListener('farast:reader-page-loaded', event => {
    const page = Number(event.detail?.page || 0);
    if (activeTrack && page === activePage) buildDocumentWordMarks(page);
  });
  const observer = new MutationObserver(mutations => {
    const changed = mutations.some(mutation => [...mutation.addedNodes].some(node =>
      node.nodeType === Node.ELEMENT_NODE && (
        node.matches?.('.reader-document-word,.reader-document-sentence,.reader-document-paragraph,.reader-document-heading,.reader-document-cell,.reader-document-row,.textLayer span:not(.reader-audio-word)') ||
        node.querySelector?.('.reader-document-word,.reader-document-sentence,.reader-document-paragraph,.reader-document-heading,.reader-document-cell,.reader-document-row,.textLayer span:not(.reader-audio-word)')
      )
    ));
    if (changed && activeTrack) requestAnimationFrame(() => {
      decorateSpeechTargets(root);
      buildMarks(activePage);
      buildDocumentWordMarks(activePage);
    });
  });
  observer.observe(root, { childList: true, subtree: true });

  const initial = root.querySelector('.farast-reader-page-item.is-active');
  selectSection(Number(initial?.dataset.index || 0));
  window.FarastAudioReader = {
    selectSection,
    playRange,
    pause() { rangeEnd = null; audio.pause(); },
    setPage(page) { activePage = Number(page) || 1; buildMarks(activePage); buildDocumentWordMarks(activePage); },
    destroy() { audio.pause(); observer.disconnect(); cancelAnimationFrame(raf); clearMarks(); }
  };
})();