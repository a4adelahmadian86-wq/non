const { test, expect } = require('@playwright/test');
test('annotation workspace persists text highlights and supports undo',async({page})=>{
  await page.goto('/');
  await page.setContent('<section class="farast-reader" data-reader-root data-product-id="annotation-test"><nav class="farast-reader-toolbar"></nav><article class="farast-reader-page-item is-active" data-index="0"><div class="farast-pdf-document"><div class="farast-pdf-text-layer"><span>FARAST annotation sample</span></div></div></article></section>');
  await page.addScriptTag({path:require('path').join(__dirname,'../../public/js/farast-reader-annotations.js')});
  await expect(page.locator('.farast-annotation-toolbar')).toBeVisible();
  await page.locator('.farast-pdf-text-layer span').evaluate(n=>{const r=document.createRange();r.selectNodeContents(n);getSelection().removeAllRanges();getSelection().addRange(r);});
  await page.locator('[data-tool="highlight"]').click();
  await expect(page.locator('.farast-annotation-mark.is-highlight')).toHaveCount(1);
  await expect.poll(()=>page.evaluate(()=>JSON.parse(localStorage.getItem('farast-reader:annotations:v1:annotation-test')||'[]').length)).toBe(1);
  await page.locator('[data-action="undo"]').click();
  await expect(page.locator('.farast-annotation-mark')).toHaveCount(0);
});