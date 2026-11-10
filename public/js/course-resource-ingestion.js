(() => {
  'use strict';

  const forms = Array.from(document.querySelectorAll('[data-course-resource-ingestion]'));
  if (!forms.length) return;

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

  const upload = (url, formData, token, onProgress) => new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest();
    xhr.open('POST', url, true);
    xhr.withCredentials = true;
    xhr.setRequestHeader('X-CSRF-TOKEN', token);
    xhr.setRequestHeader('Accept', 'application/json');
    xhr.upload.addEventListener('progress', (event) => {
      if (event.lengthComputable) onProgress(Math.round((event.loaded / event.total) * 100));
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
    xhr.addEventListener('error', () => reject(new Error('ارتباط با سرور برای بررسی منبع برقرار نشد.')));
    xhr.addEventListener('abort', () => reject(new Error('بارگذاری منبع لغو شد.')));
    xhr.send(formData);
  });

  forms.forEach((form) => {
    const input = form.querySelector('[data-course-ingestion-input]');
    const status = form.querySelector('[data-course-ingestion-status]');
    const progress = form.querySelector('[data-course-ingestion-progress]');
    const progressBar = form.querySelector('[data-course-ingestion-progress-bar]');
    const modal = form.querySelector('[data-course-ingestion-modal]');
    const report = form.querySelector('[data-course-ingestion-report]');
    const confirmButton = form.querySelector('[data-course-ingestion-confirm]');
    const cancelButton = form.querySelector('[data-course-ingestion-cancel]');
    const submitButton = form.querySelector('button[type="submit"]');
    const token = form.querySelector('input[name="_token"]')?.value || '';
    let ingestionId = null;
    let busy = false;

    const setStatus = (message, isError = false) => {
      status.textContent = message || '';
      status.classList.toggle('is-error', Boolean(isError));
    };

    const setProgress = (percent, visible = true) => {
      progress.hidden = !visible;
      progressBar.style.width = Math.max(0, Math.min(100, Number(percent || 0))) + '%';
    };

    const renderReport = (payload) => {
      const file = payload.file || {};
      const inspection = payload.inspection || {};
      const rows = [
        ['نام فایل', file.name],
        ['حجم واقعی', formatBytes(file.size_bytes)],
        ['نوع MIME', file.mime],
        ['نوع شناسایی‌شده', inspection.detected_type],
        ['تعداد صفحات / اسلایدها', inspection.page_count ?? 'در این مرحله قابل تعیین نیست'],
        ['تعداد اعضای آرشیو', inspection.archive_entry_count ?? 0],
        ['حجم بازشده آرشیو', inspection.archive_expanded_bytes ? formatBytes(inspection.archive_expanded_bytes) : '—'],
        ['ابعاد تصویر', inspection.dimensions ? inspection.dimensions.width + ' × ' + inspection.dimensions.height : '—'],
        ['سطح ریسک ساختاری', inspection.risk_level || 'نامشخص'],
        ['روش بررسی', inspection.scan_label || 'بررسی ایستای داخلی فراست'],
        ['SHA-256', file.sha256],
      ];
      const grid = rows.map(([label, value]) =>
        '<div><small>' + escapeHtml(label) + '</small><b>' + escapeHtml(value ?? '—') + '</b></div>'
      ).join('');

      const warnings = Array.isArray(inspection.warnings) ? inspection.warnings : [];
      const findings = Array.isArray(inspection.findings) ? inspection.findings : [];
      const entries = Array.isArray(inspection.archive_entries) ? inspection.archive_entries.slice(0, 40) : [];
      const warningHtml = warnings.length
        ? '<h4>هشدارها</h4><ul>' + warnings.map(item => '<li>' + escapeHtml(item) + '</li>').join('') + '</ul>'
        : '<p>هشدار ساختاری ثبت نشد.</p>';
      const findingHtml = findings.length
        ? '<h4>یافته‌ها</h4><ul>' + findings.map(item => '<li>' + escapeHtml(typeof item === 'string' ? item : (item.message || item.code || JSON.stringify(item))) + '</li>').join('') + '</ul>'
        : '';
      const archiveHtml = entries.length
        ? '<h4>نمونه محتویات آرشیو</h4><ul>' + entries.map(item => '<li>' + escapeHtml(typeof item === 'string' ? item : (item.name || item.path || 'عضو آرشیو')) + '</li>').join('') + '</ul>'
        : '';
      report.innerHTML = '<div class="course-ingestion-grid">' + grid + '</div>' + warningHtml + findingHtml + archiveHtml;
    };

    const cancelStaging = async () => {
      if (!ingestionId) return;
      const url = form.dataset.cancelUrlTemplate.replace('__INGESTION__', encodeURIComponent(ingestionId));
      const response = await fetch(url, {
        method: 'DELETE',
        credentials: 'same-origin',
        headers: { 'X-CSRF-TOKEN': token, 'Accept': 'application/json' },
      });
      if (!response.ok) throw new Error('پاک‌سازی قرنطینه کامل نشد.');
      ingestionId = null;
    };

    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      if (busy || !input.files || !input.files[0]) return;

      busy = true;
      input.disabled = true;
      submitButton.disabled = true;
      setStatus('در حال انتقال منبع به قرنطینه خصوصی و بررسی ساختار فایل...');
      setProgress(0, true);

      try {
        const data = new FormData();
        data.append('file', input.files[0]);
        const payload = await upload(form.dataset.preflightUrl, data, token, (percent) => {
          setProgress(percent, true);
          setStatus('بارگذاری و بررسی اولیه: ' + percent + '%');
        });
        ingestionId = payload.ingestion_id;
        renderReport(payload);
        modal.hidden = false;
        confirmButton.disabled = false;
        setStatus('بررسی اولیه پایان یافت. تا تأیید شما، منبع در قرنطینه باقی می‌ماند.');
        setProgress(100, true);
        busy = false;
      } catch (error) {
        setStatus(error.message || 'بررسی اولیه منبع ناموفق بود.', true);
        setProgress(0, false);
        input.disabled = false;
        submitButton.disabled = false;
        busy = false;
      }
    });

    confirmButton.addEventListener('click', async () => {
      if (!ingestionId || busy) return;
      busy = true;
      confirmButton.disabled = true;
      cancelButton.disabled = true;
      setStatus('در حال تأیید دوباره SHA-256 و انتقال منبع به مخزن خصوصی...');
      const url = form.dataset.confirmUrlTemplate.replace('__INGESTION__', encodeURIComponent(ingestionId));
      try {
        const response = await fetch(url, {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'X-CSRF-TOKEN': token, 'Accept': 'application/json' },
        });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(payload.message || 'تأیید نهایی منبع انجام نشد.');
        ingestionId = null;
        modal.hidden = true;
        setStatus(payload.message || 'منبع با موفقیت ذخیره شد.');
        window.location.reload();
      } catch (error) {
        setStatus(error.message || 'تأیید نهایی ناموفق بود.', true);
        confirmButton.disabled = false;
        cancelButton.disabled = false;
        busy = false;
      }
    });

    cancelButton.addEventListener('click', async () => {
      if (busy) return;
      busy = true;
      cancelButton.disabled = true;
      confirmButton.disabled = true;
      try {
        await cancelStaging();
        modal.hidden = true;
        setStatus('منبع قرنطینه‌شده لغو و پاک شد.');
        input.value = '';
      } catch (error) {
        setStatus(error.message || 'پاک‌سازی قرنطینه کامل نشد؛ لطفاً دوباره تلاش کنید.', true);
      } finally {
        input.disabled = false;
        submitButton.disabled = false;
        cancelButton.disabled = false;
        confirmButton.disabled = true;
        setProgress(0, false);
        busy = false;
      }
    });
  });
})();
