const { test, expect } = require('@playwright/test');

async function signInAndOpenDashboard(page) {
  const password = String(process.env.GITHUB_RUN_ID || 'local') + '-farast-e2e';
  await page.goto('/login?continue=/dashboard');
  await page.locator('input[name="mobile"]').fill('09151234567');
  await page.getByRole('button', { name: /ادامه با شماره موبایل/ }).click();
  await expect(page).toHaveURL(/\\/login\\/password/);
  await page.locator('input[name="password"]').fill(password);
  await page.getByRole('button', { name: 'ورود به حساب' }).click();
  await expect(page).toHaveURL(/\\/dashboard/);
  await expect(page.locator('[data-dashboard-nav]')).toBeVisible();
}

test.describe('Dashboard navigation recovery', () => {
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

  test('desktop navigation can always be reopened after collapsing and remembers the choice', async ({ page }) => {
    await signInAndOpenDashboard(page);
    const nav = page.locator('[data-dashboard-nav]');
    const toggle = nav.locator('[data-dashboard-nav-toggle]');

    await toggle.click();
    await expect(nav).toHaveClass(/is-collapsed/);
    await expect(toggle).toBeVisible();
    await expect(toggle).toHaveAttribute('aria-expanded', 'false');
    await expect(toggle).toHaveAttribute('aria-label', 'باز کردن منوی داشبورد');

    await page.reload();
    await expect(nav).toHaveClass(/is-collapsed/);
    await expect(toggle).toBeVisible();
    await toggle.click();
    await expect(nav).not.toHaveClass(/is-collapsed/);
    await expect(toggle).toHaveAttribute('aria-expanded', 'true');
    await expect(nav.getByRole('link', { name: /فضای کاری|داشبورد/ }).first()).toBeVisible();
  });

  test('mobile collapsed navigation keeps a visible, usable reopen control', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await signInAndOpenDashboard(page);
    const nav = page.locator('[data-dashboard-nav]');
    const toggle = nav.locator('[data-dashboard-nav-toggle]');

    await toggle.click();
    await expect(nav).toHaveClass(/is-collapsed/);
    await expect(toggle).toBeVisible();
    await expect(nav.locator('.farast-dashboard-nav__groups')).toBeHidden();

    await toggle.click();
    await expect(nav).not.toHaveClass(/is-collapsed/);
    await expect(nav.locator('.farast-dashboard-nav__groups')).toBeVisible();
  });

  test('compact desktop navigation exposes accessible names for icon-only destinations', async ({ page }) => {
    await signInAndOpenDashboard(page);
    const nav = page.locator('[data-dashboard-nav]');
    await nav.locator('[data-dashboard-nav-toggle]').click();
    const links = nav.locator('.farast-dashboard-nav__items a');
    await expect(links.first()).toHaveAttribute('aria-label', /.+/);
    await expect(links.first()).toHaveAttribute('title', /.+/);
  });
});
