(() => {
  const players = Array.from(document.querySelectorAll('.reader-document-audio'));
  if (!players.length) return;
  const pageInput = document.getElementById('reader-page-number');
  const activeSequence = new WeakMap();
  const currentSegment = (segments, milliseconds) => segments.find(segment =>
    milliseconds >= Number(segment.start_ms || 0) && milliseconds < Number(segment.end_ms || 0)
  );

  players.forEach(audio => {
    if (audio.dataset.syncReady === '1') return;
    audio.dataset.syncReady = '1';
    const pointer = audio.closest('.farast-reader-audio')?.querySelector('.reader-audio-pointer');
    let segments = [];
    try { segments = JSON.parse(audio.dataset.segments || '[]'); } catch (_) { segments = []; }

    audio.addEventListener('play', () => {
      players.forEach(other => { if (other !== audio) other.pause(); });
    });

    audio.addEventListener('timeupdate', () => {
      const milliseconds = audio.currentTime * 1000;
      const segment = currentSegment(segments, milliseconds);
      if (!segment || Number(segment.sequence || 0) === activeSequence.get(audio)) return;
      activeSequence.set(audio, Number(segment.sequence || 0));
      const page = Number(segment.page_number || 0);
      const section = segment.section ? ' · '+segment.section : '';
      if (pointer) pointer.textContent = page > 0 ? 'در حال خواندن صفحه '+page+section : 'در حال خواندن بخش '+segment.sequence+section;

      const documentId = String(audio.dataset.documentId || '');
      if (documentId) {
        const pageItem = document.querySelector('.farast-reader-page-item[data-document-id="'+CSS.escape(documentId)+'"]');
        if (pageItem && !pageItem.classList.contains('is-active')) {
          const thumb = document.querySelector('.farast-reader-thumb[data-goto="'+CSS.escape(pageItem.dataset.index || '')+'"]');
          thumb?.click();
        }
      }

      if (page > 0 && pageInput && Number(pageInput.value) !== page) {
        pageInput.value = String(page);
        pageInput.dispatchEvent(new Event('change', {bubbles:true}));
      }

      if (segment.block_id) {
        document.querySelectorAll('[data-document-block-id].is-audio-active').forEach(node=>node.classList.remove('is-audio-active'));
        const block = document.querySelector('[data-document-block-id="'+CSS.escape(String(segment.block_id))+'"]');
        if (block) {
          block.classList.add('is-audio-active');
          block.scrollIntoView({behavior:window.matchMedia('(prefers-reduced-motion: reduce)').matches?'auto':'smooth',block:'center'});
        }
      }
    });

    pageInput?.addEventListener('change', () => {
      if (audio.paused) return;
      const page = Number(pageInput.value);
      const segment = segments.find(item=>Number(item.page_number || 0)===page);
      if (segment && Math.abs(audio.currentTime*1000-Number(segment.start_ms))>1200) {
        audio.currentTime = Number(segment.start_ms)/1000;
      }
    });

    audio.addEventListener('ended',()=>{if(pointer)pointer.textContent='پایان نسخه صوتی';});
    audio.addEventListener('error',()=>{if(pointer)pointer.textContent='پخش نسخه صوتی در دسترس نیست؛ صفحه را دوباره بارگذاری کنید.';});
  });
})();