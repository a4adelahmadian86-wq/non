(() => {
  const modal = document.getElementById('newTicketModal');
  const openers = ['openNewTicket', 'openNewTicketEmpty', 'openNewTicketMain']
    .map(id => document.getElementById(id))
    .filter(Boolean);

  openers.forEach(btn => btn.addEventListener('click', () => modal?.showModal()));
  modal?.querySelectorAll('[data-close]').forEach(el => {
    el.addEventListener('click', () => modal.close());
  });

  const search = document.getElementById('ticketSearch');
  const list = document.getElementById('ticketList');
  search?.addEventListener('input', () => {
    const q = search.value.trim().toLowerCase();
    list?.querySelectorAll('.ticket-item').forEach(item => {
      const subject = (item.dataset.subject || '').toLowerCase();
      item.style.display = !q || subject.includes(q) ? '' : 'none';
    });
  });

  const body = document.getElementById('composerBody');
  body?.addEventListener('input', () => {
    body.style.height = 'auto';
    body.style.height = Math.min(body.scrollHeight, 140) + 'px';
  });

  const messages = document.getElementById('chatMessages');
  if (messages) {
    messages.scrollTop = messages.scrollHeight;
  }

  // Auto-grow & Enter to send (Shift+Enter = newline)
  const form = document.getElementById('chatComposer');
  body?.addEventListener('keydown', e => {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      form?.requestSubmit();
    }
  });
})();
