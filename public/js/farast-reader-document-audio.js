(() => {
  'use strict';

  const root = document.querySelector('[data-reader-root]');
  if (!root) return;

  const dock = root.querySelector('[data-reader-audio]');
  const url = root.dataset.readerAudioUrl || '';
  const timingNode = root.querySelector('#reader-audio-timing');
  const pendingStatus = root.querySelector('[data-reader-audio-pending]');
  const pollUrl = root.dataset.readerAudioPollUrl || '';
  const audioStatus = root.dataset.readerAudioStatus || '';

  if (pendingStatus && !url && ['unavailable', 'failed'].includes(audioStatus)) {
    pendingStatus.hidden = false;
    pendingStatus.textContent = audioStatus === 'failed'
      ? 'آماده‌سازی نسخه صوتی با خطا روبه‌رو شد؛ متن سند همچنان در دسترس است.'
      : 'سرویس تولید صوت برای این محیط تنظیم نشده است؛ متن سند همچنان در دسترس است.';
  }

  if (pendingStatus && pollUrl && ['pending', 'upgrading'].includes(audioStatus)) {
    pendingStatus.hidden = false;
    pendingStatus.textContent = audioStatus === 'upgrading'
      ? 'نسخهٔ دقیق‌تر صوت در حال آماده‌سازی است؛ نسخهٔ فعلی همچنان قابل پخش است.'
      : 'صدای کامل سند در حال آماده‌سازی است؛ پس از تکمیل، خوانشگر خودکار به‌روزرسانی می‌شود.';

    const initialDelay = 5000;
    const maximumDelay = 60000;
    let retryDelay = initialDelay;
    let pollTimer = 0;
    let inFlight = false;
    let stopped = false;
    let paused = document.visibilityState === 'hidden';
    let controller = null;

    const clearPollTimer = () => {
      if (!pollTimer) return;
      window.clearTimeout(pollTimer);
      pollTimer = 0;
    };
    const stopPolling = () => {
      stopped = true;
      clearPollTimer();
      controller?.abort();
      controller = null;
      document.removeEventListener('visibilitychange', onVisibilityChange);
      window.removeEventListener('pagehide', onPageHide);
      window.removeEventListener('pageshow', onPageShow);
    };
    const schedulePoll = (delay) => {
      if (stopped || paused || !root.isConnected) return;
      clearPollTimer();
      pollTimer = window.setTimeout(() => {
        pollTimer = 0;
        pollAudioReadiness();
      }, Math.max(0, delay));
    };
    const retryAfterDelay = (response) => {
      const value = response.headers?.get?.('Retry-After');
      if (!value) return null;
      const seconds = Number(value);
      if (Number.isFinite(seconds) && seconds >= 0) {
        return Math.min(maximumDelay * 5, seconds * 1000);
      }
      const date = Date.parse(value);
      return Number.isFinite(date)
        ? Math.min(maximumDelay * 5, Math.max(0, date - Date.now()))
        : null;
    };
    const finishWithMessage = (message) => {
      stopPolling();
      pendingStatus.textContent = message;
    };
    const onVisibilityChange = () => {
      if (document.visibilityState === 'hidden') {
        paused = true;
        clearPollTimer();
        controller?.abort();
        return;
      }
      if (paused && !stopped) {
        paused = false;
        schedulePoll(0);
      }
    };
    const onPageHide = () => {
      paused = true;
      clearPollTimer();
      controller?.abort();
    };
    const onPageShow = () => {
      if (!stopped && document.visibilityState !== 'hidden') {
        paused = false;
        schedulePoll(0);
      }
    };
    const pollAudioReadiness = async () => {
      if (stopped || paused || inFlight) return;
      if (!root.isConnected) {
        stopPolling();
        return;
      }

      inFlight = true;
      controller = new AbortController();
      let nextDelay = retryDelay;
      try {
        const response = await fetch(pollUrl, {
          method: 'HEAD',
          credentials: 'same-origin',
          cache: 'no-store',
          headers: { Accept: 'audio/wav' },
          signal: controller.signal
        });
        if ([200, 206].includes(response.status)) {
          stopPolling();
          pendingStatus.textContent = 'صوت آماده شد؛ در حال بارگذاری زمان‌بندی دقیق…';
          window.location.reload();
          return;
        }
        if (response.status === 409) {
          finishWithMessage('آماده‌سازی نسخه صوتی با خطا روبه‌رو شد؛ متن سند همچنان در دسترس است.');
          return;
        }
        if (response.status === 410) {
          finishWithMessage('سرویس تولید صوت برای این محیط تنظیم نشده است؛ متن سند همچنان در دسترس است.');
          return;
        }
        if (response.status === 404) {
          finishWithMessage('وضعیت آماده‌سازی صوت قابل تشخیص نیست؛ برای بررسی دوباره صفحه را بارگذاری کنید.');
          return;
        }
        if ([401, 403].includes(response.status)) {
          finishWithMessage('دسترسی صوت تغییر کرده است؛ صفحه را دوباره بارگذاری کنید.');
          return;
        }
        const retryable = [408, 425, 429].includes(response.status) || response.status >= 500;
        if (!retryable) {
          finishWithMessage('وضعیت آماده‌سازی صوت قابل تشخیص نیست؛ برای بررسی دوباره صفحه را بارگذاری کنید.');
          return;
        }
        nextDelay = retryAfterDelay(response) ?? retryDelay;
      } catch (error) {
        if (error?.name === 'AbortError') return;
        // Network failures are transient; keep polling with bounded backoff.
      } finally {
        inFlight = false;
        controller = null;
      }

      if (stopped || paused || !root.isConnected) {
        if (!root.isConnected) stopPolling();
        return;
      }
      retryDelay = Math.min(maximumDelay, Math.max(initialDelay, retryDelay * 2));
      schedulePoll(nextDelay);
    };

    document.addEventListener('visibilitychange', onVisibilityChange);
    window.addEventListener('pagehide', onPageHide);
    window.addEventListener('pageshow', onPageShow);
    if (!paused) pollAudioReadiness();
  }
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
    timings = Array.isArray(parsed) ? parsed.filter(item => {
      if (!item || typeof item.text !== 'string' || !item.text.trim()) return false;
      const start = Number(item.start);
      const end = Number(item.end);
      const page = Number(item.page ?? 1);
      return Number.isFinite(start) && start >= 0
        && Number.isFinite(end) && end > start
        && Number.isSafeInteger(page) && page > 0;
    }).map(item => ({
      ...item,
      start: Number(item.start),
      end: Number(item.end),
      page: Number(item.page ?? 1)
    })).sort((left, right) => left.start - right.start || left.page - right.page) : [];
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
    let low = 0;
    let high = timings.length - 1;
    let candidate = -1;
    while (low <= high) {
      const middle = (low + high) >> 1;
      if (timings[middle].start <= time) {
        candidate = middle;
        low = middle + 1;
      } else {
        high = middle - 1;
      }
    }
    const index = candidate >= 0 && time < timings[candidate].end ? candidate : -1;
    if (index < 0) {
      if (activeSegmentIndex !== -1) clearHighlights();
      activeSegmentIndex = -1;
      return;
    }
    if (index === activeSegmentIndex) return;
    activeSegmentIndex = index;
    const segment = timings[index];
    activePage = segment.page;
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
  });
  audio.addEventListener('play', updatePlayButton);
  audio.addEventListener('pause', updatePlayButton);
  audio.addEventListener('ended', () => {
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

  dock.hidden = false;
  updatePlayButton();
})();
