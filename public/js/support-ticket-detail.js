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

  let lastId = Number(page?.dataset.lastId || 0);
  const pollUrl = page?.dataset.pollUrl || '';

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

  let dragDepth = 0;
  function showOverlay(on) {
    if (overlay) overlay.hidden = !on;
  }

  ['dragenter', 'dragover'].forEach((ev) => {
    zone?.addEventListener(ev, (e) => {
      e.preventDefault();
      e.stopPropagation();
      if (ev === 'dragenter') dragDepth++;
      showOverlay(true);
    });
    page?.addEventListener(ev, (e) => e.preventDefault());
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

  window.addEventListener('dragover', (e) => e.preventDefault());
  window.addEventListener('drop', (e) => e.preventDefault());

  function escapeHtml(s) {
    return String(s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;');
  }

  function appendMessage(m) {
    if (!messages || document.querySelector('[data-msg-id="' + m.id + '"]')) return;
    const isMine = !!m.is_mine;
    const row = document.createElement('div');
    row.className = 'tk-bubble-row ' + (isMine ? 'is-user' : 'is-staff');
    row.dataset.msgId = m.id;

    let attachHtml = '';
    if (Array.isArray(m.attachments) && m.attachments.length) {
      attachHtml = '<div class="tk-attach-list">' + m.attachments.map((a) => {
        const path = a.path ? '/storage/' + a.path : '#';
        return '<a href="' + path + '" target="_blank" rel="noopener"><i class="fa-solid fa-paperclip"></i> ' + escapeHtml(a.name || 'پیوست') + '</a>';
      }).join('') + '</div>';
    }

    row.innerHTML =
      '<div class="tk-bubble-avatar ' + (isMine ? 'tk-av-user' : 'tk-av-staff') + '">' +
      '<i class="fa-solid ' + (isMine ? 'fa-user' : 'fa-headset') + '"></i></div>' +
      '<div class="tk-bubble-col">' +
      '<div class="tk-bubble ' + (isMine ? 'tk-bubble-user' : 'tk-bubble-staff') + '">' +
      '<pre>' + escapeHtml(m.body || '') + '</pre>' + attachHtml + '</div>' +
      '<p class="tk-bubble-time ' + (isMine ? '' : 'is-end') + '">' + escapeHtml(m.time || '') + '</p></div>';

    messages.appendChild(row);
    messages.scrollTop = messages.scrollHeight;
    lastId = Math.max(lastId, Number(m.id) || 0);
  }

  async function poll() {
    if (!pollUrl || document.hidden) return;
    try {
      const res = await fetch(pollUrl + (pollUrl.includes('?') ? '&' : '?') + 'after_id=' + lastId, {
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
      });
      if (!res.ok) return;
      const data = await res.json();
      if (!data.ok) return;
      (data.messages || []).forEach(appendMessage);
      if (data.status_label) {
        const el = document.getElementById('ticketStatusLabel');
        if (el) el.textContent = data.status_label;
      }
    } catch (_) {}
  }

  if (pollUrl) {
    setInterval(poll, 12000);
    document.addEventListener('visibilitychange', () => {
      if (!document.hidden) poll();
    });
  }

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
        try {
          const data = JSON.parse(xhr.responseText || '{}');
          if (data.message) {
            appendMessage({
              id: data.message.id,
              body: data.message.body,
              is_mine: true,
              is_staff: false,
              time: data.message.created_at || data.message.time || '',
              attachments: [],
            });
          }
        } catch (_) {}
        if (body) body.value = '';
        clearFile();
        if (stateText) stateText.textContent = 'ارسال شد';
        return;
      }
      form.submit();
    };

    xhr.onerror = () => {
      if (sendBtn) sendBtn.disabled = false;
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
