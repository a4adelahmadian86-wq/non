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
