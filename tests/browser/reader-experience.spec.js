const { test, expect } = require('@playwright/test');

async function mountReader(page) {
  await page.goto('/');
  await page.setContent(`
    <section class="farast-reader" data-reader-root data-theme="dark">
      <header><div class="farast-reader-account"><span>09123456789</span></div></header>
      <nav>
        <button id="reader-prev" type="button">قبلی</button>
        <label class="reader-page-input"><input id="reader-page-number" value="1"><span>/ 2</span></label>
        <button id="reader-next" type="button">بعدی</button>
        <button id="reader-zoom-out" type="button">کوچک</button>
        <output id="reader-zoom-label"></output>
        <button id="reader-zoom-in" type="button">بزرگ</button>
        <button id="reader-fit" type="button">تناسب عرض</button>
        <button id="reader-rotate" type="button">چرخش</button>
        <button id="reader-scroll-mode" type="button">پیوسته</button>
        <input id="reader-search-input" type="search">
        <button id="reader-search" type="button">جست‌وجو</button>
        <output id="reader-search-count"></output>
        <button id="reader-thumbs-toggle" type="button" aria-expanded="true">فهرست</button>
        <button id="reader-theme" type="button">پوسته</button>
        <button id="reader-fs" type="button">تمام‌صفحه</button>
      </nav>
      <div class="farast-reader-progress" role="progressbar" aria-valuenow="0"><span id="reader-progress"></span></div>
      <div id="reader-stage">
        <aside id="reader-sidebar"><div class="farast-reader-thumbs">
          <button class="farast-reader-thumb is-active" data-goto="0" aria-current="page">۱</button>
          <button class="farast-reader-thumb" data-goto="1" aria-current="false">۲</button>
        </div></aside>
        <main class="farast-reader-canvas">
          <div class="farast-reader-page-item is-active" data-index="0"><div class="farast-reader-page-content"><img alt="صفحه اول" src="data:image/gif;base64,R0lGODlhAQABAAD/ACwAAAAAAQABAAACADs="></div></div>
          <div class="farast-reader-page-item" data-index="1"><div class="farast-reader-page-content"><img alt="صفحه دوم" src="data:image/gif;base64,R0lGODlhAQABAAD/ACwAAAAAAQABAAACADs="></div></div>
        </main>
      </div>
    </section>
  `);
  await page.addStyleTag({ content: '.farast-reader-page-content img { transform: scale(var(--reader-zoom, 1)); transform-origin: top center; }' });
  await page.addScriptTag({ url: new URL('/js/farast-reader.js', page.url()).href });
}

test.describe('FARAST sample reader interactions', () => {
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
