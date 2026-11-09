const { test, expect } = require('@playwright/test');

test.use({ serviceWorkers: 'block' });

test('syncs authenticated reading progress to the account endpoint', async ({ page }) => {
  let savedState = null;
  await page.route('**/store/1/reader-state', async route => {
    if (route.request().method() === 'GET') {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ progress: null, bookmarks: [], updated_at: null }) });
      return;
    }
    savedState = route.request().postDataJSON();
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ saved: true }) });
  });

  await mountReader(page, false, '/store/1/reader-state');
  await page.locator('#reader-next').click();
  await expect.poll(() => savedState?.progress?.section).toBe(1);
  expect(savedState.bookmarks).toEqual([]);


});

async function mountReader(page, includeExcel = false, readerStateUrl = '') {
  await page.goto('/');
  const total = includeExcel ? 3 : 2;
  const excelMarkup = includeExcel ? `
          <button class="farast-reader-thumb" data-goto="2" aria-current="false">۳</button>
        ` : '';
  const excelPage = includeExcel ? `
          <div class="farast-reader-page-item" data-index="2"><div class="farast-reader-page-content"><iframe class="farast-xlsx-frame" sandbox="" srcdoc="&lt;!doctype html&gt;&lt;html&gt;&lt;body&gt;&lt;table&gt;&lt;tr&gt;&lt;td&gt;Excel&lt;/td&gt;&lt;/tr&gt;&lt;/table&gt;&lt;/body&gt;&lt;/html&gt;"></iframe></div></div>
        ` : '';
  await page.setContent(`
    <meta name="csrf-token" content="reader-test-token"><section class="farast-reader" data-reader-root data-theme="dark" data-product-id="reader-fixture" data-reader-state-url="${readerStateUrl}">
      <header><div class="farast-reader-account"><span>09123456789</span></div></header>
      <nav>
        <button id="reader-prev" type="button">قبلی</button>
        <label class="reader-page-input"><input id="reader-page-number" value="1"><span>/ ${total}</span></label>
        <button id="reader-next" type="button">بعدی</button>
        <button id="reader-resume" type="button" hidden><span>ادامه مطالعه</span></button>
        <button id="reader-bookmark-toggle" type="button" aria-pressed="false"><i class="fa-regular fa-bookmark"></i><span>نشانک</span></button>
        <button id="reader-bookmarks-open" type="button" aria-expanded="false">نشانک‌ها</button>
        <div id="reader-bookmarks-panel" class="reader-bookmarks-panel" hidden></div>
        <button id="reader-zoom-out" type="button">کوچک</button>
        <output id="reader-zoom-label"></output>
        <button id="reader-zoom-in" type="button">بزرگ</button>
        <button id="reader-fit" type="button">تناسب عرض</button>
        <button id="reader-rotate" type="button">چرخش</button>
        <button id="reader-scroll-mode" type="button">پیوسته</button>
        <div class="reader-search-group">
          <input id="reader-search-input" type="search">
          <button id="reader-search" type="button">جست‌وجو</button>
          <output id="reader-search-count"></output>
        </div>
        <button id="reader-thumbs-toggle" type="button" aria-expanded="true">فهرست</button>
        <button id="reader-theme" type="button">پوسته</button>
        <button id="reader-fs" type="button">تمام‌صفحه</button>
      </nav>
      <div class="farast-reader-progress" role="progressbar" aria-valuenow="0"><span id="reader-progress"></span></div>
      <div id="reader-stage">
        <aside id="reader-sidebar"><div class="farast-reader-thumbs">
          <button class="farast-reader-thumb is-active" data-goto="0" aria-current="page">۱</button>
          <button class="farast-reader-thumb" data-goto="1" aria-current="false">۲</button>
          ${excelMarkup}
        </div>
          <section class="farast-reader-outline" id="reader-outline" hidden><strong>فهرست مطالب</strong><div id="reader-outline-list"></div></section>
        </aside>
        <main class="farast-reader-canvas">
          <div class="farast-reader-page-item is-active" data-index="0"><div class="farast-reader-page-content"><img alt="صفحه اول" src="data:image/gif;base64,R0lGODlhAQABAAD/ACwAAAAAAQABAAACADs="></div></div>
          <div class="farast-reader-page-item" data-index="1"><div class="farast-reader-page-content"><img alt="صفحه دوم" src="data:image/gif;base64,R0lGODlhAQABAAD/ACwAAAAAAQABAAACADs="></div></div>
          ${excelPage}
        </main>
      </div>
      <div class="farast-reader-print-block" hidden>این پیش‌نمایش فقط برای مطالعهٔ آنلاین ارائه شده است.</div>
    </section>
  `);
  await page.addStyleTag({ content: '.farast-reader-page-content img,.farast-reader-page-content iframe { transform: scale(var(--reader-zoom, 1)); transform-origin: top center; }.farast-reader-print-block{display:none}@media print{body *{visibility:hidden!important}.farast-reader-print-block{display:block!important;visibility:visible!important;position:fixed;inset:0;padding:48px;background:#fff;color:#111}.farast-reader-print-block *{visibility:visible!important}}' });
  await page.addScriptTag({ url: new URL('/js/farast-reader.js', page.url()).href });
}


function makePdfFixture(pageCount = 1) {
  const objects = [
    '<< /Type /Catalog /Pages 2 0 R >>',
    '<< /Type /Pages /Kids [' + Array.from({ length: pageCount }, (_, index) => (3 + index * 3) + ' 0 R').join(' ') + '] /Count ' + pageCount + ' >>',
  ];
  for (let page = 1; page <= pageCount; page++) {
    const pageId = 3 + (page - 1) * 3;
    const fontId = pageId + 1;
    const streamId = pageId + 2;
    const stream = 'BT /F1 24 Tf 72 720 Td (FARAST PDF PAGE ' + page + ') Tj ET';
    objects.push('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 ' + fontId + ' 0 R >> >> /Contents ' + streamId + ' 0 R >>');
    objects.push('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>');
    objects.push('<< /Length ' + Buffer.byteLength(stream, 'binary') + ' >>\nstream\n' + stream + '\nendstream');
  }
  let pdf = '%PDF-1.4\n';
  const offsets = [0];
  objects.forEach((object, index) => {
    offsets.push(Buffer.byteLength(pdf, 'binary'));
    pdf += (index + 1) + ' 0 obj\n' + object + '\nendobj\n';
  });
  const xrefOffset = Buffer.byteLength(pdf, 'binary');
  pdf += 'xref\n0 ' + (objects.length + 1) + '\n0000000000 65535 f \n';
  offsets.slice(1).forEach(offset => {
    pdf += String(offset).padStart(10, '0') + ' 00000 n \n';
  });
  pdf += 'trailer\n<< /Size ' + (objects.length + 1) + ' /Root 1 0 R >>\nstartxref\n' + xrefOffset + '\n%%EOF';
  return Buffer.from(pdf, 'binary');
}

async function mountPdfReader(page, pageCount = 1) {
  page.on('console', message => console.log('[PDF QA console]', message.type(), message.text()));
  page.on('pageerror', error => console.log('[PDF QA pageerror]', error.message));
  page.on('requestfailed', request => console.log('[PDF QA requestfailed]', request.url(), request.failure()?.errorText));
  await page.route('**/reader-fixture.html', route => route.fulfill({
    status: 200,
    contentType: 'text/html',
    body: '<!doctype html><html><head><meta charset="utf-8"></head><body></body></html>',
  }));
  await page.route('**/reader-fixture.pdf', route => route.fulfill({
    status: 200,
    contentType: 'application/pdf',
    body: makePdfFixture(pageCount),
  }));
  await page.goto('/reader-fixture.html');
  await page.setContent(
    '<section class="farast-reader" data-reader-root data-theme="dark">' +
      '<header><div class="farast-reader-account"><span>09123456789</span></div></header>' +
      '<nav><button id="reader-prev" type="button">قبلی</button>' +
        '<label class="reader-page-input"><input id="reader-page-number" value="1"><span>/ ' + pageCount + '</span></label>' +
        '<button id="reader-next" type="button">بعدی</button>' +
        '<button id="reader-zoom-out" type="button">کوچک</button><output id="reader-zoom-label"></output>' +
        '<button id="reader-zoom-in" type="button">بزرگ</button><button id="reader-fit" type="button">تناسب عرض</button>' +
        '<button id="reader-rotate" type="button">چرخش</button><button id="reader-scroll-mode" type="button">پیوسته</button>' +
        '<div class="reader-search-group"><input id="reader-search-input" type="search">' +
          '<button id="reader-search" type="button">جست‌وجو</button><button id="reader-search-prev" type="button">قبلی</button>' +
          '<button id="reader-search-next" type="button">بعدی</button><output id="reader-search-count"></output></div>' +
        '<button id="reader-thumbs-toggle" type="button" aria-expanded="true">فهرست</button>' +
        '<button id="reader-theme" type="button">پوسته</button><button id="reader-fs" type="button">تمام‌صفحه</button></nav>' +
      '<div class="farast-reader-progress" role="progressbar" aria-valuenow="0"><span id="reader-progress"></span></div>' +
      '<div id="reader-stage"><aside id="reader-sidebar"><div class="farast-reader-thumbs"></div>' +
        '<section class="farast-reader-outline" id="reader-outline" hidden><strong>فهرست مطالب</strong><div id="reader-outline-list"></div></section></aside>' +
        '<main class="farast-reader-canvas"><article class="farast-reader-page-item is-active is-pdf" data-index="0">' +
          '<div class="farast-reader-page-content"><div class="farast-pdf-document" data-pdf-url="/reader-fixture.pdf">' +
            '<canvas class="farast-pdf-canvas"></canvas><div class="farast-pdf-text-layer"></div>' +
            '<div class="farast-pdf-continuous-pages" hidden></div><div class="farast-pdf-loading" role="status">در حال آماده‌سازی PDF…</div>' +
          '</div></div></article></main></div></section>'
  );
  await page.addStyleTag({ content: '#reader-stage{height:600px;max-height:600px;overflow-y:scroll!important;overflow-x:hidden;display:block}.farast-reader-canvas{height:auto;overflow:visible}.farast-pdf-document{position:relative;width:800px;min-height:600px;background:#fff}.farast-pdf-document.is-continuous .farast-pdf-continuous-pages{display:grid;grid-template-columns:1fr;gap:20px}.farast-pdf-cont-page{display:block;position:relative;min-height:792px;margin:0 auto 20px}.farast-pdf-canvas{display:block;max-width:100%;height:auto}.farast-pdf-loading{position:absolute;inset:0;display:grid;place-items:center;background:#fff}' });
  await page.addScriptTag({ url: new URL('/js/farast-reader.js', page.url()).href });
}

