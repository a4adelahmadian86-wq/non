const { test, expect } = require('@playwright/test');

async function openEditor(page) {
  const password = `${process.env.GITHUB_RUN_ID || 'local'}-farast-e2e`;
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
  await expect(page).toHaveURL(/\/editor(?:\?.*)?$/);
  await expect(page.locator('#pagesViewport .farast-page').first()).toBeVisible({ timeout: 5000 });
  return page.locator('.farast-editor').first();
}

test('editor kernel routes typing, formatting, structure and persistence through the document model', async ({ page }) => {
  const pageErrors = [];
  page.on('pageerror', error => pageErrors.push(String(error)));
  const editor = await openEditor(page);

  await editor.click();
  await page.keyboard.insertText('سلام فراست');
  await expect.poll(async () => page.evaluate(() => window.FarastEditor?.state?.model?.plain_text)).toContain('سلام فراست');

  await page.keyboard.press('Enter');
  await page.keyboard.insertText('FARAST 2026');
  await expect.poll(async () => page.evaluate(() => (window.FarastEditor?.state?.model?.sections?.[0]?.blocks || []).length)).toBeGreaterThanOrEqual(2);

  await page.keyboard.press('Control+a');
  await page.keyboard.press('Control+b');
  await expect.poll(async () => page.evaluate(() => JSON.stringify(window.FarastEditor?.state?.model || {}))).toContain('"bold":true');

  await page.keyboard.press('Control+z');
  await expect.poll(async () => page.evaluate(() => JSON.stringify(window.FarastEditor?.state?.model || {}))).not.toContain('"bold":true');
  await page.keyboard.press('Control+y');
  await expect.poll(async () => page.evaluate(() => JSON.stringify(window.FarastEditor?.state?.model || {}))).toContain('"bold":true');

  const tx = await page.evaluate(() => window.FarastEditor?.getTransactions?.() || []);
  expect(tx.some(x => x.command === 'InsertText')).toBeTruthy();
  expect(tx.some(x => x.command === 'Undo')).toBeTruthy();
  expect(tx.some(x => x.command === 'Redo')).toBeTruthy();
  expect(tx.every(x => x.id && x.timestamp && x.command && x.source)).toBeTruthy();

  await page.getByRole('button', { name: 'درج' }).click();
  await page.locator('.farast-ribbon button[data-command="pageBreak"]').click();
  await expect.poll(async () => page.locator('#pagesViewport .farast-page').count()).toBeGreaterThan(1);
  await expect.poll(async () => page.evaluate(() => window.FarastEditor?.state?.model?.sections?.[0]?.blocks?.some(b => b.type === 'page_break'))).toBeTruthy();

  await page.getByRole('button', { name: 'خانه' }).click();
  await editor.click();
  await page.keyboard.press('Control+a');
  await page.locator('.farast-ribbon button[data-command="ul"]').click();
  await expect.poll(async () => page.evaluate(() => window.FarastEditor?.state?.model?.sections?.[0]?.blocks?.some(b => b.type === 'list' && !b.ordered))).toBeTruthy();

  await page.locator('#saveNow').click();
  await expect(page.locator('#saveState')).toHaveText('ذخیره شد', { timeout: 5000 });
  await page.reload();
  await expect(page.locator('#pagesViewport .farast-page').first()).toBeVisible({ timeout: 5000 });

  const persisted = await page.evaluate(() => ({
    blocks: window.FarastEditor?.state?.model?.sections?.[0]?.blocks || [],
    plain: window.FarastEditor?.state?.model?.plain_text || ''
  }));
  expect(persisted.plain).toContain('سلام فراست');
  expect(persisted.blocks.some(b => b.type === 'page_break')).toBeTruthy();
  expect(persisted.blocks.some(b => b.type === 'list')).toBeTruthy();
  expect(pageErrors).toEqual([]);
});

test('editor kernel inserts an image as a stable resource reference instead of base64 document content', async ({ page }) => {
  const editor = await openEditor(page);
  await editor.click();
  await page.keyboard.insertText('تصویر:');

  const png = Buffer.from(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
    'base64'
  );
  await page.locator('#source').setInputFiles({
    name: 'kernel-test.png',
    mimeType: 'image/png',
    buffer: png
  });

  await expect.poll(async () => page.evaluate(() => window.FarastEditor?.state?.model?.resources?.length || 0)).toBeGreaterThan(0);
  const result = await page.evaluate(() => {
    const model = window.FarastEditor?.state?.model;
    const resource = model?.resources?.[0];
    return {
      resource,
      imageBlock: model?.sections?.[0]?.blocks?.find(b => b.type === 'image')
    };
  });
  expect(result.resource.id).toMatch(/^asset-/);
  expect(result.resource.storage_path).toBeTruthy();
  expect(result.resource.url).toContain('/editor/documents/');
  expect(result.resource.source || '').not.toMatch(/^data:image\//);
  expect(result.imageBlock.resourceId).toBe(result.resource.id);
});
