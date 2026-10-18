(() => {
  const modal = document.getElementById('newTicketModal');
  ['openNewTicket', 'openNewTicketEmpty', 'openNewTicketMain']
    .map((id) => document.getElementById(id))
    .filter(Boolean)
    .forEach((btn) => btn.addEventListener('click', () => modal?.showModal()));

  modal?.querySelectorAll('[data-close]').forEach((el) => {
    el.addEventListener('click', () => modal.close());
  });

  const closeDialog = document.getElementById('closeTicketDialog');
  document.getElementById('btnCloseTicketOpen')?.addEventListener('click', () => closeDialog?.showModal());
  closeDialog?.querySelectorAll('[data-close-dialog]').forEach((el) => {
    el.addEventListener('click', () => closeDialog.close());
  });

  const search = document.getElementById('ticketSearch');
  const list = document.getElementById('ticketList');
  const filters = document.getElementById('statusFilters');
  let statusFilter = 'all';

  function applyFilters() {
    const q = (search?.value || '').trim().toLowerCase();
    list?.querySelectorAll('.sd-item').forEach((item) => {
      const subject = (item.dataset.subject || '').toLowerCase();
      const st = item.dataset.status || '';
      const okStatus = statusFilter === 'all' || st === statusFilter;
      const okSearch = !q || subject.includes(q);
      item.style.display = okStatus && okSearch ? '' : 'none';
    });
  }

  search?.addEventListener('input', applyFilters);
  filters?.querySelectorAll('.sd-chip').forEach((chip) => {
    chip.addEventListener('click', () => {
      filters.querySelectorAll('.sd-chip').forEach((c) => c.classList.remove('is-on'));
      chip.classList.add('is-on');
      statusFilter = chip.dataset.status || 'all';
      applyFilters();
    });
  });

  const body = document.getElementById('composerBody');
  const form = document.getElementById('chatComposer');
  body?.addEventListener('input', () => {
    body.style.height = 'auto';
    body.style.height = Math.min(body.scrollHeight, 140) + 'px';
  });
  body?.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      form?.requestSubmit();
    }
  });

  const messages = document.getElementById('chatMessages');
  if (messages) messages.scrollTop = messages.scrollHeight;

  const emojiToggle = document.getElementById('emojiToggle');
  const emojiPanel = document.getElementById('emojiPanel');
  emojiToggle?.addEventListener('click', () => {
    if (!emojiPanel) return;
    emojiPanel.hidden = !emojiPanel.hidden;
  });
  emojiPanel?.querySelectorAll('[data-emoji]').forEach((btn) => {
    btn.addEventListener('click', () => {
      if (!body) return;
      const start = body.selectionStart ?? body.value.length;
      const end = body.selectionEnd ?? body.value.length;
      const em = btn.getAttribute('data-emoji') || '';
      body.value = body.value.slice(0, start) + em + body.value.slice(end);
      body.focus();
      const pos = start + em.length;
      body.setSelectionRange(pos, pos);
      body.dispatchEvent(new Event('input'));
    });
  });

  const fileInput = document.getElementById('composerFile');
  const fileName = document.getElementById('composerFileName');
  fileInput?.addEventListener('change', () => {
    if (!fileName) return;
    if (fileInput.files?.[0]) {
      fileName.hidden = false;
      fileName.innerHTML = '<i class="fa-solid fa-paperclip"></i> ' + fileInput.files[0].name;
    } else {
      fileName.hidden = true;
      fileName.textContent = '';
    }
  });

  // AJAX send for smoother messenger feel (falls back to normal submit)
  form?.addEventListener('submit', async (e) => {
    if (!window.fetch || fileInput?.files?.length) return; // let browser handle multipart
    e.preventDefault();
    const text = (body?.value || '').trim();
    if (!text) return;

    const sendBtn = document.getElementById('sendBtn');
    if (sendBtn) sendBtn.disabled = true;

    const fd = new FormData(form);
    try {
      const res = await fetch(form.action, {
        method: 'POST',
        headers: {
          Accept: 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
          'X-CSRF-TOKEN': form.querySelector('[name=_token]')?.value || '',
        },
        body: fd,
        credentials: 'same-origin',
      });

      if (!res.ok) {
        form.submit();
        return;
      }

      const data = await res.json().catch(() => ({}));
      const msg = data.message;
      if (messages && msg) {
        const row = document.createElement('div');
        row.className = 'sd-msg sd-msg-user';
        row.innerHTML =
          '<div class="sd-msg-col"><div class="sd-bubble"><pre></pre></div><time class="sd-msg-time"></time></div>';
        row.querySelector('pre').textContent = msg.body || text;
        row.querySelector('time').textContent = msg.time || new Date().toLocaleTimeString('fa-IR', { hour: '2-digit', minute: '2-digit' });
        messages.appendChild(row);
        messages.scrollTop = messages.scrollHeight;
      }
      if (body) {
        body.value = '';
        body.style.height = 'auto';
      }
      emojiPanel && (emojiPanel.hidden = true);
    } catch (_) {
      form.submit();
    } finally {
      if (sendBtn) sendBtn.disabled = false;
    }
  });

  // auto-hide toast
  const toast = document.querySelector('.sd-toast');
  if (toast) setTimeout(() => toast.remove(), 4000);
})();
