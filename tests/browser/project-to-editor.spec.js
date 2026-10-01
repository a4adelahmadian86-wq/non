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
    throw new Error(`${error.message}\nPAGE_ERRORS=${JSON.stringify(pageErrors)}\nCONSOLE_ERRORS=${JSON.stringify(consoleErrors)}`);
  }
  await expect(page.locator('.farast-editor').first()).toHaveAttribute('contenteditable', 'true');
  await expect(page.locator('#docTitle')).toHaveValue('سند جدید');

  const editor = page.locator('.farast-editor').first();
  await editor.click();
  await page.keyboard.type('سلام فراست');
  await expect(editor).toContainText('سلام فراست');
  await page.keyboard.type(' FARAST 2026');
  await expect(editor).toContainText('FARAST 2026');

  await page.keyboard.press('Control+ArrowLeft');
  await page.keyboard.press('Shift+ArrowRight');
  await page.locator('.farast-ribbon button[data-command="bold"]').click();
  await expect(editor.locator('strong')).toContainText('6');

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

  await page.locator('.farast-ribbon button[data-command="pageBreak"]').click();
  await expect(page.locator('#pagesViewport .farast-page')).toHaveCount(2);
  await expect(page.locator('#totalPages')).toHaveText('۲');
});
