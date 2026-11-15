const { test, expect } = require('@playwright/test');

test('reader shortcut guide is discoverable, keyboard accessible and does not hijack editable fields', async ({ page }) => {
  await page.setContent(`
    <section class="farast-reader" data-reader-root>
      <nav class="farast-reader-toolbar" aria-label="ابزارهای مطالعه"></nav>
      <input aria-label="جست‌وجوی آزمایشی">
      <main id="reader-stage"></main>
    </section>
  `);
  await page.addScriptTag({ path: require('path').join(__dirname, '../../public/js/farast-reader-help.js') });

  const trigger = page.getByRole('button', { name: 'راهنمای میان‌برهای صفحه‌کلید' });
  const dialog = page.getByRole('dialog', { name: 'میان‌برهای صفحه‌کلید' });
  await expect(trigger).toBeVisible();
  await expect(dialog).toBeHidden();

  await page.keyboard.press('Shift+/');
  await expect(dialog).toBeVisible();
  await expect(dialog.getByRole('row')).toHaveCount(8);
  await expect(dialog.getByText('جست‌وجو در سند')).toBeVisible();
  await expect(dialog.getByText('ثبت نشانک صفحه')).toBeVisible();

  await page.getByRole('button', { name: 'متوجه شدم' }).click();
  await expect(dialog).toBeHidden();
  await expect(trigger).toBeFocused();

  await page.getByRole('textbox', { name: 'جست‌وجوی آزمایشی' }).focus();
  await page.keyboard.press('Shift+/');
  await expect(dialog).toBeHidden();

  await trigger.click();
  await expect(dialog).toBeVisible();

  await page.evaluate(() => {
    window.__shortcutDialogBubbledKeys = [];
    document.addEventListener('keydown', event => {
      if (event.target instanceof Element && event.target.closest('#reader-shortcuts-dialog')) {
        window.__shortcutDialogBubbledKeys.push(event.key);
      }
    });
  });
  await page.keyboard.press('f');
  await page.keyboard.press('ArrowLeft');
  expect(await page.evaluate(() => window.__shortcutDialogBubbledKeys)).toEqual([]);

  await page.keyboard.press('Escape');
  await expect(dialog).toBeHidden();
  await expect(trigger).toBeFocused();
  expect(await page.evaluate(() => window.__shortcutDialogBubbledKeys)).toEqual(['Escape']);
});
