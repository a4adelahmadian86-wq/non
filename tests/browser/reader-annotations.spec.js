const { test, expect } = require('@playwright/test');
test('annotation workspace persists text highlights and supports undo',async({page})=>{
  await page.goto('/');
  await page.setContent('<section class="farast-reader" data-reader-root data-product-id="annotation-test"><nav class="farast-reader-toolbar"></nav><article class="farast-reader-page-item is-active" data-index="0"><div class="farast-pdf-document"><div class="farast-pdf-text-layer"><span>FARAST annotation sample</span></div></div></article></section>');
  await page.addStyleTag({content:'.farast-pdf-document{position:relative;width:600px;height:800px;background:#fff}.farast-pdf-text-layer{position:absolute;inset:0}.farast-annotation-layer{position:absolute;inset:0;z-index:8}.farast-annotation-interactive .farast-annotation-layer{pointer-events:auto;touch-action:none}'});
  await page.addScriptTag({path:require('path').join(__dirname,'../../public/js/farast-reader-annotations.js')});
  await expect(page.locator('.farast-annotation-toolbar')).toBeVisible();
  await expect.poll(()=>page.evaluate(()=>document.querySelector('[data-reader-root]')?.dataset.annotationsReady)).toBe('true');
  await page.locator('.farast-pdf-text-layer span').evaluate(n=>{const r=document.createRange();r.selectNodeContents(n);getSelection().removeAllRanges();getSelection().addRange(r);});
  await page.locator('[data-tool="highlight"]').click();
  await expect(page.locator('.farast-annotation-mark.is-highlight')).toHaveCount(1);
  await expect.poll(()=>page.evaluate(()=>JSON.parse(localStorage.getItem('farast-reader:annotations:v1:annotation-test')||'[]').length)).toBe(1);
  await page.locator('[data-action="undo"]').click();
  await expect(page.locator('.farast-annotation-mark')).toHaveCount(0);
  await page.locator('[data-action="redo"]').click();
  await expect(page.locator('.farast-annotation-mark.is-highlight')).toHaveCount(1);
  await page.locator('[data-tool="rectangle"]').click();
  const rect=await page.locator('.farast-pdf-document').boundingBox();
  await page.mouse.move(rect.x+40,rect.y+40);await page.mouse.down();await page.mouse.move(rect.x+180,rect.y+140);await page.mouse.up();
  await expect(page.locator('.farast-annotation-ink path')).toHaveCount(1);
  await page.locator('[data-tool="stamp"]').click();
  await page.mouse.click(rect.x+220,rect.y+180);
  await expect(page.locator('.farast-reader-text-dialog')).toBeVisible();
  await page.locator('.farast-reader-text-dialog textarea').fill('APPROVED');
  await page.locator('.farast-reader-text-dialog button[type="submit"]').click();
  await expect(page.locator('.farast-annotation-stamp')).toHaveText('APPROVED');
  const before=await page.locator('.farast-annotation-stamp').boundingBox();
  await page.locator('[data-tool="move"]').click();
  const center={x:before.x+before.width/2,y:before.y+before.height/2};await page.mouse.move(center.x,center.y);await page.mouse.down();await page.mouse.move(center.x+60,center.y+45);await page.mouse.up();
  const after=await page.locator('.farast-annotation-stamp').boundingBox();
  expect(after.x).toBeGreaterThan(before.x+20);
  await page.locator('[data-action="list"]').click();
  await expect(page.locator('.farast-annotation-list-item')).toHaveCount(3);
  await page.locator('.farast-annotation-list-item').first().getByRole('button',{name:'تکثیر حاشیه‌نویسی'}).click();
  await expect(page.locator('.farast-annotation-list-item')).toHaveCount(4);
  await page.locator('.farast-annotation-list-item').first().locator('input[type="checkbox"]').check();
  await page.getByRole('button',{name:/حذف انتخاب‌شده‌ها/}).click();
  await expect(page.locator('.farast-annotation-list-item')).toHaveCount(3);
});
test('annotation backup import rejects malformed records and confirms replacement',async({page})=>{
  await page.goto('/');
  await page.setContent('<section class="farast-reader" data-reader-root data-product-id="annotation-import-test"><nav class="farast-reader-toolbar"></nav><article class="farast-reader-page-item is-active" data-index="0"><div class="farast-pdf-document"><div class="farast-pdf-text-layer"><span>Import validation</span></div></div></article></section>');
  await page.addScriptTag({path:require('path').join(__dirname,'../../public/js/farast-reader-annotations.js')});
  const key='farast-reader:annotations:v1:annotation-import-test';
  await page.evaluate(k=>localStorage.setItem(k,JSON.stringify([{id:'existing',type:'note',page:'0:1',x:.2,y:.2,text:'Keep me'}])),key);
  await page.locator('[data-action="import"]').click();
  await page.locator('[data-file]').setInputFiles({name:'bad.json',mimeType:'application/json',buffer:Buffer.from(JSON.stringify({version:1,items:[{id:'bad',type:'ink',page:'0:1',points:[{x:9,y:0}]}]}))});
  await expect(page.locator('.farast-annotation-status')).toHaveText('فایل JSON معتبر نیست.');
  await expect.poll(()=>page.evaluate(k=>JSON.parse(localStorage.getItem(k)||'[]')[0]?.id,key)).toBe('existing');
  await page.locator('[data-action="import"]').click();
  await page.locator('[data-file]').setInputFiles({name:'valid.json',mimeType:'application/json',buffer:Buffer.from(JSON.stringify({version:1,items:[{id:'new-note',type:'note',page:'0:1',x:.3,y:.3,text:'Imported'}],pressureInk:[]}))});
  await expect(page.locator('.farast-reader-text-dialog')).toBeVisible();
  await expect(page.locator('.farast-reader-text-dialog')).toContainText('جایگزین');
  await page.locator('.farast-reader-text-dialog button[type="submit"]').click();
  await expect.poll(()=>page.evaluate(k=>JSON.parse(localStorage.getItem(k)||'[]')[0]?.id,key)).toBe('new-note');
});

