(() => {
  const fab = document.getElementById('farast-support-fab');
  const widget = document.getElementById('farast-support-widget');
  if (!fab || !widget) return;

  const isAuth = widget.getAttribute('data-auth') === '1';
  const closeBtn = widget.querySelector('[data-fsw-close]');
  const messagesEl = widget.querySelector('[data-fsw-messages]');
  const form = widget.querySelector('[data-fsw-form]');
  const textarea = widget.querySelector('[data-fsw-input]');
  const contactBox = widget.querySelector('[data-fsw-contact]');
  const contactForm = widget.querySelector('[data-fsw-contact-form]');
  const emojiPanel = widget.querySelector('[data-fsw-emoji]');
  const emojiToggle = widget.querySelector('[data-fsw-emoji-toggle]');
  const iconChat = fab.querySelector('[data-fsw-icon="chat"]');
  const iconClose = fab.querySelector('[data-fsw-icon="close"]');
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content;

  let ticketId = sessionStorage.getItem('farast_support_ticket_id') || null;
  let pendingBody = null;

  const open = () => {
    widget.classList.add('is-open');
    widget.setAttribute('aria-hidden', 'false');
    fab.classList.add('is-open');
    if (iconChat) iconChat.hidden = true;
    if (iconClose) iconClose.hidden = false;
    textarea?.focus();
  };
  const close = () => {
    widget.classList.remove('is-open');
    widget.setAttribute('aria-hidden', 'true');
    fab.classList.remove('is-open');
    if (iconChat) iconChat.hidden = false;
    if (iconClose) iconClose.hidden = true;
    if (emojiPanel) emojiPanel.hidden = true;
  };

  fab.addEventListener('click', () => {
    widget.classList.contains('is-open') ? close() : open();
  });
  closeBtn?.addEventListener('click', close);

  document.querySelectorAll('[data-open-support]').forEach((el) => {
    el.addEventListener('click', (e) => {
      e.preventDefault();
      open();
    });
  });

  function appendMsg(text, type) {
    const wrap = document.createElement('div');
    wrap.className = 'fsw-msg ' + (type === 'user' ? 'fsw-msg-user' : 'fsw-msg-bot');
    const time = new Date().toLocaleTimeString('fa-IR', { hour: '2-digit', minute: '2-digit' });
    const bubble = document.createElement('div');
    bubble.className = 'fsw-msg-bubble';
    bubble.textContent = text;
    const t = document.createElement('time');
    t.textContent = time;
    wrap.appendChild(bubble);
    wrap.appendChild(t);
    messagesEl.appendChild(wrap);
    messagesEl.scrollTop = messagesEl.scrollHeight;
  }

  async function sendMessage(body) {
    const payload = { body, ticket_id: ticketId ? Number(ticketId) : null };
    if (!isAuth) {
      const nameInput = contactForm?.querySelector('[name="name"]');
      const emailInput = contactForm?.querySelector('[name="email"]');
      if (nameInput?.value) payload.name = nameInput.value;
      if (emailInput?.value) payload.email = emailInput.value;
    }

    const res = await fetch('/api/support/guest-message', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-CSRF-TOKEN': csrf || '',
        'X-Requested-With': 'XMLHttpRequest',
      },
      body: JSON.stringify(payload),
      credentials: 'same-origin',
    });

    const data = await res.json().catch(() => ({}));

    if (res.status === 422 && data.need_contact) {
      pendingBody = body;
      contactBox?.classList.add('is-visible');
      appendMsg(data.message || 'لطفاً نام و ایمیل را وارد کنید.', 'bot');
      return;
    }

    if (!res.ok || !data.ok) {
      appendMsg(data.message || 'ارسال پیام ممکن نشد. دوباره تلاش کنید.', 'bot');
      return;
    }

    if (data.ticket_id) {
      ticketId = String(data.ticket_id);
      sessionStorage.setItem('farast_support_ticket_id', ticketId);
    }

    if (data.reply) appendMsg(data.reply, 'bot');

    if (isAuth && data.redirect) {
      appendMsg('می‌توانید ادامه گفتگو را در داشبورد → پشتیبانی ببینید.', 'bot');
    }
  }

  form?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const body = (textarea?.value || '').trim();
    if (!body) return;
    appendMsg(body, 'user');
    textarea.value = '';
    textarea.style.height = 'auto';
    try {
      await sendMessage(body);
    } catch (_) {
      appendMsg('خطای شبکه. دوباره تلاش کنید.', 'bot');
    }
  });

  contactForm?.addEventListener('submit', async (e) => {
    e.preventDefault();
    contactBox?.classList.remove('is-visible');
    const body = pendingBody || 'سلام، نیاز به پشتیبانی دارم.';
    pendingBody = null;
    try {
      await sendMessage(body);
    } catch (_) {
      appendMsg('خطای شبکه. دوباره تلاش کنید.', 'bot');
    }
  });

  // Quick answers
  widget.querySelectorAll('[data-qa]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const text = btn.getAttribute('data-qa') || btn.textContent || '';
      appendMsg(text, 'user');
      try {
        await sendMessage(text);
      } catch (_) {
        appendMsg('خطای شبکه. دوباره تلاش کنید.', 'bot');
      }
    });
  });

  // Emoji
  emojiToggle?.addEventListener('click', () => {
    if (!emojiPanel) return;
    emojiPanel.hidden = !emojiPanel.hidden;
  });
  emojiPanel?.querySelectorAll('[data-emoji]').forEach((btn) => {
    btn.addEventListener('click', () => {
      if (!textarea) return;
      textarea.value += btn.getAttribute('data-emoji') || '';
      textarea.focus();
    });
  });

  // Auto-grow textarea
  textarea?.addEventListener('input', () => {
    textarea.style.height = 'auto';
    textarea.style.height = Math.min(textarea.scrollHeight, 100) + 'px';
  });

  textarea?.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      form?.requestSubmit();
    }
  });
})();
