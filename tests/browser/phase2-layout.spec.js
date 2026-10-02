const { test, expect } = require('@playwright/test');

async function openEditor(page) {
  const password = String(process.env.GITHUB_RUN_ID || 'local') + '-farast-e2e';
  await page.goto('/login?continue=/projects/new');
  await page.locator('input[name="mobile"]').fill('09151234567');
  await page.getByRole('button', { name: /ادامه با شماره موبایل/ }).click();
  await expect(page).toHaveURL(/\/login\/password/);
  await page.locator('input[name="password"]').fill(password);
  await page.getByRole('button', { name: 'ورود به حساب' }).click();
  await expect(page).toHaveURL(/\/projects\/new/);
  await page.getByRole('button', { name: /تایپ/ }).click();
  await page.getByRole('button', { name: 'خودم تایپ می‌کنم' }).click();
  await page.locator('#choices .project-card').first().click();
  await page.getByRole('button', { name: 'ایجاد پروژه' }).click();
  await expect(page.locator('#farastWord')).toHaveAttribute('data-editor-ready', '1', { timeout: 5000 });
  await expect(page.locator('#pagesViewport .farast-page').first()).toBeVisible({ timeout: 5000 });
}

test.afterEach(async ({ page }) => {
  try {
    const csrf = await page.locator('meta[name="csrf-token"]').getAttribute('content');
    if (csrf) await page.evaluate(async token => fetch('/logout', { method: 'POST', headers: { 'X-CSRF-TOKEN': token, Accept: 'application/json' }, credentials: 'same-origin' }), csrf);
  } catch (_) {}
});

test('A4 geometry and long paragraph fragmentation', async ({ page }) => {
  await openEditor(page);
  await page.locator('.farast-editor').first().click();
  await page.keyboard.insertText('فراست FARAST 2026 متن فارسی برای آزمون صفحه‌بندی واقعی است. '.repeat(55));
  await page.evaluate(() => window.FarastEditor.flushLayout());
  await expect.poll(async () => page.locator('#pagesViewport .farast-page').count()).toBeGreaterThan(1);
  const result = await page.evaluate(() => ({
    layout: window.FarastDocumentLayout?.lastLayout || null,
    width: document.querySelector('.farast-page')?.getBoundingClientRect().width,
    height: document.querySelector('.farast-page')?.getBoundingClientRect().height,
    plain: window.FarastEditor?.state?.model?.plain_text || ''
  }));
  expect(result.layout.engine).toBe('farast-layout-v2');
  expect(result.width).toBeGreaterThan(780);
  expect(result.height).toBeGreaterThan(1100);
  expect(result.plain).toContain('FARAST 2026');
});

test('explicit page break survives reload', async ({ page }) => {
  await openEditor(page);
  await page.locator('.farast-editor').first().click();
  await page.keyboard.insertText('صفحه اول');
  await page.locator('.farast-tab[data-tab="insert"]').click();
  await page.locator('.farast-ribbon button[data-command="pageBreak"]').click();
  await expect.poll(async () => page.evaluate(() => window.FarastEditor.state.model.sections[0].blocks.some(b => b.type === 'page_break'))).toBeTruthy();
  await page.locator('#saveNow').click();
  await expect(page.locator('#saveState')).toHaveText('ذخیره شد', { timeout: 5000 });
  await page.reload();
  await expect.poll(async () => page.locator('#pagesViewport .farast-page').count()).toBe(2);
  expect(await page.evaluate(() => window.FarastEditor.state.model.sections[0].blocks.filter(b => b.type === 'page_break').length)).toBe(1);
});

test('table and image remain represented across pagination', async ({ page }) => {
  await openEditor(page);
  await page.locator('.farast-editor').first().click();
  await page.keyboard.insertText('متن '.repeat(180));
  await page.locator('.farast-tab[data-tab="insert"]').click();
  const accept = dialog => dialog.accept('12');
  page.on('dialog', accept);
  await page.locator('.farast-ribbon button[data-command="table"]').click();
  page.off('dialog', accept);
  await expect.poll(async () => page.evaluate(() => window.FarastEditor.state.model.sections[0].blocks.some(b => b.type === 'table'))).toBeTruthy();
  const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', 'base64');
  await page.locator('#source').setInputFiles({ name: 'phase2.png', mimeType: 'image/png', buffer: png });
  await expect.poll(async () => page.evaluate(() => window.FarastEditor.state.model.resources.length)).toBeGreaterThan(0);
  expect(await page.evaluate(() => window.FarastEditor.state.model.sections[0].blocks.find(b => b.type === 'table').rows.length)).toBe(12);
  expect(await page.evaluate(() => window.FarastEditor.state.model.sections[0].blocks.some(b => b.type === 'image'))).toBeTruthy();
});

test('editing a fragmented paragraph preserves the complete canonical text', async ({ page }) => {
  await openEditor(page);
  const original = 'پاراگراف بسیار طولانی فراست برای آزمون حفظ متن بین صفحات است. FARAST 2026. '.repeat(90);
  await page.locator('.farast-editor').first().click();
  await page.keyboard.insertText(original);
  await page.evaluate(() => window.FarastEditor.flushLayout());
  await expect.poll(async () => page.locator('#pagesViewport .farast-page').count()).toBeGreaterThan(1);
  const before = await page.evaluate(() => window.FarastEditor.state.model.plain_text);
  const second = page.locator('.farast-editor').nth(1);
  await second.click();
  await page.keyboard.press('End');
  await page.keyboard.insertText(' پایان');
  await expect.poll(async () => page.evaluate(() => window.FarastEditor.state.model.plain_text)).toContain('پایان');
  const after = await page.evaluate(() => window.FarastEditor.state.model.plain_text);
  expect(after.length).toBeGreaterThan(before.length);
  expect(after).toContain('FARAST 2026');
});
test('print uses A4 page geometry', async ({ page }) => {
  await openEditor(page);
  const css = await page.evaluate(() => document.getElementById('farastDynamicPrint')?.textContent || '');
  expect(css).toContain('210mm');
  expect(css).toContain('297mm');
});
