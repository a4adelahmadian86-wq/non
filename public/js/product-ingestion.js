(() => {
  'use strict';

  const root = document.querySelector('[data-product-ingestion]');
  if (!root) return;

  const input = root.querySelector('[data-ingestion-input]');
  const folderInput = root.querySelector('[data-ingestion-folder]');
  const folderPick = root.querySelector('[data-ingestion-folder-pick]');
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
  const selectedView = root.querySelector('[data-ingestion-selected]');
  const progress = root.querySelector('[data-ingestion-progress]');
  const progressBar = root.querySelector('[data-ingestion-progress-bar]');
  const progressLabel = root.querySelector('[data-ingestion-progress-label]');

  const state = {
    queue: [],
    rejected: [],
    current: null,
    ingestionId: null,
    primaryForCurrent: false,
    primaryCommitted: false,
    busy: false,
    dragDepth: 0,
    archiveEntries: [],
  };

  const labels = {
    pdf: 'سند PDF', archive: 'فایل فشرده', zip: 'آرشیو ZIP', tar: 'آرشیو TAR', office: 'سند آفیس',
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

  const setProgress = (percent, label, visible = true) => {
    if (!progress || !progressBar || !progressLabel) return;
    progress.hidden = !visible;
    progressBar.style.width = Math.max(0, Math.min(100, Number(percent || 0))) + '%';
    progressLabel.textContent = label || '';
  };

  const sendPreflight = (url, form, onProgress) => new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest();
    xhr.open('POST', url, true);
    xhr.withCredentials = true;
    xhr.setRequestHeader('X-CSRF-TOKEN', csrf);
    xhr.setRequestHeader('Accept', 'application/json');
    xhr.upload.addEventListener('progress', (event) => {
      if (event.lengthComputable) onProgress(Math.round((event.loaded / event.total) * 100), event.loaded >= event.total);
    });
    xhr.addEventListener('load', () => {
      let payload = {};
      try { payload = JSON.parse(xhr.responseText || '{}'); } catch (_) {}
      if (xhr.status < 200 || xhr.status >= 300) {
        reject(new Error(payload.message || 'بررسی اولیه فایل انجام نشد.'));
        return;
      }
      resolve(payload);
    });
    xhr.addEventListener('error', () => reject(new Error('ارتباط با سرور برای بارگذاری فایل برقرار نشد.')));
    xhr.addEventListener('abort', () => reject(new Error('بارگذاری فایل لغو شد.')));
    xhr.send(form);
  });

  const openModal = (file) => {
    state.current = file;
    state.ingestionId = null;
    state.archiveEntries = [];
    filename.textContent = file.webkitRelativePath || file.name;
    setProgress(0, '', false);
    report.innerHTML = '<p>فایل انتخاب شد. پیش از انتقال به قرنطینه، فهرست و مشخصات اولیه را بررسی کنید؛ هنوز چیزی آپلود یا ذخیره نشده است.</p>';
    confirmButton.disabled = false;
    confirmButton.textContent = 'بررسی امن فایل';
    cancelButton.disabled = false;
    cancelButton.textContent = 'لغو انتخاب';
    closeButton.disabled = false;
    setStep('received', []);
    renderSelectedFiles();
    modal.hidden = false;
  };

  const closeModal = () => {
    modal.hidden = true;
    state.current = null;
    state.ingestionId = null;
    state.archiveEntries = [];
    state.busy = false;
    setProgress(0, '', false);
  };

  const showError = (message) => {
    report.innerHTML = '<div class="product-ingestion-warning">' + escapeHtml(message) + '</div>';
    confirmButton.disabled = true;
    setStep(null, ['received']);
  };

  const renderReport = (payload) => {
    const inspection = payload.inspection || {};
    const file = payload.file || {};
    const pageLabel = inspection.page_count_label || (inspection.detected_type === 'pptx' ? 'تعداد اسلاید' : 'تعداد صفحات');
    const documentParts = inspection.document_parts || {};
    const partsLabel = inspection.detected_type === 'office'
      ? [
          documentParts.headers ? 'سربرگ: ' + documentParts.headers : '',
          documentParts.footers ? 'پابرگ: ' + documentParts.footers : '',
          documentParts.footnotes ? 'پانویس دارد' : '',
          documentParts.endnotes ? 'یادداشت پایانی دارد' : '',
        ].filter(Boolean).join(' · ') || 'ساختار پایه سند شناسایی شد'
      : '—';
    const rows = [
      ['نوع شناسایی‌شده', labels[inspection.detected_type] || inspection.detected_type || 'نامشخص'],
      ['حجم واقعی', formatBytes(file.size_bytes)],
      ['نوع MIME', inspection.mime || file.mime || 'نامشخص'],
      [pageLabel, inspection.page_count ?? 'در این مرحله قابل تعیین نیست'],
      ['تعداد برگه‌های Excel', inspection.sheet_count ?? '—'],
      ['ساختار تکمیلی سند', partsLabel],
      ['پدیدآورنده', inspection.author || 'در فراداده فایل ثبت نشده است'],
      ['تاریخ ایجاد', inspection.created_at || 'ثبت نشده است'],
      ['ابعاد تصویر', inspection.dimensions ? inspection.dimensions.width + ' × ' + inspection.dimensions.height : '—'],
      ['تعداد اعضای آرشیو', inspection.archive_entry_count ?? 0],
      ['حجم بازشده آرشیو', inspection.archive_expanded_bytes ? formatBytes(inspection.archive_expanded_bytes) : '—'],
      ['سطح ریسک ساختاری', inspection.risk_level || 'نامشخص'],
      ['SHA-256', file.sha256 || '—'],
      ['موتور بررسی', inspection.scan_label || 'بررسی ایستای داخلی فراست'],
    ];
    const grid = rows.map(([label, value]) =>
      '<div><small>' + escapeHtml(label) + '</small><b>' + escapeHtml(value) + '</b></div>'
    ).join('');

    const entries = Array.isArray(inspection.archive_entries) ? inspection.archive_entries : [];
    state.archiveEntries = entries;
    const archive = entries.length
      ? '<h4>محتویات شناسایی‌شده آرشیو · برای مشاهده جزئیات هر مورد انتخاب کنید</h4><div class="product-ingestion-archive-list">' +
        entries.map((entry, index) => {
          const scanLabel = entry.content_scan === 'checked'
            ? 'بررسی شد'
            : (entry.content_scan === 'skipped_size_limit' ? 'بررسی محدود' : 'بررسی‌نشده');
          return '<button type="button" class="product-ingestion-archive-item" data-archive-entry="' + index + '" aria-expanded="false"><strong>' +
            escapeHtml(entry.name) + '</strong><small>' + (entry.type === 'directory' ? 'پوشه' : 'فایل') +
            '</small><small>' + formatBytes(entry.size_bytes) + ' · ' + scanLabel + '</small></button>';
        }).join('') + '</div><div class="product-ingestion-archive-detail" data-archive-detail hidden></div>' +
        (inspection.archive_entry_count > entries.length ? '<p>فقط ' + entries.length + ' مورد از ' + inspection.archive_entry_count + ' مورد مجاز نمایش داده شده است.</p>' : '')
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
    openModal(file);
  };

  const updateQueue = () => {
    const pending = state.queue.map((file, index) =>
      '<div><span>' + escapeHtml(file.name) + '</span><small>' + formatBytes(file.size) + ' · در صف بررسی</small><button type="button" data-remove-queued="' + index + '" aria-label="حذف ' + escapeHtml(file.name) + ' از صف">حذف از صف</button></div>'
    ).join('');
    const rejected = state.rejected.map((item) =>
      '<div class="product-ingestion-queue-error"><span>' + escapeHtml(item.name) + '</span><small>' + escapeHtml(item.reason) + '</small></div>'
    ).join('');
    queueView.innerHTML = pending + rejected;
  };

  const preflight = async (file) => {
    state.busy = true;
    const form = new FormData();
    form.append('file', file, file.name);
    state.primaryForCurrent = !!primaryInput.checked && !state.primaryCommitted;
    form.append('is_primary', state.primaryForCurrent ? '1' : '0');

    try {
      setStep('upload', ['received']);
      setProgress(0, 'در حال انتقال فایل به قرنطینه خصوصی...');
      const payload = await sendPreflight(root.dataset.preflightUrl, form, (percent, uploaded) => {
        setProgress(percent, uploaded ? 'انتقال کامل شد؛ بررسی ساختار و امنیت در حال اجراست...' : 'در حال انتقال امن فایل · ' + percent + '٪');
        if (uploaded) setStep('inspect', ['received', 'upload']);
      });
      setProgress(100, 'بررسی اولیه کامل شد؛ گزارش آماده است.');
      state.ingestionId = payload.ingestion_id;
      renderReport(payload);
      setStep('review', ['received', 'upload', 'inspect']);
      confirmButton.disabled = !payload.inspection?.safe;
      confirmButton.textContent = 'تأیید و ذخیره فایل';
      cancelButton.textContent = 'رد فایل';
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
      state.queue = state.queue.filter((file) => file !== state.current);
      closeModal();
      processNext();
    }
  };

  const pollProcessing = async (fileId) => {
    const template = root.dataset.statusUrlTemplate;
    if (!template || !fileId) return;
    const url = template.replace('__FILE__', encodeURIComponent(fileId));
    let attempts = 0;
    const completedSteps = ['received', 'upload', 'inspect', 'review', 'store'];

    const finish = (payload) => {
      const status = payload.processing_status || 'pending';
      const audio = payload.audio_status || 'not_started';
      const sample = payload.sample_status || 'not_available';
      const pages = Array.isArray(payload.sample_pages) ? payload.sample_pages : [];
      const done = [...completedSteps];
      if (status === 'completed') done.push('study');
      if (status === 'completed' && sample !== 'pending') done.push('sample');
      if (audio === 'completed') done.push('audio');
      setStep(null, done);

      const messages = {
        completed: 'مطالعه محتوایی فایل تکمیل شد.',
        extracted: 'متن استخراج شد؛ تحلیل معنایی کامل نیست و فایل به‌عنوان دانش کامل معرفی نمی‌شود.',
        partial: 'پردازش فایل ناقص است؛ اطلاعات ناموجود به هوش مصنوعی اعلام خواهد شد.',
        failed: 'پردازش فایل با خطا متوقف شد؛ فایل اصلی محفوظ مانده است.',
        stale: 'هش فایل با نسخه پردازش‌شده تطابق ندارد؛ پردازش دوباره لازم است.',
        awaiting_provider: 'فایل ذخیره شد؛ برای مطالعه محتوایی اتصال ارائه‌دهنده هوش مصنوعی لازم است.',
      };
      const summary = messages[status] || 'وضعیت پردازش: ' + status;
      const sampleText = sample === 'completed'
        ? 'نمونه یک‌بار ساخته شد' + (pages.length ? ' · صفحات ' + pages.join('، ') : '')
        : (sample === 'not_available' ? 'برای این فایل نمونه صفحه‌ای تولید نشد' : 'نمونه هنوز آماده نشده است');
      const audioText = audio === 'completed'
        ? 'نسخه صوتی همگام آماده شد · ' + Math.round((payload.audio_duration_ms || 0) / 1000) + ' ثانیه'
        : (audio === 'awaiting_provider' ? 'تولید صوت در انتظار اتصال موتور صداست' : 'وضعیت صوت: ' + audio);
      const factText = 'اطلاعات مستند ثبت‌شده: ' + Number(payload.fact_count || 0) + ' گزاره';
      const readerText = payload.reader_ready
        ? 'خوانشگر آماده است · ' + Number(payload.reader_page_count || 0) + ' بخش'
        : 'نسخه متنی خوانشگر هنوز آماده نشده است';
      report.insertAdjacentHTML('beforeend',
        '<div class="product-ingestion-processing-result"><strong>' + escapeHtml(summary) + '</strong><p>' +
        escapeHtml(sampleText) + '</p><p>' + escapeHtml(readerText) + '</p><p>' + escapeHtml(factText) + '</p><p>' + escapeHtml(audioText) + '</p></div>'
      );
      confirmButton.textContent = 'ذخیره شد';
      confirmButton.disabled = true;
      cancelButton.textContent = 'بستن';
      cancelButton.disabled = false;
      closeButton.disabled = false;
      setProgress(100, 'مراحل پردازش در پس‌زمینه ثبت شدند.');
    };

    const poll = async () => {
      if (modal.hidden) return;
      attempts += 1;
      try {
        const response = await fetch(url, {
          headers: { 'Accept': 'application/json' },
          credentials: 'same-origin',
          cache: 'no-store',
        });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(payload.message || 'وضعیت پردازش دریافت نشد.');
        const status = payload.processing_status || 'pending';
        const audio = payload.audio_status || 'not_started';
        const sample = payload.sample_status || 'not_available';

        if (status === 'pending' || status === 'processing') {
          setStep('study', completedSteps);
          setProgress(100, status === 'pending' ? 'فایل در صف مطالعه محتوایی است...' : 'هوش مصنوعی در حال مطالعه محتوای استخراج‌شده است...');
        } else if (status === 'completed' && (sample === 'pending' || audio === 'pending')) {
          setStep(sample === 'pending' ? 'sample' : 'audio', [...completedSteps, 'study']);
          setProgress(100, sample === 'pending' ? 'در حال ثبت فایل نمونه...' : 'در حال ساخت صوت و زمان‌بندی همگام...');
        } else {
          finish(payload);
          window.setTimeout(() => {
            closeModal();
            if (state.queue.length) processNext();
            else window.location.reload();
          }, 1800);
          return;
        }
      } catch (error) {
        setProgress(100, error.message || 'وضعیت پردازش موقتاً در دسترس نیست؛ پردازش در پس‌زمینه ادامه دارد.');
      }

      if (attempts >= 90) {
        report.insertAdjacentHTML('beforeend', '<p>پردازش در پس‌زمینه ادامه دارد. وضعیت نهایی در فهرست فایل‌های محصول ثبت خواهد شد.</p>');
        cancelButton.textContent = 'بستن';
        cancelButton.disabled = false;
        closeButton.disabled = false;
        return;
      }
      window.setTimeout(poll, 2000);
    };

    await poll();
  };

  const confirmCurrent = async () => {
    if (state.busy) return;
    if (!state.ingestionId) {
      if (state.current) await preflight(state.current);
      return;
    }
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
      setStep('study', ['received', 'upload', 'inspect', 'review', 'store']);
      if (state.primaryForCurrent) state.primaryCommitted = true;
      state.ingestionId = null;
      state.busy = false;
      confirmButton.textContent = 'ذخیره شد';
      cancelButton.textContent = 'بستن';
      cancelButton.disabled = false;
      closeButton.disabled = false;
      const savedMessage = payload.message || 'فایل در فضای خصوصی ذخیره شد.';
      const republishNotice = payload.product?.requires_republish
        ? '<p class="product-ingestion-warning">محصول از انتشار خارج شد. پس از آماده‌شدن خوانشگر یا بررسی تازه ناظر، وضعیت انتشار را دوباره تنظیم کنید.</p>'
        : '';
      report.insertAdjacentHTML('beforeend',
        '<div class="product-ingestion-processing-result"><strong>' + escapeHtml(savedMessage) + '</strong>' +
        '<p>مطالعه محتوا، ساخت نمونه و تولید صوت به‌صورت مرحله‌ای در پس‌زمینه انجام می‌شود.</p>' +
        republishNotice + '</div>'
      );
      if (payload.product?.requires_republish) {
        const statusSelect = document.querySelector('#productEditorForm [name="status"]');
        if (statusSelect) statusSelect.value = 'draft';
        const readinessLabel = document.querySelector('.product-readiness-head strong');
        if (readinessLabel) {
          readinessLabel.classList.remove('is-ready');
          readinessLabel.classList.add('is-pending');
          readinessLabel.textContent = 'نیازمند تکمیل';
        }
        const contentRow = Array.from(document.querySelectorAll('.product-readiness-list > div'))
          .find((element) => element.textContent.includes('فایل اصلی'));
        if (contentRow) {
          contentRow.classList.remove('is-ready', 'is-optional');
          contentRow.classList.add('is-pending');
          const detail = contentRow.querySelector('small');
          if (detail) detail.textContent = 'نیازمند پردازش مجدد';
        }
      }
      if (payload.file?.id) {
        await pollProcessing(payload.file.id);
      } else {
        window.setTimeout(() => {
          closeModal();
          window.location.reload();
        }, 1200);
      }
    } catch (error) {
      state.busy = false;
      cancelButton.disabled = false;
      closeButton.disabled = false;
      showError(error.message || 'ذخیره‌سازی فایل با خطا مواجه شد.');
    }
  };

  const allowedExtensions = new Set([
    'pdf','zip','tar','tar.gz','tar.bz2','jpg','jpeg','png','webp','gif',
    'docx','xlsx','pptx','txt','csv','md','mp3','wav','ogg','flac','m4a','mp4','webm','mov',
  ]);
  const fileExtension = (name) => {
    const lower = String(name || '').toLowerCase();
    if (lower.endsWith('.tar.gz')) return 'tar.gz';
    if (lower.endsWith('.tar.bz2')) return 'tar.bz2';
    return lower.includes('.') ? lower.split('.').pop() : '';
  };
  const fileIdentity = (file) => [file.name.toLowerCase(), file.size, file.lastModified].join(':');

  const enqueue = (files) => {
    state.rejected = [];
    const candidates = Array.from(files || []);
    candidates.forEach((file) => {
      if (!file || file.size < 1) {
        state.rejected.push({name: file?.name || 'فایل ناشناس', reason: 'فایل خالی است.'});
        return;
      }
      if (file.size > 50 * 1024 * 1024) {
        state.rejected.push({name: file.name, reason: 'حجم فایل بیشتر از ۵۰ مگابایت است.'});
        return;
      }
      if (!allowedExtensions.has(fileExtension(file.name))) {
        state.rejected.push({name: file.name, reason: 'فرمت فایل در فهرست فرمت‌های مجاز نیست.'});
        return;
      }
      const identity = fileIdentity(file);
      const selectedBytes = (state.current ? state.current.size : 0) + state.queue.reduce((total, queued) => total + queued.size, 0);
      if (selectedBytes + file.size > 500 * 1024 * 1024) {
        state.rejected.push({name: file.name, reason: 'مجموع حجم فایل‌های این نوبت نمی‌تواند از ۵۰۰ مگابایت بیشتر شود.'});
        return;
      }
      if ((state.current && fileIdentity(state.current) === identity) || state.queue.some((queued) => fileIdentity(queued) === identity)) {
        state.rejected.push({name: file.name, reason: 'این فایل قبلاً در همین صف انتخاب شده است.'});
        return;
      }
      if (state.queue.length >= 100) {
        state.rejected.push({name: file.name, reason: 'حداکثر ۱۰۰ فایل را می‌توان در یک نوبت در صف قرار داد.'});
        return;
      }
      state.queue.push(file);
    });
    updateQueue();
    renderSelectedFiles();
    processNext();
  };

  const renderSelectedFiles = () => {
    if (!selectedView) return;
    const files = [state.current, ...state.queue].filter(Boolean);
    const rows = files.map((file, index) => {
      const relative = file.webkitRelativePath || file.relativePath || file.name;
      return '<div><strong>' + escapeHtml(relative) + '</strong><small>' + formatBytes(file.size) +
        ' · ' + escapeHtml(fileExtension(file.name).toUpperCase() || 'نامشخص') +
        (file.lastModified ? ' · ' + new Intl.DateTimeFormat('fa-IR').format(new Date(file.lastModified)) : '') +
        '</small>' + (index > 0 ? '<button type="button" data-select-queued="' + (index - 1) + '">جزئیات و بررسی</button>' : '<small>فایل جاری</small>') + '</div>';
    }).join('');
    const errors = state.rejected.map(item => '<div class="product-ingestion-queue-error"><strong>' +
      escapeHtml(item.name) + '</strong><small>' + escapeHtml(item.reason) + '</small></div>').join('');
    selectedView.innerHTML = files.length
      ? '<strong>فهرست انتخاب‌شده · ' + files.length + ' فایل</strong>' + rows + errors
      : errors;
  };

  const showArchiveEntryDetail = (index) => {
    const entry = state.archiveEntries[index];
    const target = report.querySelector('[data-archive-detail]');
    if (!entry || !target) return;
    const meta = entry.metadata || {};
    const scan = entry.content_scan === 'checked' ? 'محتوای فایل در بررسی ایستا خوانده شد'
      : (entry.content_scan === 'skipped_size_limit' ? 'بررسی محتوا به‌دلیل محدودیت اندازه کامل نشد' : 'بررسی محتوایی انجام نشده');
    const details = [
      ['مسیر داخل آرشیو', entry.name || '—'],
      ['نوع فایل', meta.mime || meta.extension || entry.type || 'نامشخص'],
      ['حجم اصلی', formatBytes(entry.size_bytes)],
      ['حجم فشرده', entry.compressed_bytes != null ? formatBytes(entry.compressed_bytes) : '—'],
      ['تعداد صفحات', meta.page_count != null ? meta.page_count : 'از این فایل قابل تشخیص نیست'],
      ['پدیدآورنده', meta.author || 'فراداده پدیدآورنده ثبت نشده است'],
      ['تاریخ ایجاد', meta.created_at || 'ثبت نشده است'],
      ['وضعیت بررسی داخلی', scan],
    ];
    target.innerHTML = '<dl>' + details.map(([label, value]) =>
      '<div><dt>' + escapeHtml(label) + '</dt><dd>' + escapeHtml(value) + '</dd></div>'
    ).join('') + '</dl>';
    target.hidden = false;
    report.querySelectorAll('[data-archive-entry]').forEach(button => {
      button.setAttribute('aria-expanded', Number(button.dataset.archiveEntry) === index ? 'true' : 'false');
    });
  };

  report.addEventListener('click', (event) => {
    const button = event.target.closest('[data-archive-entry]');
    if (!button) return;
    showArchiveEntryDetail(Number(button.dataset.archiveEntry));
  });

  selectedView?.addEventListener('click', (event) => {
    const removeButton = event.target.closest('[data-remove-selected]');
    if (removeButton) {
      const index = Number(removeButton.dataset.removeSelected);
      if (!Number.isInteger(index) || index < 0 || index >= state.queue.length) return;
      state.queue.splice(index, 1);
      updateQueue();
      renderSelectedFiles();
      return;
    }
    const selectButton = event.target.closest('[data-select-queued]');
    if (!selectButton || state.busy || state.ingestionId) return;
    const index = Number(selectButton.dataset.selectQueued);
    if (!Number.isInteger(index) || index < 0 || index >= state.queue.length) return;
    const selected = state.queue.splice(index, 1)[0];
    state.queue.unshift(state.current);
    openModal(selected);
    updateQueue();
  });

  queueView.addEventListener('click', (event) => {
    const button = event.target.closest('[data-remove-queued]');
    if (!button) return;
    const index = Number(button.dataset.removeQueued);
    if (!Number.isInteger(index) || index < 0 || index >= state.queue.length) return;
    state.queue.splice(index, 1);
    updateQueue();
    renderSelectedFiles();
  });

  pick.addEventListener('click', () => input.click());
  folderPick?.addEventListener('click', () => folderInput?.click());
  folderInput?.addEventListener('change', () => {
    enqueue(folderInput.files);
    folderInput.value = '';
  });
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

  const readDroppedEntry = async (entry, prefix = '') => {
    if (entry.isFile) {
      return new Promise(resolve => entry.file(file => {
        try {
          Object.defineProperty(file, 'relativePath', {value: prefix + file.name, configurable: true});
        } catch (_) {}
        resolve([file]);
      }, () => resolve([])));
    }
    if (!entry.isDirectory) return [];
    const reader = entry.createReader();
    const children = [];
    while (true) {
      const batch = await new Promise(resolve => reader.readEntries(resolve, () => resolve([])));
      if (!batch.length) break;
      children.push(...batch);
    }
    const nested = await Promise.all(children.map(child => readDroppedEntry(child, prefix + entry.name + '/')));
    return nested.flat();
  };

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
    if (!event.dataTransfer) return;
    const items = Array.from(event.dataTransfer.items || []);
    if (!event.dataTransfer.files?.length && !items.length) return;
    event.preventDefault();
    state.dragDepth = 0;
    overlay.hidden = true;
    const entries = items.map(item => item.webkitGetAsEntry?.()).filter(Boolean);
    if (entries.length) {
      Promise.all(entries.map(entry => readDroppedEntry(entry))).then(groups => enqueue(groups.flat()));
    } else {
      enqueue(event.dataTransfer.files);
    }
  });
  window.addEventListener('dragend', () => {
    state.dragDepth = 0;
    overlay.hidden = true;
  });
})();
