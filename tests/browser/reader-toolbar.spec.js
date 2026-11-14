const { test, expect } = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');

const readerView = fs.readFileSync(
  path.join(__dirname, '../../resources/views/store/reader.blade.php'),
  'utf8'
);
const readerStyles = readerView.match(/<style>([\s\S]*?)<\/style>/)?.[1];
if (!readerStyles) throw new Error('Reader view styles could not be loaded.');

test('wide reader toolbar actions stay visibly labeled without remote icon fonts', async ({ page }) => {
  await page.setContent(
    '<style>' + readerStyles + '</style>' +
    '<section class="farast-reader" dir="rtl">' +
      '<nav class="farast-reader-toolbar" aria-label="ابزارهای مطالعه">' +
        '<button type="button" class="reader-tool reader-tool-wide" aria-label="ثبت نشانک">' +
          '<i class="fa-solid fa-bookmark" aria-hidden="true"></i>' +
          '<span class="reader-tool-label">نشانک</span>' +
        '</button>' +
      '</nav>' +
    '</section>'
  );

  const action = page.locator('.reader-tool-wide[aria-label="ثبت نشانک"]');
  await expect(action).toBeVisible();

  for (const width of [390, 1280]) {
    await page.setViewportSize({ width, height: 850 });
    const visibleLabel = await action.evaluate(element => getComputedStyle(element, '::after').content);
    const buttonWidth = await action.evaluate(element => element.getBoundingClientRect().width);
    expect(visibleLabel).toBe('"ثبت نشانک"');
    expect(buttonWidth).toBeGreaterThan(38);
  }
});
