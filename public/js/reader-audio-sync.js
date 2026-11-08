(() => {
  const audio = document.getElementById('reader-document-audio');
  if (!audio || audio.dataset.syncReady === '1') return;
  audio.dataset.syncReady = '1';
  const pointer = document.getElementById('reader-audio-pointer');
  const pageInput = document.getElementById('reader-page-number');
  let segments = [];
  try { segments = JSON.parse(audio.dataset.segments || '[]'); } catch (_) { segments = []; }
  let activeSequence = -1;
  let changingFromAudio = false;

  const currentSegment = milliseconds => segments.find(segment =>
    milliseconds >= Number(segment.start_ms || 0) && milliseconds < Number(segment.end_ms || 0)
  );

  audio.addEventListener('timeupdate', () => {
    const milliseconds = audio.currentTime * 1000;
    const segment = currentSegment(milliseconds);
    if (!segment || Number(segment.sequence || 0) === activeSequence) return;
    activeSequence = Number(segment.sequence || 0);
    const page = Number(segment.page_number || 0);
    const section = segment.section ? ' · '+segment.section : '';
    pointer.textContent = page > 0 ? 'در حال خواندن صفحه '+page+section : 'در حال خواندن بخش '+activeSequence+section;

    if (page > 0 && pageInput && Number(pageInput.value) !== page) {
      changingFromAudio = true;
      pageInput.value = String(page);
      pageInput.dispatchEvent(new Event('change', {bubbles:true}));
      changingFromAudio = false;
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
    if (changingFromAudio || audio.paused) return;
    const page = Number(pageInput.value);
    const segment = segments.find(item=>Number(item.page_number || 0)===page);
    if (segment && Math.abs(audio.currentTime*1000-Number(segment.start_ms))>1200) {
      audio.currentTime = Number(segment.start_ms)/1000;
    }
  });

  audio.addEventListener('ended',()=>{if(pointer)pointer.textContent='پایان نسخه صوتی';});
  audio.addEventListener('error',()=>{if(pointer)pointer.textContent='پخش نسخه صوتی در دسترس نیست؛ صفحه را دوباره بارگذاری کنید.';});
})();