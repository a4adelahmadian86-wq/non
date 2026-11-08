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
    const documentPage = Number(article.dataset.readerPage || 0);
    if (documentPage > 0) return documentPage;
    const continuousPage = element?.closest('[data-pdf-cont-page]');
    if (continuousPage) return Number(continuousPage.dataset.pdfContPage) || 1;
    return Math.max(1, Number(root.querySelector('#reader-page-number')?.value || Number(article.dataset.index || 0) + 1) || 1);
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
    window.FarastDocumentAudioReader?.pause();
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
        : response.status === 503 ? 'سرویس صدای خوانشگر هنوز آماده نیست.'
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
      ? selection.toString().trim() : '';
  };
  const speechTarget = '.reader-document-word,.reader-audio-word,.reader-document-sentence,.reader-document-paragraph,.reader-document-heading,.reader-document-cell,.reader-document-row,.reader-document-section';

  const speakPdfCaret = (event, layer) => {
    const caret = document.caretRangeFromPoint?.(event.clientX, event.clientY);
    const position = document.caretPositionFromPoint?.(event.clientX, event.clientY);
    const node = caret?.startContainer || position?.offsetNode;
    if (node?.nodeType !== Node.TEXT_NODE) return false;
    const raw = node.nodeValue || '';
    const offset = Number(caret?.startOffset ?? position?.offset ?? 0);
    const wordPattern = /[\p{L}\p{N}]+(?:[\u200c][\p{L}\p{N}]+)*/gu;
    let match;
    while ((match = wordPattern.exec(raw))) {
      if (offset >= match.index && offset <= match.index + match[0].length) {
        speak(match[0], pageFor(layer), layer, 'در حال خواندن واژهٔ انتخاب‌شده');
        return true;
      }
    }
    const before = raw.slice(0, offset), after = raw.slice(offset);
    const left = before.search(/[^.!؟۔؛]*$/u);
    const right = after.match(/^[^.!؟۔؛]*(?:[.!؟۔؛]|$)/u);
    const sentence = (before.slice(left < 0 ? 0 : left) + (right?.[0] || '')).trim();
    if (sentence.length > 1) {
      speak(sentence, pageFor(layer), layer, 'در حال خواندن جملهٔ انتخاب‌شده');
      return true;
    }
    return false;
  };

  root.addEventListener('click', event => {
    const word = event.target.closest('.reader-document-word,.reader-audio-word');
    if (word) {
      event.preventDefault();
      event.stopImmediatePropagation();
      speak(word.dataset.word || word.textContent, Number(word.dataset.page || pageFor(word)), word, 'در حال خواندن واژهٔ انتخاب‌شده');
      return;
    }

    const sentence = event.target.closest('.reader-document-sentence');
    if (sentence) {
      event.preventDefault();
      speak(sentence.dataset.readerSpeechText || sentence.textContent, pageFor(sentence), sentence, 'در حال خواندن جملهٔ انتخاب‌شده');
      return;
    }

    const cell = event.target.closest('.reader-document-cell');
    if (cell) {
      event.preventDefault();
      speak(cell.dataset.readerSpeechText || cell.textContent, pageFor(cell), cell, 'در حال خواندن سلول انتخاب‌شده');
      return;
    }

    const paragraph = event.target.closest('.reader-document-paragraph,.reader-document-heading,.reader-document-row,.reader-document-section');
    if (paragraph) {
      event.preventDefault();
      const label = paragraph.classList.contains('reader-document-heading') ? 'عنوان'
        : paragraph.classList.contains('reader-document-row') ? 'ردیف'
        : paragraph.classList.contains('reader-document-section') ? 'بخش' : 'پاراگراف';
      speak(paragraph.dataset.readerSpeechText || paragraph.textContent, pageFor(paragraph), paragraph, 'در حال خواندن ' + label + ' انتخاب‌شده');
      return;
    }

    const pdfText = event.target.closest('.farast-pdf-text-layer,.farast-pdf-cont-text');
    if (pdfText && speakPdfCaret(event, pdfText)) {
      event.preventDefault();
      return;
    }

    const article = event.target.closest('.farast-reader-page-item');
    if (article && !event.target.closest('button,input,select,a,iframe,.farast-audio-dock')) {
      const page = pageFor(event.target);
      stop();
      window.FarastAudioReader?.pause();
      if (window.FarastDocumentAudioReader?.playPage(page)) return;
      let timings = [];
      try { timings = JSON.parse(article.dataset.audioTiming || '[]'); } catch (_) {}
      const pageTimings = timings.filter(item => Number(item.page || page) === page && Number(item.end) > Number(item.start));
      if (pageTimings.length && window.FarastAudioReader?.playRange) {
        const first = pageTimings.reduce((a, b) => Number(a.start) <= Number(b.start) ? a : b);
        const last = pageTimings.reduce((a, b) => Number(a.end) >= Number(b.end) ? a : b);
        window.FarastAudioReader.selectSection(Number(article.dataset.index || 0));
        window.FarastAudioReader.playRange(Number(first.start), Number(last.end), 'در حال خواندن صفحهٔ انتخاب‌شده');
      }
    }
  }, true);

  root.addEventListener('keydown', event => {
    if (event.key !== 'Enter' && event.key !== ' ') return;
    const pageTarget = event.target.closest('.farast-reader-page-item');
    if (pageTarget && event.target === pageTarget) {
      event.preventDefault();
      event.stopImmediatePropagation();
      const page = pageFor(pageTarget);
      stop();
      window.FarastAudioReader?.pause();
      if (window.FarastDocumentAudioReader?.playPage(page)) return;
      let timings = [];
      try { timings = JSON.parse(pageTarget.dataset.audioTiming || '[]'); } catch (_) {}
      const pageTimings = timings.filter(item => Number(item.page || page) === page && Number(item.end) > Number(item.start));
      if (pageTimings.length && window.FarastAudioReader?.playRange) {
        const first = pageTimings.reduce((a, b) => Number(a.start) <= Number(b.start) ? a : b);
        const last = pageTimings.reduce((a, b) => Number(a.end) >= Number(b.end) ? a : b);
        window.FarastAudioReader.selectSection(Number(pageTarget.dataset.index || 0));
        window.FarastAudioReader.playRange(Number(first.start), Number(last.end), 'در حال خواندن صفحهٔ انتخاب‌شده');
      }
      return;
    }
    const target = event.target.closest(speechTarget);
    if (!target) return;
    event.preventDefault();
    event.stopImmediatePropagation();
    const word = target.matches('.reader-document-word,.reader-audio-word');
    const sentence = target.matches('.reader-document-sentence');
    const label = word ? 'واژه' : sentence ? 'جمله' : target.classList.contains('reader-document-cell') ? 'سلول' : 'بخش';
    speak(target.dataset.readerSpeechText || target.dataset.word || target.textContent, pageFor(target), target, 'در حال خواندن ' + label + ' انتخاب‌شده');
  }, true);

  root.addEventListener('pointerup', () => {
    const selection = window.getSelection();
    const text = selectedText();
    if (!text) return;
    const anchor = selection?.anchorNode?.parentElement;
    if (!anchor || !root.contains(anchor)) return;
    speak(text, pageFor(anchor), anchor.closest(speechTarget) || anchor, 'در حال خواندن عبارت انتخاب‌شده');
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
  style.textContent = '.reader-audio-selection-active{outline:2px solid rgba(59,130,246,.75);outline-offset:3px;background:rgba(59,130,246,.10)}.reader-document-word{cursor:pointer;border-radius:2px}.reader-document-word:focus-visible,.reader-document-sentence:focus-visible,.reader-document-paragraph:focus-visible,.reader-document-heading:focus-visible,.reader-document-cell:focus-visible,.reader-document-row:focus-visible{outline:2px solid #3b82f6;outline-offset:2px}.reader-document-sentence,.reader-document-paragraph,.reader-document-heading,.reader-document-cell,.reader-document-row{cursor:pointer}';
  document.head.append(style);
})();