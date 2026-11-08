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
    await expect(page.locator('[data-audio-status]')).toContainText('همگام‌سازی جمله‌ای');
    await expect(page.locator('[data-audio-progress]')).toContainText('بخش');
    await expect(page.locator('.reader-audio-word')).toHaveCount(3);
    await expect(page.locator('.reader-audio-word').first()).toHaveAttribute('aria-label', 'رفتن به بخش سلام');
  });

});

  test('clicking a document page plays only that page range from the full narration track', async ({ page }) => {
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
    await page.route('**/reader-full.wav', route => route.fulfill({ status: 200, contentType: 'audio/wav', body: wav }));
    await page.setContent(`
      <meta name="csrf-token" content="reader-test-token">
      <section data-reader-root data-reader-speech-url="/mock-speech" data-reader-audio-url="/reader-full.wav">
        <section class="farast-reader-audio" data-reader-audio hidden>
          <button data-audio-play aria-label="play"></button>
          <input type="range" data-audio-seek>
          <span data-audio-current>00:00</span><span data-audio-duration>00:00</span>
          <select data-audio-speed><option value="1" selected>1x</option></select>
          <span data-audio-status aria-live="polite"></span>
        </section>
        <script type="application/json" id="reader-audio-timing">[
          {"text":"صفحه اول","start":0,"end":0.6,"page":1},
          {"text":"صفحه دوم","start":0.7,"end":1.4,"page":2}
        ]</script>
        <article class="farast-reader-page-item is-active" data-index="1" data-reader-page="2" tabindex="0">
          <div class="farast-reader-page-content"><div class="reader-page-background">فضای خالی صفحه دوم</div></div>
        </article>
      </section>
    `);
    await page.addScriptTag({ url: new URL('/js/farast-reader-document-audio.js', page.url()).href });
    await page.addScriptTag({ url: new URL('/js/farast-reader-granular-audio.js', page.url()).href });
    await expect(page.locator('[data-reader-audio]')).toBeVisible();
    await expect.poll(() => page.locator('[data-audio-duration]').textContent()).not.toBe('00:00');
    const pageTarget = page.locator('.farast-reader-page-item');
    await pageTarget.click();
    await expect.poll(() => page.evaluate(() => window.FarastDocumentAudioReader.currentTime)).toBeGreaterThanOrEqual(0.7);
    await expect(page.locator('[data-audio-status]')).toContainText('صفحه');
    await page.evaluate(() => window.FarastDocumentAudioReader.pause());
    await pageTarget.focus();
    await page.keyboard.press('Enter');
    await expect.poll(() => page.evaluate(() => window.FarastDocumentAudioReader.currentTime)).toBeGreaterThanOrEqual(0.7);
  });

  test('granular speech sends the exact clicked word and selected phrase with page locators', async ({ page }) => {
    const requests = [];
    await page.goto('/');
    await page.setContent(`
      <meta name="csrf-token" content="reader-test-token">
      <section class="farast-reader" data-reader-root data-reader-speech-url="/store/product/1/reader/speech">
        <div data-audio-status aria-live="polite"></div>
        <article class="farast-reader-page-item is-active" data-index="0" data-reader-page="4">
          <p class="reader-document-paragraph" tabindex="0">
            <span class="reader-document-sentence" tabindex="0">
              <span class="reader-document-word" role="button" tabindex="0" data-word="سلام" data-page="4">سلام</span>
              <span> </span>
              <span class="reader-document-word" role="button" tabindex="0" data-word="جهان" data-page="4">جهان</span>
              <span>.</span>
            </span>
          </p>
        </article>
      </section>
    `);
    await page.route('**/store/product/1/reader/speech', async route => {
      requests.push(route.request().postDataJSON());
      await route.fulfill({ status: 200, contentType: 'audio/wav', body: Buffer.from('RIFF0000WAVE') });
    });
    await page.addScriptTag({ url: new URL('/js/farast-reader-granular-audio.js', page.url()).href });
    await page.locator('.reader-document-word').first().click();
    await expect.poll(() => requests.length).toBe(1);
    expect(requests[0]).toEqual({ text: 'سلام', page: 4 });

    await page.locator('.reader-document-sentence > span').last().click();
    await expect.poll(() => requests.length).toBe(2);
    expect(requests[1].page).toBe(4);
    expect(requests[1].text).toContain('سلام');
    expect(requests[1].text).toContain('جهان');

    await page.evaluate(() => {
      const words = document.querySelectorAll('.reader-document-word');
      const range = document.createRange();
      range.setStart(words[0].firstChild, 0);
      range.setEnd(words[1].firstChild, words[1].textContent.length);
      const selection = window.getSelection();
      selection.removeAllRanges();
      selection.addRange(range);
      const root = document.querySelector('[data-reader-root]');
      root.dispatchEvent(new PointerEvent('pointerup', { bubbles: true }));
      root.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
    });
    await expect.poll(() => requests.length).toBe(3);
    expect(requests[2].page).toBe(4);
    expect(requests[2].text).toContain('سلام');
    expect(requests[2].text).toContain('جهان');
  });

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
  let protectedWordPageRequests = 0;
  const wordPageErrors = [];
  await page.goto('/');
  await page.setContent(`
    <section data-reader-root data-reader-mode="document">
      <article class="farast-reader-page-item is-active" data-index="0" data-reader-page="1" data-reader-page-url="/protected-word-page">
        <div data-reader-document-page>
          <div class="farast-pdf-loading" role="status">loading</div>
          <div data-reader-text-layer></div>
        </div>
      </article>
      <article class="farast-reader-page-item" data-index="1" data-reader-page="2" data-reader-page-url="/protected-word-page-2">
        <div data-reader-document-page>
          <div class="farast-pdf-loading" role="status">loading</div>
          <div data-reader-text-layer></div>
        </div>
      </article>
    </section>
  `);
  await page.route('**/protected-word-page', route => {
    protectedWordPageRequests += 1;
    return route.fulfill({
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
        { type: 'list_item', list_kind: 'number', list_level: 2, text: 'زیرگزینه شماره‌دار' },
        { type: 'table_row', table_index: 1, row_index: 1, cells: ['عنوان', 'مقدار'], text: 'عنوان | مقدار' },
        { type: 'table_row', table_index: 1, row_index: 2, cells: ['محتوا', '<img src=x onerror=alert(1)>'], text: 'محتوا | <img src=x onerror=alert(1)>' }
      ],
      content_sha256: 'word-fixture-hash'
    })
    });
  });
  let protectedWordPage2Requests = 0;
  await page.route('**/protected-word-page-2', route => {
    protectedWordPage2Requests += 1;
    return route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        page_number: 2,
        page_kind: 'section',
        label: 'بخش ۲',
        content_text: 'صفحه دوم',
        content_blocks: [{ type: 'paragraph', text: 'صفحه دوم' }],
        content_sha256: 'word-fixture-hash-2'
      })
    });
  });
  page.on('pageerror', error => wordPageErrors.push(error.message));
  await page.addScriptTag({ path: require.resolve('../../public/js/farast-reader-document.js') });
  await page.locator('[data-reader-root]').evaluate(root => root.dispatchEvent(new CustomEvent('farast:reader-section-change', { detail: { section: 0 } })));
  await expect.poll(() => protectedWordPageRequests).toBe(1);
  await expect(page.locator('.farast-reader-page-item[data-index="0"]')).toHaveAttribute('data-reader-loaded', '1');
  expect(wordPageErrors).toEqual([]);
  await expect(page.locator('[data-reader-text-layer] h1')).toHaveText('فصل اول');
  await expect(page.locator('[data-reader-text-layer] p')).toHaveText('متن توضیحی');
  await expect(page.locator('[data-reader-text-layer] ul > li').first()).toContainText('گزینه نخست');
  await expect(page.locator('[data-reader-text-layer] ul > li > ol > li')).toHaveText('زیرگزینه شماره‌دار');
  const table = page.locator('.farast-reader-docx-table');
  await expect(table).toBeVisible();
  await expect(table.locator('tbody tr')).toHaveCount(2);
  await expect(table.locator('tbody tr').nth(1).locator('td').nth(1)).toHaveText('<img src=x onerror=alert(1)>');
  await expect(table.locator('tbody tr').nth(1)).toHaveClass(/reader-document-line/);
  await expect(table.locator('tbody tr').nth(1)).toHaveAttribute('data-reader-audio-text', 'محتوا | <img src=x onerror=alert(1)>');
  await expect(table.locator('img')).toHaveCount(0);

  // The production reader emits detail.section; verify navigation to a later protected section.
  await page.locator('[data-reader-root]').evaluate(root => root.dispatchEvent(new CustomEvent('farast:reader-section-change', { detail: { section: 1 } })));
  await expect.poll(() => protectedWordPage2Requests).toBe(1);
  await expect(page.locator('.farast-reader-page-item').nth(1)).toHaveAttribute('data-reader-loaded', '1');
  await expect(page.locator('.farast-reader-page-item').nth(1).locator('[data-reader-text-layer] p')).toHaveText('صفحه دوم');
  expect(wordPageErrors).toEqual([]);
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
