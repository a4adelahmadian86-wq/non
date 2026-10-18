(() => {
  const modal = document.getElementById('newTicketModal');
  const openers = ['openNewTicket', 'openNewTicketEmpty', 'openNewTicketMain']
    .map((id) => document.getElementById(id))
    .filter(Boolean);

  openers.forEach((btn) => btn.addEventListener('click', () => modal?.showModal()));
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
  search?.addEventListener('input', () => {
    const q = search.value.trim().toLowerCase();
    list?.querySelectorAll('.rf-ticket-row').forEach((item) => {
      const subject = (item.dataset.subject || '').toLowerCase();
      item.style.display = !q || subject.includes(q) ? '' : 'none';
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

  // Emoji panel (Rubika-like)
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

  // File name preview
  const fileInput = document.getElementById('composerFile');
  const fileName = document.getElementById('composerFileName');
  fileInput?.addEventListener('change', () => {
    if (!fileName) return;
    if (fileInput.files && fileInput.files[0]) {
      fileName.textContent = fileInput.files[0].name;
      fileName.hidden = false;
    } else {
      fileName.hidden = true;
      fileName.textContent = '';
    }
  });
})();
