(() => {
  'use strict';

  const root = document.querySelector('[data-reader-root][data-reader-mode="document"]');
  if (!root) return;

  const activeArticle = () => root.querySelector('.farast-reader-page-item.is-active');
  const loadPage = async (article) => {
    if (!article) return;
    const pageUrl = article.dataset.readerPageUrl;
    const container = article.querySelector('[data-reader-document-page]');
    const loading = container?.querySelector('.farast-pdf-loading');
    const layer = container?.querySelector('[data-reader-text-layer]');
    if (!pageUrl || !container || !layer) return;

    if (article.dataset.readerLoaded === '1') {
      root.dispatchEvent(new CustomEvent('farast:reader-page-loaded', {
        detail: { page: Number(article.dataset.readerPage || 1), article }
      }));
      return;
    }

    if (article.dataset.readerLoading === '1') return;
    article.dataset.readerLoading = '1';
    if (loading) {
      loading.hidden = false;
      loading.textContent = 'در حال دریافت محتوای مجاز این صفحه…';
    }

    try {
      const response = await fetch(pageUrl, {
        method: 'GET',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { Accept: 'application/json' },
      });
      const payload = await response.json().catch(() => ({}));
      if (!response.ok) throw new Error(payload.message || 'دریافت صفحه ممکن نشد.');

      layer.replaceChildren();
      layer.style.position = 'relative';
      layer.style.display = 'block';
      layer.style.inset = 'auto';
      layer.style.overflow = 'visible';
      layer.style.opacity = '1';
      layer.style.pointerEvents = 'auto';
      layer.style.userSelect = 'none';
      layer.style.webkitUserSelect = 'none';

      const heading = document.createElement('div');
      heading.className = 'farast-document-html-heading';
      heading.textContent = payload.label || ('بخش ' + (payload.page_number || ''));
      container.insertBefore(heading, layer);

      const text = String(payload.content_text || '');
      if (!text.trim()) {
        const empty = document.createElement('p');
        empty.className = 'reader-document-empty';
        empty.textContent = 'متن قابل استخراجی برای این بخش موجود نیست. نسخه اصلی فایل در مرورگر ارسال نمی‌شود.';
        layer.appendChild(empty);
      } else if (payload.page_kind === 'sheet') {
        const rows = text.split(/\r?\n/).map((line) => line.split('|').map((cell) => cell.trim()));
        const usableRows = rows.filter((row) => row.some((cell) => cell !== '')).slice(0, 80);
        const columnCount = Math.min(30, Math.max(0, ...usableRows.map((row) => row.length)));
        if (usableRows.length && columnCount > 1) {
          const wrap = document.createElement('div');
          wrap.className = 'farast-reader-sheet-table-wrap';
          wrap.setAttribute('role', 'region');
          wrap.setAttribute('aria-label', 'جدول برگه ' + String(payload.sheet_name || 'Excel'));
          wrap.tabIndex = 0;
          const table = document.createElement('table');
          table.className = 'farast-reader-sheet-table';
          const caption = document.createElement('caption');
          caption.textContent = String(payload.sheet_name || payload.label || 'برگه Excel');
          table.appendChild(caption);
          const tbody = document.createElement('tbody');
          usableRows.forEach((cells, rowIndex) => {
            const tr = document.createElement('tr');
            const rowHeading = document.createElement('th');
            rowHeading.scope = 'row';
            rowHeading.textContent = String(Math.max(1, Number(payload.row_start || 1) + rowIndex)).replace(/\d/g, (digit) => '۰۱۲۳۴۵۶۷۸۹'[Number(digit)]);
            tr.appendChild(rowHeading);
            for (let column = 0; column < columnCount; column += 1) {
              const td = document.createElement('td');
              td.textContent = cells[column] || '';
              tr.appendChild(td);
            }
            tbody.appendChild(tr);
          });
          table.appendChild(tbody);
          wrap.appendChild(table);
          layer.appendChild(wrap);
          if (usableRows.length >= 80 || rows.length > 80) {
            const note = document.createElement('p');
            note.className = 'farast-reader-sheet-note';
            note.textContent = 'برای حفظ سرعت نمایش، حداکثر ۸۰ ردیف از این بخش نمایش داده شده است.';
            layer.appendChild(note);
          }
        } else {
          const notice = document.createElement('p');
          notice.className = 'reader-document-empty';
          notice.textContent = 'ساختار جدولی این برگه به‌طور کامل قابل تشخیص نبود؛ متن استخراج‌شده در ادامه نمایش داده می‌شود.';
          layer.appendChild(notice);
          for (const line of text.split(/\r?\n/)) {
            const span = document.createElement('span');
            span.className = 'reader-document-line';
            span.textContent = line || ' ';
            layer.appendChild(span);
          }
        }
      } else {
        for (const line of text.split(/\r?\n/)) {
          const span = document.createElement('span');
          span.className = 'reader-document-line';
          span.textContent = line || ' ';
          span.style.position = 'relative';
          span.style.display = 'block';
          span.style.color = '#1f2937';
          span.style.whiteSpace = 'pre-wrap';
          span.style.lineHeight = '1.95';
          span.style.unicodeBidi = 'plaintext';
          layer.appendChild(span);
        }
      }

      article.dataset.readerLoaded = '1';
      article.dataset.readerContentHash = String(payload.content_sha256 || '');
      if (loading) loading.hidden = true;
      root.dispatchEvent(new CustomEvent('farast:reader-page-loaded', {
        detail: { page: Number(payload.page_number || article.dataset.readerPage || 1), article, payload }
      }));
    } catch (error) {
      if (loading) {
        loading.hidden = false;
        loading.textContent = error.message || 'بارگذاری صفحه ناموفق بود.';
      }
    } finally {
      delete article.dataset.readerLoading;
    }
  };

  root.addEventListener('farast:reader-section-change', (event) => {
    const index = Number(event.detail?.index ?? 0);
    const article = root.querySelector('.farast-reader-page-item[data-index="' + index + '"]');
    loadPage(article);
  });

  loadPage(activeArticle());
})();
