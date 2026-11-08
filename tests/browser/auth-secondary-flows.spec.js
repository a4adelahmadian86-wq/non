const { test, expect } = require('@playwright/test');

test.describe('Secondary authentication flows', () => {
  test('email login form reports API errors accessibly on mobile', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    const pageErrors = [];
    page.on('pageerror', error => pageErrors.push(error.message));

    const response = await page.goto('/login/email');
    expect(response?.status()).toBe(200);
    await expect(page.locator('#emailLoginInput')).toBeVisible();

    await page.route('**/login/email', route => {
      if (route.request().method() === 'POST') {
        return route.fulfill({
          status: 422,
          contentType: 'application/json',
          body: JSON.stringify({ message: 'ایمیل واردشده معتبر نیست.' }),
        });
      }
      return route.continue();
    });

    await page.locator('#emailLoginInput').fill('invalid@example.test');
    await page.locator('#emailForm button[type="submit"]').click();

    const message = page.locator('#emailMessage');
    await expect(message).toBeVisible();
    await expect(message).toContainText('ایمیل واردشده معتبر نیست.');
    await expect(message).toHaveAttribute('role', 'alert');
    await expect(page.locator('#emailForm button[type="submit"]')).toBeEnabled();
    expect(pageErrors).toEqual([]);
  });

  test('password recovery form reports API errors accessibly on mobile', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    const pageErrors = [];
    page.on('pageerror', error => pageErrors.push(error.message));

    const response = await page.goto('/forgot-password');
    expect(response?.status()).toBe(200);
    await expect(page.locator('#resetMobile')).toBeVisible();

    await page.route('**/forgot-password', route => {
      if (route.request().method() === 'POST') {
        return route.fulfill({
          status: 422,
          contentType: 'application/json',
          body: JSON.stringify({ message: 'برای این شماره حسابی پیدا نشد.' }),
        });
      }
      return route.continue();
    });

    await page.locator('#resetMobile').fill('09123456789');
    await page.locator('#resetPhone button[type="submit"]').click();

    const message = page.locator('#resetMessage');
    await expect(message).toBeVisible();
    await expect(message).toContainText('برای این شماره حسابی پیدا نشد.');
    await expect(message).toHaveAttribute('role', 'alert');
    await expect(page.locator('#resetPhone button[type="submit"]')).toBeEnabled();
    expect(pageErrors).toEqual([]);
  });
});
