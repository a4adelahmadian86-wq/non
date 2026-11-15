const { test, expect } = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');

const readerView = fs.readFileSync(
  path.join(__dirname, '../../resources/views/store/reader.blade.php'),
  'utf8'
);
const readerStyles = fs.readFileSync(
  path.join(__dirname, '../../public/css/farast-reader.css'),
  'utf8'
);

test('reader styles are served from a local cacheable asset without remote dependencies', async () => {
  expect(readerView).toMatch(/\/css\/farast-reader\.css\?v=[A-Za-z0-9_-]+/);
  expect(readerView).not.toMatch(/<style>/i);
  expect(readerStyles).not.toMatch(/@import\s+[^;]*https?:|url\(\s*['"]?https?:/i);
});

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
