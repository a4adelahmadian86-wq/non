const { test, expect } = require('@playwright/test');

async function signInAndOpenDashboard(page) {
  const password = String(process.env.GITHUB_RUN_ID || 'local') + '-farast-e2e';
  await page.goto('/login?continue=/dashboard');
  await page.locator('input[name="mobile"]').fill('09151234567');
  await page.getByRole('button', { name: /ادامه با شماره موبایل/ }).click();
  await expect(page).toHaveURL(new RegExp('/login/password'));
  await page.locator('input[name="password"]').fill(password);
  await page.getByRole('button', { name: 'ورود به حساب' }).click();
  await expect(page).toHaveURL(new RegExp('/dashboard'));
  await expect(page.locator('.user-dashboard')).toBeVisible();
}

test.describe('Personal dashboard visual experience', () => {
  test.afterEach(async ({ page }) => {
    try {
      const csrf = await page.locator('meta[name="csrf-token"]').getAttribute('content');
      if (csrf) await page.evaluate(async token => fetch('/logout', {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': token, Accept: 'application/json' },
        credentials: 'same-origin'
      }), csrf);
    } catch (_) {}
  });

  test('desktop dashboard presents a clear five-column metric hierarchy', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 1000 });
    await signInAndOpenDashboard(page);

    const metrics = page.locator('.user-dashboard__metrics');
    await expect(metrics.locator('.user-dashboard__metric')).toHaveCount(5);
    const columns = await metrics.evaluate(element => getComputedStyle(element).gridTemplateColumns.split(' ').length);
    expect(columns).toBe(5);

    const hero = page.locator('.user-dashboard__hero');
    const heroStyle = await hero.evaluate(element => ({
      radius: getComputedStyle(element).borderRadius,
      shadow: getComputedStyle(element).boxShadow
    }));
    expect(heroStyle.radius).not.toBe('0px');
    expect(heroStyle.shadow).not.toBe('none');
  });

  test('mobile dashboard keeps cards within the viewport without horizontal overflow', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await signInAndOpenDashboard(page);

    const dashboard = page.locator('.user-dashboard');
    const metrics = page.locator('.user-dashboard__metrics');
    const columns = await metrics.evaluate(element => getComputedStyle(element).gridTemplateColumns.split(' ').length);
    expect(columns).toBe(1);

    const bounds = await dashboard.evaluate(element => {
      const rect = element.getBoundingClientRect();
      return { left: rect.left, right: rect.right, viewport: document.documentElement.clientWidth };
    });
    expect(bounds.left).toBeGreaterThanOrEqual(-1);
    expect(bounds.right).toBeLessThanOrEqual(bounds.viewport + 1);
  });
});
