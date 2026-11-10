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
  const composerInput = form.querySelector('textarea[name="body"]');
  let lastId = Number(root.dataset.lastId || 0);
  let typingTimer = null;
  let typingSent = false;
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
      if (typingSent) { setTyping(false); typingSent = false; }
    } catch (e) { if (error) error.textContent = e.message || 'خطا در ارسال پیام'; }
    finally { button.disabled = false; }
  });

  const setTyping = (typing) => fetch(root.dataset.presenceUrl, {
    method: 'POST',
    headers: {'X-CSRF-TOKEN': csrf, 'Content-Type': 'application/json', 'Accept': 'application/json'},
    body: JSON.stringify({typing}),
    credentials: 'same-origin'
  }).catch(() => {});

  composerInput?.addEventListener('input', () => {
    if (!typingSent) { setTyping(true); typingSent = true; }
    window.clearTimeout(typingTimer);
    typingTimer = window.setTimeout(() => { setTyping(false); typingSent = false; }, 1600);
  });

  const poll = async () => {
    try {
      const response = await fetch(root.dataset.pollUrl + '?after=' + lastId, {headers:{'Accept':'application/json'},credentials:'same-origin'});
      if (!response.ok) return;
      const data = await response.json();
      (data.messages || []).forEach(append);
      const presence = document.getElementById('fm-presence');
      if (presence) {
        const typingNames = (data.presence || []).filter((p) => p.typing).map((p) => p.name);
        const onlineCount = (data.presence || []).filter((p) => p.online).length;
        presence.textContent = typingNames.length ? '• ' + typingNames.join('، ') + ' در حال نوشتن…' : '• ' + (onlineCount ? onlineCount + ' نفر آنلاین' : 'فعلاً آفلاین');
      }
    } catch (_) {}
  };
  list.scrollTop = list.scrollHeight;
  window.setInterval(poll, 4000);
})();
