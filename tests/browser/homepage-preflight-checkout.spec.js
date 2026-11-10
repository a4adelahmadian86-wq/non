const { test, expect } = require('@playwright/test');

test('homepage file intake sends a deposit quote to checkout after explicit acceptance', async ({ page }) => {
  let accepted = 0;
  let created = 0;

  await page.route('**/editor/upload', route => route.fulfill({
    status: 200,
    contentType: 'application/json',
    body: JSON.stringify({ ok: true, requires_login: false, path: 'typing/qa/source.pdf', mime: 'application/pdf', name: 'source.pdf' }),
  }));

  await page.route('**/projects', async route => {
    if (route.request().method() !== 'POST') return route.continue();
    created += 1;
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ ok: true, project_id: 91, next_url: '/editor?project=91' }),
    });
  });

  await page.route('**/editor/preflight/estimate', route => route.fulfill({
    status: 200,
    contentType: 'application/json',
    body: JSON.stringify({
      ok: true,
      mode: 'deposit',
      pages: 8,
      estimate_rials: 200000,
      discount_rials: 50000,
      payable_estimate_rials: 150000,
      deposit_percent: 30,
      deposit_rials: 45000,
      free_page_applied: 1,
    }),
  }));

  await page.route('**/editor/preflight/accept', async route => {
    accepted += 1;
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ ok: true, action: 'deposit', order_id: 812, checkout_url: '/checkout/812' }),
    });
  });

  await page.route('**/checkout/812', route => route.fulfill({
    status: 200,
    contentType: 'text/html',
    body: '<!doctype html><html lang="fa"><body><h1>Mock checkout reached</h1></body></html>',
  }));

  await page.goto('/');
  await page.locator('[data-intent="file"]').click();
  await page.locator('[data-source="printed"]').click();
  await page.locator('#intentFile').setInputFiles({
    name: 'source.pdf',
    mimeType: 'application/pdf',
    buffer: Buffer.from('%PDF-1.4 homepage preflight test'),
  });

  await expect(page.getByText('پیش‌پرداخت قابل پرداخت اکنون')).toBeVisible();
  await expect(page.getByText('۴٬۵۰۰ تومان')).toBeVisible();
  await expect(page.getByText('۱۵٬۰۰۰ تومان')).toBeVisible();
  await expect(page.getByRole('button', { name: 'تأیید و رفتن به پرداخت پیش‌پرداخت' })).toBeVisible();

  await page.getByRole('button', { name: 'تأیید و رفتن به پرداخت پیش‌پرداخت' }).click();
  await expect(page).toHaveURL(/\/checkout\/812$/);
  await expect(page.getByRole('heading', { name: 'Mock checkout reached' })).toBeVisible();

  expect(created).toBe(1);
  expect(accepted).toBe(1);
});


test('service showcase entry points open the matching guided workflow instead of bypassing it', async ({ page }) => {
  await page.goto('/');
  const fileCard = page.locator('.home-service-card').filter({ hasText: 'تبدیل فایل به متن' });
  await fileCard.getByRole('link', { name: 'شروع با فایل' }).click();
  await expect(page.getByRole('heading', { name: 'نوع محتوای فایل چیست؟' })).toBeVisible();

  await page.locator('#discoveryBack').click();
  const voiceCard = page.locator('.home-service-card').filter({ hasText: 'تایپ با گفتار' });
  await voiceCard.getByRole('link', { name: 'انتخاب سرویس' }).click();
  await expect(page.getByRole('heading', { name: 'کدام کار را می‌خواهید انجام دهید؟' })).toBeVisible();
  await page.locator('[data-service="voice"]').click();
  await expect(page.getByRole('heading', { name: 'زبان گفتار چیست؟' })).toBeVisible();
});
