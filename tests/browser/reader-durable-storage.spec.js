const { test, expect } = require('@playwright/test');

test.use({ serviceWorkers: 'block' });

async function readDurableRecord(page, key) {
  return page.evaluate(key => new Promise((resolve, reject) => {
    const request = indexedDB.open('farast-reader-local-v1', 1);
    request.onerror = () => reject(request.error);
    request.onsuccess = () => {
      const db = request.result;
      const get = db.transaction('documents', 'readonly').objectStore('documents').get(key);
      get.onsuccess = () => resolve(get.result?.value || null);
      get.onerror = () => reject(get.error);
    };
  }), key);
}

async function mountReader(page) {
  await page.goto('/');
  await page.setContent(`
    <meta name="csrf-token" content="reader-test-token">
    <section class="farast-reader" data-reader-root data-theme="dark" data-product-id="durable-reader-fixture">
      <header><div class="farast-reader-account"><span>reader-test</span></div></header>
      <nav>
        <button id="reader-prev" type="button">قبلی</button>
        <label class="reader-page-input"><input id="reader-page-number" value="1"><span>/ 2</span></label>
        <button id="reader-next" type="button">بعدی</button>
        <button id="reader-resume" type="button" hidden><span>ادامه مطالعه</span></button>
        <button id="reader-bookmark-toggle" type="button" aria-pressed="false"><i class="fa-regular fa-bookmark"></i><span>نشانک</span></button>
        <button id="reader-bookmarks-open" type="button" aria-expanded="false">نشانک‌ها</button>
        <div id="reader-bookmarks-panel" class="reader-bookmarks-panel" hidden></div>
        <button id="reader-zoom-out" type="button">کوچک</button><output id="reader-zoom-label"></output>
        <button id="reader-zoom-in" type="button">بزرگ</button>
        <button id="reader-fit" type="button">تناسب عرض</button><button id="reader-fit-page" type="button">تناسب صفحه</button>
        <button id="reader-rotate" type="button">چرخش</button><button id="reader-scroll-mode" type="button">پیوسته</button>
        <div class="reader-search-group"><input id="reader-search-input" type="search"><button id="reader-search" type="button">جست‌وجو</button><output id="reader-search-count"></output></div>
        <button id="reader-thumbs-toggle" type="button" aria-expanded="true">فهرست</button>
        <button id="reader-theme" type="button">پوسته</button><button id="reader-fs" type="button">تمام‌صفحه</button>
      </nav>
      <div class="farast-reader-progress" role="progressbar" aria-valuenow="0"><span id="reader-progress"></span></div>
      <div id="reader-stage">
        <aside id="reader-sidebar"><div class="farast-reader-thumbs">
          <button class="farast-reader-thumb is-active" data-goto="0" aria-current="page">۱</button>
          <button class="farast-reader-thumb" data-goto="1" aria-current="false">۲</button>
        </div><section class="farast-reader-outline" id="reader-outline" hidden><strong>فهرست مطالب</strong><div id="reader-outline-list"></div></section></aside>
        <main class="farast-reader-canvas">
          <div class="farast-reader-page-item is-active" data-index="0"><div class="farast-reader-page-content"><img alt="صفحه اول" src="data:image/gif;base64,R0lGODlhAQABAAD/ACwAAAAAAQABAAACADs="></div></div>
          <div class="farast-reader-page-item" data-index="1"><div class="farast-reader-page-content"><img alt="صفحه دوم" src="data:image/gif;base64,R0lGODlhAQABAAD/ACwAAAAAAQABAAACADs="></div></div>
        </main>
      </div>
    </section>
  `);
  await page.addScriptTag({ url: new URL('/js/farast-reader.js', page.url()).href });
}

test('personal annotations and preferences survive localStorage clearing through IndexedDB', async ({ page }) => {
  const key = 'farast.reader.personal-tools.v1:durable-personal-fixture';
  const markup = `
    <section class="farast-reader" data-reader-root data-product-id="durable-personal-fixture">
      <nav class="farast-reader-toolbar"></nav>
      <article class="farast-reader-page-item is-active" data-index="0" data-reader-page="1">
        <div class="farast-reader-document"><p>متن نمونه برای ذخیره پایدار یادداشت و هایلایت</p></div>
      </article>
    </section>
  `;
  await page.goto('/');
  await page.setContent(markup);
  await page.addScriptTag({ path: require.resolve('../../public/js/farast-reader-personal-tools.js') });
  await page.locator('[data-reader-font]').selectOption('serif');
  await page.evaluate(() => {
    const node = document.querySelector('.farast-reader-document p').firstChild;
    const phrase = 'ذخیره پایدار';
    const start = node.nodeValue.indexOf(phrase);
    const range = document.createRange();
    range.setStart(node, start);
    range.setEnd(node, start + phrase.length);
    const selection = window.getSelection();
    selection.removeAllRanges();
    selection.addRange(range);
  });
  await page.locator('[data-reader-highlight]').click();
  await expect.poll(async () => (await readDurableRecord(page, key))?.highlights?.length || 0).toBe(1);

  await page.evaluate(key => localStorage.removeItem(key), key);
  await page.goto('/');
  await page.setContent(markup);
  await page.addScriptTag({ path: require.resolve('../../public/js/farast-reader-personal-tools.js') });
  await expect(page.locator('mark.farast-reader-local-highlight')).toHaveCount(1);
  await expect(page.locator('[data-reader-font]')).toHaveValue('serif');
});

test('reading progress and bookmarks restore after localStorage clearing', async ({ page }) => {
  const key = 'farast-reader:v1:durable-reader-fixture';
  await mountReader(page);
  await page.locator('#reader-next').click();
  await page.locator('#reader-bookmark-toggle').click();
  await expect.poll(async () => (await readDurableRecord(page, key))?.bookmarks?.length || 0).toBe(1);

  await page.evaluate(key => localStorage.removeItem(key), key);
  await mountReader(page);
  await expect(page.locator('#reader-resume')).toBeVisible();
  await page.locator('#reader-resume').click();
  await expect(page.locator('#reader-page-number')).toHaveValue('2');
  await expect(page.locator('#reader-bookmark-toggle')).toHaveAttribute('aria-pressed', 'true');
});
