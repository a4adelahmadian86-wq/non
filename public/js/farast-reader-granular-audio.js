(() => {
  'use strict';
  const root = document.querySelector('[data-reader-root]');
  const speechUrl = root?.dataset.readerSpeechUrl || '';
  if (!root || !speechUrl) return;

  const status = () => root.querySelector('[data-audio-status]');
  const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';
  let activeAudio = null;
  let activeUrl = null;
  let requestId = 0;
  let activeElement = null;

  const pageFor = element => {
    const article = element?.closest('.farast-reader-page-item') || root.querySelector('.farast-reader-page-item.is-active');
    if (!article) return 1;
    const documentPage = Number(article.querySelector('[data-document-page]')?.dataset.documentPage || 0);
    if (documentPage > 0) return documentPage;
    const continuousPage = element?.closest('[data-pdf-cont-page]');
    if (continuousPage) return Number(continuousPage.dataset.pdfContPage) || 1;
    return Math.max(1, Number(root.querySelector('#reader-page-number')?.value || article.dataset.index || 0) || 1);
  };

  const stop = () => {
    requestId++;
    if (activeAudio) {
      activeAudio.pause();
      activeAudio.src = '';
      activeAudio = null;
    }
    if (activeUrl) {
      URL.revokeObjectURL(activeUrl);
      activeUrl = null;
    }
    activeElement?.classList.remove('reader-audio-selection-active');
    activeElement = null;
  };

  const speak = async (text, page, element, label) => {
    const clean = String(text || '').trim();
    if (!clean) return;
    if (clean.length > 2000) {
      if (status()) status().textContent = 'برای پخش انتخابی، متن باید حداکثر ۲۰۰۰ نویسه باشد.';
      return;
    }
    stop();
    const id = ++requestId;
    window.FarastAudioReader?.pause();
    if (status()) status().textContent = 'در حال آماده‌سازی صدای انتخاب‌شده…';
    try {
      const response = await fetch(speechUrl, {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
          'Accept': 'audio/wav',
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': csrf(),
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify({ text: clean, page: Math.max(1, Number(page) || 1) })
      });
      if (!response.ok) throw new Error(response.status === 403
        ? 'دسترسی خواندن این سند تأیید نشد.'
        : 'تولید صدای انتخاب‌شده ممکن نشد.');
      const blob = await response.blob();
      if (id !== requestId) return;
      if (!blob.size || blob.size > 8 * 1024 * 1024) throw new Error('اندازه صدای تولیدشده معتبر نیست.');
      activeUrl = URL.createObjectURL(blob);
      activeAudio = new Audio(activeUrl);
      activeAudio.preload = 'auto';
      activeAudio.playbackRate = Number(root.querySelector('[data-audio-speed]')?.value || 1);
      activeAudio.volume = Number(root.querySelector('[data-audio-volume]')?.value || 1);
      activeElement = element || null;
      activeElement?.classList.add('reader-audio-selection-active');
      activeAudio.addEventListener('ended', stop, { once: true });
      activeAudio.addEventListener('error', () => {
        if (status()) status().textContent = 'پخش صدای انتخاب‌شده ناموفق بود.';
        stop();
      }, { once: true });
      await activeAudio.play();
      if (status()) status().textContent = label || 'در حال خواندن بخش انتخاب‌شده';
    } catch (error) {
      if (id === requestId && status()) status().textContent = error.message || 'پخش صدای انتخاب‌شده ناموفق بود.';
      stop();
    }
  };

  const selectedText = () => {
    const selection = window.getSelection();
    return selection && !selection.isCollapsed && selection.toString().trim().length >= 2
      ? selection.toString().trim()
      : '';
  };

  root.addEventListener('click', event => {
    const word = event.target.closest('.reader-audio-word');
    if (word) {
      event.preventDefault();
      event.stopImmediatePropagation();
      speak(word.dataset.word || '', Number(word.dataset.page || pageFor(word)), word, 'در حال خواندن واژهٔ انتخاب‌شده');
      return;
    }

    const sentence = event.target.closest('.reader-document-sentence');
    if (sentence) {
      event.preventDefault();
      speak(sentence.textContent, pageFor(sentence), sentence, 'در حال خواندن جملهٔ انتخاب‌شده');
      return;
    }

    const paragraph = event.target.closest('.reader-document-paragraph');
    if (paragraph) {
      event.preventDefault();
      speak(paragraph.textContent, pageFor(paragraph), paragraph, 'در حال خواندن پاراگراف انتخاب‌شده');
      return;
    }

    const pdfText = event.target.closest('.farast-pdf-text-layer,.farast-pdf-cont-text');
    if (pdfText) {
      const caret = document.caretRangeFromPoint?.(event.clientX, event.clientY);
      const node = caret?.startContainer;
      if (node?.nodeType === Node.TEXT_NODE) {
        const raw = node.nodeValue || '', offset = Number(caret.startOffset || 0);
        const before = raw.slice(0, offset), after = raw.slice(offset);
        const left = before.search(/[^.!؟۔؛]*$/u);
        const right = after.match(/^[^.!؟۔؛]*(?:[.!؟۔؛]|$)/u);
        const sentenceText = (before.slice(left < 0 ? 0 : left) + (right?.[0] || '')).trim();
        if (sentenceText.length > 1) {
          event.preventDefault();
          speak(sentenceText, pageFor(pdfText), pdfText, 'در حال خواندن جملهٔ انتخاب‌شده');
          return;
        }
      }
    }

    const article = event.target.closest('.farast-reader-page-item');
    if (article && !event.target.closest('button,input,select,a,.farast-pdf-text-layer,.farast-pdf-cont-text,.reader-audio-dock')) {
      const page = pageFor(event.target);
      let timings = [];
      try { timings = JSON.parse(article.dataset.audioTiming || '[]'); } catch (_) {}
      const pageTimings = timings.filter(item => Number(item.page || page) === page && Number(item.end) > Number(item.start));
      if (pageTimings.length && window.FarastAudioReader?.playRange) {
        stop();
        const first = pageTimings.reduce((a, b) => Number(a.start) <= Number(b.start) ? a : b);
        const last = pageTimings.reduce((a, b) => Number(a.end) >= Number(b.end) ? a : b);
        window.FarastAudioReader.selectSection(Number(article.dataset.index || 0));
        window.FarastAudioReader.playRange(Number(first.start), Number(last.end), 'در حال خواندن صفحهٔ انتخاب‌شده');
      }
    }
  }, true);

  root.addEventListener('keydown', event => {
    if (event.key !== 'Enter' && event.key !== ' ') return;
    const word = event.target.closest('.reader-audio-word');
    if (word) {
      event.preventDefault();
      event.stopImmediatePropagation();
      speak(word.dataset.word || '', Number(word.dataset.page || pageFor(word)), word, 'در حال خواندن واژهٔ انتخاب‌شده');
      return;
    }
    const target = event.target.closest('.reader-document-sentence,.reader-document-paragraph');
    if (!target) return;
    event.preventDefault();
    speak(target.textContent, pageFor(target), target,
      target.classList.contains('reader-document-sentence') ? 'در حال خواندن جملهٔ انتخاب‌شده' : 'در حال خواندن پاراگراف انتخاب‌شده');
  }, true);

  root.addEventListener('pointerup', () => {
    const selection = window.getSelection();
    const text = selectedText();
    if (!text) return;
    const anchor = selection?.anchorNode?.parentElement;
    if (!anchor || !root.contains(anchor)) return;
    speak(text, pageFor(anchor), anchor.closest('.reader-document-paragraph,.reader-document-sentence') || anchor,
      'در حال خواندن عبارت انتخاب‌شده');
    selection.removeAllRanges();
  });

  root.addEventListener('farast:reader-section-change', stop);
  root.addEventListener('farast:reader-page-change', stop);
  window.addEventListener('beforeunload', stop);
  root.addEventListener('change', event => {
    if (event.target.matches('[data-audio-speed]') && activeAudio) activeAudio.playbackRate = Number(event.target.value) || 1;
    if (event.target.matches('[data-audio-volume]') && activeAudio) activeAudio.volume = Math.max(0, Math.min(1, Number(event.target.value)));
  });

  const style = document.createElement('style');
  style.textContent = '.reader-audio-selection-active{outline:2px solid rgba(59,130,246,.75);outline-offset:3px;background:rgba(59,130,246,.10)}.reader-document-sentence,.reader-document-paragraph{cursor:pointer}.reader-document-sentence:focus-visible,.reader-document-paragraph:focus-visible{outline:2px solid #3b82f6;outline-offset:2px}';
  document.head.append(style);
})();