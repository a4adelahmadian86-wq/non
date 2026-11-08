(() => {
  'use strict';

  const root = document.querySelector('[data-reader-root]');
  if (!root) return;

  const dock = root.querySelector('[data-reader-audio]');
  const url = root.dataset.readerAudioUrl || '';
  const timingNode = root.querySelector('#reader-audio-timing');
  const playButton = dock?.querySelector('[data-audio-play]');
  const seek = dock?.querySelector('[data-audio-seek]');
  const currentLabel = dock?.querySelector('[data-audio-current]');
  const durationLabel = dock?.querySelector('[data-audio-duration]');
  const speed = dock?.querySelector('[data-audio-speed]');
  const status = dock?.querySelector('[data-audio-status]');

  if (!dock || !url || !playButton || !seek || !timingNode) {
    if (dock) dock.hidden = true;
    return;
  }

  let timings = [];
  try {
    const parsed = JSON.parse(timingNode.textContent || '[]');
    timings = Array.isArray(parsed) ? parsed.filter(item =>
      item && Number.isFinite(Number(item.start)) && Number.isFinite(Number(item.end)) && Number(item.end) > Number(item.start)
    ).map(item => ({
      ...item,
      start: Number(item.start),
      end: Number(item.end),
      page: Math.max(1, Number(item.page) || 1),
      words: Array.isArray(item.words) ? item.words.filter(word => word && typeof word.text === 'string'
        && Number.isFinite(Number(word.start)) && Number.isFinite(Number(word.end))
        && Number(word.start) >= 0 && Number(word.end) > Number(word.start))
        .map(word => ({ ...word, start: Number(word.start), end: Number(word.end) })) : []
    })).sort((a, b) => a.start - b.start) : [];
  } catch (_) {
    timings = [];
  }

  if (!timings.length) {
    dock.hidden = true;
    return;
  }

  const audio = new Audio(url);
  audio.preload = 'metadata';
  audio.crossOrigin = 'same-origin';
  let activeSegmentIndex = -1;
  let activePage = 0;
  let seeking = false;
  let rangeEnd = null;

  const faNumber = (value) => String(value).replace(/\d/g, digit => '۰۱۲۳۴۵۶۷۸۹'[Number(digit)]);
  const formatTime = (seconds) => {
    const safe = Math.max(0, Math.floor(Number(seconds) || 0));
    const minutes = Math.floor(safe / 60);
    const remaining = safe % 60;
    return faNumber(String(minutes).padStart(2, '0') + ':' + String(remaining).padStart(2, '0'));
  };
  const normalize = (value) => String(value || '').replace(/\s+/g, ' ').trim().toLocaleLowerCase();
  const normalizeToken = value => normalize(value)
    .replace(/[يى]/g, 'ی')
    .replace(/ك/g, 'ک')
    .replace(/[\u064B-\u065F\u0670\u200C\u200F\u202A-\u202E]/g, '')
    .replace(/[^\p{L}\p{N}]+/gu, '');
  const tokens = value => String(value || '').trim().split(/\s+/u).map(normalizeToken).filter(Boolean);
  const wordTimingsByPage = new Map();
  timings.forEach(segment => {
    const page = Number(segment.page) || 1;
    const words = wordTimingsByPage.get(page) || [];
    segment.words.forEach(word => {
      const token = normalizeToken(word.text);
      if (token) words.push({ token, start: word.start, end: word.end, page });
    });
    wordTimingsByPage.set(page, words);
  });
  wordTimingsByPage.forEach(words => words.sort((a, b) => a.start - b.start));
  const alignmentQuality = timings.length > 0 && timings.every(item =>
    item.words.length > 0 && item.alignment_quality === 'phoneme'
  ) ? 'phoneme' : 'estimated';

  const currentArticle = () => root.querySelector('.farast-reader-page-item.is-active');
  const clearHighlights = () => {
    root.querySelectorAll('.reader-document-line.is-audio-active,.reader-document-word.is-audio-active').forEach(node => node.classList.remove('is-audio-active'));
  };

  const jumpToPage = (page) => {
    const number = Number(page) || 0;
    if (number < 1) return;
    const input = root.querySelector('#reader-page-number');
    if (!input || Number(input.value) === number) return;
    input.value = String(number);
    input.dispatchEvent(new Event('change', { bubbles: true }));
  };

  const highlightSegment = (segment) => {
    clearHighlights();
    const article = currentArticle();
    if (!article || !segment) return;
    const targetPage = Number(segment.page) || 0;
    if (targetPage > 0 && Number(article.dataset.readerPage || 0) !== targetPage) {
      jumpToPage(targetPage);
      return;
    }

    const layer = article.querySelector('[data-reader-text-layer]');
    if (!layer) return;
    const segmentText = normalize(segment.text);
    if (!segmentText) return;

    const lines = Array.from(layer.querySelectorAll('.reader-document-line'));
    const matching = lines.filter(line => {
      const lineText = normalize(line.dataset.readerAudioText || line.textContent);
      return lineText && (segmentText.includes(lineText) || lineText.includes(segmentText.slice(0, 90)));
    });
    matching.forEach(line => line.classList.add('is-audio-active'));
  };

  const updatePlayButton = () => {
    playButton.innerHTML = audio.paused
      ? '<i class="fa-solid fa-play"></i>'
      : '<i class="fa-solid fa-pause"></i>';
    playButton.setAttribute('aria-label', audio.paused ? 'پخش نسخه صوتی' : 'توقف موقت نسخه صوتی');
  };

  const updateTime = () => {
    if (currentLabel) currentLabel.textContent = formatTime(audio.currentTime);
    if (durationLabel) durationLabel.textContent = formatTime(audio.duration);
    if (!seeking && Number.isFinite(audio.duration) && audio.duration > 0) {
      seek.value = String(Math.round((audio.currentTime / audio.duration) * 1000));
    }
  };

  const highlightWordAtTime = (segment, time) => {
    const page = Number(segment?.page) || 1;
    const article = currentArticle();
    if (!article || Number(article.dataset.readerPage || 0) !== page) return;
    article.querySelectorAll('.reader-document-word.is-audio-active').forEach(node => node.classList.remove('is-audio-active'));
    const words = wordTimingsByPage.get(page) || [];
    const index = words.findIndex(word => time >= word.start && time < word.end);
    if (index < 0) return;
    article.querySelector('.reader-document-word[data-reader-word-index="' + index + '"]')?.classList.add('is-audio-active');
  };

  const updateSegment = () => {
    const time = audio.currentTime;
    const index = timings.findIndex(item => time >= Number(item.start) && time < Number(item.end));
    if (index < 0) {
      if (activeSegmentIndex !== -1) clearHighlights();
      activeSegmentIndex = -1;
      return;
    }
    const segment = timings[index];
    if (index !== activeSegmentIndex) {
      activeSegmentIndex = index;
      activePage = Number(segment.page) || activePage;
      highlightSegment(segment);
    }
    highlightWordAtTime(segment, time);
  };

  playButton.addEventListener('click', async () => {
    try {
      if (audio.paused) await audio.play();
      else audio.pause();
    } catch (_) {
      if (status) status.textContent = 'پخش صوت در این مرورگر ممکن نشد.';
    }
  });

  seek.addEventListener('input', () => {
    seeking = true;
    rangeEnd = null;
    if (Number.isFinite(audio.duration) && audio.duration > 0) {
      audio.currentTime = (Number(seek.value) / 1000) * audio.duration;
      updateSegment();
    }
    updateTime();
  });
  seek.addEventListener('change', () => { seeking = false; });

  speed?.addEventListener('change', () => {
    audio.playbackRate = Math.max(0.5, Math.min(2, Number(speed.value) || 1));
  });

  audio.addEventListener('loadedmetadata', () => {
    if (durationLabel) durationLabel.textContent = formatTime(audio.duration);
    if (status) status.textContent = alignmentQuality === 'phoneme'
      ? 'صوت آماده · هم‌ترازی واجی واژه‌ها'
      : 'صوت آماده · زمان‌بندی تقریبی واژه‌ها';
  });
  audio.addEventListener('timeupdate', () => {
    updateTime();
    updateSegment();
    if (rangeEnd !== null && audio.currentTime >= rangeEnd) {
      const stopAt = rangeEnd;
      rangeEnd = null;
      audio.pause();
      audio.currentTime = stopAt;
      if (status) status.textContent = 'پایان محدوده انتخاب‌شده';
    }
  });
  let rangeStopFrame = 0;
  const startRangeStopLoop = () => {
    if (rangeStopFrame) cancelAnimationFrame(rangeStopFrame);
    const tick = () => {
      if (audio.paused) return;
      if (rangeEnd !== null && audio.currentTime >= rangeEnd) {
        const stopAt = rangeEnd;
        rangeEnd = null;
        audio.pause();
        audio.currentTime = stopAt;
        if (status) status.textContent = 'پایان محدوده انتخاب‌شده';
        return;
      }
      rangeStopFrame = requestAnimationFrame(tick);
    };
    rangeStopFrame = requestAnimationFrame(tick);
  };
  audio.addEventListener('play', () => {
    updatePlayButton();
    if (rangeEnd !== null) startRangeStopLoop();
  });
  audio.addEventListener('pause', () => {
    updatePlayButton();
    if (rangeStopFrame) cancelAnimationFrame(rangeStopFrame);
    rangeStopFrame = 0;
  });
  audio.addEventListener('ended', () => {
    rangeEnd = null;
    updatePlayButton();
    clearHighlights();
    if (status) status.textContent = 'پایان نسخه صوتی';
  });
  audio.addEventListener('error', () => {
    if (status) status.textContent = 'نسخه صوتی موقتاً در دسترس نیست.';
  });

  root.addEventListener('farast:reader-page-loaded', (event) => {
    const page = Number(event.detail?.page || 0);
    if (activeSegmentIndex >= 0 && page === activePage) {
      const segment = timings[activeSegmentIndex];
      highlightSegment(segment);
      highlightWordAtTime(segment, audio.currentTime);
    }
  });

  const playRange = (start, end, label) => {
    const from = Math.max(0, Number(start) || 0);
    const to = Number(end);
    if (!Number.isFinite(to) || to <= from) return false;
    rangeEnd = to;
    audio.currentTime = from;
    if (label && status) status.textContent = label;
    audio.play().then(startRangeStopLoop).catch(() => {
      if (status) status.textContent = 'پخش محدودهٔ انتخاب‌شده ممکن نشد.';
    });
    return true;
  };

  const pageFor = element => Number(
    element?.dataset?.page
    || element?.closest('.farast-reader-page-item')?.dataset.readerPage
    || root.querySelector('#reader-page-number')?.value
    || activePage
    || 1
  );
  const findTextRange = (value, page, wordIndex = null) => {
    const wanted = tokens(value);
    if (!wanted.length) return null;
    const indexedWords = wordTimingsByPage.get(Math.max(1, Number(page) || 1)) || [];
    const preferredIndex = Number(wordIndex);
    if (wanted.length === 1 && Number.isInteger(preferredIndex)
      && preferredIndex >= 0 && preferredIndex < indexedWords.length
      && indexedWords[preferredIndex].token === wanted[0]) {
      const candidate = indexedWords[preferredIndex];
      return { start: candidate.start, end: candidate.end, page: candidate.page };
    }
    for (let index = 0; index <= indexedWords.length - wanted.length; index++) {
      if (wanted.every((token, offset) => indexedWords[index + offset].token === token)) {
        return {
          start: indexedWords[index].start,
          end: indexedWords[index + wanted.length - 1].end,
          page: indexedWords[index].page
        };
      }
    }
    return null;
  };

  const activateTextTarget = (target, event) => {
    const isWord = target.matches('.reader-document-word');
    const page = pageFor(target);
    const value = isWord ? (target.dataset.word || target.textContent) : (target.dataset.readerSpeechText || target.textContent);
    const range = findTextRange(value, page, isWord ? target.dataset.readerWordIndex : null);
    if (!range) return false;
    event?.preventDefault();
    const label = isWord ? 'واژه' : target.matches('.reader-document-sentence') ? 'جمله'
      : target.matches('.reader-document-heading') ? 'عنوان'
      : target.matches('.reader-document-cell') ? 'سلول'
      : target.matches('.reader-document-row') ? 'ردیف' : 'بخش';
    return playRange(range.start, range.end, 'در حال خواندن ' + label + ' انتخاب‌شده');
  };

  let suppressNextClick = false;
  let selectionSuppressTimer = 0;
  root.addEventListener('click', event => {
    if (suppressNextClick) {
      suppressNextClick = false;
      window.clearTimeout(selectionSuppressTimer);
      return;
    }
    const word = event.target.closest('.reader-document-word');
    if (word) { activateTextTarget(word, event); return; }
    const target = event.target.closest('.reader-document-sentence,.reader-document-paragraph,.reader-document-heading,.reader-document-cell,.reader-document-row');
    if (target) { activateTextTarget(target, event); return; }
    const article = event.target.closest('.farast-reader-page-item');
    if (article && !event.target.closest('button,input,select,a,iframe,.farast-reader-audio')) {
      event.preventDefault();
      playPage(pageFor(article));
    }
  }, true);

  root.addEventListener('keydown', event => {
    if (event.key !== 'Enter' && event.key !== ' ') return;
    const word = event.target.closest('.reader-document-word');
    const target = word || event.target.closest('.reader-document-sentence,.reader-document-paragraph,.reader-document-heading,.reader-document-cell,.reader-document-row');
    if (target) { activateTextTarget(target, event); return; }
    const article = event.target.closest('.farast-reader-page-item');
    if (article) { event.preventDefault(); playPage(pageFor(article)); }
  });

  root.addEventListener('pointerup', () => {
    const selection = window.getSelection();
    if (!selection || selection.isCollapsed) return;
    const anchor = selection.anchorNode?.parentElement;
    if (!anchor || !root.contains(anchor)) return;
    const range = findTextRange(selection.toString(), pageFor(anchor));
    if (!range) return;
    suppressNextClick = true;
    window.clearTimeout(selectionSuppressTimer);
    selectionSuppressTimer = window.setTimeout(() => { suppressNextClick = false; }, 500);
    playRange(range.start, range.end, 'در حال خواندن عبارت انتخاب‌شده');
  });

  const playPage = page => {
    const pageNumber = Math.max(1, Number(page) || 1);
    const pageSegments = timings.filter(item => Number(item.page) === pageNumber
      && Number.isFinite(Number(item.start)) && Number.isFinite(Number(item.end))
      && Number(item.end) > Number(item.start));
    if (!pageSegments.length) return false;
    const start = Math.min(...pageSegments.map(item => Number(item.start)));
    const end = Math.max(...pageSegments.map(item => Number(item.end)));
    return playRange(start, end, 'در حال خواندن صفحهٔ ' + faNumber(String(pageNumber)));
  };

  window.FarastDocumentAudioReader = {
    audio,
    playRange,
    playPage,
    pause() { rangeEnd = null; audio.pause(); },
    get available() { return timings.length > 0; },
    get currentTime() { return audio.currentTime; }
  };


  const readerAudioStyle = document.createElement('style');
  readerAudioStyle.textContent = '.reader-document-word{cursor:pointer;border-radius:2px}.reader-document-word.is-audio-active{background:rgba(59,130,246,.16);outline:1px solid rgba(59,130,246,.45)}.reader-document-word:focus-visible,.reader-document-sentence:focus-visible,.reader-document-paragraph:focus-visible,.reader-document-heading:focus-visible,.reader-document-cell:focus-visible,.reader-document-row:focus-visible{outline:2px solid #3b82f6;outline-offset:2px}';
  document.head.appendChild(readerAudioStyle);

  dock.hidden = false;
  updatePlayButton();
})();
