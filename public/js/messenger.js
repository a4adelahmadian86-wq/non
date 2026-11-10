(() => {
  const dialog = document.querySelector('[data-compose]');
  document.querySelector('[data-open-compose]')?.addEventListener('click', () => dialog?.showModal());
  dialog?.querySelector('[data-close-compose]')?.addEventListener('click', () => dialog.close());
  const type = dialog?.querySelector('[data-conversation-type]');
  const groupTitle = dialog?.querySelector('[data-group-title]');
  type?.addEventListener('change', () => { if (groupTitle) groupTitle.hidden = type.value !== 'group'; });

  const root = document.getElementById('fm-chat');
  const list = document.getElementById('fm-messages');
  const form = document.getElementById('fm-composer');
  if (!root || !list || !form) return;

  const error = document.getElementById('fm-error');
  let lastId = Number(root.dataset.lastId || 0);
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content || form.querySelector('input[name="_token"]')?.value || '';

  const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const append = (message) => {
    if (document.querySelector('[data-message-id="' + message.id + '"]')) return;
    const article = document.createElement('article');
    article.className = 'fm-message fm-new-message' + (Number(message.user_id) === Number(root.dataset.currentUserId || -1) ? ' is-mine' : '');
    article.dataset.messageId = message.id;
    const date = message.created_at ? new Date(message.created_at).toLocaleString() : '';
    article.innerHTML = '<div class="fm-message-meta"><strong>' + escapeHtml(message.user_name || 'کاربر') + '</strong><time>' + escapeHtml(date) + '</time></div>' + (message.body ? '<p></p>' : '');
    if (message.body) article.querySelector('p').textContent = message.body;
    (message.attachments || []).forEach((file) => {
      const link = document.createElement(file.url ? 'a' : 'span');
      link.className = 'fm-attachment';
      link.textContent = '📎 ' + (file.name || 'پیوست');
      if (file.url) link.href = file.url;
      article.appendChild(link);
    });
    list.appendChild(article);
    lastId = Math.max(lastId, Number(message.id));
    list.scrollTop = list.scrollHeight;
  };

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (error) error.textContent = '';
    const button = form.querySelector('button[type="submit"]');
    button.disabled = true;
    try {
      const response = await fetch(root.dataset.sendUrl, {method:'POST',headers:{'X-CSRF-TOKEN':csrf,'Accept':'application/json'},body:new FormData(form),credentials:'same-origin'});
      const data = await response.json();
      if (!response.ok) throw new Error(data.message || Object.values(data.errors || {}).flat()[0] || 'ارسال پیام ناموفق بود.');
      append({...data.message,user_name:data.message.user_name});
      form.reset();
    } catch (e) { if (error) error.textContent = e.message || 'خطا در ارسال پیام'; }
    finally { button.disabled = false; }
  });

  const poll = async () => {
    try {
      const response = await fetch(root.dataset.pollUrl + '?after=' + lastId, {headers:{'Accept':'application/json'},credentials:'same-origin'});
      if (!response.ok) return;
      const data = await response.json();
      (data.messages || []).forEach(append);
    } catch (_) {}
  };
  list.scrollTop = list.scrollHeight;
  window.setInterval(poll, 4000);
})();
