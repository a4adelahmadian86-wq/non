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
    ) : [];
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

  const currentArticle = () => root.querySelector('.farast-reader-page-item.is-active');
  const clearHighlights = () => {
    root.querySelectorAll('.reader-document-line.is-audio-active').forEach(node => node.classList.remove('is-audio-active'));
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

  const updateSegment = () => {
    const time = audio.currentTime;
    const index = timings.findIndex(item => time >= Number(item.start) && time < Number(item.end));
    if (index < 0) {
      if (activeSegmentIndex !== -1) clearHighlights();
      activeSegmentIndex = -1;
      return;
    }
    if (index === activeSegmentIndex) return;
    activeSegmentIndex = index;
    const segment = timings[index];
    activePage = Number(segment.page) || activePage;
    highlightSegment(segment);
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
    if (status) status.textContent = 'صوت و متن همگام هستند';
  });
  audio.addEventListener('timeupdate', () => {
    updateTime();
    updateSegment();
    if (rangeEnd !== null && audio.currentTime >= rangeEnd) {
      rangeEnd = null;
      audio.pause();
      if (status) status.textContent = 'پایان محدوده انتخاب‌شده';
    }
  });
  audio.addEventListener('play', updatePlayButton);
  audio.addEventListener('pause', updatePlayButton);
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
      highlightSegment(timings[activeSegmentIndex]);
    }
  });

  const playRange = (start, end, label) => {
    const from = Math.max(0, Number(start) || 0);
    const to = Number(end);
    if (!Number.isFinite(to) || to <= from) return false;
    rangeEnd = to;
    audio.currentTime = from;
    if (label && status) status.textContent = label;
    audio.play().catch(() => {
      if (status) status.textContent = 'پخش محدودهٔ انتخاب‌شده ممکن نشد.';
    });
    return true;
  };

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
    playRange,
    playPage,
    pause() { rangeEnd = null; audio.pause(); },
    get available() { return timings.length > 0; },
    get currentTime() { return audio.currentTime; }
  };

  dock.hidden = false;
  updatePlayButton();
})();
