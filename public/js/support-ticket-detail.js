(() => {
  const page = document.getElementById('ticketDetailPage');
  const zone = document.getElementById('ticketDropZone');
  const overlay = document.getElementById('dropOverlay');
  const form = document.getElementById('chatComposer');
  const body = document.getElementById('composerBody');
  const fileInput = document.getElementById('composerFile');
  const pickBtn = document.getElementById('pickAttachBtn');
  const sendBtn = document.getElementById('sendBtn');
  const statusBox = document.getElementById('uploadStatus');
  const fileNameEl = document.getElementById('uploadFileName');
  const stateText = document.getElementById('uploadStateText');
  const removeBtn = document.getElementById('removeAttach');
  const progressWrap = document.getElementById('uploadProgressWrap');
  const progressBar = document.getElementById('uploadProgressBar');
  const messages = document.getElementById('chatMessages');
  const closeDialog = document.getElementById('closeTicketDialog');

  if (messages) messages.scrollTop = messages.scrollHeight;

  document.getElementById('openCloseDialog')?.addEventListener('click', () => closeDialog?.showModal());
  closeDialog?.querySelectorAll('[data-close-dialog]').forEach((el) => {
    el.addEventListener('click', () => closeDialog.close());
  });

  const toast = document.querySelector('.sd-toast');
  if (toast) setTimeout(() => toast.remove(), 4000);

  function setFile(file) {
    if (!file || !fileInput) return;
    const max = 5 * 1024 * 1024;
    if (file.size > max) {
      alert('حجم فایل نباید بیشتر از ۵ مگابایت باشد.');
      return;
    }
    const dt = new DataTransfer();
    dt.items.add(file);
    fileInput.files = dt.files;
    if (statusBox) statusBox.hidden = false;
    if (fileNameEl) fileNameEl.textContent = file.name;
    if (stateText) stateText.textContent = formatSize(file.size) + ' · آماده ارسال';
    if (progressWrap) progressWrap.hidden = true;
    if (progressBar) progressBar.style.width = '0%';
  }

  function clearFile() {
    if (fileInput) fileInput.value = '';
    if (statusBox) statusBox.hidden = true;
    if (fileNameEl) fileNameEl.textContent = '—';
    if (stateText) stateText.textContent = 'آماده ارسال';
    if (progressWrap) progressWrap.hidden = true;
    if (progressBar) progressBar.style.width = '0%';
  }

  function formatSize(n) {
    if (n < 1024) return n + ' B';
    if (n < 1024 * 1024) return (n / 1024).toFixed(1) + ' KB';
    return (n / (1024 * 1024)).toFixed(1) + ' MB';
  }

  pickBtn?.addEventListener('click', () => fileInput?.click());
  fileInput?.addEventListener('change', () => {
    if (fileInput.files?.[0]) setFile(fileInput.files[0]);
    else clearFile();
  });
  removeBtn?.addEventListener('click', clearFile);

  // Drag & drop anywhere on ticket card
  let dragDepth = 0;
  function showOverlay(on) {
    if (!overlay) return;
    overlay.hidden = !on;
  }

  ['dragenter', 'dragover'].forEach((ev) => {
    zone?.addEventListener(ev, (e) => {
      e.preventDefault();
      e.stopPropagation();
      if (ev === 'dragenter') dragDepth++;
      showOverlay(true);
    });
    page?.addEventListener(ev, (e) => {
      e.preventDefault();
    });
  });

  zone?.addEventListener('dragleave', (e) => {
    e.preventDefault();
    e.stopPropagation();
    dragDepth = Math.max(0, dragDepth - 1);
    if (dragDepth === 0) showOverlay(false);
  });

  zone?.addEventListener('drop', (e) => {
    e.preventDefault();
    e.stopPropagation();
    dragDepth = 0;
    showOverlay(false);
    const f = e.dataTransfer?.files?.[0];
    if (f) setFile(f);
  });

  // Prevent browser open-file on drop outside
  window.addEventListener('dragover', (e) => e.preventDefault());
  window.addEventListener('drop', (e) => e.preventDefault());

  // AJAX submit with upload progress when file present
  form?.addEventListener('submit', (e) => {
    e.preventDefault();
    const text = (body?.value || '').trim();
    if (!text) return;

    const fd = new FormData(form);
    const xhr = new XMLHttpRequest();
    const hasFile = !!(fileInput?.files && fileInput.files.length);

    if (sendBtn) sendBtn.disabled = true;
    if (hasFile) {
      if (statusBox) statusBox.hidden = false;
      if (progressWrap) progressWrap.hidden = false;
      if (stateText) stateText.textContent = 'در حال آپلود...';
      if (progressBar) progressBar.style.width = '0%';
    } else if (stateText) {
      stateText.textContent = 'در حال ارسال...';
    }

    xhr.open('POST', form.action);
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.setRequestHeader('Accept', 'application/json');
    xhr.setRequestHeader('X-CSRF-TOKEN', form.querySelector('[name=_token]')?.value || '');

    xhr.upload.addEventListener('progress', (ev) => {
      if (!ev.lengthComputable || !progressBar) return;
      const pct = Math.round((ev.loaded / ev.total) * 100);
      progressBar.style.width = pct + '%';
      if (stateText) stateText.textContent = 'آپلود ' + pct + '%';
    });

    xhr.onreadystatechange = () => {
      if (xhr.readyState !== 4) return;
      if (sendBtn) sendBtn.disabled = false;

      if (xhr.status >= 200 && xhr.status < 300) {
        if (stateText) stateText.textContent = 'ارسال شد';
        if (progressBar) progressBar.style.width = '100%';
        // refresh to show new message + attachment
        window.location.reload();
        return;
      }

      // fallback full submit
      if (stateText) stateText.textContent = 'خطا — تلاش مجدد...';
      form.submit();
    };

    xhr.onerror = () => {
      if (sendBtn) sendBtn.disabled = false;
      if (stateText) stateText.textContent = 'خطای شبکه';
      form.submit();
    };

    xhr.send(fd);
  });

  body?.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
      e.preventDefault();
      form?.requestSubmit();
    }
  });
})();
