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
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content;

  let ticketId = sessionStorage.getItem('farast_support_ticket_id') || null;
  let pendingBody = null;

  const open = () => {
    widget.classList.add('is-open');
    widget.setAttribute('aria-hidden', 'false');
    textarea?.focus();
  };
  const close = () => {
    widget.classList.remove('is-open');
    widget.setAttribute('aria-hidden', 'true');
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
    const div = document.createElement('div');
    div.className = 'fsw-msg ' + type;
    const time = new Date().toLocaleTimeString('fa-IR', { hour: '2-digit', minute: '2-digit' });
    div.innerHTML = `<div></div><time>${time}</time>`;
    div.querySelector('div').textContent = text;
    messagesEl.appendChild(div);
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

    if (res.status === 422 && data.need_contact && !isAuth) {
      pendingBody = body;
      contactBox?.classList.add('is-visible');
      appendMsg(data.message || 'لطفاً نام و ایمیل را وارد کنید.', 'bot');
      return;
    }

    if (!res.ok || !data.ok) {
      appendMsg(data.message || 'خطا در ارسال. دوباره تلاش کنید.', 'bot');
      return;
    }

    if (data.ticket_id) {
      ticketId = String(data.ticket_id);
      sessionStorage.setItem('farast_support_ticket_id', ticketId);
    }
    if (data.reply) appendMsg(data.reply, 'bot');
  }

  form?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const text = (textarea?.value || '').trim();
    if (!text) return;
    appendMsg(text, 'user');
    textarea.value = '';
    try {
      await sendMessage(text);
    } catch {
      appendMsg('ارتباط برقرار نشد. اینترنت را بررسی کنید.', 'bot');
    }
  });

  contactForm?.addEventListener('submit', async (e) => {
    e.preventDefault();
    contactBox?.classList.remove('is-visible');
    if (pendingBody) {
      const t = pendingBody;
      pendingBody = null;
      await sendMessage(t);
    }
  });

  if (messagesEl && messagesEl.children.length === 0) {
    appendMsg(
      isAuth
        ? 'سلام! پیام خود را بنویسید. تاریخچه کامل در داشبورد ← پشتیبانی در دسترس است.'
        : 'سلام! به پشتیبانی فراست خوش آمدید. پیام خود را بنویسید؛ تیم ما پاسخ می‌دهد.',
      'bot'
    );
  }
})();
