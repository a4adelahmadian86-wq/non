const { test, expect } = require('@playwright/test');

test.use({ serviceWorkers: 'block' });

async function mountReader(page, includeExcel = false) {
  await page.goto('/');
  const total = includeExcel ? 3 : 2;
  const excelMarkup = includeExcel ? `
          <button class="farast-reader-thumb" data-goto="2" aria-current="false">۳</button>
        ` : '';
  const excelPage = includeExcel ? `
          <div class="farast-reader-page-item" data-index="2"><div class="farast-reader-page-content"><iframe class="farast-xlsx-frame" sandbox="" srcdoc="&lt;!doctype html&gt;&lt;html&gt;&lt;body&gt;&lt;table&gt;&lt;tr&gt;&lt;td&gt;Excel&lt;/td&gt;&lt;/tr&gt;&lt;/table&gt;&lt;/body&gt;&lt;/html&gt;"></iframe></div></div>
        ` : '';
  await page.setContent(`
    <section class="farast-reader" data-reader-root data-theme="dark">
      <header><div class="farast-reader-account"><span>09123456789</span></div></header>
      <nav>
        <button id="reader-prev" type="button">قبلی</button>
        <label class="reader-page-input"><input id="reader-page-number" value="1"><span>/ ${total}</span></label>
        <button id="reader-next" type="button">بعدی</button>
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
    </section>
  `);
  await page.addStyleTag({ content: '.farast-reader-page-content img,.farast-reader-page-content iframe { transform: scale(var(--reader-zoom, 1)); transform-origin: top center; }' });
  await page.addScriptTag({ url: new URL('/js/farast-reader.js', page.url()).href });
}


function makePdfFixture() {
  const stream = 'BT /F1 24 Tf 72 720 Td (FARAST PDF QA) Tj ET';
  const objects = [
    '<< /Type /Catalog /Pages 2 0 R >>',
    '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
    '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
    '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
    '<< /Length ' + Buffer.byteLength(stream, 'binary') + ' >>\nstream\n' + stream + '\nendstream',
  ];
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

async function mountPdfReader(page) {
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
    body: makePdfFixture(),
  }));
  await page.goto('/reader-fixture.html');
  await page.setContent(
    '<section class="farast-reader" data-reader-root data-theme="dark">' +
      '<header><div class="farast-reader-account"><span>09123456789</span></div></header>' +
      '<nav><button id="reader-prev" type="button">قبلی</button>' +
        '<label class="reader-page-input"><input id="reader-page-number" value="1"><span>/ 1</span></label>' +
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
  await page.addStyleTag({ content: '.farast-pdf-document{position:relative;width:800px;min-height:600px;background:#fff}.farast-pdf-canvas{display:block;max-width:100%;height:auto}.farast-pdf-loading{position:absolute;inset:0;display:grid;place-items:center;background:#fff}' });
  await page.addScriptTag({ url: new URL('/js/farast-reader.js', page.url()).href });
}

test.describe('FARAST sample reader interactions', () => {
  test('loads a real PDF page with PDF.js, account watermark and text search', async ({ page }) => {
    await mountPdfReader(page);
    const root = page.locator('[data-reader-root]');
    const canvas = root.locator('.farast-pdf-canvas');
    await expect(root.locator('.farast-pdf-loading')).toBeHidden({ timeout: 45000 });
    await expect.poll(() => canvas.evaluate(element => element.width)).toBeGreaterThan(0);
    await expect.poll(() => canvas.evaluate(element => element.height)).toBeGreaterThan(0);
    await expect(root.locator('.farast-pdf-text-layer span').first()).toBeVisible();
    await root.locator('#reader-search-input').fill('FARAST');
    await root.locator('#reader-search').click();
    await expect(root.locator('#reader-search-count')).toContainText('۱');
    await root.locator('#reader-zoom-in').click();
    await expect(root.locator('#reader-zoom-label')).toContainText('۱۱۰');
    await root.locator('#reader-scroll-mode').click();
    await expect(root.locator('.farast-pdf-continuous-pages')).toBeVisible();
    await expect(root.locator('.farast-pdf-cont-page canvas')).toBeVisible();
    await expect(root.locator('.farast-pdf-cont-page')).toHaveAttribute('data-rendered', '1');
    // Continuous rendering must not lock the rest of the reading toolbar.
    await expect(root.locator('#reader-zoom-in')).toBeEnabled();
    await root.locator('#reader-zoom-in').click();
    await expect(root.locator('#reader-zoom-label')).toContainText('۱۲۰');
    await expect(root.locator('#reader-prev')).toBeEnabled();
    await root.locator('#reader-scroll-mode').click();
    await expect(root.locator('.farast-pdf-continuous-pages')).toBeHidden();
  });

  test('navigates between sample pages and synchronizes the page indicator and progress', async ({ page }) => {
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
      const copy = new ClipboardEvent('copy', { bubbles: true, cancelable: true });
      const cut = new ClipboardEvent('cut', { bubbles: true, cancelable: true });
      const menu = new MouseEvent('contextmenu', { bubbles: true, cancelable: true });
      const drag = new DragEvent('dragstart', { bubbles: true, cancelable: true });
      target.dispatchEvent(copy);
      target.dispatchEvent(cut);
      target.dispatchEvent(menu);
      target.dispatchEvent(drag);
      return { copy: copy.defaultPrevented, cut: cut.defaultPrevented, menu: menu.defaultPrevented, drag: drag.defaultPrevented };
    });
    expect(results).toEqual({ copy: true, cut: true, menu: true, drag: true });
  });
});
