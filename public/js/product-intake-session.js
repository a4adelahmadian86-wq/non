(() => {
  const root = document.querySelector('[data-product-intake]');
  if (!root || root.dataset.ready === '1') return;
  root.dataset.ready = '1';

  const fileInput = document.getElementById('intake-file-input');
  const folderInput = document.getElementById('intake-folder-input');
  const dropzone = document.getElementById('intake-dropzone');
  const list = document.getElementById('intake-file-list');
  const progress = document.getElementById('intake-progress');
  const count = document.getElementById('intake-file-count');
  const total = document.getElementById('intake-total-size');
  const folders = document.getElementById('intake-folder-count');
  const continueButton = document.getElementById('intake-continue');
  const continueNote = document.getElementById('intake-continue-note');
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
  const rows = new Map();
  let totalBytes = 0;
  let folderNames = new Set();
  try {
    const existing = JSON.parse(root.dataset.existingFiles || '[]');
    existing.forEach(file => {
      rows.set(file.id, { uploaded: file.status === 'uploaded' || file.status === 'attached', isPrimary: !!file.is_primary, supervisorStatus: file.supervisor_status, securityGate: file.security_gate });
      totalBytes += Number(file.size_bytes || 0);
      const directory = (file.relative_path || '').split('/').slice(0, -1).join('/');
      if (directory) { const parts = directory.split('/'); for (let depth = 1; depth <= parts.length; depth++) folderNames.add(parts.slice(0, depth).join('/')); }
      if (file.status === 'uploaded' && ['pending', 'processing'].includes(file.supervisor_status)) {
        const card = Array.from(list.children).find(node => node.textContent.includes(file.relative_path || file.name));
        if (card) {
          const status = document.createElement('div');
          status.textContent = 'ناظر هوشمند در حال بررسی فایل است…';
          card.append(status);
          setTimeout(() => pollStatus(file.id, status, rows.get(file.id)), 0);
        }
      }
    });
  } catch (_) {}

  const formatBytes = value => {
    if (value < 1024) return value + ' بایت';
    const units = ['کیلوبایت', 'مگابایت', 'گیگابایت', 'ترابایت'];
    let size = value, index = -1;
    do { size /= 1024; index++; } while (size >= 1024 && index < units.length - 1);
    return size.toFixed(1) + ' ' + units[index];
  };
  const refreshSummary = () => {
    count.textContent = String(rows.size);
    total.textContent = formatBytes(totalBytes);
    folders.textContent = String(folderNames.size);
    const allReady = rows.size > 0 && Array.from(rows.values()).every(row =>
      row.uploaded && ['completed'].includes(row.supervisorStatus) && row.securityGate !== 'blocked'
    );
    continueButton.disabled = !allReady;
    continueNote.textContent = allReady
      ? 'بازرسی فایل‌ها و بررسی ناظر هوشمند تکمیل شده است.'
      : 'ادامه پس از انتقال همه فایل‌ها و تکمیل بررسی ناظر هوشمند فعال می‌شود.';
  };
  const makeCard = (file, relativePath, report) => {
    const card = document.createElement('article');
    card.className = 'store-panel';
    card.style.cssText = 'padding:14px;display:grid;gap:8px';
    const title = document.createElement('strong');
    title.textContent = relativePath || file.name;
    const meta = document.createElement('div');
    meta.textContent = formatBytes(file.size) + ' · ' + (report.mime || file.type || 'نوع نامشخص');
    const details = document.createElement('pre');
    details.style.cssText = 'white-space:pre-wrap;overflow-wrap:anywhere;font:inherit;font-size:13px;background:var(--surface-secondary,#f5f5f5);padding:10px;border-radius:8px';
    const summary = [
      'SHA-256: ' + (report.checksum || '—'),
      'نوع تشخیص‌داده‌شده: ' + (report.detected_type || 'نامشخص'),
      'تعداد اقلام آرشیو: ' + (report.archive_entry_count ?? 0),
      'سطح ریسک: ' + (report.risk_level || 'نامشخص'),
      'بررسی ایستا: ' + (report.scan_label || report.scan || 'انجام شد'),
      'آنتی‌ویروس: ' + (report.antivirus?.status === 'clean' ? 'پاک' : report.antivirus?.status === 'not_requested' ? 'درخواست نشده' : report.antivirus?.status || 'نامشخص'),
      'هشدارها: ' + ((report.warnings || []).join('، ') || 'ندارد'),
      'یافته‌ها: ' + ((report.findings || []).join('، ') || 'ندارد')
    ];
    details.textContent = summary.join('\n');
    const status = document.createElement('div');
    status.textContent = 'بازرسی ساختاری انجام شد؛ منتظر تأیید شما';
    const confirm = document.createElement('button');
    confirm.type = 'button';
    confirm.className = 'store-primary-btn';
    confirm.textContent = 'تأیید گزارش و انتقال به ذخیره‌سازی';
    confirm.addEventListener('click', async () => {
      confirm.disabled = true;
      status.textContent = 'در حال انتقال امن فایل…';
      try {
        const url = root.dataset.confirmUrl.replace('__FILE__', String(report.file_id));
        const response = await fetch(url, { method: 'POST', headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' } });
        const payload = await response.json();
        if (!response.ok) throw new Error(payload.message || payload.code || 'انتقال فایل ناموفق بود');
        const row = rows.get(report.file_id);
        row.uploaded = true;
        row.supervisorStatus = payload.supervisor_status || 'pending';
        status.textContent = 'فایل ذخیره شد؛ بررسی ناظر هوشمند در حال انجام است.';
        confirm.remove();
        await pollStatus(report.file_id, status, row);
      } catch (error) {
        status.textContent = error.message;
        confirm.disabled = false;
      }
      refreshSummary();
    });
    card.append(title, meta, details, status, confirm);
    list.prepend(card);
    return card;
  };
  const pollStatus = async (id, status, row) => {
    const url = root.dataset.statusUrl.replace('__FILE__', String(id));
    for (let attempt = 0; attempt < 120; attempt++) {
      await new Promise(resolve => setTimeout(resolve, 5000));
      try {
        const response = await fetch(url, { headers: { 'Accept': 'application/json' }, cache: 'no-store' });
        if (!response.ok) throw new Error('وضعیت بررسی دریافت نشد');
        const data = await response.json();
        row.supervisorStatus = data.supervisor_status;
        row.securityGate = data.security_gate;
        if (data.supervisor_status === 'completed') {
          status.textContent = data.security_gate === 'manual_scan_required'
            ? 'بررسی هوشمند انجام شد؛ برای تأیید امنیتی به بررسی مدیر نیاز است.'
            : data.security_gate === 'blocked'
              ? 'ناظر هوشمند فایل را مسدود کرد؛ این فایل قابل ادامه نیست.'
              : 'بررسی ناظر هوشمند تکمیل شد.';
          break;
        }
        if (['failed', 'awaiting_provider', 'blocked'].includes(data.supervisor_status)) {
          status.textContent = data.supervisor_status === 'awaiting_provider'
            ? 'حجم فایل از سقف تحلیل فعلی بیشتر است؛ بررسی کامل هنوز انجام نشده است.'
            : data.supervisor_status === 'blocked'
              ? 'فایل توسط ناظر هوشمند مسدود شد.'
              : 'بررسی ناظر هوشمند ناموفق بود؛ فایل تأییدشده محسوب نمی‌شود.';
          break;
        }
        status.textContent = 'ناظر هوشمند در حال بررسی فایل است…';
      } catch (error) {
        status.textContent = error.message;
      }
    }
    refreshSummary();
  };
  const confirmExisting = async (id, button) => {
    button.disabled = true;
    const card = button.closest('article');
    const status = card?.querySelector('[data-intake-existing-status]') || document.createElement('div');
    status.textContent = 'در حال انتقال امن فایل…';
    if (!status.parentNode) card?.append(status);
    try {
      const response = await fetch(root.dataset.confirmUrl.replace('__FILE__', String(id)), {
        method: 'POST', headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' }
      });
      const payload = await response.json();
      if (!response.ok) throw new Error(payload.message || payload.code || 'انتقال فایل ناموفق بود');
      const row = rows.get(id) || { uploaded: true, supervisorStatus: payload.supervisor_status || 'pending', securityGate: null };
      row.uploaded = true;
      row.supervisorStatus = payload.supervisor_status || 'pending';
      rows.set(id, row);
      status.textContent = 'فایل ذخیره شد؛ بررسی ناظر هوشمند در حال انجام است.';
      button.remove();
      await pollStatus(id, status, row);
    } catch (error) {
      status.textContent = error.message;
      button.disabled = false;
    }
    refreshSummary();
  };
  document.querySelectorAll('[data-intake-confirm-existing]').forEach(button => {
    button.addEventListener('click', () => confirmExisting(Number(button.dataset.intakeConfirmExisting), button));
  });

  const preflight = async (item, index) => {
    const form = new FormData();
    form.append('file', item.file);
    form.append('relative_path', item.relativePath || item.file.name);
    const hasPrimary = Array.from(rows.values()).some(row => row.isPrimary);
    form.append('is_primary', hasPrimary ? '0' : '1');
    const response = await fetch(root.dataset.preflightUrl, {
      method: 'POST', headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' }, body: form
    });
    const payload = await response.json();
    if (!response.ok) throw new Error((item.relativePath || item.file.name) + ': ' + (payload.message || payload.code || 'بازرسی ناموفق'));
    const id = payload.file_id;
    totalBytes += item.file.size;
    const directory = (item.relativePath || '').split('/').slice(0, -1).join('/');
    if (directory) { const parts = directory.split('/'); for (let depth = 1; depth <= parts.length; depth++) folderNames.add(parts.slice(0, depth).join('/')); }
    const row = { uploaded: false, isPrimary: !!payload.file.is_primary, supervisorStatus: 'pending', securityGate: null };
    rows.set(id, row);
    makeCard(item.file, item.relativePath, { ...payload.inspection, file_id: id });
    refreshSummary();
  };

  const addFiles = async items => {
    if (!items.length) return;
    progress.textContent = 'در حال بازرسی ' + items.length + ' فایل…';
    for (let index = 0; index < items.length; index++) {
      try { await preflight(items[index], index); }
      catch (error) {
        const line = document.createElement('p');
        line.textContent = error.message;
        line.setAttribute('role', 'alert');
        progress.append(line);
      }
    }
    progress.textContent = 'بازرسی اولیه انتخاب‌ها پایان یافت. گزارش هر فایل را بررسی و جداگانه تأیید کنید.';
  };

  const walkEntry = async (entry, prefix = '') => {
    if (entry.isFile) {
      const file = await new Promise((resolve, reject) => entry.file(resolve, reject));
      return [{ file, relativePath: prefix + file.name }];
    }
    if (!entry.isDirectory) return [];
    const reader = entry.createReader();
    const children = [];
    while (true) {
      const batch = await new Promise((resolve, reject) => reader.readEntries(resolve, reject));
      if (!batch.length) break;
      children.push(...batch);
    }
    const nested = await Promise.all(children.map(child => walkEntry(child, prefix + entry.name + '/')));
    return nested.flat();
  };

  fileInput?.addEventListener('change', () => addFiles(Array.from(fileInput.files || []).map(file => ({ file, relativePath: file.name }))));
  folderInput?.addEventListener('change', () => addFiles(Array.from(folderInput.files || []).map(file => ({ file, relativePath: file.webkitRelativePath || file.name }))));
  dropzone?.addEventListener('click', event => { if (event.target === dropzone) fileInput?.click(); });
  dropzone?.addEventListener('keydown', event => { if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); fileInput?.click(); } });
  dropzone?.addEventListener('dragover', event => { event.preventDefault(); dropzone.classList.add('is-dragging'); });
  dropzone?.addEventListener('dragleave', () => dropzone.classList.remove('is-dragging'));
  dropzone?.addEventListener('drop', async event => {
    event.preventDefault();
    dropzone.classList.remove('is-dragging');
    const items = Array.from(event.dataTransfer?.items || []);
    const entries = items.map(item => item.webkitGetAsEntry?.()).filter(Boolean);
    if (entries.length) {
      const nested = await Promise.all(entries.map(entry => walkEntry(entry)));
      await addFiles(nested.flat());
    } else {
      await addFiles(Array.from(event.dataTransfer?.files || []).map(file => ({ file, relativePath: file.name })));
    }
  });
  continueButton.addEventListener('click', () => { if (!continueButton.disabled) window.location.assign(root.dataset.detailsUrl); });
  refreshSummary();
})();
