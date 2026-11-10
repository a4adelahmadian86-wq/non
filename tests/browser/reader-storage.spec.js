const {test,expect}=require('@playwright/test');
const path=require('path');
test('reader data survives local storage clearing through the durable IndexedDB layer',async({page})=>{
 await page.goto('/');
 await page.addScriptTag({path:path.join(__dirname,'../../public/js/farast-reader-storage.js')});
 const key='farast-reader:test-durable-record';
 await page.evaluate(async key=>{
  await window.FarastReaderDurableStore.set(key,JSON.stringify({progress:{section:2,page:7},bookmarks:[{section:2,page:4}]}));
  localStorage.setItem(key,'legacy-stale-value');
  localStorage.removeItem(key);
 },key);
 await page.reload();
 await page.addScriptTag({path:path.join(__dirname,'../../public/js/farast-reader-storage.js')});
 const restored=await page.evaluate(key=>window.FarastReaderDurableStore.restore(key),key);
 expect(JSON.parse(restored)).toEqual({progress:{section:2,page:7},bookmarks:[{section:2,page:4}]});
});
test('legacy reader local storage is migrated once into IndexedDB',async({page})=>{
 await page.goto('/');
 await page.evaluate(()=>localStorage.setItem('farast-reader:migration-test','legacy-data'));
 await page.addScriptTag({path:path.join(__dirname,'../../public/js/farast-reader-storage.js')});
 const migrated=await page.evaluate(()=>window.FarastReaderDurableStore.migrate('farast-reader:migration-test',localStorage.getItem('farast-reader:migration-test')));
 expect(migrated).toBe('legacy-data');
 await page.evaluate(()=>localStorage.removeItem('farast-reader:migration-test'));
 const restored=await page.evaluate(()=>window.FarastReaderDurableStore.restore('farast-reader:migration-test'));
 expect(restored).toBe('legacy-data');
});
test('storage persistence capability is reported without blocking reader data',async({page})=>{
 await page.goto('/');
 await page.addScriptTag({path:path.join(__dirname,'../../public/js/farast-reader-storage.js')});
 const result=await page.evaluate(()=>window.FarastReaderDurableStore.requestPersistence());
 expect(result).toHaveProperty('supported');
 expect(result).toHaveProperty('persisted');
 await page.evaluate(()=>window.FarastReaderDurableStore.set('farast-reader:after-persist-request','ok'));
 await expect.poll(()=>page.evaluate(()=>window.FarastReaderDurableStore.restore('farast-reader:after-persist-request'))).toBe('ok');
});
