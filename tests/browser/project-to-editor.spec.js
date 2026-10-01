const { test, expect } = require('@playwright/test');

test('logs in through the real auth flow, creates a typing project, and opens the real word processor surface', async ({ page }) => {
  const pageErrors = [];
  const consoleErrors = [];
  page.on('pageerror', error => pageErrors.push(String(error)));
  page.on('console', message => { if (message.type() === 'error') consoleErrors.push(message.text()); });
  const password = `${process.env.GITHUB_RUN_ID || 'local'}-farast-e2e`;

  await page.goto('/login?continue=/projects/new');
  await page.locator('input[name="mobile"]').fill('09151234567');
  const phoneResponsePromise = page.waitForResponse(response => response.url().endsWith('/login/phone'));
  await page.getByRole('button', { name: /ادامه با شماره موبایل/ }).click();
  const phoneResponse = await phoneResponsePromise;
  if (!phoneResponse.ok()) throw new Error(`/login/phone failed: ${phoneResponse.status()} ${await phoneResponse.text()}`);
  await expect(page).toHaveURL(/\/login\/password/);
  await page.locator('input[name="password"]').fill(password);
  await page.getByRole('button', { name: 'ورود به حساب' }).click();

  await expect(page).toHaveURL(/\/projects\/new/);
  await expect(page.getByRole('heading', { name: 'پروژه جدید' })).toBeVisible();

  await page.getByRole('button', { name: /تایپ/ }).click();
  await page.getByRole('button', { name: 'خودم تایپ می‌کنم' }).click();

  const templateCards = page.locator('#choices .project-card');
  await expect(templateCards.first()).toBeVisible();
  await templateCards.first().click();

  await page.getByRole('button', { name: 'ایجاد پروژه' }).click();
  await expect(page).toHaveURL(/\/editor(?:\?.*)?$/);
  await expect(page.locator('#farastWord')).toBeVisible({ timeout: 5000 });
  try {
    await expect(page.locator('#pagesViewport .farast-page')).toBeVisible({ timeout: 5000 });
  } catch (error) {
    throw new Error(`${error.message}\nSTATUS=${JSON.stringify(await page.locator('#statusText').textContent())}\nSAVE_STATE=${JSON.stringify(await page.locator('#saveState').textContent())}\nPAGE_ERRORS=${JSON.stringify(pageErrors)}\nCONSOLE_ERRORS=${JSON.stringify(consoleErrors)}`);
  }
  await expect(page.locator('.farast-editor').first()).toHaveAttribute('contenteditable', 'true');
  await expect(page.locator('#docTitle')).toHaveValue('سند جدید');

  const editor = page.locator('.farast-editor').first();
  await editor.click();
  await page.keyboard.type('سلام فراست');
  await expect(editor).toContainText('سلام فراست');
  await page.keyboard.type(' FARAST 2026');
  await expect(editor).toContainText('FARAST 2026');

  await page.keyboard.press('Control+a');
  const selection = await page.evaluate(() => { const s = window.FarastEditor?.getSelection?.(); return s ? { text: s.text, start: s.start, end: s.end } : null; });
  if (!selection?.text?.includes('سلام فراست')) throw new Error('SELECTION='+JSON.stringify(selection));
  await page.locator('.farast-ribbon button[data-command="bold"]').click();
  const modelAfterBold = await page.evaluate(() => window.FarastEditor?.state?.model?.sections?.[0]?.blocks || null);
  const domAfterBold = await editor.innerHTML();
  if (!JSON.stringify(modelAfterBold).includes('"bold":true')) { const debug = await page.evaluate(() => ({ stateSelection: window.FarastEditor?.state?.selection || null, lastSelection: window.FarastEditor?.state?.lastSelection || null, raw: (() => { const s = getSelection(); return s ? { text: s.toString(), anchorNode: s.anchorNode?.nodeType, anchorOffset: s.anchorOffset, focusNode: s.focusNode?.nodeType, focusOffset: s.focusOffset } : null; })() })); throw new Error('MODEL_AFTER_BOLD='+JSON.stringify(modelAfterBold)+' DOM='+domAfterBold+' DEBUG='+JSON.stringify(debug)); }
  await expect.poll(async () => editor.locator('strong').allTextContents()).toContain('سلام فراست');
  const transactionLog = await page.evaluate(() => window.FarastEditor?.getTransactions?.() || []);
  expect(transactionLog.some(x => x.command === 'InsertText')).toBeTruthy();
  expect(transactionLog.some(x => x.command === 'bold' || x.command === 'FormatText')).toBeTruthy();
  expect(transactionLog.every(x => x.id && x.timestamp && x.command && x.source)).toBeTruthy();


  await page.keyboard.press('Control+z');
  await expect(editor.locator('strong')).toHaveCount(0);
  await page.keyboard.press('Control+y');
  await expect(editor.locator('strong')).toHaveCount(1);

  await page.locator('#saveNow').click();
  await expect(page.locator('#saveState')).toHaveText('ذخیره شد', { timeout: 5000 });

  await page.reload();
  await expect(page.locator('#pagesViewport .farast-page').first()).toBeVisible({ timeout: 5000 });
  await expect(page.locator('.farast-editor').first()).toContainText('سلام فراست');
  await expect(page.locator('.farast-editor').first()).toContainText('FARAST 2026');
  await expect(page.locator('#farastWord')).toHaveAttribute('data-document-id', /.+/);

  const paragraph = 'این یک پاراگراف فارسی برای آزمون صفحه‌بندی پایدار فراست است. FARAST 2026.';
  await page.context().grantPermissions(['clipboard-read', 'clipboard-write'], { origin: process.env.FARAST_E2E_URL || 'http://127.0.0.1:8000' });
  await page.evaluate(() => navigator.clipboard.writeText('متن چسبانده‌شده فارسی FARAST'));
  await page.locator('.farast-editor').first().click();
  await page.keyboard.press('Control+a');
  await page.keyboard.press('Control+v');
  await expect(page.locator('.farast-editor').first()).toContainText('متن چسبانده‌شده فارسی FARAST');
  await page.locator('.farast-tab[data-tab="insert"]').click();
  await expect(page.locator('.farast-ribbon button[data-command="pageBreak"]')).toBeVisible();
  await page.locator('.farast-ribbon button[data-command="pageBreak"]').click();
  await expect.poll(async () => page.evaluate(() => window.FarastEditor?.state?.model?.sections?.[0]?.blocks?.some(b => b.type === 'page_break'))).toBeTruthy();

  await page.locator('.farast-tab[data-tab="home"]').click();
  await page.locator('.farast-editor').first().click();
  await page.keyboard.press('Control+a');
  await page.locator('.farast-ribbon button[data-command="ul"]').click();
  await expect.poll(async () => page.evaluate(() => window.FarastEditor?.state?.model?.sections?.[0]?.blocks?.some(b => b.type === 'list' && !b.ordered))).toBeTruthy();
  await page.keyboard.press('Control+z');
  await expect.poll(async () => page.evaluate(() => !window.FarastEditor?.state?.model?.sections?.[0]?.blocks?.some(b => b.type === 'list'))).toBeTruthy();

  const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', 'base64');
  await page.locator('#source').setInputFiles({ name: 'kernel-test.png', mimeType: 'image/png', buffer: png });
  await expect.poll(async () => page.evaluate(() => window.FarastEditor?.state?.model?.resources?.length || 0)).toBeGreaterThan(0);
  const resource = await page.evaluate(() => window.FarastEditor?.state?.model?.resources?.[0] || null);
  expect(resource?.id).toMatch(/^asset-/);
  expect(resource?.storage_path).toBeTruthy();
  expect(resource?.source || '').not.toMatch(/^data:image\//);

  await page.locator('.farast-editor').first().click();
  await page.keyboard.press('Control+a');
  for (let i = 0; i < 120; i++) {
    await page.keyboard.insertText(paragraph);
    if (i < 119) await page.keyboard.press('Enter');
  }
  await expect.poll(async () => page.locator('#pagesViewport .farast-page').count()).toBeGreaterThan(1);

  await page.locator('#saveNow').click();
  await expect(page.locator('#saveState')).toHaveText('ذخیره شد', { timeout: 5000 });

  await page.locator('.farast-tab[data-tab="insert"]').click();
  await page.locator('.farast-ribbon button[data-command="pageBreak"]').click();
  await expect.poll(async () => page.locator('#pagesViewport .farast-page').count()).toBeGreaterThan(2);
  await expect(page.locator('#totalPages')).toHaveText(/^[۳-۹۰-۹]+$/);

  await page.setViewportSize({ width: 820, height: 900 });
  await expect(page.locator('#farastWord')).toBeVisible();
  await expect(page.locator('#pagesViewport .farast-page').first()).toBeVisible();
  await expect(page.locator('.farast-editor').first()).toHaveAttribute('contenteditable', 'true');
});
