const { test, expect } = require('@playwright/test');

async function signInAsSeededAdmin(page) {
  const password = String(process.env.GITHUB_RUN_ID || 'local') + '-farast-e2e';

  await page.goto('/login?continue=%2Fadmin%2Fstore%2Fcreate');
  await page.locator('input[name="mobile"]').fill('09151234567');

  const phoneResponsePromise = page.waitForResponse(response =>
    response.url().endsWith('/login/phone') && response.request().method() === 'POST'
  );
  await page.getByRole('button', { name: /ادامه با شماره موبایل/ }).click();
  const phoneResponse = await phoneResponsePromise;
  expect(phoneResponse.ok()).toBeTruthy();

  await expect(page).toHaveURL(/\/login\/password/);
  await page.locator('input[name="password"]').fill(password);
  await page.getByRole('button', { name: 'ورود به حساب' }).click();
  await expect(page).toHaveURL(/\/admin\/store\/create(?:[?#].*)?$/);
}

async function signInAsSeededEmployee(page) {
  const password = String(process.env.GITHUB_RUN_ID || 'local') + '-farast-employee';

  await page.goto('/login?continue=%2Fdashboard');
  await page.locator('input[name="mobile"]').fill('09150000001');

  const phoneResponsePromise = page.waitForResponse(response =>
    response.url().endsWith('/login/phone') && response.request().method() === 'POST'
  );
  await page.getByRole('button', { name: /ادامه با شماره موبایل/ }).click();
  const phoneResponse = await phoneResponsePromise;
  expect(phoneResponse.ok()).toBeTruthy();

  await expect(page).toHaveURL(/\/login\/password/);
  await page.locator('input[name="password"]').fill(password);
  await page.getByRole('button', { name: 'ورود به حساب' }).click();
  await expect(page).toHaveURL(/\/dashboard(?:[?#].*)?$/);
}

async function advanceProductWizardToReview(page) {
  for (const [current, next] of [[2, 3], [3, 4]]) {
    await page.locator(`[data-wizard-panel="${current}"] .product-wizard-next`).click();
    await expect(page.locator(`[data-wizard-panel="${next}"]`)).toBeVisible();
  }
}

test.describe('Real admin product workflow', () => {
  test.afterEach(async ({ page }) => {
    try {
      const csrf = await page.locator('meta[name="csrf-token"]').getAttribute('content');
      if (csrf) {
        await page.evaluate(async token => fetch('/logout', {
          method: 'POST',
          headers: { 'X-CSRF-TOKEN': token, Accept: 'application/json' },
          credentials: 'same-origin',
        }), csrf);
      }
    } catch (_) {}
  });

  test('creates a product through the real UI, exposes validation errors, and persists a private draft', async ({ page }) => {
    test.setTimeout(120_000);
    const runId = String(process.env.GITHUB_RUN_ID || 'local').replace(/[^a-zA-Z0-9-]/g, '');
    const productTitle = `محصول آزمون رابط ${runId}`;

    await signInAsSeededAdmin(page);

    // The E2E database is populated with the repository's canonical demo category.
    // Category creation has no current form in this editor, so the test does not invent one.
    const categorySelect = page.locator('select[name="category_id"]');
    await categorySelect.selectOption({ label: 'آزمایشی' });
    const categoryId = await categorySelect.inputValue();
    expect(categoryId).not.toBe('');

    await page.locator('input[name="title"]').fill(productTitle);
    await page.locator('select[name="type"]').selectOption('digital_file');
    await categorySelect.selectOption(categoryId);
    await page.locator('[data-wizard-panel="0"] .product-wizard-next').click();
    await expect(page.locator('[data-wizard-panel="1"]')).toBeVisible();

    await page.locator('input[name="price_rials"]').fill('125000');
    await page.locator('input[name="compare_at_price_rials"]').fill('100000');
    await page.locator('[data-wizard-panel="1"] .product-wizard-next').click();
    await advanceProductWizardToReview(page);
    await page.locator('#productSaveButton').click();

    await expect(page).toHaveURL(/\/admin\/store\/create(?:[?#].*)?$/);
    await expect(page.locator('[role="alert"]')).toBeVisible();
    await expect(page.locator('input[name="compare_at_price_rials"]')).toHaveValue('100000');

    await page.locator('[data-wizard-panel="0"] .product-wizard-next').click();
    await expect(page.locator('[data-wizard-panel="1"]')).toBeVisible();
    await page.locator('input[name="compare_at_price_rials"]').fill('150000');
    await page.locator('[data-wizard-panel="1"] .product-wizard-next').click();
    await advanceProductWizardToReview(page);
    await page.locator('#productSaveButton').click();

    await expect(page).toHaveURL(/\/admin\/store\/\d+\/edit#product-files$/);
    await expect(page.locator('.store-alert.success')).toContainText('پیش‌نویس ایجاد شد');
    await expect(page.locator('input[name="title"]')).toHaveValue(productTitle);
    await expect(page.locator('select[name="category_id"]')).toHaveValue(categoryId);
    await expect(page.locator('input[name="price_rials"]')).toHaveValue('125000');
    await expect(page.locator('input[name="compare_at_price_rials"]')).toHaveValue('150000');
    await expect(page.locator('select[name="status"]')).toHaveValue('draft');
    await expect(page.locator('#product-files')).toBeVisible();

    await page.goto('/admin/store?q=' + encodeURIComponent(productTitle));
    await expect(page.getByText(productTitle, { exact: true })).toBeVisible();
  });

  test('creates, replies to, closes, and rates a real support ticket', async ({ page }) => {
    test.setTimeout(90_000);
    const runId = String(process.env.GITHUB_RUN_ID || 'local').replace(/[^a-zA-Z0-9-]/g, '');
    const subject = `تیکت آزمون سرتاسری ${runId}`;
    const firstMessage = 'برای بررسی چرخه واقعی پشتیبانی این پیام آزمایشی ثبت می‌شود.';
    const reply = 'پاسخ آزمایشی برای تأیید ذخیره واقعی پیام در گفتگو.';

    await signInAsSeededAdmin(page);
    await page.goto('/support/create');
    const form = page.locator('#createTicketForm');
    await expect(form).toBeVisible();

    await form.locator('select[name="department"]').selectOption('support');
    await form.locator('select[name="priority"]').selectOption('normal');
    await form.locator('input[name="topic_area"][value="services"]').check();
    await form.locator('input[name="related_service"][value="typing"]').check();
    await form.locator('input[name="subject"]').fill(subject);
    await form.locator('textarea[name="body"]').fill(firstMessage);

    const createResponsePromise = page.waitForResponse(response =>
      response.url().includes('/support/tickets') &&
      response.request().method() === 'POST' &&
      !/\/messages(?:\/|$)/.test(new URL(response.url()).pathname)
    );
    await form.getByRole('button', { name: 'ثبت تیکت' }).click();
    const createResponse = await createResponsePromise;
    expect(createResponse.status()).toBe(302);
    await expect(page).toHaveURL(/\/support\/tickets\/\d+$/);
    await expect(page.getByRole('heading', { name: subject })).toBeVisible();
    await expect(page.locator('#chatMessages')).toContainText(firstMessage);

    const ticketUrl = page.url();
    await page.locator('#composerBody').fill(reply);
    const replyResponsePromise = page.waitForResponse(response =>
      response.url().includes('/messages') && response.request().method() === 'POST'
    );
    await page.locator('#sendBtn').click();
    const replyResponse = await replyResponsePromise;
    expect(replyResponse.status()).toBe(302);
    await expect(page.locator('#chatMessages')).toContainText(reply);

    await page.locator('#openCloseDialog').click();
    const closeDialog = page.locator('#closeTicketDialog');
    await expect(closeDialog).toBeVisible();
    const closeResponsePromise = page.waitForResponse(response =>
      response.url().includes('/close') && response.request().method() === 'POST'
    );
    await closeDialog.getByRole('button', { name: 'بستن تیکت' }).click();
    const closeResponse = await closeResponsePromise;
    expect(closeResponse.status()).toBe(302);
    await expect(page).toHaveURL(ticketUrl);
    await expect(page.locator('#replyBox')).toHaveCount(0);
    await expect(page.locator('#chatComposer')).toHaveCount(0);
    await expect(page.locator('#csatForm')).toBeVisible();

    await page.locator('.csat-star[data-score="5"]').click();
    await expect(page.locator('#csatSubmit')).toBeEnabled();
    await page.locator('#csatForm textarea[name="comment"]').fill('پاسخ‌گویی آزمایشی قابل قبول بود.');
    const csatResponsePromise = page.waitForResponse(response =>
      response.url().includes('/csat') && response.request().method() === 'POST'
    );
    await page.locator('#csatSubmit').click();
    const csatResponse = await csatResponsePromise;
    expect(csatResponse.status()).toBe(302);
    await expect(page.getByText('امتیاز شما: 5 از ۵')).toBeVisible();
    await expect(page.getByText('پاسخ‌گویی آزمایشی قابل قبول بود.')).toBeVisible();
  });

  test('admin can open the role console and see the role matrix', async ({ page }) => {
    await signInAsSeededAdmin(page);
    const response = await page.goto('/admin/access');
    expect(response?.status()).toBe(200);
    await expect(page.getByText('مدیریت نقش‌ها و مجوزها')).toBeVisible();
    await expect(page.getByText('مدیر سازمان')).toBeVisible();
    await expect(page.getByText('سرپرست تیم')).toBeVisible();
    await expect(page.getByText('کارمند')).toBeVisible();
  });

  test('employee can use the dashboard but cannot open the role permission console', async ({ page }) => {
    await signInAsSeededEmployee(page);
    await expect(page).toHaveURL(/\/dashboard/);
    const response = await page.goto('/admin/access');
    expect(response?.status()).toBe(403);
  });

  test('free storefront checkout grants a library entitlement and a secure file download', async ({ page }) => {
    await signInAsSeededAdmin(page);
    await page.goto('/store/product/demo-free');
    await expect(page.getByRole('heading', { name: 'فایل آزمایشی رایگان' })).toBeVisible();

    const addButton = page.locator('.add-to-cart[data-product-id]');
    const addResponsePromise = page.waitForResponse(response =>
      new URL(response.url()).pathname.startsWith('/cart/products/') &&
      response.request().method() === 'POST'
    );
    await addButton.click();
    const addResponse = await addResponsePromise;
    expect(addResponse.status()).toBe(200);
    await expect(addButton).toContainText('در سبد خرید قرار گرفت');

    await page.goto('/cart');
    await expect(page.getByText('فایل آزمایشی رایگان', { exact: true })).toBeVisible();
    const checkoutForm = page.locator('form.store-checkout-form');
    await checkoutForm.locator('input[name="accept_terms"]').check();

    const checkoutResponsePromise = page.waitForResponse(response =>
      new URL(response.url()).pathname === '/cart/checkout' &&
      response.request().method() === 'POST'
    );
    await checkoutForm.getByRole('button', { name: 'دریافت رایگان' }).click();
    const checkoutResponse = await checkoutResponsePromise;
    expect(checkoutResponse.status()).toBe(302);
    await expect(page).toHaveURL(/\/library(?:[?#].*)?$/);
    await expect(page.locator('.store-alert.success[role="status"]')).toContainText('سفارش رایگان ثبت شد');

    const downloadButton = page.locator('.library-download');
    await expect(downloadButton).toBeVisible();
    const downloadPromise = page.waitForEvent('download');
    await downloadButton.click();
    const download = await downloadPromise;
    expect(download.suggestedFilename()).toBe('demo-free.txt');
    expect(await download.failure()).toBeNull();
  });

  test('admin and employee exchange real messages through the internal messenger UI', async ({ page }) => {
    test.setTimeout(90_000);
    const firstMessage = 'پیام آزمایشی مدیر برای آزمون ارتباط داخلی واقعی.';
    const reply = 'پاسخ آزمایشی کارمند برای آزمون دریافت زنده پیام.';

    await signInAsSeededAdmin(page);
    await page.goto('/messenger');
    await expect(page.getByRole('heading', { name: 'مرکز پیام‌رسانی' })).toBeVisible();

    await page.locator('[data-open-compose]').click();
    const compose = page.locator('dialog[data-compose]');
    await expect(compose).toBeVisible();
    const employeeOption = compose.locator('select[name="participant_ids[]"] option').filter({ hasText: 'کارمند آزمون رابط' });
    const employeeId = await employeeOption.getAttribute('value');
    expect(employeeId).toBeTruthy();
    await compose.locator('select[name="type"]').selectOption('direct');
    await compose.locator('select[name="participant_ids[]"]').selectOption(employeeId);

    const createResponsePromise = page.waitForResponse(response =>
      new URL(response.url()).pathname === '/messenger/conversations' &&
      response.request().method() === 'POST'
    );
    await compose.getByRole('button', { name: 'ایجاد گفت‌وگو' }).click();
    const createResponse = await createResponsePromise;
    expect(createResponse.status()).toBe(302);
    await expect(page).toHaveURL(/\/messenger\/conversations\/\d+$/);
    const conversationUrl = page.url();

    const composer = page.locator('#fm-composer');
    await composer.locator('textarea[name="body"]').fill(firstMessage);
    const firstSendPromise = page.waitForResponse(response =>
      new URL(response.url()).pathname.endsWith('/messages') &&
      response.request().method() === 'POST'
    );
    await composer.getByRole('button', { name: 'ارسال پیام' }).click();
    const firstSend = await firstSendPromise;
    expect(firstSend.status()).toBe(201);
    await expect(page.locator('#fm-messages')).toContainText(firstMessage);

    const employeePage = await page.context().newPage();
    try {
      await signInAsSeededEmployee(employeePage);
      const conversationResponse = await employeePage.goto(conversationUrl);
      expect(conversationResponse?.status()).toBe(200);
      await expect(employeePage.locator('#fm-messages')).toContainText(firstMessage);

      const employeeComposer = employeePage.locator('#fm-composer');
      await employeeComposer.locator('textarea[name="body"]').fill(reply);
      const replyPromise = employeePage.waitForResponse(response =>
        new URL(response.url()).pathname.endsWith('/messages') &&
        response.request().method() === 'POST'
      );
      await employeeComposer.getByRole('button', { name: 'ارسال پیام' }).click();
      const replyResponse = await replyPromise;
      expect(replyResponse.status()).toBe(201);
      await expect(employeePage.locator('#fm-messages')).toContainText(reply);

      // The admin page remains open; its authenticated polling loop must receive the employee's message.
      await expect(page.locator('#fm-messages')).toContainText(reply, { timeout: 12_000 });
    } finally {
      await employeePage.close();
    }
  });

  test('mobile product editor fits the viewport and its primary fields are keyboard reachable', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });

    await page.goto('/admin/store');
    await expect(page).toHaveURL(/\/login/);

    await signInAsSeededAdmin(page);
    const dimensions = await page.evaluate(() => ({
      viewport: document.documentElement.clientWidth,
      document: document.documentElement.scrollWidth,
    }));
    expect(dimensions.document).toBeLessThanOrEqual(dimensions.viewport + 1);

    const title = page.locator('input[name="title"]');
    await title.focus();
    await page.keyboard.press('Tab');
    await expect(page.locator('select[name="type"]')).toBeFocused();
    await page.keyboard.press('Tab');
    await expect(page.locator('select[name="category_id"]')).toBeFocused();
  });
});
