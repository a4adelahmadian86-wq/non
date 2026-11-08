(() => {
  'use strict';

  const root = document.querySelector('[data-product-ingestion]');
  if (!root) return;

  const input = root.querySelector('[data-ingestion-input]');
  const pick = root.querySelector('[data-ingestion-pick]');
  const overlay = root.querySelector('[data-ingestion-global]');
  const modal = root.querySelector('[data-ingestion-modal]');
  const report = root.querySelector('[data-ingestion-report]');
  const filename = root.querySelector('[data-ingestion-filename]');
  const confirmButton = root.querySelector('[data-ingestion-confirm]');
  const cancelButton = root.querySelector('[data-ingestion-cancel]');
  const closeButton = root.querySelector('[data-ingestion-close]');
  const primaryInput = root.querySelector('[data-ingestion-primary]');
  const csrf = root.querySelector('[data-ingestion-csrf]').value;
  const queueView = root.querySelector('[data-ingestion-queue]');

  const state = {
    queue: [],
    current: null,
    ingestionId: null,
    primaryForCurrent: false,
    primaryCommitted: false,
    busy: false,
    dragDepth: 0,
  };

  const labels = {
    pdf: 'سند PDF', archive: 'فایل فشرده', office: 'سند آفیس',
    image: 'تصویر', audio: 'فایل صوتی', video: 'ویدئو', text: 'فایل متنی',
  };

  const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;',
  }[char]));

  const formatBytes = (bytes) => {
    const value = Number(bytes || 0);
    if (value < 1024) return value + ' بایت';
    if (value < 1024 * 1024) return (value / 1024).toFixed(1) + ' کیلوبایت';
    if (value < 1024 * 1024 * 1024) return (value / 1024 / 1024).toFixed(2) + ' مگابایت';
    return (value / 1024 / 1024 / 1024).toFixed(2) + ' گیگابایت';
  };

  const setStep = (active, done = []) => {
    root.querySelectorAll('[data-step]').forEach((element) => {
      const name = element.dataset.step;
      element.classList.toggle('is-active', name === active);
      element.classList.toggle('is-done', done.includes(name));
    });
  };

  const openModal = (file) => {
    state.current = file;
    state.ingestionId = null;
    filename.textContent = file.name;
    report.innerHTML = '<p>فایل در قرنطینه خصوصی دریافت شد. بررسی ساختار و نشانه‌های خطر در حال انجام است...</p>';
    confirmButton.disabled = true;
    confirmButton.textContent = 'تأیید و ذخیره فایل';
    cancelButton.disabled = false;
    closeButton.disabled = false;
    setStep('inspect', ['received']);
    modal.hidden = false;
  };

  const closeModal = () => {
    modal.hidden = true;
    state.current = null;
    state.ingestionId = null;
    state.busy = false;
  };

  const showError = (message) => {
    report.innerHTML = '<div class="product-ingestion-warning">' + escapeHtml(message) + '</div>';
    confirmButton.disabled = true;
    setStep(null, ['received']);
  };

  const renderReport = (payload) => {
    const inspection = payload.inspection || {};
    const file = payload.file || {};
    const rows = [
      ['نوع شناسایی‌شده', labels[inspection.detected_type] || inspection.detected_type || 'نامشخص'],
      ['حجم واقعی', formatBytes(file.size_bytes)],
      ['نوع MIME', inspection.mime || file.mime || 'نامشخص'],
      ['تعداد صفحات PDF', inspection.page_count ?? 'در این فرمت قابل تعیین نیست'],
      ['ابعاد تصویر', inspection.dimensions ? inspection.dimensions.width + ' × ' + inspection.dimensions.height : '—'],
      ['تعداد اعضای آرشیو', inspection.archive_entry_count ?? 0],
      ['حجم بازشده آرشیو', inspection.archive_expanded_bytes ? formatBytes(inspection.archive_expanded_bytes) : '—'],
      ['SHA-256', file.sha256 || '—'],
      ['موتور بررسی', inspection.scan_label || 'بررسی ایستای داخلی فراست'],
    ];
    const grid = rows.map(([label, value]) =>
      '<div><small>' + escapeHtml(label) + '</small><b>' + escapeHtml(value) + '</b></div>'
    ).join('');

    const entries = Array.isArray(inspection.archive_entries) ? inspection.archive_entries : [];
    const archive = entries.length
      ? '<h4>محتویات شناسایی‌شده آرشیو</h4><ol class="product-ingestion-archive">' +
        entries.map((entry) => '<li>' + escapeHtml(entry.name) + ' · ' + formatBytes(entry.size_bytes) + '</li>').join('') +
        '</ol>' + (inspection.archive_entry_count > entries.length ? '<p>فقط ' + entries.length + ' مورد نخست از ' + inspection.archive_entry_count + ' مورد نمایش داده شده است.</p>' : '')
      : '';

    const findings = Array.isArray(inspection.findings) ? inspection.findings : [];
    const warnings = Array.isArray(inspection.warnings) ? inspection.warnings : [];
    const notes = [...findings, ...warnings].map((item) =>
      '<div class="product-ingestion-warning">' + escapeHtml(item) + '</div>'
    ).join('');

    report.innerHTML =
      '<h4>خلاصه بررسی سایت، بدون استفاده از هوش مصنوعی</h4>' +
      '<div class="product-ingestion-report-grid">' + grid + '</div>' + archive + notes +
      '<p><strong>نتیجه بررسی:</strong> ' + (inspection.safe ? 'ساختار فایل از کنترل‌های ایستای داخلی عبور کرده است.' : 'فایل برای ذخیره‌سازی پذیرفته نشده است.') + '</p>';
  };

  const processNext = async () => {
    if (state.busy || state.current || state.queue.length === 0) {
      updateQueue();
      return;
    }
    const file = state.queue.shift();
    updateQueue();
    await preflight(file);
  };

  const updateQueue = () => {
    const pending = state.queue.map((file) =>
      '<div><span>' + escapeHtml(file.name) + '</span><small>' + formatBytes(file.size) + ' · در صف بررسی</small></div>'
    ).join('');
    queueView.innerHTML = pending;
  };

  const preflight = async (file) => {
    if (file.size > 50 * 1024 * 1024) {
      report.textContent = '';
      openModal(file);
      showError('حجم فایل از سقف ۵۰ مگابایت بیشتر است.');
      return;
    }

    openModal(file);
    state.busy = true;
    const form = new FormData();
    form.append('file', file, file.name);
    state.primaryForCurrent = !!primaryInput.checked && !state.primaryCommitted;
    form.append('is_primary', state.primaryForCurrent ? '1' : '0');

    try {
      const response = await fetch(root.dataset.preflightUrl, {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
        body: form,
        credentials: 'same-origin',
      });
      const payload = await response.json().catch(() => ({}));
      if (!response.ok) throw new Error(payload.message || 'بررسی اولیه فایل انجام نشد.');
      state.ingestionId = payload.ingestion_id;
      renderReport(payload);
      setStep('review', ['received', 'inspect']);
      confirmButton.disabled = !payload.inspection?.safe;
      if (!payload.inspection?.safe) {
        showError('این فایل از بررسی ایستای داخلی عبور نکرد و ذخیره نخواهد شد.');
      }
    } catch (error) {
      showError(error.message || 'ارتباط با سرور برای بررسی فایل برقرار نشد.');
    } finally {
      state.busy = false;
    }
  };

  const cancelCurrent = async () => {
    if (state.busy) return;
    state.busy = true;
    try {
      if (state.ingestionId) {
        const url = root.dataset.cancelUrlTemplate.replace('__INGESTION__', encodeURIComponent(state.ingestionId));
        await fetch(url, {
          method: 'DELETE',
          headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
          credentials: 'same-origin',
        });
      }
    } finally {
      closeModal();
      processNext();
    }
  };

  const confirmCurrent = async () => {
    if (state.busy || !state.ingestionId) return;
    state.busy = true;
    confirmButton.disabled = true;
    cancelButton.disabled = true;
    closeButton.disabled = true;
    confirmButton.textContent = 'در حال ذخیره‌سازی...';
    setStep('store', ['received', 'inspect', 'review']);

    try {
      const url = root.dataset.confirmUrlTemplate.replace('__INGESTION__', encodeURIComponent(state.ingestionId));
      const response = await fetch(url, {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
        credentials: 'same-origin',
      });
      const payload = await response.json().catch(() => ({}));
      if (!response.ok) throw new Error(payload.message || 'ذخیره‌سازی فایل انجام نشد.');
      setStep(null, ['received', 'inspect', 'review', 'store']);
      report.insertAdjacentHTML('beforeend', '<div class="product-ingestion-warning" style="background:#eaf7ef;color:#236b43">فایل با موفقیت ذخیره شد. پردازش محتوایی آن در صف آماده‌سازی قرار گرفت.</div>');
      if (state.primaryForCurrent) state.primaryCommitted = true;
      state.busy = false;
      confirmButton.textContent = 'ذخیره شد';
      window.setTimeout(() => {
        closeModal();
        if (state.queue.length) processNext();
        else window.location.reload();
      }, 850);
    } catch (error) {
      state.busy = false;
      cancelButton.disabled = false;
      closeButton.disabled = false;
      showError(error.message || 'ذخیره‌سازی فایل با خطا مواجه شد.');
    }
  };

  const enqueue = (files) => {
    const valid = Array.from(files || []).filter((file) => file && file.size > 0);
    if (!valid.length) return;
    state.queue.push(...valid);
    updateQueue();
    processNext();
  };

  pick.addEventListener('click', () => input.click());
  pick.addEventListener('keydown', (event) => {
    if (event.key === 'Enter' || event.key === ' ') {
      event.preventDefault();
      input.click();
    }
  });
  input.addEventListener('change', () => {
    enqueue(input.files);
    input.value = '';
  });

  confirmButton.addEventListener('click', confirmCurrent);
  cancelButton.addEventListener('click', cancelCurrent);
  closeButton.addEventListener('click', cancelCurrent);

  document.addEventListener('dragenter', (event) => {
    if (!event.dataTransfer || !Array.from(event.dataTransfer.types || []).includes('Files')) return;
    event.preventDefault();
    state.dragDepth += 1;
    overlay.hidden = false;
  });
  document.addEventListener('dragover', (event) => {
    if (event.dataTransfer && Array.from(event.dataTransfer.types || []).includes('Files')) {
      event.preventDefault();
      event.dataTransfer.dropEffect = 'copy';
    }
  });
  document.addEventListener('dragleave', (event) => {
    if (!event.dataTransfer || !Array.from(event.dataTransfer.types || []).includes('Files')) return;
    state.dragDepth = Math.max(0, state.dragDepth - 1);
    if (state.dragDepth === 0) overlay.hidden = true;
  });
  document.addEventListener('drop', (event) => {
    if (!event.dataTransfer || !event.dataTransfer.files?.length) return;
    event.preventDefault();
    state.dragDepth = 0;
    overlay.hidden = true;
    enqueue(event.dataTransfer.files);
  });
  window.addEventListener('dragend', () => {
    state.dragDepth = 0;
    overlay.hidden = true;
  });
})();
