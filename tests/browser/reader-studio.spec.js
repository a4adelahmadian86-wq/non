const { test, expect } = require('@playwright/test');
test('studio exposes favorites immediately, applies saved styles and supports shortcuts',async({page})=>{
  await page.goto('/');
  await page.setContent('<section class="farast-reader" data-reader-root data-product-id="studio-test"><nav class="farast-reader-toolbar"></nav><section class="farast-annotation-toolbar"><div class="farast-annotation-tools" role="toolbar"><button data-tool="select" aria-pressed="true">Select</button><button data-tool="pen" aria-pressed="false">Pen</button><button data-tool="highlight" aria-pressed="false">Highlight</button><button data-tool="underline" aria-pressed="false">Underline</button><button data-tool="strike" aria-pressed="false">Strike</button><button data-tool="note" aria-pressed="false">Note</button><button data-tool="comment" aria-pressed="false">Comment</button><button data-tool="eraser" aria-pressed="false">Eraser</button><label>Color <input data-color type="color" value="#202020"></label><label>Width <input data-width type="range" value="3"></label></div></section><main id="reader-stage" style="height:300px;overflow:auto"><div style="height:1000px;width:1000px"></div></main></section>');
  await page.addScriptTag({path:require('path').join(__dirname,'../../public/js/farast-reader-studio.js')});
  await expect(page.locator('.farast-reader-studio-dock')).toBeVisible();
  await expect(page.locator('.farast-studio-favorite')).toHaveCount(14);
  await page.locator('[data-favorite-id="pen-blue"]').click();
  await expect(page.locator('[data-tool="pen"]')).toHaveAttribute('aria-pressed','true');
  await expect(page.locator('[data-color]')).toHaveValue('#2563eb');
  await expect(page.locator('[data-width]')).toHaveValue('2');
  await page.keyboard.press('h');
  await expect(page.locator('[data-tool="highlight"]')).toHaveAttribute('aria-pressed','true');
  await page.locator('[aria-label="جابجایی صفحه"]').click();
  await expect(page.locator('[aria-label="جابجایی صفحه"]')).toHaveAttribute('aria-pressed','true');
  await page.locator('[aria-label="تعویض سریع ابزار"]').click();
  await expect(page.locator('[aria-label="جابجایی صفحه"]')).toHaveAttribute('aria-pressed','false');
  await page.reload();
});