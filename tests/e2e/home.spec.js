import { test, expect } from '@playwright/test';

const baseURL = process.env.FARAST_E2E_URL || 'http://127.0.0.1:8000';

test.describe('FARAST homepage discovery', () => {
  test('guest sees intent-first homepage and unknown-intent question', async ({ page }) => {
    await page.goto(baseURL + '/');
    await expect(page.getByRole('heading', { name: 'چه کاری می‌خواهید انجام دهید؟' })).toBeVisible();
    await expect(page.getByRole('button', { name: /فایل دارم/ })).toBeVisible();
    await expect(page.getByRole('button', { name: /از صفر شروع می‌کنم/ })).toBeVisible();
    await expect(page.getByRole('button', { name: /دنبال یک سرویس هستم/ })).toBeVisible();

    await page.getByRole('button', { name: /هنوز مطمئن نیستم/ }).click();
    await expect(page.getByRole('heading', { name: 'فایل آماده دارید یا می‌خواهید چیزی بسازید؟' })).toBeVisible();
  });

  test('guest progressive file flow asks only the source-type question before upload', async ({ page }) => {
    await page.goto(baseURL + '/');
    await page.getByRole('button', { name: /فایل دارم/ }).click();
    await expect(page.getByRole('heading', { name: 'نوع محتوای فایل چیست؟' })).toBeVisible();
    await expect(page.getByRole('button', { name: /دست‌نوشته/ })).toBeVisible();
    await page.getByRole('button', { name: /دست‌نوشته/ }).click();
    await expect(page.getByRole('heading', { name: 'فایل را انتخاب کنید' })).toBeVisible();
    await expect(page.locator('#intentFile')).toHaveAttribute('accept', /\.pdf/);
  });

  test('authenticated article flow creates a Word Processor project and opens the editor', async ({ page }) => {
    test.skip(!process.env.ADMIN_MOBILE || !process.env.GITHUB_RUN_ID, 'CI admin seed is required for this authenticated browser scenario.');

    await page.goto(baseURL + '/login');
    await page.locator('input[name="mobile"]').fill(process.env.ADMIN_MOBILE);
    await page.locator('#phoneForm button[type="submit"]').click();
    await page.waitForURL(/\/login\/password/);
    await page.locator('input[name="password"]').fill(process.env.GITHUB_RUN_ID + '-farast-e2e');
    await page.locator('form button[type="submit"]').click();
    await page.waitForLoadState('domcontentloaded');
    await page.goto(baseURL + '/');

    await page.getByRole('button', { name: /از صفر شروع می‌کنم/ }).click();
    await page.getByRole('button', { name: /مقاله/ }).click();
    await page.waitForURL(/\/editor\?project=\d+|\/editor$/);
    await expect(page.locator('#farastWord')).toBeVisible();
    await expect(page.locator('#pagesViewport')).toBeVisible();
  });
});
