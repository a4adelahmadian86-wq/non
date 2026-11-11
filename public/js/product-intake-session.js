(() => {
  const root = document.querySelector('[data-product-intake]');
  if (!root) return;

  const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
  const typeInputs = [...root.querySelectorAll('[data-intake-type]')];
  const startButton = root.querySelector('[data-intake-start]');
  const message = root.querySelector('[data-intake-message]');
  const uploadPanel = root.querySelector('[data-intake-upload]');
  const reviewPanel = root.querySelector('[data-intake-review]');
  const nextPanel = root.querySelector('[data-intake-next]');
  const filePicker = root.querySelector('[data-intake-file-picker]');
  const folderPicker = root.querySelector('[data-intake-folder-picker]');
  const processButton = root.querySelector('[data-intake-process]');
  const clearButton = root.querySelector('[data-intake-clear]');
  const fileList = root.querySelector('[data-intake-file-list]');
  const reviewList = root.querySelector('[data-intake-review-list]');
  const modal = document.querySelector('[data-intake-file-modal]');
  const modalTitle = modal.querySelector('[data-intake-file-title]');
  const modalReport = modal.querySelector('[data-intake-file-report]');
  const modalConfirm = modal.querySelector('[data-intake-file-confirm]');
  const modalReject = modal.querySelector('[data-intake-file-reject]');
  const state = { sessionId: null, type: null, files: [], primaryIndex: 0, processing: false, activeIngestionId: null };

  const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (ch) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;',
  }[ch]));
  const bytes = (value) => {
    const n = Number(value || 0);
    if (n < 1024) return n.toFixed(0) + ' بایت';
    if (n < 1048576) return (n / 1024).toFixed(1) + ' کیلوبایت';
    if (n < 1073741824) return (n / 1048576).toFixed(2) + ' مگابایت';
    return (n / 1073741824).toFixed(2) + ' گیگابایت';
  };
  const url = (template, token, value) => template.replace(token, encodeURIComponent(value));
  const selectedType = () => typeInputs.find((input) => input.checked)?.value || 'digital_file';
  const request = async (target, options = {}) => {
    const response = await fetch(target, {
      credentials: 'same-origin',
      cache: 'no-store',
      ...options,
      headers: {
        'Accept': 'application/json',
        ...(options.body instanceof FormData ? {} : { 'Content-Type': 'application/json' }),
        'X-CSRF-TOKEN': csrf,
        ...(options.headers || {}),
      },
    });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(payload.message || 'درخواست انجام نشد.');
    return payload;
  };

  const renderSelected = () => {
    const total = state.files.reduce((sum, file) => sum + file.size, 0);
    const dirs = new Set(state.files.map(file => (file.webkitRelativePath || '').split('/').slice(0, -1).join('/')).filter(Boolean));
    const archiveCount = state.files.filter(file => /\.(zip|tar|gz|bz2)$/i.test(file.name)).length;
    root.querySelector('[data-stat-files]').textContent = String(state.files.length);
    root.querySelector('[data-stat-folders]').textContent = String(dirs.size);
    root.querySelector('[data-stat-archives]').textContent = String(archiveCount);
    root.querySelector('[data-stat-size]').textContent = bytes(total);
    fileList.innerHTML = state.files.map((file, index) => (
      '<div><span>' + escapeHtml(file.webkitRelativePath || file.name) + '</span><small>' + bytes(file.size) +
      (state.type === 'digital_file' ? (index === state.primaryIndex ? ' · فایل اصلی' : '') : '') + '</small>' +
      (state.type === 'digital_file' ? '<label><input type="radio" name="intake-primary" data-primary-index="' + index + '"' +
        (index === state.primaryIndex ? ' checked' : '') + '> فایل اصلی</label>' : '') + '</div>'
    )).join('');
    processButton.disabled = state.files.length === 0 || state.processing;
  };

  const addFiles = (files) => {
    const incoming = [...files].filter(file => file && file.size > 0);
    const known = new Set(state.files.map(file => (file.webkitRelativePath || file.name) + ':' + file.size + ':' + file.lastModified));
    for (const file of incoming) {
      const key = (file.webkitRelativePath || file.name) + ':' + file.size + ':' + file.lastModified;
      if (!known.has(key)) { state.files.push(file); known.add(key); }
    }
    renderSelected();
  };

  const showInspection = (file, payload) => new Promise((resolve) => {
    const inspection = payload.inspection || {};
    const details = [
      ['نوع شناسایی‌شده', inspection.detected_type || 'نامشخص'],
      ['حجم واقعی', bytes(payload.file?.size_bytes || file.size)],
      ['نوع MIME', inspection.mime || payload.file?.mime || 'نامشخص'],
      ['تعداد اعضای آرشیو', inspection.archive_entry_count ?? 0],
      ['حجم بازشده آرشیو', inspection.archive_expanded_bytes ? bytes(inspection.archive_expanded_bytes) : '—'],
      ['سطح ریسک ساختاری', inspection.risk_level || 'نامشخص'],
      ['SHA-256', payload.file?.sha256 || '—'],
      ['موتور بررسی', inspection.scan_label || 'بررسی ساختاری داخلی فراست'],
    ];
    modalTitle.textContent = file.webkitRelativePath || file.name;
    modalReport.innerHTML = '<div class="product-ingestion-report-grid">' + details.map(([label, value]) =>
      '<div><small>' + escapeHtml(label) + '</small><b>' + escapeHtml(value) + '</b></div>'
    ).join('') + '</div>' +
      (inspection.safe ? '<p>فایل از بررسی ساختاری عبور کرد. این نتیجه به‌معنی اسکن آنتی‌ویروس کامل نیست.</p>' : '<p class="product-ingestion-warning">فایل از بررسی ساختاری عبور نکرده و ذخیره نمی‌شود.</p>') +
      (Array.isArray(inspection.findings) ? inspection.findings.map(item => '<div class="product-ingestion-warning">' + escapeHtml(item) + '</div>').join('') : '') +
      (Array.isArray(inspection.warnings) ? inspection.warnings.map(item => '<div class="product-ingestion-warning">' + escapeHtml(item) + '</div>').join('') : '');
    modal.hidden = false;
    modalConfirm.disabled = !inspection.safe;
    modalConfirm.textContent = 'تأیید و انتقال به فضای مدیریت‌شده';
    modalReject.textContent = 'رد فایل';
    const finish = (confirmed) => {
      modalConfirm.removeEventListener('click', onConfirm);
      modalReject.removeEventListener('click', onReject);
      modal.hidden = true;
      resolve(confirmed);
    };
    const onConfirm = () => finish(true);
    const onReject = () => finish(false);
    modalConfirm.addEventListener('click', onConfirm);
    modalReject.addEventListener('click', onReject);
  });

  const preflightOne = async (file, index) => {
    const form = new FormData();
    form.append('file', file, file.name);
    form.append('is_primary', state.type === 'digital_file' && index === state.primaryIndex ? '1' : '0');
    const target = url(root.dataset.preflightUrlTemplate, '__SESSION__', state.sessionId);
    const preflight = await request(target, { method: 'POST', body: form });
    if (!preflight.inspection?.safe) throw new Error('فایل ' + file.name + ' از بررسی ساختاری عبور نکرد.');
    const accepted = await showInspection(file, preflight);
    if (!accepted) {
      const cancelTarget = url(url(root.dataset.cancelUrlTemplate, '__SESSION__', state.sessionId), '__INGESTION__', preflight.ingestion_id);
      await request(cancelTarget, { method: 'DELETE' });
      throw new Error('فایل ' + file.name + ' توسط کاربر رد شد.');
    }
    const confirmTarget = url(url(root.dataset.confirmUrlTemplate, '__SESSION__', state.sessionId), '__INGESTION__', preflight.ingestion_id);
    await request(confirmTarget, { method: 'POST' });
  };

  const renderProgress = (payload) => {
    const session = payload.session || {};
    const progress = payload.progress || {};
    root.querySelector('[data-intake-session-status]').textContent = session.status || 'در حال بررسی';
    root.querySelector('[data-progress-uploaded]').textContent = String(progress.uploaded_count || 0);
    root.querySelector('[data-progress-reviewed]').textContent = String(progress.review_completed_count || 0);
    root.querySelector('[data-progress-pending]').textContent = String(progress.review_pending_count || 0);
    root.querySelector('[data-progress-attention]').textContent = String(progress.review_blocked_count || 0);
    reviewList.innerHTML = (progress.files || []).map(file =>
      '<div><span>' + escapeHtml(file.name) + '</span><small>' + bytes(file.size_bytes) + ' · ' +
      escapeHtml(file.supervisor_status || file.status) + '</small>' +
      (state.type === 'digital_file'
        ? '<label><input type="radio" name="intake-primary-existing" data-primary-ingestion="' + escapeHtml(file.id) + '"' +
          (file.is_primary ? ' checked' : '') + '> فایل اصلی</label>'
        : '') + '</div>'
    ).join('');
    const allDone = progress.file_count > 0
      && progress.uploaded_count === progress.file_count
      && progress.review_completed_count === progress.file_count
      && session.status === 'files_reviewed';
    nextPanel.hidden = !allDone;
    root.querySelector('[data-intake-attention]').hidden = !(progress.review_blocked_count > 0);
    if (allDone) {
      root.querySelector('[data-intake-details]').href =
        url(root.dataset.detailsUrlTemplate, '__SESSION__', state.sessionId);
      message.textContent = 'بازبینی همه فایل‌ها کامل شد. اکنون می‌توانید مشخصات واقعی محصول را وارد کنید.';
    }
  };

  reviewList.addEventListener('change', async (event) => {
    const input = event.target.closest('[data-primary-ingestion]');
    if (!input || !input.checked || !state.sessionId) return;

    input.disabled = true;
    try {
      const target = url(
        url(root.dataset.primaryUrlTemplate, '__SESSION__', state.sessionId),
        '__INGESTION__',
        input.dataset.primaryIngestion
      );
      await request(target, { method: 'POST' });
      message.textContent = 'فایل اصلی محصول به‌روزرسانی شد.';
      await pollProgress();
    } catch (error) {
      message.textContent = error.message || 'تغییر فایل اصلی انجام نشد.';
      await pollProgress();
    } finally {
      input.disabled = false;
    }
  });

  const pollProgress = async () => {
    if (!state.sessionId) return;
    const target = url(root.dataset.progressUrlTemplate, '__SESSION__', state.sessionId);
    try {
      const payload = await request(target);
      renderProgress(payload);
      const status = payload.session?.status;
      if (!['files_reviewed', 'review_failed', 'submitted'].includes(status)) {
        window.setTimeout(pollProgress, status === 'review_attention' ? 15000 : 3000);
      }
    } catch (error) {
      message.textContent = error.message || 'دریافت وضعیت فرایند ممکن نشد.';
    }
  };

  startButton.addEventListener('click', async () => {
    startButton.disabled = true;
    try {
      state.type = selectedType();
      const payload = await request(root.dataset.startUrl, {
        method: 'POST',
        body: JSON.stringify({ product_type: state.type }),
      });
      state.sessionId = payload.intake_session_id;
      uploadPanel.hidden = false;
      reviewPanel.hidden = false;
      root.querySelector('[data-intake-type-label]').textContent = state.type === 'course' ? 'دوره آموزشی' : 'فایل دیجیتال';
      message.textContent = 'فرایند خصوصی ایجاد شد. فایل‌ها را انتخاب کنید.';
      uploadPanel.scrollIntoView({ behavior: 'smooth', block: 'start' });
      pollProgress();
    } catch (error) {
      message.textContent = error.message || 'شروع فرایند ممکن نشد.';
      startButton.disabled = false;
    }
  });

  filePicker.addEventListener('change', () => addFiles(filePicker.files));
  folderPicker.addEventListener('change', () => addFiles(folderPicker.files));
  fileList.addEventListener('change', (event) => {
    const radio = event.target.closest('[data-primary-index]');
    if (!radio) return;
    state.primaryIndex = Number(radio.dataset.primaryIndex);
    renderSelected();
  });
  clearButton.addEventListener('click', () => {
    state.files = [];
    state.primaryIndex = 0;
    filePicker.value = '';
    folderPicker.value = '';
    renderSelected();
  });

  processButton.addEventListener('click', async () => {
    if (state.processing || state.files.length === 0) return;
    state.processing = true;
    processButton.disabled = true;
    startButton.disabled = true;
    reviewPanel.hidden = false;
    try {
      for (let i = 0; i < state.files.length; i++) {
        message.textContent = 'در حال بررسی فایل ' + (i + 1) + ' از ' + state.files.length + ': ' + state.files[i].name;
        await preflightOne(state.files[i], i);
        await pollProgress();
      }
      message.textContent = 'انتقال فایل‌ها تمام شد؛ در انتظار تکمیل بازبینی ناظر هستیم.';
      pollProgress();
    } catch (error) {
      message.textContent = error.message || 'فرایند بررسی فایل متوقف شد.';
    } finally {
      state.processing = false;
      processButton.disabled = state.files.length === 0;
    }
  });

  renderSelected();
})();
