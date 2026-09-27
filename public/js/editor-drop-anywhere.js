(() => {
  const input = document.getElementById('source');
  const app = document.getElementById('farastWord');
  if (!input || !app) return;
  let depth = 0;
  const hasFiles = e => e.dataTransfer && Array.from(e.dataTransfer.types || []).includes('Files');
  const setActive = active => app.classList.toggle('global-file-drag', active);
  window.addEventListener('dragenter', e => { if (!hasFiles(e)) return; depth++; e.preventDefault(); setActive(true); }, true);
  window.addEventListener('dragover', e => { if (!hasFiles(e)) return; e.preventDefault(); e.dataTransfer.dropEffect = 'copy'; setActive(true); }, true);
  window.addEventListener('dragleave', e => { if (!hasFiles(e)) return; depth=Math.max(0,depth-1); if(depth===0)setActive(false); }, true);
  window.addEventListener('drop', e => {
    if (!hasFiles(e)) return;
    e.preventDefault(); e.stopPropagation(); e.stopImmediatePropagation(); depth=0; setActive(false);
    const file=e.dataTransfer.files && e.dataTransfer.files[0];
    if(!file) return;
    try { const dt=new DataTransfer(); dt.items.add(file); input.files=dt.files; input.dispatchEvent(new Event('change',{bubbles:true})); }
    catch { input.click(); }
  }, true);
})();