test.describe('FARAST sample reader interactions', () => {
  test('saves reading progress and resumes the last section on this device', async ({ page }) => {
    await mountReader(page);
    await page.locator('#reader-next').click();
    await expect(page.locator('#reader-page-number')).toHaveValue('2');

    await mountReader(page);
    const resume = page.locator('#reader-resume');
    await expect(resume).toBeVisible();
    await resume.click();
    await expect(page.locator('#reader-page-number')).toHaveValue('2');
    await expect(resume).toBeHidden();
  });

  test('keeps page bookmarks across reader reloads and allows removing them', async ({ page }) => {
    await mountReader(page);
    const bookmark = page.locator('#reader-bookmark-toggle');
    await bookmark.click();
    await expect(bookmark).toHaveAttribute('aria-pressed', 'true');

    await mountReader(page);
    await page.locator('#reader-bookmarks-open').click();
    const saved = page.locator('#reader-bookmarks-panel button').filter({ hasText: 'بخش ۱' }).first();
    await expect(saved).toBeVisible();
    await page.locator('#reader-bookmark-toggle').click();
    await expect(page.locator('#reader-bookmark-toggle')).toHaveAttribute('aria-pressed', 'false');
  });

  test('loads a real PDF page with same-origin PDF.js, account watermark and text search', async ({ page }) => {
    const pdfjsRequests = [];
    const externalPdfjsRequests = [];
    page.on('request', request => {
      if (request.url().includes('/vendor/pdfjs/')) pdfjsRequests.push(request.url());
      if (request.url().includes('cdn.jsdelivr.net/npm/pdfjs-dist')) externalPdfjsRequests.push(request.url());
    });
    await mountPdfReader(page);
    const root = page.locator('[data-reader-root]');
    const canvas = root.locator('.farast-pdf-canvas');
    await expect(root.locator('.farast-pdf-loading')).toBeHidden({ timeout: 45000 });
    await expect.poll(() => pdfjsRequests.some(url => url.endsWith('/pdf.mjs'))).toBe(true);
    await expect.poll(() => pdfjsRequests.some(url => url.endsWith('/pdf.worker.mjs'))).toBe(true);
    expect(externalPdfjsRequests).toHaveLength(0);
    await expect.poll(() => canvas.evaluate(element => element.width)).toBeGreaterThan(0);
    await expect.poll(() => canvas.evaluate(element => element.height)).toBeGreaterThan(0);
    // The watermark must be painted into the page bitmap, not merely placed as removable HTML.
    const watermarkPixels = await canvas.evaluate(element => {
      const context = element.getContext('2d');
      const { data, width, height } = context.getImageData(0, 0, element.width, element.height);
      let darkPixels = 0;
      for (let y = Math.floor(height * 0.45); y < height; y += 3) {
        for (let x = 0; x < width; x += 3) {
          const offset = (y * width + x) * 4;
          if (data[offset] < 245 && data[offset + 1] < 245 && data[offset + 2] < 245) darkPixels++;
        }
      }
      return darkPixels;
    });
    expect(watermarkPixels).toBeGreaterThan(50);
    const textSpan = root.locator('.farast-pdf-text-layer span').first();
    await expect(textSpan).toBeVisible();
    await expect(textSpan).toHaveCSS('user-select', 'text');
    await root.locator('#reader-search-input').fill('FARAST');
    await root.locator('#reader-search').click();
    await expect(root.locator('#reader-search-count')).toContainText('۱');
    await expect(root.locator('.farast-pdf-text-layer .reader-search-hit')).toHaveCount(1);
    await root.locator('#reader-search-input').fill('');
    await root.locator('#reader-search').click();
    await expect(root.locator('#reader-search-count')).toBeEmpty();
    await expect(root.locator('.farast-pdf-text-layer .reader-search-hit')).toHaveCount(0);
    await root.locator('#reader-zoom-in').click();
    await expect(root.locator('#reader-zoom-label')).toContainText('۱۱۰');
    await root.locator('#reader-scroll-mode').click();
    await expect(root.locator('.farast-pdf-continuous-pages')).toBeVisible();
    await expect(root.locator('.farast-pdf-cont-page canvas')).toBeVisible();
    await expect(root.locator('.farast-pdf-cont-page')).toHaveAttribute('data-rendered', '1');
    // Page-number navigation in continuous mode must scroll the matching page into view.
    const continuousPage = root.locator('.farast-pdf-cont-page').first();
    await continuousPage.evaluate(element => {
      element.dataset.scrollCalls = '0';
      element.scrollIntoView = () => { element.dataset.scrollCalls = String(Number(element.dataset.scrollCalls) + 1); };
    });
    await root.locator('#reader-page-number').fill('1');
    await root.locator('#reader-page-number').dispatchEvent('change');
    await expect(continuousPage).toHaveAttribute('data-scroll-calls', '1');
    // Continuous rendering must not lock the rest of the reading toolbar.
    await expect(root.locator('#reader-zoom-in')).toBeEnabled();
    await root.locator('#reader-zoom-in').click();
    await expect(root.locator('#reader-zoom-label')).toContainText('۱۲۰');
    await expect(root.locator('#reader-prev')).toBeEnabled();
    await root.locator('#reader-scroll-mode').click();
    await expect(root.locator('.farast-pdf-continuous-pages')).toBeHidden();
  });

  test('continuous PDF mode releases canvases outside the viewport window', async ({ page }) => {
    await mountPdfReader(page, 12);
    const root = page.locator('[data-reader-root]');
    await expect(root.locator('.farast-pdf-loading')).toBeHidden({ timeout: 45000 });
    await root.locator('#reader-scroll-mode').click();
    const pages = root.locator('.farast-pdf-cont-page');
    await expect(pages).toHaveCount(12);
    await expect.poll(() => pages.locator('canvas').evaluateAll(canvases => canvases.filter(canvas => canvas.width > 0).length)).toBeLessThan(12);
    await expect.poll(() => root.locator('#reader-stage').evaluate(element => element.scrollHeight)).toBeGreaterThan(600);

    await root.locator('#reader-page-number').fill('12');
    await root.locator('#reader-page-number').dispatchEvent('change');
    await expect.poll(() => pages.last().locator('canvas').evaluate(canvas => canvas.width)).toBeGreaterThan(0);
    await expect.poll(() => pages.first().locator('canvas').evaluate(canvas => canvas.width)).toBe(0);
    await expect.poll(() => pages.locator('canvas').evaluateAll(canvases => canvases.filter(canvas => canvas.width > 0).length)).toBeLessThan(12);
  });

  test('navigates between sample pages without loading PDF.js for image-only previews', async ({ page }) => {
    const pdfRequests = [];
    page.on('request', request => {
      if (request.url().includes('pdfjs-dist')) pdfRequests.push(request.url());
    });
    await mountReader(page);
    const root = page.locator('[data-reader-root]');
    await expect(root.locator('.farast-reader-page-item.is-active')).toHaveAttribute('data-index', '0');
    await root.locator('#reader-next').click();
    await expect(root.locator('.farast-reader-page-item.is-active')).toHaveAttribute('data-index', '1');
    await expect(root.locator('#reader-page-number')).toHaveValue('2');
    await expect(root.locator('.farast-reader-progress')).toHaveAttribute('aria-valuenow', '100');
    await root.locator('#reader-prev').click();
    await expect(root.locator('.farast-reader-page-item.is-active')).toHaveAttribute('data-index', '0');
    await expect(root.locator('.farast-reader-thumb[data-goto="0"]')).toHaveAttribute('aria-current', 'page');
    expect(pdfRequests).toHaveLength(0);
  });

  test('supports zoom, fit-width reset, rotation, theme and sidebar controls', async ({ page }) => {
    await mountReader(page);
    const root = page.locator('[data-reader-root]');
    const image = root.locator('.farast-reader-page-item.is-active img');
    await root.locator('#reader-zoom-in').click();
    await expect(image).toHaveCSS('transform', /matrix\(1\.1,/);
    await root.locator('#reader-fit').click();
    await expect(image).toHaveCSS('transform', 'matrix(1, 0, 0, 1, 0, 0)');
    await root.locator('#reader-rotate').click();
    await expect(image).toHaveCSS('transform', /matrix\(0,/);
    await root.locator('#reader-theme').click();
    await expect(root).toHaveAttribute('data-theme', 'light');
    await root.locator('#reader-thumbs-toggle').click();
    await expect(root).toHaveClass(/is-sidebar-hidden/);
    await expect(root.locator('#reader-thumbs-toggle')).toHaveAttribute('aria-expanded', 'false');
  });

  test('opens a sandboxed Excel workbook item and keeps reader zoom controls available', async ({ page }) => {
    await mountReader(page, true);
    const root = page.locator('[data-reader-root]');
    await root.locator('#reader-next').click();
    await root.locator('#reader-next').click();
    const iframe = root.locator('.farast-reader-page-item.is-active iframe.farast-xlsx-frame');
    await expect(iframe).toHaveAttribute('sandbox', '');
    await expect(root.locator('#reader-page-number')).toHaveValue('3');
    await expect(root.locator('#reader-scroll-mode')).toBeHidden();
    await expect(root.locator('.reader-search-group')).toBeHidden();
    await expect(root.locator('#reader-outline')).toBeHidden();
    await expect(root.locator('#reader-rotate')).toBeHidden();
    await root.locator('#reader-zoom-in').click();
    await expect(iframe).toHaveCSS('transform', /matrix\(1\.1,/);
  });

  test('keyboard navigation works and copy, cut, drag and context-menu events are suppressed', async ({ page }) => {
    await mountReader(page);
    const root = page.locator('[data-reader-root]');
    await page.keyboard.press('ArrowLeft');
    await expect(root.locator('.farast-reader-page-item.is-active')).toHaveAttribute('data-index', '1');

    const results = await page.evaluate(() => {
      const target = document.querySelector('[data-reader-root]');
      const input = document.querySelector('#reader-search-input');
      const copy = new ClipboardEvent('copy', { bubbles: true, cancelable: true });
      const cut = new ClipboardEvent('cut', { bubbles: true, cancelable: true });
      const paste = new ClipboardEvent('paste', { bubbles: true, cancelable: true });
      const select = new Event('selectstart', { bubbles: true, cancelable: true });
      const menu = new MouseEvent('contextmenu', { bubbles: true, cancelable: true });
      const drag = new DragEvent('dragstart', { bubbles: true, cancelable: true });
      const print = new KeyboardEvent('keydown', { key: 'p', ctrlKey: true, bubbles: true, cancelable: true });
      const save = new KeyboardEvent('keydown', { key: 's', ctrlKey: true, bubbles: true, cancelable: true });
      target.dispatchEvent(copy);
      target.dispatchEvent(cut);
      target.dispatchEvent(paste);
      target.dispatchEvent(select);
      target.dispatchEvent(menu);
      target.dispatchEvent(drag);
      input.dispatchEvent(print);
      input.dispatchEvent(save);
      return {
        copy: copy.defaultPrevented, cut: cut.defaultPrevented, paste: paste.defaultPrevented,
        select: select.defaultPrevented, menu: menu.defaultPrevented, drag: drag.defaultPrevented,
        print: print.defaultPrevented, save: save.defaultPrevented,
      };
    });
    expect(results).toEqual({ copy: true, cut: true, paste: true, select: true, menu: true, drag: true, print: true, save: true });
  });

  test('print media hides reader content and displays the online-only notice', async ({ page }) => {
    await mountReader(page);
    const root = page.locator('[data-reader-root]');
    await page.emulateMedia({ media: 'print' });
    await expect(root.locator('.farast-reader-print-block')).toBeVisible();
    await expect(root.locator('#reader-stage')).not.toBeVisible();
  });
  test('plays a prerecorded track and maps timestamped Persian words onto the PDF text layer', async ({ page }) => {
    const sampleRate = 8000;
    const sampleCount = sampleRate * 2;
    const dataSize = sampleCount * 2;
    const wav = Buffer.alloc(44 + dataSize);
    wav.write('RIFF', 0);
    wav.writeUInt32LE(36 + dataSize, 4);
    wav.write('WAVE', 8);
    wav.write('fmt ', 12);
    wav.writeUInt32LE(16, 16);
    wav.writeUInt16LE(1, 20);
    wav.writeUInt16LE(1, 22);
    wav.writeUInt32LE(sampleRate, 24);
    wav.writeUInt32LE(sampleRate * 2, 28);
    wav.writeUInt16LE(2, 32);
    wav.writeUInt16LE(16, 34);
    wav.write('data', 36);
    wav.writeUInt32LE(dataSize, 40);

    await page.goto('/');
    await page.route('**/reader-audio.wav', route => route.fulfill({
      status: 200,
      contentType: 'audio/wav',
      body: wav,
    }));
    const timings = [
      { text: 'سلام', start: 0, end: 0.7, page: 1 },
      { text: 'دنیا', start: 0.7, end: 1.4, page: 1 },
    ];
    await page.setContent(`
      <section class="farast-reader" data-reader-root data-product-id="audio-fixture">
        <nav><label class="reader-page-input"><input id="reader-page-number" value="1"></label></nav>
        <div id="reader-stage">
          <article class="farast-reader-page-item is-active" data-index="0"
            data-audio-url="/reader-audio.wav"
            data-audio-timing='${JSON.stringify(timings)}'>
            <div class="farast-pdf-document">
              <div class="farast-pdf-text-layer" style="position:relative;width:500px;height:120px">
                <span style="position:relative;display:inline-block;font-size:24px;direction:rtl">سلام دنیا</span>
              </div>
            </div>
          </article>
        </div>
      </section>
    `);
    await page.addScriptTag({ url: new URL('/js/farast-reader-audio.js', page.url()).href });
    await expect(page.locator('.farast-audio-dock')).toBeVisible();
    await expect.poll(() => page.locator('[data-audio-duration]').textContent()).not.toBe('00:00');

    await page.evaluate(() => window.FarastAudioReader.setPage(1));
    const marks = page.locator('.farast-pdf-text-layer .reader-audio-word');
    await expect(marks).toHaveCount(2);
    await expect(marks.nth(0)).toHaveClass(/is-current/);
    await expect(page.locator('[data-audio-caption]')).toContainText('سلام');
  });

  test('uses phrase-level labels when only sentence timestamps are supplied', async ({ page }) => {
    const sampleRate = 8000;
    const sampleCount = sampleRate * 2;
    const dataSize = sampleCount * 2;
    const wav = Buffer.alloc(44 + dataSize);
    wav.write('RIFF', 0);
    wav.writeUInt32LE(36 + dataSize, 4);
    wav.write('WAVE', 8);
    wav.write('fmt ', 12);
    wav.writeUInt32LE(16, 16);
    wav.writeUInt16LE(1, 20);
    wav.writeUInt16LE(1, 22);
    wav.writeUInt32LE(sampleRate, 24);
    wav.writeUInt32LE(sampleRate * 2, 28);
    wav.writeUInt16LE(2, 32);
    wav.writeUInt16LE(16, 34);
    wav.write('data', 36);
    wav.writeUInt32LE(dataSize, 40);
    await page.goto('/');
    await page.route('**/reader-audio.wav', route => route.fulfill({
      status: 200,
      contentType: 'audio/wav',
      body: wav,
    }));
    const timings = [{ text: 'سلام به همه', start: 0, end: 1.2, page: 1 }];
    await page.setContent(`
      <section class="farast-reader" data-reader-root>
        <div id="reader-stage"><article class="farast-reader-page-item is-active" data-index="0"
          data-audio-url="/reader-audio.wav" data-audio-timing='${JSON.stringify(timings)}'>
          <div class="farast-pdf-document"><div class="farast-pdf-text-layer" style="position:relative;width:500px;height:120px">
            <span style="position:relative;display:inline-block;font-size:24px;direction:rtl">سلام به همه</span>
          </div></div>
        </article></div>
      </section>`);
    await page.addScriptTag({ url: new URL('/js/farast-reader-audio.js', page.url()).href });
    await expect(page.locator('.farast-audio-dock')).toBeVisible();
    await expect(page.locator('[data-audio-status]')).toContainText('زمان‌بندی تقریبی واژه‌ها');
    await expect(page.locator('[data-audio-progress]')).toContainText('بخش');
    await expect(page.locator('.reader-audio-word')).toHaveCount(3);
    await expect(page.locator('.reader-audio-word').first()).toHaveAttribute('aria-label', 'رفتن به بخش سلام');
  });


  test('clicking or keyboard-activating an HTML document word plays only its precomputed aligned interval', async ({ page }) => {
    await page.addInitScript(() => {
      class FakeAudio extends EventTarget {
        constructor() {
          super();
          this.currentTime = 0;
          this.duration = 2;
          this.paused = true;
          this.seeking = false;
          this.playbackRate = 1;
          this.volume = 1;
          this.src = '';
          window.__fakeReaderAudio = this;
        }
        setAttribute() {}
        load() { this.dispatchEvent(new Event('loadedmetadata')); }
        play() {
          this.paused = false;
          this.dispatchEvent(new Event('play'));
          return Promise.resolve();
        }
        pause() {
          if (!this.paused) {
            this.paused = true;
            this.dispatchEvent(new Event('pause'));
          }
        }
      }
      window.Audio = FakeAudio;
      window.__snippetRequests = 0;
      const originalFetch = window.fetch.bind(window);
      window.fetch = (input, ...args) => {
        if (String(input).includes('/reader/speech')) window.__snippetRequests += 1;
        return originalFetch(input, ...args);
      };
    });
    await page.goto('/');
    const timings = [{
      text: 'سلام جهان',
      start: 0.1,
      end: 0.7,
      page: 1,
      alignment_quality: 'phoneme',
      words: [
        { text: 'سلام', start: 0.1, end: 0.3 },
        { text: 'جهان', start: 0.5, end: 0.7 }
      ]
    }];
    const encodedTimings = JSON.stringify(timings).replace(/"/g, '&quot;');
    await page.setContent(
      '<section class="farast-reader" data-reader-root data-reader-audio-url="/reader-full.wav">' +
        '<nav><input id="reader-page-number" value="1"></nav>' +
        '<article class="farast-reader-page-item is-active" data-index="0" data-reader-page="1" ' +
          'data-audio-url="/reader-full.wav" data-audio-timing="' + encodedTimings + '">' +
          '<p class="reader-document-paragraph"><span class="reader-document-sentence" data-page="1">' +
            '<span class="reader-document-word" data-page="1" role="button" tabindex="0">سلام</span>' +
            '<span> </span>' +
            '<span class="reader-document-word" data-page="1" role="button" tabindex="0">جهان</span>' +
          '</span></p>' +
        '</article>' +
      '</section>'
    );
    await page.addScriptTag({ url: new URL('/js/farast-reader-audio.js', page.url()).href });
    const words = page.locator('.reader-document-word');
    await words.nth(0).click();
    await expect.poll(() => page.evaluate(() => window.__fakeReaderAudio.currentTime)).toBe(0.1);
    // The caption stays at sentence level; the playback range is asserted below at word boundaries.
    await expect(page.locator('[data-audio-caption]')).toContainText('سلام جهان');

    const stopped = await page.evaluate(() => {
      const audio = window.__fakeReaderAudio;
      audio.currentTime = 0.3;
      audio.dispatchEvent(new Event('timeupdate'));
      return { paused: audio.paused, currentTime: audio.currentTime };
    });
    expect(stopped.paused).toBe(true);
    expect(stopped.currentTime).toBe(0.3);

    await words.nth(1).focus();
    await page.keyboard.press('Enter');
    await expect.poll(() => page.evaluate(() => window.__fakeReaderAudio.currentTime)).toBe(0.5);
    expect(await page.evaluate(() => window.__snippetRequests)).toBe(0);
  });


  test('keeps one full-document audio source and timeline continuous when the reader changes pages', async ({ page }) => {
    await page.addInitScript(() => {
      class FakeAudio extends EventTarget {
        constructor() {
          super();
          this.currentTime = 0;
          this.duration = 2;
          this.paused = true;
          this.seeking = false;
          this.playbackRate = 1;
          this.volume = 1;
          this.src = '';
          this.loadCount = 0;
          window.__fakeReaderAudio = this;
        }
        setAttribute() {}
        load() { this.loadCount += 1; this.dispatchEvent(new Event('loadedmetadata')); }
        play() { this.paused = false; this.dispatchEvent(new Event('play')); return Promise.resolve(); }
        pause() { if (!this.paused) { this.paused = true; this.dispatchEvent(new Event('pause')); } }
      }
      window.Audio = FakeAudio;
    });
    await page.goto('/');
    const timings = [
      { text: 'صفحه اول', start: 0.1, end: 0.4, page: 1, alignment_quality: 'phoneme', words: [
        { text: 'صفحه', start: 0.1, end: 0.2 }, { text: 'اول', start: 0.2, end: 0.4 }
      ] },
      { text: 'صفحه دوم', start: 0.5, end: 0.9, page: 2, alignment_quality: 'phoneme', words: [
        { text: 'صفحه', start: 0.5, end: 0.65 }, { text: 'دوم', start: 0.65, end: 0.9 }
      ] }
    ];
    const encodedTimings = JSON.stringify(timings).replace(/"/g, '&quot;');
    await page.setContent(
      '<section class="farast-reader" data-reader-root data-reader-mode="document" ' +
        'data-reader-audio-url="/reader-full.wav" data-reader-audio-timing="' + encodedTimings + '">' +
        '<input id="reader-page-number" value="1">' +
        '<article class="farast-reader-page-item is-active" data-index="0" data-reader-page="1" data-audio-url="/reader-full.wav"></article>' +
        '<article class="farast-reader-page-item" data-index="1" data-reader-page="2" data-audio-url="/reader-full.wav"></article>' +
      '</section>'
    );
    await page.addScriptTag({ url: new URL('/js/farast-reader-audio.js', page.url()).href });

    const before = await page.evaluate(() => {
      const audio = window.__fakeReaderAudio;
      audio.currentTime = 0.6;
      audio.play();
      audio.dispatchEvent(new Event('timeupdate'));
      document.querySelector('[data-reader-root]').dispatchEvent(
        new CustomEvent('farast:reader-section-change', { detail: { section: 1 } })
      );
      return { currentTime: audio.currentTime, paused: audio.paused, loads: audio.loadCount };
    });
    expect(before.currentTime).toBe(0.6);
    expect(before.paused).toBe(false);
    expect(before.loads).toBe(1);
  });

});


test('plays the exact repeated paragraph occurrence and supports blank-page mouse and keyboard narration', async ({ page }) => {
  await page.addInitScript(() => {
    class FakeAudio extends EventTarget {
      constructor() {
        super();
        this.currentTime = 0;
        this.duration = 2;
        this.paused = true;
        this.seeking = false;
        this.playbackRate = 1;
        this.volume = 1;
        this.src = '';
        this.currentSrc = '';
        window.__fakeReaderAudio = this;
      }
      setAttribute() {}
      load() { this.currentSrc = this.src; this.dispatchEvent(new Event('loadedmetadata')); }
      play() { this.paused = false; this.dispatchEvent(new Event('play')); return Promise.resolve(); }
      pause() { if (!this.paused) { this.paused = true; this.dispatchEvent(new Event('pause')); } }
      removeAttribute(name) { if (name === 'src') { this.src = ''; this.currentSrc = ''; } }
    }
    window.Audio = FakeAudio;
  });
  await page.goto('/');
  const timings = [{
    text: 'سلام جهان سلام جهان',
    start: 0.1,
    end: 0.9,
    page: 1,
    alignment_quality: 'phoneme',
    words: [
      { text: 'سلام', start: 0.1, end: 0.25 },
      { text: 'جهان', start: 0.25, end: 0.4 },
      { text: 'سلام', start: 0.5, end: 0.65 },
      { text: 'جهان', start: 0.65, end: 0.9 }
    ]
  }];
  const encodedTimings = JSON.stringify(timings).replace(/"/g, '&quot;');
  await page.setContent(
    '<section class="farast-reader" data-reader-root data-reader-mode="document" ' +
      'data-reader-audio-url="/reader-full.wav" data-reader-audio-timing="' + encodedTimings + '">' +
      '<input id="reader-page-number" value="1">' +
      '<article class="farast-reader-page-item is-active" data-index="0" data-reader-page="1" data-audio-url="/reader-full.wav">' +
        '<p class="reader-document-paragraph" tabindex="0" data-reader-speech-text="سلام جهان">سلام جهان</p>' +
        '<p class="reader-document-paragraph" tabindex="0" data-reader-speech-text="سلام جهان">سلام جهان</p>' +
        '<div class="farast-pdf-text-layer" style="width:240px;height:120px"></div>' +
      '</article>' +
    '</section>'
  );
  await page.addScriptTag({ url: new URL('/js/farast-reader-audio.js', page.url()).href });

  const paragraphs = page.locator('.reader-document-paragraph');
  await paragraphs.nth(1).click();
  await expect.poll(() => page.evaluate(() => window.__fakeReaderAudio?.currentTime)).toBe(0.5);

  const article = page.locator('.farast-reader-page-item');
  await expect(article).toHaveAttribute('tabindex', '0');
  await expect(article).toHaveAttribute('aria-label', 'خواندن صفحه ۱');
  await page.locator('.farast-pdf-text-layer').click({ position: { x: 220, y: 100 } });
  await expect.poll(() => page.evaluate(() => window.__fakeReaderAudio?.currentTime)).toBe(0.1);

  await page.evaluate(() => {
    const audio = window.__fakeReaderAudio;
    audio.currentTime = 0.9;
    audio.dispatchEvent(new Event('timeupdate'));
  });
  await expect.poll(() => page.evaluate(() => window.__fakeReaderAudio?.paused)).toBe(true);

  await article.focus();
  await page.keyboard.press('Enter');
  await expect.poll(() => page.evaluate(() => window.__fakeReaderAudio?.currentTime)).toBe(0.1);
});


test('scopes repeated phrase matching to the selected continuous PDF page', async ({ page }) => {
  await page.addInitScript(() => {
    class FakeAudio extends EventTarget {
      constructor() {
        super();
        this.currentTime = 0;
        this.duration = 2;
        this.paused = true;
        this.seeking = false;
        this.playbackRate = 1;
        this.volume = 1;
        this.src = '';
        this.currentSrc = '';
        window.__fakeReaderAudio = this;
      }
      setAttribute() {}
      load() { this.currentSrc = this.src; this.dispatchEvent(new Event('loadedmetadata')); }
      play() { this.paused = false; this.dispatchEvent(new Event('play')); return Promise.resolve(); }
      pause() { if (!this.paused) { this.paused = true; this.dispatchEvent(new Event('pause')); } }
      removeAttribute(name) { if (name === 'src') { this.src = ''; this.currentSrc = ''; } }
    }
    window.Audio = FakeAudio;
  });
  await page.goto('/');
  const timings = [
    { text: 'سلام جهان', start: 0.1, end: 0.4, page: 1, words: [
      { text: 'سلام', start: 0.1, end: 0.25 }, { text: 'جهان', start: 0.25, end: 0.4 }
    ] },
    { text: 'سلام جهان', start: 0.6, end: 0.9, page: 2, words: [
      { text: 'سلام', start: 0.6, end: 0.75 }, { text: 'جهان', start: 0.75, end: 0.9 }
    ] }
  ];
  const encodedTimings = JSON.stringify(timings).replace(/"/g, '&quot;');
  await page.setContent(
    '<section class="farast-reader" data-reader-root data-reader-mode="document" ' +
      'data-reader-audio-url="/reader-full.wav" data-reader-audio-timing="' + encodedTimings + '">' +
      '<input id="reader-page-number" value="1">' +
      '<article class="farast-reader-page-item is-active" data-index="0" data-reader-page="1" data-audio-url="/reader-full.wav">' +
        '<div class="farast-pdf-cont-page" data-pdf-cont-page="1"><p class="reader-document-sentence" data-reader-speech-text="سلام جهان">سلام جهان</p></div>' +
        '<div class="farast-pdf-cont-page" data-pdf-cont-page="2"><p class="reader-document-sentence" data-reader-speech-text="سلام جهان">سلام جهان</p></div>' +
      '</article>' +
    '</section>'
  );
  await page.addScriptTag({ url: new URL('/js/farast-reader-audio.js', page.url()).href });

  await page.locator('[data-pdf-cont-page="2"] .reader-document-sentence').click();
  await expect.poll(() => page.evaluate(() => window.__fakeReaderAudio?.currentTime)).toBe(0.6);
});


test('shows pending narration state and checks the protected audio artifact without exposing storage paths', async ({ page }) => {
  let polls = 0;
  await page.route('**/store/product/1/reader/audio/2*', async route => {
    expect(route.request().method()).toBe('HEAD');
    polls += 1;
    await route.fulfill({ status: 404, headers: { 'Cache-Control': 'private, no-store' } });
  });
  await page.goto('/');
  await page.setContent(
    '<section data-reader-root data-reader-audio-status="pending" ' +
      'data-reader-audio-poll-url="/store/product/1/reader/audio/2?version=3">' +
      '<div data-reader-audio-pending role="status" aria-live="polite" hidden></div>' +
    '</section>'
  );
  await page.addScriptTag({ url: new URL('/js/farast-reader-document-audio.js', page.url()).href });
  const status = page.locator('[data-reader-audio-pending]');
  await expect(status).toBeVisible();
  await expect(status).toContainText('در حال آماده‌سازی');
  await expect.poll(() => polls).toBe(1);
});


test('highlights the exact active HTML word while the precomputed document audio plays', async ({ page }) => {
  await page.addInitScript(() => {
    class FakeAudio extends EventTarget {
      constructor() {
        super();
        this.currentTime = 0;
        this.duration = 2;
        this.paused = true;
        this.seeking = false;
        this.playbackRate = 1;
        this.volume = 1;
        this.src = '';
        this.currentSrc = '';
        window.__fakeReaderAudio = this;
      }
      setAttribute() {}
      load() { this.currentSrc = this.src; this.dispatchEvent(new Event('loadedmetadata')); }
      play() { this.paused = false; this.dispatchEvent(new Event('play')); return Promise.resolve(); }
      pause() { if (!this.paused) { this.paused = true; this.dispatchEvent(new Event('pause')); } }
      removeAttribute(name) { if (name === 'src') { this.src = ''; this.currentSrc = ''; } }
    }
    window.Audio = FakeAudio;
  });
  await page.goto('/');
  const timings = [{
    text: 'سلام جهان',
    start: 0.1,
    end: 0.7,
    page: 1,
    alignment_quality: 'phoneme',
    words: [
      { text: 'سلام', start: 0.1, end: 0.3 },
      { text: 'جهان', start: 0.5, end: 0.7 }
    ]
  }];
  const encodedTimings = JSON.stringify(timings).replace(/"/g, '&quot;');
  await page.setContent(
    '<section class="farast-reader" data-reader-root data-reader-mode="document" ' +
      'data-reader-audio-url="/reader-full.wav" data-reader-audio-timing="' + encodedTimings + '">' +
      '<article class="farast-reader-page-item is-active" data-index="0" data-reader-page="1" data-audio-url="/reader-full.wav">' +
        '<p class="reader-document-paragraph" data-reader-speech-text="سلام جهان">' +
          '<span class="reader-document-sentence" data-reader-speech-text="سلام جهان">' +
            '<span class="reader-document-word" data-page="1" role="button" tabindex="0">سلام</span>' +
            '<span> </span>' +
            '<span class="reader-document-word" data-page="1" role="button" tabindex="0">جهان</span>' +
          '</span>' +
        '</p>' +
      '</article>' +
    '</section>'
  );
  await page.addScriptTag({ url: new URL('/js/farast-reader-audio.js', page.url()).href });
  const words = page.locator('.reader-document-word');
  await words.first().click();
  await expect.poll(() => page.evaluate(() => window.__fakeReaderAudio?.currentTime)).toBe(0.1);
  await page.evaluate(() => window.FarastAudioReader.playRange(0.1, 0.7, 'در حال خواندن جمله'));
  await page.evaluate(() => {
    const audio = window.__fakeReaderAudio;
    audio.currentTime = 0.55;
    audio.dispatchEvent(new Event('timeupdate'));
  });
  await expect(words.nth(1)).toHaveClass(/is-current/);
  await expect(words.first()).not.toHaveClass(/is-current/);
});


test('sentence and selected phrase playback use the complete exact text range', async ({ page }) => {
  await page.addInitScript(() => {
    class FakeAudio extends EventTarget {
      constructor() {
        super();
        this.currentTime = 0;
        this.duration = 2;
        this.paused = true;
        this.seeking = false;
        this.playbackRate = 1;
        this.volume = 1;
        this.src = '';
        this.currentSrc = '';
        window.__fakeReaderAudio = this;
      }
      setAttribute() {}
      load() { this.currentSrc = this.src; this.dispatchEvent(new Event('loadedmetadata')); }
      play() { this.paused = false; this.dispatchEvent(new Event('play')); return Promise.resolve(); }
      pause() { if (!this.paused) { this.paused = true; this.dispatchEvent(new Event('pause')); } }
      removeAttribute(name) { if (name === 'src') { this.src = ''; this.currentSrc = ''; } }
    }
    window.Audio = FakeAudio;
  });
  await page.goto('/');
  const timings = [{
    text: 'سلام جهان',
    start: 0.1,
    end: 0.7,
    page: 1,
    alignment_quality: 'phoneme',
    words: [
      { text: 'سلام', start: 0.1, end: 0.3 },
      { text: 'جهان', start: 0.5, end: 0.7 }
    ]
  }];
  const encodedTimings = JSON.stringify(timings).replace(/"/g, '&quot;');
  await page.setContent(
    '<section class="farast-reader" data-reader-root data-reader-mode="document" ' +
      'data-reader-audio-url="/reader-full.wav" data-reader-audio-timing="' + encodedTimings + '">' +
      '<article class="farast-reader-page-item is-active" data-index="0" data-reader-page="1" data-audio-url="/reader-full.wav">' +
        '<p class="reader-document-paragraph" data-reader-speech-text="سلام جهان" style="display:block;width:320px;height:80px">' +
          '<span class="reader-document-sentence" data-reader-speech-text="سلام جهان" style="display:block;width:320px;height:60px">' +
            '<span class="reader-document-word" data-page="1" role="button" tabindex="0">سلام</span>' +
            '<span> </span>' +
            '<span class="reader-document-word" data-page="1" role="button" tabindex="0">جهان</span>' +
          '</span>' +
        '</p>' +
      '</article>' +
    '</section>'
  );
  await page.addScriptTag({ url: new URL('/js/farast-reader-audio.js', page.url()).href });

  const sentence = page.locator('.reader-document-sentence');
  await sentence.click({ position: { x: 300, y: 50 } });
  await expect.poll(() => page.evaluate(() => window.__fakeReaderAudio?.currentTime)).toBe(0.1);
  await page.evaluate(() => {
    const audio = window.__fakeReaderAudio;
    audio.currentTime = 0.7;
    audio.dispatchEvent(new Event('timeupdate'));
  });
  await expect.poll(() => page.evaluate(() => window.__fakeReaderAudio?.paused)).toBe(true);
  await expect.poll(() => page.evaluate(() => window.__fakeReaderAudio?.currentTime)).toBe(0.7);

  await page.evaluate(() => {
    const sentenceNode = document.querySelector('.reader-document-sentence');
    const range = document.createRange();
    range.selectNodeContents(sentenceNode);
    const selection = window.getSelection();
    selection.removeAllRanges();
    selection.addRange(range);
    document.querySelector('[data-reader-root]').dispatchEvent(new PointerEvent('pointerup', { bubbles: true }));
  });
  await expect.poll(() => page.evaluate(() => window.__fakeReaderAudio?.currentTime)).toBe(0.1);
  await page.evaluate(() => {
    const audio = window.__fakeReaderAudio;
    audio.currentTime = 0.7;
    audio.dispatchEvent(new Event('timeupdate'));
  });
  await expect.poll(() => page.evaluate(() => window.__fakeReaderAudio?.paused)).toBe(true);
  await expect.poll(() => page.evaluate(() => window.__fakeReaderAudio?.currentTime)).toBe(0.7);
});


test('clicking a repeated sentence across PDF text spans plays the sentence at the clicked location', async ({ page }) => {
  await page.addInitScript(() => {
    class FakeAudio extends EventTarget {
      constructor() {
        super();
        this.currentTime = 0;
        this.duration = 2;
        this.paused = true;
        this.seeking = false;
        this.playbackRate = 1;
        this.volume = 1;
        this.src = '';
        this.currentSrc = '';
        window.__fakeReaderAudio = this;
      }
      setAttribute() {}
      load() { this.currentSrc = this.src; this.dispatchEvent(new Event('loadedmetadata')); }
      play() { this.paused = false; this.dispatchEvent(new Event('play')); return Promise.resolve(); }
      pause() { if (!this.paused) { this.paused = true; this.dispatchEvent(new Event('pause')); } }
      removeAttribute(name) { if (name === 'src') { this.src = ''; this.currentSrc = ''; } }
    }
    window.Audio = FakeAudio;
  });
  await page.goto('/');
  const timings = [
    { text: 'سلام جهان.', start: 0.1, end: 0.4, page: 1, alignment_quality: 'phoneme', words: [
      { text: 'سلام', start: 0.1, end: 0.25 }, { text: 'جهان', start: 0.25, end: 0.4 }
    ] },
    { text: 'سلام جهان.', start: 0.5, end: 0.8, page: 1, alignment_quality: 'phoneme', words: [
      { text: 'سلام', start: 0.5, end: 0.65 }, { text: 'جهان', start: 0.65, end: 0.8 }
    ] }
  ];
  const encodedTimings = JSON.stringify(timings).replace(/"/g, '&quot;');
  await page.setContent(
    '<section class="farast-reader" data-reader-root data-reader-mode="document" ' +
      'data-reader-audio-url="/reader-full.wav" data-reader-audio-timing="' + encodedTimings + '">' +
      '<input id="reader-page-number" value="1">' +
      '<article class="farast-reader-page-item is-active" data-index="0" data-reader-page="1" data-audio-url="/reader-full.wav">' +
        '<div class="farast-pdf-text-layer" style="width:320px;height:80px">سلام جهان. سلام جهان.</div>' +
      '</article>' +
    '</section>'
  );
  await page.addScriptTag({ url: new URL('/js/farast-reader-audio.js', page.url()).href });
  await page.evaluate(() => {
    const node = document.querySelector('.farast-pdf-text-layer').firstChild;
    Object.defineProperty(document, 'caretRangeFromPoint', {
      configurable: true,
      value: () => {
        const range = document.createRange();
        range.setStart(node, 13);
        range.collapse(true);
        return range;
      }
    });
  });
  await page.locator('.farast-pdf-text-layer').click({ position: { x: 250, y: 20 } });
  await expect.poll(() => page.evaluate(() => window.__fakeReaderAudio?.currentTime)).toBe(0.5);
  await page.evaluate(() => {
    const audio = window.__fakeReaderAudio;
    audio.currentTime = 0.8;
    audio.dispatchEvent(new Event('timeupdate'));
  });
  await expect.poll(() => page.evaluate(() => window.__fakeReaderAudio?.paused)).toBe(true);
  await expect.poll(() => page.evaluate(() => window.__fakeReaderAudio?.currentTime)).toBe(0.8);
});


test('explicit sentence and paragraph controls play their own exact ranges', async ({ page }) => {
  await page.addInitScript(() => {
    class FakeAudio extends EventTarget {
      constructor() {
        super();
        this.currentTime = 0;
        this.duration = 2;
        this.paused = true;
        this.seeking = false;
        this.playbackRate = 1;
        this.volume = 1;
        this.src = '';
        this.currentSrc = '';
        window.__fakeReaderAudio = this;
      }
      setAttribute() {}
      load() { this.currentSrc = this.src; this.dispatchEvent(new Event('loadedmetadata')); }
      play() { this.paused = false; this.dispatchEvent(new Event('play')); return Promise.resolve(); }
      pause() { if (!this.paused) { this.paused = true; this.dispatchEvent(new Event('pause')); } }
      removeAttribute(name) { if (name === 'src') { this.src = ''; this.currentSrc = ''; } }
    }
    window.Audio = FakeAudio;
  });
  await page.goto('/');
  const timings = [
    { text: 'سلام جهان.', start: 0.1, end: 0.4, page: 1, alignment_quality: 'phoneme', words: [
      { text: 'سلام', start: 0.1, end: 0.25 }, { text: 'جهان.', start: 0.25, end: 0.4 }
    ] },
    { text: 'خوب هستی.', start: 0.5, end: 0.8, page: 1, alignment_quality: 'phoneme', words: [
      { text: 'خوب', start: 0.5, end: 0.65 }, { text: 'هستی.', start: 0.65, end: 0.8 }
    ] }
  ];
  const encodedTimings = JSON.stringify(timings).replace(/"/g, '&quot;');
  await page.setContent(
    '<section class="farast-reader" data-reader-root data-reader-mode="document" ' +
      'data-reader-audio-url="/reader-full.wav" data-reader-audio-timing="' + encodedTimings + '">' +
      '<article class="farast-reader-page-item is-active" data-index="0" data-reader-page="1" data-audio-url="/reader-full.wav">' +
        '<p class="reader-document-paragraph" data-reader-speech-text="سلام جهان. خوب هستی.">' +
          '<span class="reader-document-sentence" data-reader-speech-text="سلام جهان.">' +
            '<span class="reader-document-word" data-page="1" role="button" tabindex="0">سلام</span><span> </span>' +
            '<span class="reader-document-word" data-page="1" role="button" tabindex="0">جهان.</span>' +
          '</span><span> </span>' +
          '<span class="reader-document-sentence" data-reader-speech-text="خوب هستی.">' +
            '<span class="reader-document-word" data-page="1" role="button" tabindex="0">خوب</span><span> </span>' +
            '<span class="reader-document-word" data-page="1" role="button" tabindex="0">هستی.</span>' +
          '</span>' +
        '</p>' +
      '</article>' +
    '</section>'
  );
  await page.addScriptTag({ path: require.resolve('../../public/js/farast-reader-audio.js') });

  const paragraphControl = page.locator('[data-reader-speech-control="paragraph"]');
  await expect(paragraphControl).toHaveAttribute('aria-label', 'خواندن پاراگراف');
  await paragraphControl.click();
  await expect.poll(() => page.evaluate(() => window.__fakeReaderAudio?.currentTime)).toBe(0.1);
  await page.evaluate(() => {
    const audio = window.__fakeReaderAudio;
    audio.currentTime = 0.8;
    audio.dispatchEvent(new Event('timeupdate'));
  });
  await expect.poll(() => page.evaluate(() => window.__fakeReaderAudio?.paused)).toBe(true);
  await expect.poll(() => page.evaluate(() => window.__fakeReaderAudio?.currentTime)).toBe(0.8);

  const sentenceControl = page.locator('[data-reader-speech-control="sentence"]').nth(1);
  await expect(sentenceControl).toHaveAttribute('aria-label', 'خواندن جمله');
  await sentenceControl.click();
  await expect.poll(() => page.evaluate(() => window.__fakeReaderAudio?.currentTime)).toBe(0.5);
  await page.evaluate(() => {
    const audio = window.__fakeReaderAudio;
    audio.currentTime = 0.8;
    audio.dispatchEvent(new Event('timeupdate'));
  });
  await expect.poll(() => page.evaluate(() => window.__fakeReaderAudio?.paused)).toBe(true);
});


test('heading cell and spreadsheet row controls are independently keyboard reachable', async ({ page }) => {
  await page.addInitScript(() => {
    class FakeAudio extends EventTarget {
      constructor() {
        super();
        this.currentTime = 0;
        this.duration = 3;
        this.paused = true;
        this.seeking = false;
        this.playbackRate = 1;
        this.volume = 1;
        this.src = '';
        this.currentSrc = '';
        window.__fakeReaderAudio = this;
      }
      setAttribute() {}
      load() { this.currentSrc = this.src; this.dispatchEvent(new Event('loadedmetadata')); }
      play() { this.paused = false; this.dispatchEvent(new Event('play')); return Promise.resolve(); }
      pause() { if (!this.paused) { this.paused = true; this.dispatchEvent(new Event('pause')); } }
      removeAttribute(name) { if (name === 'src') { this.src = ''; this.currentSrc = ''; } }
    }
    window.Audio = FakeAudio;
  });
  await page.goto('/');
  const timings = [
    { text: 'عنوان گزارش', start: 0.1, end: 0.3, page: 1, words: [
      { text: 'عنوان', start: 0.1, end: 0.2 }, { text: 'گزارش', start: 0.2, end: 0.3 }
    ] },
    { text: 'فروش', start: 0.5, end: 0.7, page: 1, words: [
      { text: 'فروش', start: 0.5, end: 0.7 }
    ] },
    { text: 'فروش هزینه سود', start: 0.9, end: 1.4, page: 1, words: [
      { text: 'فروش', start: 0.9, end: 1.05 }, { text: 'هزینه', start: 1.05, end: 1.2 }, { text: 'سود', start: 1.2, end: 1.4 }
    ] }
  ];
  const encodedTimings = JSON.stringify(timings).replace(/"/g, '&quot;');
  await page.setContent(
    '<section class="farast-reader" data-reader-root data-reader-mode="document" ' +
      'data-reader-audio-url="/reader-full.wav" data-reader-audio-timing="' + encodedTimings + '">' +
      '<article class="farast-reader-page-item is-active" data-index="0" data-reader-page="1" data-audio-url="/reader-full.wav">' +
        '<h2 class="reader-document-heading" data-reader-speech-text="عنوان گزارش">عنوان گزارش</h2>' +
        '<table><tbody><tr class="reader-document-row" data-reader-speech-text="فروش هزینه سود">' +
          '<td class="reader-document-cell" data-reader-speech-text="فروش">فروش</td>' +
          '<td class="reader-document-cell">هزینه</td><td class="reader-document-cell">سود</td>' +
        '</tr></tbody></table>' +
      '</article></section>'
  );
  await page.addScriptTag({ path: require.resolve('../../public/js/farast-reader-audio.js') });

  const heading = page.locator('[data-reader-speech-control="heading"]');
  await expect(heading).toHaveAttribute('aria-label', 'خواندن عنوان');
  await heading.focus();
  await page.keyboard.press('Enter');
  await expect.poll(() => page.evaluate(() => window.__fakeReaderAudio?.currentTime)).toBe(0.1);
  await page.evaluate(() => { const audio = window.__fakeReaderAudio; audio.currentTime = 0.3; audio.dispatchEvent(new Event('timeupdate')); });
  await expect.poll(() => page.evaluate(() => window.__fakeReaderAudio?.paused)).toBe(true);

  const cell = page.locator('[data-reader-speech-control="cell"]').first();
  await cell.click();
  await expect.poll(() => page.evaluate(() => window.__fakeReaderAudio?.currentTime)).toBe(0.5);
  await page.evaluate(() => { const audio = window.__fakeReaderAudio; audio.currentTime = 0.7; audio.dispatchEvent(new Event('timeupdate')); });
  await expect.poll(() => page.evaluate(() => window.__fakeReaderAudio?.paused)).toBe(true);

  const row = page.locator('[data-reader-speech-control="row"]');
  await expect(row).toHaveAttribute('aria-label', 'خواندن ردیف');
  await row.click();
  await expect.poll(() => page.evaluate(() => window.__fakeReaderAudio?.currentTime)).toBe(0.9);
  await page.evaluate(() => { const audio = window.__fakeReaderAudio; audio.currentTime = 1.4; audio.dispatchEvent(new Event('timeupdate')); });
  await expect.poll(() => page.evaluate(() => window.__fakeReaderAudio?.paused)).toBe(true);
});


test('Enter reads a keyboard-selected phrase instead of only the focused word', async ({ page }) => {
  await page.addInitScript(() => {
    class FakeAudio extends EventTarget {
      constructor() {
        super();
        this.currentTime = 0;
        this.duration = 2;
        this.paused = true;
        this.seeking = false;
        this.playbackRate = 1;
        this.volume = 1;
        this.src = '';
        this.currentSrc = '';
        window.__fakeReaderAudio = this;
      }
      setAttribute() {}
      load() { this.currentSrc = this.src; this.dispatchEvent(new Event('loadedmetadata')); }
      play() { this.paused = false; this.dispatchEvent(new Event('play')); return Promise.resolve(); }
      pause() { if (!this.paused) { this.paused = true; this.dispatchEvent(new Event('pause')); } }
      removeAttribute(name) { if (name === 'src') { this.src = ''; this.currentSrc = ''; } }
    }
    window.Audio = FakeAudio;
  });
  await page.goto('/');
  const timings = [{
    text: 'سلام جهان',
    start: 0.1,
    end: 0.7,
    page: 1,
    alignment_quality: 'phoneme',
    words: [
      { text: 'سلام', start: 0.1, end: 0.3 },
      { text: 'جهان', start: 0.5, end: 0.7 }
    ]
  }];
  const encodedTimings = JSON.stringify(timings).replace(/"/g, '&quot;');
  await page.setContent(
    '<section class="farast-reader" data-reader-root data-reader-mode="document" ' +
      'data-reader-audio-url="/reader-full.wav" data-reader-audio-timing="' + encodedTimings + '">' +
      '<article class="farast-reader-page-item is-active" data-index="0" data-reader-page="1" data-audio-url="/reader-full.wav">' +
        '<p class="reader-document-paragraph" data-reader-speech-text="سلام جهان">' +
          '<span class="reader-document-sentence" data-reader-speech-text="سلام جهان" tabindex="0">' +
            '<span class="reader-document-word" data-page="1" role="button" tabindex="0">سلام</span>' +
            '<span> </span>' +
            '<span class="reader-document-word" data-page="1" role="button" tabindex="0">جهان</span>' +
          '</span>' +
        '</p>' +
      '</article>' +
    '</section>'
  );
  await page.addScriptTag({ url: new URL('/js/farast-reader-audio.js', page.url()).href });
  await page.evaluate(() => {
    const sentence = document.querySelector('.reader-document-sentence');
    const range = document.createRange();
    range.selectNodeContents(sentence);
    const selection = window.getSelection();
    selection.removeAllRanges();
    selection.addRange(range);
    sentence.focus();
  });
  await page.keyboard.press('Enter');
  await expect.poll(() => page.evaluate(() => window.__fakeReaderAudio?.currentTime)).toBe(0.1);
  await page.evaluate(() => {
    const audio = window.__fakeReaderAudio;
    audio.currentTime = 0.7;
    audio.dispatchEvent(new Event('timeupdate'));
  });
  await expect.poll(() => page.evaluate(() => window.__fakeReaderAudio?.paused)).toBe(true);
  await expect.poll(() => page.evaluate(() => window.__fakeReaderAudio?.currentTime)).toBe(0.7);
});

test('supports PageUp PageDown Home and End shortcuts without hijacking form controls', async ({ page }) => {
  await page.goto('/');
  await page.setContent(`
    <section data-reader-root data-product-id="keyboard-shortcuts">
      <article class="farast-reader-page-item is-active" data-index="0" data-reader-page="1"></article>
      <article class="farast-reader-page-item" data-index="1" data-reader-page="2"></article>
      <div id="reader-stage"></div>
      <button id="reader-prev" type="button">previous</button>
      <button id="reader-next" type="button">next</button>
      <input id="reader-page-number" value="1">
      <div class="reader-page-input"><span>1</span><span>/ 2</span></div>
      <div id="reader-progress"></div>
      <div class="farast-reader-progress" role="progressbar"></div>
    </section>
  `);
  await page.addScriptTag({ path: require.resolve('../../public/js/farast-reader.js') });
  await expect(page.locator('.farast-reader-page-item').nth(0)).toHaveClass(/is-active/);

  await page.keyboard.press('PageDown');
  await expect(page.locator('.farast-reader-page-item').nth(1)).toHaveClass(/is-active/);
  await page.keyboard.press('Home');
  await expect(page.locator('.farast-reader-page-item').nth(0)).toHaveClass(/is-active/);
  await page.keyboard.press('End');
  await expect(page.locator('.farast-reader-page-item').nth(1)).toHaveClass(/is-active/);
  await page.keyboard.press('PageUp');
  await expect(page.locator('.farast-reader-page-item').nth(0)).toHaveClass(/is-active/);

  const pageInput = page.locator('#reader-page-number');
  await pageInput.focus();
  await page.keyboard.press('PageDown');
  await expect(page.locator('.farast-reader-page-item').nth(0)).toHaveClass(/is-active/);
});

test('renders Word headings lists and tables as safe semantic document blocks', async ({ page }) => {
  await page.goto('/');
  await page.setContent(`
    <section data-reader-root data-reader-mode="document" data-reader-audio-url="/reader-full.wav">
      <article class="farast-reader-page-item is-active" data-index="0" data-reader-page="1" data-reader-page-url="/protected-word-page">
        <div data-reader-document-page>
          <div class="farast-pdf-loading" role="status">loading</div>
          <div data-reader-text-layer></div>
        </div>
      </article>
      <article class="farast-reader-page-item" data-index="1" data-reader-page="2" data-reader-page-url="/protected-word-page-2">
        <div data-reader-document-page><div class="farast-pdf-loading" role="status">loading</div><div data-reader-text-layer></div></div>
      </article>
    </section>
  `);
  await page.route('**/protected-word-page', route => route.fulfill({
    status: 200,
    contentType: 'application/json',
    body: JSON.stringify({
      page_number: 1,
      page_kind: 'section',
      label: 'بخش ۱',
      content_text: 'فصل اول\\nمتن توضیحی\\nگزینه نخست\\nعنوان | مقدار',
      content_blocks: [
        { type: 'heading', level: 1, text: 'فصل اول' },
        { type: 'paragraph', text: 'متن توضیحی' },
        { type: 'list_item', list_kind: 'bullet', list_level: 1, text: 'گزینه نخست' },
        { type: 'table_row', table_index: 1, row_index: 1, cells: ['عنوان', 'مقدار'], text: 'عنوان | مقدار' },
        { type: 'table_row', table_index: 1, row_index: 2, cells: ['محتوا', '<img src=x onerror=alert(1)>'], text: 'محتوا | <img src=x onerror=alert(1)>' }
      ],
      content_sha256: 'word-fixture-hash'
    })
  }));
  await page.route('**/protected-word-page-2', route => route.fulfill({
    status: 200,
    contentType: 'application/json',
    body: JSON.stringify({
      page_number: 2,
      page_kind: 'section',
      label: 'بخش ۲',
      content_text: 'صفحه دوم',
      content_blocks: [{ type: 'paragraph', text: 'متن صفحه دوم' }],
      content_sha256: 'word-fixture-page-two'
    })
  }));
  await page.addScriptTag({ path: require.resolve('../../public/js/farast-reader-document.js') });
  await expect(page.locator('[data-reader-text-layer] h1')).toHaveText('فصل اول');
  await expect(page.locator('[data-reader-text-layer] p')).toHaveText('متن توضیحی');
  await expect(page.locator('[data-reader-text-layer] ul li')).toHaveText('گزینه نخست');
  const table = page.locator('.farast-reader-docx-table');
  await expect(table).toBeVisible();
  await expect(table.locator('tbody tr')).toHaveCount(2);
  await expect(table.locator('tbody tr').nth(1).locator('td').nth(1)).toHaveText('<img src=x onerror=alert(1)>');
  await expect(table.locator('tbody tr').nth(1)).toHaveClass(/reader-document-line/);
  await expect(table.locator('tbody tr').nth(1)).toHaveAttribute('data-reader-speech-text', 'محتوا | <img src=x onerror=alert(1)>');
  await expect(page.locator('.reader-document-word').first()).toBeVisible();
  await page.evaluate(() => {
    const reader = document.querySelector('[data-reader-root]');
    reader.dispatchEvent(new CustomEvent('farast:reader-section-change', { detail: { index: 1 } }));
  });
  const secondPage = page.locator('.farast-reader-page-item[data-index="1"]');
  await expect.poll(() => secondPage.getAttribute('data-reader-loaded')).toBe('1');
  await expect(secondPage.locator('.reader-document-paragraph')).toHaveText('متن صفحه دوم');
  await expect(table.locator('img')).toHaveCount(0);
});

test('renders protected spreadsheet sections as safe responsive tables', async ({ page }) => {
  await page.goto('/');
  await page.setContent(`
    <section data-reader-root data-reader-mode="document">
      <article class="farast-reader-page-item is-active" data-index="0" data-reader-page="1" data-reader-page-url="/protected-page">
        <div data-reader-document-page>
          <div class="farast-pdf-loading" role="status">loading</div>
          <div data-reader-text-layer></div>
        </div>
      </article>
    </section>
  `);
  await page.route('**/protected-page', route => route.fulfill({
    status: 200,
    contentType: 'application/json',
    body: JSON.stringify({
      page_number: 1,
      page_kind: 'sheet',
      label: 'برگه Sales',
      sheet_name: 'Sales',
      row_start: 12,
      row_end: 13,
      content_text: 'نام | توضیح\nکتاب | <img src=x onerror=alert(1)>',
      content_sha256: 'fixture-hash'
    })
  }));
  await page.addScriptTag({ path: require.resolve('../../public/js/farast-reader-document.js') });
  const table = page.locator('.farast-reader-sheet-table');
  await expect(table).toBeVisible();
  await expect(table.locator('caption')).toHaveText('Sales');
  await expect(table.locator('tbody tr')).toHaveCount(2);
  await expect(table.locator('tbody tr').nth(0).locator('th')).toHaveText('۱۲');
  await expect(table.locator('tbody tr').nth(1).locator('td').nth(1)).toHaveText('<img src=x onerror=alert(1)>');
  await expect(page.locator('.farast-reader-sheet-table img')).toHaveCount(0);
});


test('personal reading light, font preferences, highlights and annotations stay in browser storage', async ({ page }) => {
  await page.goto('/');
  await page.setContent(`
    <section class="farast-reader" data-reader-root data-product-id="personal-tools-fixture">
      <nav class="farast-reader-toolbar"></nav>
      <article class="farast-reader-page-item is-active" data-index="0" data-reader-page="1">
        <div class="farast-reader-document"><p>این متن برای هایلایت و حاشیه نویسی آزمایشی است.</p></div>
      </article>
    </section>
  `);
  await page.addScriptTag({ path: require.resolve('../../public/js/farast-reader-personal-tools.js') });

  await page.locator('[data-reading-light]').click();
  await expect(page.locator('[data-reading-light]')).toHaveAttribute('aria-pressed', 'true');
  await page.locator('select[data-reader-font]').selectOption('serif');
  await page.locator('input[data-reader-font-size]').fill('125');
  await expect(page.locator('[data-reader-root]')).toHaveAttribute('data-reader-font', 'serif');
  await expect(page.locator('[data-reader-root]')).toHaveAttribute('data-reader-font-size', '125');
  await expect(page.locator('.farast-reader-document')).toHaveCSS('font-family', /Georgia/);

  await page.evaluate(() => {
    const text = document.querySelector('.farast-reader-document p').firstChild;
    const range = document.createRange();
    range.setStart(text, 4);
    range.setEnd(text, 8);
    const selection = window.getSelection();
    selection.removeAllRanges();
    selection.addRange(range);
  });
  await page.locator('[data-reader-highlight-color]').selectOption('green');
  await page.locator('[data-reader-highlight]').click();
  await expect(page.locator('mark.farast-reader-local-highlight')).toHaveText('متن');
  await expect(page.locator('mark.farast-reader-local-highlight')).toHaveAttribute('data-highlight-id', /.+/);
  await page.locator('mark.farast-reader-local-highlight').click();
  await expect(page.locator('mark.farast-reader-local-highlight')).toHaveCount(0);
  await page.evaluate(() => {
    const text = document.querySelector('.farast-reader-document p').firstChild;
    const range = document.createRange(); range.setStart(text, 4); range.setEnd(text, 8);
    const selection = window.getSelection(); selection.removeAllRanges(); selection.addRange(range);
  });
  await page.locator('[data-reader-highlight]').click();
  await expect(page.locator('mark.farast-reader-local-highlight')).toHaveText('متن');

  await page.evaluate(() => {
    const walker = document.createTreeWalker(document.querySelector('.farast-reader-document p'), NodeFilter.SHOW_TEXT);
    let text = null;
    while (walker.nextNode()) if (walker.currentNode.nodeValue.includes('حاشیه')) { text = walker.currentNode; break; }
    const start = text.nodeValue.indexOf('حاشیه');
    const range = document.createRange();
    range.setStart(text, start);
    range.setEnd(text, start + 'حاشیه نویسی آزمایشی'.length);
    const selection = window.getSelection();
    selection.removeAllRanges();
    selection.addRange(range);
  });
  page.once('dialog', dialog => dialog.accept('یادداشت محلی'));
  await page.locator('[data-reader-note]').click();
  await expect(page.locator('.farast-reader-note')).toContainText('یادداشت محلی');
  const saved = await page.evaluate(() => JSON.parse(localStorage.getItem('farast.reader.personal-tools.v1:personal-tools-fixture')));
  expect(saved.light).toBe(true);
  expect(saved.font).toBe('serif');
  expect(saved.size).toBe(125);
  expect(saved.highlights).toHaveLength(1);
  expect(saved.notes).toHaveLength(1);
});


test('personal reader backup restores local notes and highlights without a server request', async ({ page }) => {
  await page.goto('/');
  await page.setContent(`
    <section class="farast-reader" data-reader-root data-product-id="backup-fixture">
      <nav class="farast-reader-toolbar"></nav>
      <article class="farast-reader-page-item" data-index="0" data-reader-page="1">
        <div class="farast-reader-document"><p>متن نمونه برای پشتیبان محلی خوانشگر.</p></div>
      </article>
    </section>
  `);
  await page.addScriptTag({ path: require.resolve('../../public/js/farast-reader-personal-tools.js') });
  const backup = { format: 'farast-reader-personal-tools', version: 1, data: {
    light: true, font: 'serif', size: 130,
    highlights: [{ id: 'h1', quote: 'متن نمونه', page: 1, created: 1 }],
    notes: [{ id: 'n1', quote: 'متن نمونه', note: 'یادداشت بازیابی‌شده', page: 1, created: 1 }],
    inkStrokes: [{ id: 's1', page: 1, tool: 'pen', color: '#123456', width: 3, points: [{ x: 0.1, y: 0.2 }, { x: 0.4, y: 0.5 }] }]
  }};
  page.once('dialog', dialog => dialog.accept());
  await page.locator('[data-reader-import-file]').setInputFiles({
    name: 'reader-backup.json',
    mimeType: 'application/json',
    buffer: Buffer.from(JSON.stringify(backup))
  });
  await expect(page.locator('[data-reader-root]')).toHaveClass(/is-reading-light/);
  await expect(page.locator('[data-reader-root]')).toHaveAttribute('data-reader-font', 'serif');
  await expect(page.locator('.farast-reader-note')).toContainText('یادداشت بازیابی‌شده');
  await expect(page.locator('mark.farast-reader-local-highlight')).toHaveText('متن نمونه');
  const saved = await page.evaluate(() => JSON.parse(localStorage.getItem('farast.reader.personal-tools.v1:backup-fixture')));
  expect(saved.notes[0].note).toBe('یادداشت بازیابی‌شده');
  expect(saved.inkStrokes).toHaveLength(1);
  expect(saved.inkStrokes[0].points[1].x).toBe(0.4);
});


test('personal highlights span inline formatting and restore after a page rerender', async ({ page }) => {
  await page.goto('/');
  await page.setContent(`
    <section class="farast-reader" data-reader-root data-product-id="cross-node-highlight">
      <nav class="farast-reader-toolbar"></nav>
      <article class="farast-reader-page-item" data-index="0" data-reader-page="1">
        <div class="farast-reader-document"><p>ابتدای <strong>عبارت مهم</strong> انتهای متن</p></div>
      </article>
    </section>
  `);
  await page.addScriptTag({ path: require.resolve('../../public/js/farast-reader-personal-tools.js') });
  await page.evaluate(() => {
    const p = document.querySelector('.farast-reader-document p');
    const range = document.createRange();
    range.setStart(p.querySelector('strong').firstChild, 0);
    range.setEnd(p.lastChild, 5);
    const selection = window.getSelection(); selection.removeAllRanges(); selection.addRange(range);
  });
  await page.locator('[data-reader-highlight]').click();
  await expect(page.locator('mark.farast-reader-local-highlight')).toHaveCount(2);
  await expect(page.locator('mark.farast-reader-local-highlight').nth(0)).toHaveText(' انت');
  await expect(page.locator('mark.farast-reader-local-highlight').nth(1)).toHaveText('عبارت مهم');
  await page.evaluate(() => {
    const page = document.querySelector('.farast-reader-page-item');
    page.querySelectorAll('mark.farast-reader-local-highlight').forEach(mark => mark.replaceWith(document.createTextNode(mark.textContent)));
    document.querySelector('[data-reader-root]').dispatchEvent(new CustomEvent('farast:reader-page-loaded'));
  });
  await expect(page.locator('mark.farast-reader-local-highlight')).toHaveCount(2);
});


test('local reader ink supports drawing, erasing mode, undo and browser persistence', async ({ page }) => {
  await page.goto('/');
  await page.setContent(`
    <section class="farast-reader" data-reader-root data-product-id="local-ink-fixture">
      <nav class="farast-reader-toolbar"></nav>
      <article class="farast-reader-page-item is-active" data-index="0" data-reader-page="1" style="width:640px;height:420px">
        <div class="farast-reader-document"><p>متن صفحه برای ترسیم</p></div>
      </article>
      <article class="farast-reader-page-item" data-index="1" data-reader-page="2" style="width:640px;height:420px">
        <div class="farast-reader-document"><p>صفحه دوم</p></div>
      </article>
    </section>
  `);
  await page.addScriptTag({ path: require.resolve('../../public/js/farast-reader-personal-tools.js') });
  await page.locator('[data-ink-toggle]').click();
  await expect(page.locator('[data-ink-toggle]')).toHaveAttribute('aria-pressed', 'true');
  const canvas = page.locator('.farast-reader-page-item').first().locator('canvas.farast-reader-ink-canvas');
  const bounds = await canvas.boundingBox();
  expect(bounds).not.toBeNull();
  await page.mouse.move(bounds.x + 30, bounds.y + 35);
  await page.mouse.down();
  await page.mouse.move(bounds.x + 100, bounds.y + 90, { steps: 5 });
  await page.mouse.up();
  const stored = await page.evaluate(() => JSON.parse(localStorage.getItem('farast.reader.personal-tools.v1:local-ink-fixture')));
  expect(stored.inkStrokes).toHaveLength(1);
  expect(stored.inkStrokes[0].page).toBe(1);
  expect(stored.inkStrokes[0].points.length).toBeGreaterThan(1);
  expect(stored.inkStrokes[0].points.every(point => point.x >= 0 && point.x <= 1 && point.y >= 0 && point.y <= 1)).toBe(true);

  await page.locator('[data-ink-eraser]').click();
  await expect(page.locator('[data-ink-eraser]')).toHaveAttribute('aria-pressed', 'true');
  await page.locator('[data-ink-undo]').click();
  const afterUndo = await page.evaluate(() => JSON.parse(localStorage.getItem('farast.reader.personal-tools.v1:local-ink-fixture')));
  expect(afterUndo.inkStrokes).toHaveLength(0);
  await page.locator('[data-ink-toggle]').click();
  await expect(canvas).toHaveCSS('pointer-events', 'none');
});

test('reader highlights stay anchored to the selected duplicate phrase', async ({ page }) => {
  await page.goto('/');
  await page.setContent(`
    <section class="farast-reader" data-reader-root data-product-id="duplicate-highlight-fixture">
      <nav class="farast-reader-toolbar"></nav>
      <article class="farast-reader-page-item" data-index="0" data-reader-page="1">
        <div class="farast-reader-document"><p>عبارت تکراری میان متن و عبارت تکراری در پایان</p></div>
      </article>
    </section>
  `);
  await page.addScriptTag({ path: require.resolve('../../public/js/farast-reader-personal-tools.js') });
  const phrase = 'عبارت تکراری';
  await page.evaluate(phrase => {
    const text = document.querySelector('.farast-reader-document p').firstChild;
    const start = text.nodeValue.lastIndexOf(phrase);
    const range = document.createRange();
    range.setStart(text, start);
    range.setEnd(text, start + phrase.length);
    const selection = window.getSelection();
    selection.removeAllRanges();
    selection.addRange(range);
  }, phrase);
  await page.locator('[data-reader-highlight]').click();
  const saved = await page.evaluate(() => JSON.parse(localStorage.getItem('farast.reader.personal-tools.v1:duplicate-highlight-fixture')));
  const expectedOffset = await page.locator('.farast-reader-document p').evaluate(node => node.textContent.lastIndexOf('عبارت تکراری'));
  expect(saved.highlights[0].startOffset).toBe(expectedOffset);
  expect(saved.highlights[0].endOffset).toBe(expectedOffset + phrase.length);
  await page.evaluate(phrase => {
    const text = document.querySelector('.farast-reader-document p').firstChild;
    const start = text.nodeValue.indexOf(phrase);
    const range = document.createRange();
    range.setStart(text, start);
    range.setEnd(text, start + phrase.length);
    const selection = window.getSelection();
    selection.removeAllRanges();
    selection.addRange(range);
  }, phrase);
  await page.locator('[data-reader-highlight]').click();
  await page.evaluate(() => document.querySelector('[data-reader-root]').dispatchEvent(new CustomEvent('farast:reader-page-loaded')));
  const markedOffsets = await page.locator('mark.farast-reader-local-highlight').evaluateAll(marks => marks.map(mark => {
    const range = document.createRange();
    range.selectNodeContents(mark.parentElement);
    range.setEndBefore(mark);
    return range.toString().length;
  }).sort((a, b) => a - b));
  const firstOffset = await page.locator('.farast-reader-document p').evaluate(node => node.textContent.indexOf('عبارت تکراری'));
  expect(markedOffsets).toEqual([firstOffset, expectedOffset]);
  await expect(page.locator('mark.farast-reader-local-highlight')).toHaveCount(2);
  await expect(page.locator('mark.farast-reader-local-highlight').first()).toHaveText(phrase);
});

test('reader ink exposes keyboard guidance and discards cancelled pointer strokes', async ({ page }) => {
  await page.goto('/');
  await page.setContent(`
    <section class="farast-reader" data-reader-root data-product-id="ink-accessibility-fixture">
      <nav class="farast-reader-toolbar"></nav>
      <article class="farast-reader-page-item is-active" data-index="0" data-reader-page="1" style="width:640px;height:420px">
        <div class="farast-reader-document"><p>متن صفحه</p></div>
      </article>
    </section>
  `);
  await page.addScriptTag({ path: require.resolve('../../public/js/farast-reader-personal-tools.js') });
  const toggle = page.locator('[data-ink-toggle]');
  await expect(toggle).toHaveAttribute('aria-pressed', 'false');
  await expect(page.locator('.farast-reader-ink-help')).toContainText('صفحه‌کلید');
  await toggle.click();
  const canvas = page.locator('canvas.farast-reader-ink-canvas').first();
  await expect(canvas).toHaveAttribute('role', 'img');
  await expect(canvas).toHaveAttribute('aria-describedby', /farast-reader-ink-help/);
  const bounds = await canvas.boundingBox();
  await page.evaluate(() => { HTMLCanvasElement.prototype.setPointerCapture = () => {}; });
  await page.dispatchEvent('canvas.farast-reader-ink-canvas', 'pointerdown', {
    pointerId: 71, pointerType: 'pen', button: 0, clientX: bounds.x + 20, clientY: bounds.y + 20, pressure: 0.7
  });
  await page.dispatchEvent('canvas.farast-reader-ink-canvas', 'pointermove', {
    pointerId: 71, pointerType: 'pen', button: 0, clientX: bounds.x + 80, clientY: bounds.y + 70, pressure: 0.7
  });
  await page.dispatchEvent('canvas.farast-reader-ink-canvas', 'pointercancel', {
    pointerId: 71, pointerType: 'pen', button: 0, clientX: bounds.x + 80, clientY: bounds.y + 70, pressure: 0
  });
  const stored = await page.evaluate(() => {
    const value = localStorage.getItem('farast.reader.personal-tools.v1:ink-accessibility-fixture');
    return value ? JSON.parse(value) : null;
  });
  expect(stored?.inkStrokes || []).toHaveLength(0);
  await toggle.click();
  await expect(canvas).toHaveCSS('touch-action', 'auto');
});

test('reader notes preserve the exact selected occurrence offset', async ({ page }) => {
  await page.goto('/');
  await page.setContent(`
    <section class="farast-reader" data-reader-root data-product-id="anchored-note-fixture">
      <nav class="farast-reader-toolbar"></nav>
      <article class="farast-reader-page-item" data-index="0" data-reader-page="1">
        <div class="farast-reader-document"><p>یادداشت روی عبارت و سپس عبارت برای یادداشت</p></div>
      </article>
    </section>
  `);
  await page.addScriptTag({ path: require.resolve('../../public/js/farast-reader-personal-tools.js') });
  await page.evaluate(() => { window.prompt = () => 'یادداشت آزمایشی'; });
  const phrase = 'عبارت';
  await page.evaluate(phrase => {
    const text = document.querySelector('.farast-reader-document p').firstChild;
    const start = text.nodeValue.lastIndexOf(phrase);
    const range = document.createRange();
    range.setStart(text, start);
    range.setEnd(text, start + phrase.length);
    const selection = window.getSelection();
    selection.removeAllRanges();
    selection.addRange(range);
  }, phrase);
  await page.locator('[data-reader-note]').click();
  const stored = await page.evaluate(() => JSON.parse(localStorage.getItem('farast.reader.personal-tools.v1:anchored-note-fixture')));
  const expected = await page.locator('.farast-reader-document p').evaluate(node => node.textContent.lastIndexOf('عبارت'));
  expect(stored.notes).toHaveLength(1);
  expect(stored.notes[0].quote).toBe(phrase);
  expect(stored.notes[0].startOffset).toBe(expected);
  expect(stored.notes[0].endOffset).toBe(expected + phrase.length);
  await expect(page.locator('[data-reader-notes]')).toHaveAttribute('aria-expanded', 'true');
});
