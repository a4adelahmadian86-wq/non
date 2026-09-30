const { test, expect } = require('@playwright/test');

test('logs in through the real auth flow, creates a typing project, and opens the real word processor surface', async ({ page }) => {
  const password = `${process.env.GITHUB_RUN_ID || 'local'}-farast-e2e`;

  await page.goto('/login?continue=/projects/new');
  await page.getByRole('textbox', { name: /شماره موبایل/ }).fill('091512345678');
  await page.getByRole('button', { name: /ادامه با شماره موبایل/ }).click();
  await expect(page).toHaveURL(/\/login\/password/);
  await page.getByLabel('رمز عبور').fill(password);
  await page.getByRole('button', { name: 'ورود به حساب' }).click();

  await expect(page).toHaveURL(/\/projects\/new/);
  await expect(page.getByRole('heading', { name: 'پروژه جدید' })).toBeVisible();

  await page.getByRole('button', { name: /تایپ/ }).click();
  await page.getByRole('button', { name: 'خودم تایپ می‌کنم' }).click();

  const templateCards = page.locator('#choices .project-card');
  await expect(templateCards.first()).toBeVisible();
  await templateCards.first().click();

  await page.getByRole('button', { name: 'ایجاد پروژه' }).click();
  await expect(page.locator('#farastWord')).toBeVisible();
  await expect(page.locator('#pagesViewport .farast-page')).toBeVisible();
  await expect(page.locator('.farast-editor').first()).toHaveAttribute('contenteditable', 'true');
  await expect(page.locator('#docTitle')).toHaveValue('سند جدید');
});