test('annotations are restored from IndexedDB after localStorage is cleared',async({page})=>{
  await page.goto('/');
  await page.setContent('<section class="farast-reader" data-reader-root data-product-id="annotation-durable-test"><nav class="farast-reader-toolbar"></nav><article class="farast-reader-page-item is-active" data-index="0"><div class="farast-pdf-document"><div class="farast-pdf-text-layer"><span>Durable annotation sample</span></div></div></article></section>');
  await page.addScriptTag({path:require('path').join(__dirname,'../../public/js/farast-reader-storage.js')});
  await page.addScriptTag({path:require('path').join(__dirname,'../../public/js/farast-reader-annotations.js')});
  await expect.poll(()=>page.evaluate(()=>document.querySelector('[data-reader-root]')?.dataset.annotationsReady)).toBe('true');
  await page.locator('.farast-pdf-text-layer span').evaluate(n=>{const r=document.createRange();r.selectNodeContents(n);getSelection().removeAllRanges();getSelection().addRange(r);});
  await page.locator('[data-tool="highlight"]').click();
  const key='farast-reader:annotations:v1:annotation-durable-test';
  await expect.poll(()=>page.evaluate(async key=>{const value=await window.FarastReaderDurableStore.restore(key);return value?JSON.parse(value).length:0;},key)).toBe(1);
  await page.evaluate(async()=>{localStorage.clear();if('caches' in window){const cache=await caches.open('reader-test-cache');await cache.put('/reader-cache-probe',new Response('temporary'));await Promise.all((await caches.keys()).map(key=>caches.delete(key)));}});
  const restored=await page.evaluate(async key=>JSON.parse(await window.FarastReaderDurableStore.restore(key)),key);
  expect(restored).toHaveLength(1);
  expect(restored[0].type).toBe('highlight');
});

test('durable reader storage keeps product data isolated and restores a full workspace backup',async({page})=>{
  await page.goto('/');
  await page.addScriptTag({path:require('path').join(__dirname,'../../public/js/farast-reader-storage.js')});
  const result=await page.evaluate(async()=>{
    const store=window.FarastReaderDurableStore,product='storage-backup-test';
    const annotationKey='farast-reader:annotations:v1:'+product,favoritesKey='farast-reader:studio-favorites:v1:'+product,otherKey='farast-reader:annotations:v1:another-product';
    await store.set(annotationKey,JSON.stringify([{id:'a1',type:'note'}]));
    await store.set(favoritesKey,JSON.stringify([{id:'pen',tool:'pen'}]));
    await store.set(otherKey,'must-not-export');
    const backup=await store.exportProduct(product);
    await store.remove(annotationKey);await store.remove(favoritesKey);
    const restored=await store.importProduct(backup,product);
    return {count:restored,keys:backup.records.map(x=>x.key),annotation:await store.restore(annotationKey),favorites:await store.restore(favoritesKey),foreign:backup.records.some(x=>x.key===otherKey)};
  });
  expect(result.count).toBe(2);
  expect(result.keys).toContain('farast-reader:annotations:v1:storage-backup-test');
  expect(result.keys).toContain('farast-reader:studio-favorites:v1:storage-backup-test');
  expect(result.annotation).toContain('a1');expect(result.favorites).toContain('pen');expect(result.foreign).toBe(false);
});

test('durable backup import rejects another product and malformed records',async({page})=>{
  await page.goto('/');
  await page.addScriptTag({path:require('path').join(__dirname,'../../public/js/farast-reader-storage.js')});
  const result=await page.evaluate(async()=>{
    const store=window.FarastReaderDurableStore;
    const backup={format:'farast-reader-local-backup',version:2,productId:'reader-a',records:[{key:'farast-reader:annotations:v1:reader-b',value:'[]'}]};
    let wrongProduct=false,wrongKey=false;
    try{await store.importProduct({...backup,records:[]},'reader-b');}catch(_){wrongProduct=true;}
    try{await store.importProduct(backup,'reader-a');}catch(_){wrongKey=true;}
    return {wrongProduct,wrongKey};
  });
  expect(result.wrongProduct).toBe(true);expect(result.wrongKey).toBe(true);
});

test('reader exposes local storage durability status to the user',async({page})=>{
  await page.goto('/');
  await page.setContent('<section class="farast-reader" data-reader-root data-product-id="storage-status-test"><nav class="farast-reader-toolbar"></nav><article class="farast-reader-page-item is-active" data-index="0"><div class="farast-pdf-document"><div class="farast-pdf-text-layer"><span>Storage status</span></div></div></article></section>');
  await page.addScriptTag({path:require('path').join(__dirname,'../../public/js/farast-reader-storage.js')});
  await page.addScriptTag({path:require('path').join(__dirname,'../../public/js/farast-reader-annotations.js')});
  await expect.poll(()=>page.evaluate(()=>document.querySelector('[data-reader-root]')?.dataset.annotationsReady)).toBe('true');
  await page.locator('[data-action="storage"]').click();
  await expect.poll(()=>page.locator('.farast-annotation-status').textContent()).toContain('مگابایت');
});
