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
      } else if (Array.isArray(payload.content_blocks) && payload.content_blocks.length) {
        let activeList = null;
        let activeListKind = '';
        let activeTable = null;
        let activeTableBody = null;
        let activeTableIndex = 0;
        const closeList = () => { activeList = null; activeListKind = ''; };
        const closeTable = () => { activeTable = null; activeTableBody = null; activeTableIndex = 0; };

        for (const rawBlock of payload.content_blocks.slice(0, 120)) {
          if (!rawBlock || typeof rawBlock !== 'object') continue;
          const type = String(rawBlock.type || '');
          const value = String(rawBlock.text || '');
          if (!['heading', 'paragraph', 'list_item', 'table_row'].includes(type)) continue;

          if (type === 'table_row') {
            closeList();
            const tableIndex = Math.max(1, Math.min(100, Number(rawBlock.table_index) || 1));
            if (!activeTable || activeTableIndex !== tableIndex) {
              closeTable();
              const wrap = document.createElement('div');
              wrap.className = 'farast-reader-docx-table-wrap';
              wrap.setAttribute('role', 'region');
              wrap.setAttribute('aria-label', 'جدول سند Word');
              wrap.tabIndex = 0;
              wrap.style.maxWidth = '100%';
              wrap.style.overflowX = 'auto';
              wrap.style.margin = '1.25rem 0';
              activeTable = document.createElement('table');
              activeTable.className = 'farast-reader-docx-table';
              activeTable.style.width = '100%';
              activeTable.style.borderCollapse = 'collapse';
              activeTable.style.minWidth = 'min(100%, 34rem)';
              const caption = document.createElement('caption');
              caption.textContent = 'جدول ' + String(tableIndex);
              caption.style.fontWeight = '700';
              caption.style.textAlign = 'start';
              caption.style.padding = '0.5rem';
              activeTable.appendChild(caption);
              activeTableBody = document.createElement('tbody');
              activeTable.appendChild(activeTableBody);
              wrap.appendChild(activeTable);
              layer.appendChild(wrap);
              activeTableIndex = tableIndex;
            }
            const cells = Array.isArray(rawBlock.cells) ? rawBlock.cells.slice(0, 50) : value.split('|').slice(0, 50);
            const row = document.createElement('tr');
            row.className = 'reader-document-line';
            row.dataset.readerAudioText = value || cells.map(cell => String(cell ?? '')).join(' | ');
            cells.forEach((cellValue, cellIndex) => {
              const cell = document.createElement('td');
              cell.textContent = String(cellValue ?? '');
              if (Number(rawBlock.row_index) === 1) {
                const headerCell = document.createElement('th');
                headerCell.scope = 'col';
                headerCell.textContent = String(cellValue ?? '');
                headerCell.style.background = '#eef2f7';
                headerCell.style.fontWeight = '700';
                headerCell.style.border = '1px solid #d7dee8';
                headerCell.style.padding = '0.6rem';
                row.appendChild(headerCell);
              } else {
                cell.style.border = '1px solid #d7dee8';
                cell.style.padding = '0.6rem';
                cell.style.verticalAlign = 'top';
                row.appendChild(cell);
              }
            });
            activeTableBody.appendChild(row);
            continue;
          }

          closeTable();
          if (type === 'list_item') {
            const listKind = rawBlock.list_kind === 'number' ? 'number' : 'bullet';
            if (!activeList || activeListKind !== listKind) {
              closeList();
              activeList = document.createElement(listKind === 'number' ? 'ol' : 'ul');
              activeList.className = 'farast-reader-docx-list';
              activeList.style.paddingInlineStart = '1.5rem';
              activeList.style.lineHeight = '1.9';
              activeList.style.margin = '0.5rem 0 1rem';
              layer.appendChild(activeList);
              activeListKind = listKind;
            }
            const item = document.createElement('li');
            item.className = 'reader-document-line';
            item.textContent = value;
            item.style.marginInlineStart = Math.max(0, Math.min(5, Number(rawBlock.list_level) || 1) - 1) + 'em';
            activeList.appendChild(item);
            continue;
          }

          closeList();
          const element = type === 'heading'
            ? document.createElement('h' + Math.max(1, Math.min(6, Number(rawBlock.level) || 2)))
            : document.createElement('p');
          element.className = 'reader-document-line ' + (type === 'heading' ? 'farast-reader-docx-heading' : 'farast-reader-docx-paragraph');
          element.textContent = value;
          element.dir = 'auto';
          if (type === 'heading') {
            element.style.fontWeight = '800';
            element.style.fontSize = Math.max(1.05, 1.65 - (Number(rawBlock.level) || 2) * 0.09) + 'rem';
            element.style.lineHeight = '1.6';
            element.style.margin = '1.4rem 0 0.65rem';
          } else {
            element.style.lineHeight = '1.95';
            element.style.margin = '0.55rem 0';
            element.style.whiteSpace = 'pre-wrap';
          }
          layer.appendChild(element);
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
