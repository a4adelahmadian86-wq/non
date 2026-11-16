const {test,expect}=require('@playwright/test');
const path=require('path');
test('reader data survives clearing localStorage and reloading through IndexedDB',async({page})=>{
 await page.goto('/');
 await page.addScriptTag({path:path.join(__dirname,'../../public/js/farast-reader-storage.js')});
 const key='farast-reader:test-durable-record';
 await page.evaluate(async key=>{
  await window.FarastReaderDurableStore.set(key,JSON.stringify({progress:{section:2,page:7},bookmarks:[{section:2,page:4}]}));
  localStorage.setItem(key,'legacy-stale-value');
  const canonical=await window.FarastReaderDurableStore.migrate(key,localStorage.getItem(key));
  if(canonical!==JSON.stringify({progress:{section:2,page:7},bookmarks:[{section:2,page:4}]}))throw new Error('Legacy data replaced the durable canonical record');
  localStorage.clear();
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

test('separate reader features can migrate into the same durable device store',async({page})=>{
 await page.goto('/');
 await page.addScriptTag({path:path.join(__dirname,'../../public/js/farast-reader-storage.js')});
 const records={
  'farast-reader:annotations:v1:42':JSON.stringify([{id:'note-1',type:'note',text:'یادداشت'}]),
  'farast-reader:pressure-ink:v1:42':JSON.stringify([{id:'stroke-1',points:[{x:.1,y:.1},{x:.2,y:.2}]}]),
  'farast.reader.personal-tools.v1:42':JSON.stringify({font:'serif',size:120,notes:[{id:'n1',note:'مهم'}]}),
  'farast-reader:studio-favorites:v1:42':JSON.stringify([{id:'pen-blue',tool:'pen'}]),
  'farast-reader:v1:42':JSON.stringify({progress:{section:1,page:8},bookmarks:[{section:1,page:4}]})
 };
 await page.evaluate(async records=>{
  for(const [key,value] of Object.entries(records)){
   localStorage.setItem(key,value);
   await window.FarastReaderDurableStore.migrate(key,value);
  }
  localStorage.clear();
 },records);
 const restored=await page.evaluate(async keys=>{
  const values={};
  for(const key of keys)values[key]=await window.FarastReaderDurableStore.restore(key);
  return values;
 },Object.keys(records));
 for(const [key,value] of Object.entries(records))expect(restored[key]).toBe(value);
});


test('storage persistence denial is reported to the reader UI',async({page})=>{
 await page.addInitScript(()=>{
  Object.defineProperty(navigator,'storage',{configurable:true,value:{persisted:async()=>false,persist:async()=>false,estimate:async()=>({usage:1,quota:100})}});
  window.__persistenceStatuses=[];
  window.addEventListener('farast:storage-persistence-status',event=>window.__persistenceStatuses.push(event.detail));
 });
 await page.goto('/');
 await page.addScriptTag({path:path.join(__dirname,'../../public/js/farast-reader-storage.js')});
 const result=await page.evaluate(()=>window.FarastReaderDurableStore.requestPersistence());
 expect(result).toMatchObject({supported:true,persisted:false,granted:false,reason:null,usage:1,quota:100});
 await expect.poll(()=>page.evaluate(()=>window.__persistenceStatuses.length)).toBe(1);
});

test('IndexedDB write failures emit a visible storage warning event',async({page})=>{
 await page.addInitScript(()=>{
  Object.defineProperty(indexedDB,'open',{configurable:true,value:()=>{throw new Error('blocked by test');}});
  window.__storageWriteErrors=[];
  window.addEventListener('farast:storage-write-error',event=>window.__storageWriteErrors.push(event.detail));
 });
 await page.goto('/');
 await page.addScriptTag({path:path.join(__dirname,'../../public/js/farast-reader-storage.js')});
 const failed=await page.evaluate(async()=>{try{await window.FarastReaderDurableStore.set('farast-reader:blocked-write','value');return false;}catch(_){return true;}});
 expect(failed).toBe(true);
 await expect.poll(()=>page.evaluate(()=>window.__storageWriteErrors.length)).toBe(1);
 expect(await page.evaluate(()=>window.__storageWriteErrors[0].key)).toBe('farast-reader:blocked-write');
});

test('device records survive Cache API cleanup and local backup round-trips only this reader',async({page})=>{
 await page.goto('/');
 await page.addScriptTag({path:path.join(__dirname,'../../public/js/farast-reader-storage.js')});
 const result=await page.evaluate(async()=>{
  const store=window.FarastReaderDurableStore;
  const product='42';
  const key='farast-reader:v1:'+product;
  const annotationKey='farast-reader:annotations:v1:'+product;
  await store.set(key,JSON.stringify({progress:{section:1,page:9},bookmarks:[]}));
  await store.set(annotationKey,JSON.stringify([{id:'local-note',text:'یادداشت محلی'}]));
  await store.set('farast-reader:v1:other-product',JSON.stringify({private:'must not export'}));
  const cache=await caches.open('reader-cache-cleanup-test');
  await cache.put('/reader-cache-cleanup-test',new Response('temporary cache entry'));
  await caches.delete('reader-cache-cleanup-test');
  const afterCacheCleanup=await store.restore(key);
  const backup=await store.exportProduct(product);
  await store.remove(key);
  await store.remove(annotationKey);
  const restoredCount=await store.importProduct(backup,product);
  return {
   afterCacheCleanup,
   restored:await store.restore(key),
   annotation:await store.restore(annotationKey),
   restoredCount,
   exportedKeys:backup.records.map(record=>record.key),
   leakedOtherProduct:backup.records.some(record=>record.key.includes('other-product'))
  };
 });
 expect(JSON.parse(result.afterCacheCleanup)).toEqual({progress:{section:1,page:9},bookmarks:[]});
 expect(JSON.parse(result.restored)).toEqual({progress:{section:1,page:9},bookmarks:[]});
 expect(JSON.parse(result.annotation)).toEqual([{id:'local-note',text:'یادداشت محلی'}]);
 expect(result.restoredCount).toBe(2);
 expect(result.exportedKeys).toContain('farast-reader:v1:42');
 expect(result.exportedKeys).toContain('farast-reader:annotations:v1:42');
 expect(result.leakedOtherProduct).toBe(false);
});

test('IndexedDB read failures emit a visible storage warning event',async({page})=>{
 await page.addInitScript(()=>{
  Object.defineProperty(indexedDB,'open',{configurable:true,value:()=>{throw new Error('blocked read');}});
  window.__storageReadErrors=[];
  window.addEventListener('farast:storage-read-error',event=>window.__storageReadErrors.push(event.detail));
 });
 await page.goto('/');
 await page.addScriptTag({path:path.join(__dirname,'../../public/js/farast-reader-storage.js')});
 const failed=await page.evaluate(async()=>{try{await window.FarastReaderDurableStore.restore('farast-reader:blocked-read');return false;}catch(_){return true;}});
 expect(failed).toBe(true);
 await expect.poll(()=>page.evaluate(()=>window.__storageReadErrors.length)).toBe(1);
 expect(await page.evaluate(()=>window.__storageReadErrors[0].key)).toBe('farast-reader:blocked-read');
});


test('legacy migration cannot overwrite a newer concurrent IndexedDB write',async({page})=>{
 await page.goto('/');
 await page.addScriptTag({path:path.join(__dirname,'../../public/js/farast-reader-storage.js')});
 const result=await page.evaluate(async()=>{
  const store=window.FarastReaderDurableStore;
  const key='farast-reader:migration-race-test';
  const migration=store.migrate(key,'legacy-stale-state');
  await store.set(key,'new-user-state');
  return {migration:await migration,restored:await store.restore(key)};
 });
 expect(result.migration).toBe('legacy-stale-state');
 expect(result.restored).toBe('new-user-state');
});

test('serializes account reader-state writes so older slow requests cannot overwrite newer progress',async({page})=>{
 let releaseFirst;
 let firstStartedResolve;
 const firstStarted=new Promise(resolve=>{firstStartedResolve=resolve;});
 const firstGate=new Promise(resolve=>{releaseFirst=resolve;});
 const requests=[];
 await page.route('**/reader-state-sync-fixture',async route=>{
  if(route.request().method()==='GET'){
   await route.fulfill({status:200,contentType:'application/json',body:JSON.stringify({progress:null,bookmarks:[],updated_at:null})});
   return;
  }
  requests.push(route.request().postDataJSON());
  if(requests.length===1){
   firstStartedResolve();
   await firstGate;
  }
  await route.fulfill({status:200,contentType:'application/json',body:JSON.stringify({saved:true})});
 });
 await page.goto('/');
 await page.setContent(`
  <section class="farast-reader" data-reader-root data-product-id="remote-sync-order-fixture" data-reader-state-url="/reader-state-sync-fixture">
   <nav class="farast-reader-toolbar">
    <button id="reader-prev" type="button">قبلی</button>
    <label class="reader-page-input"><input id="reader-page-number" value="1"><span>/ 2</span></label>
    <button id="reader-next" type="button">بعدی</button>
    <output id="reader-zoom-label">۱۰۰٪</output>
    <button id="reader-resume" type="button" hidden><span>ادامه مطالعه</span></button>
    <button id="reader-bookmark-toggle" type="button" aria-pressed="false"></button>
    <button id="reader-bookmarks-open" type="button" aria-expanded="false"></button>
    <div id="reader-bookmarks-panel" hidden></div>
   </nav>
   <div class="farast-reader-progress" role="progressbar" aria-valuenow="0"><span id="reader-progress"></span></div>
   <div id="reader-stage">
    <article class="farast-reader-page-item is-active" data-index="0"><div class="farast-reader-page-content">صفحه نخست</div></article>
    <article class="farast-reader-page-item" data-index="1"><div class="farast-reader-page-content">صفحه دوم</div></article>
   </div>
  </section>
 `);
 await page.addScriptTag({path:path.join(__dirname,'../../public/js/farast-reader-storage.js')});
 await page.addScriptTag({path:path.join(__dirname,'../../public/js/farast-reader.js')});
 await expect.poll(()=>page.locator('#reader-page-number').inputValue()).toBe('1');
 await page.locator('#reader-next').click();
 await firstStarted;
 expect(requests).toHaveLength(1);
 expect(requests[0].progress).toEqual({section:1,page:1});
 await page.locator('#reader-prev').click();
 await page.waitForTimeout(850);
 // The second snapshot is queued behind the first PUT, not sent concurrently.
 expect(requests).toHaveLength(1);
 releaseFirst();
 await expect.poll(()=>requests.length).toBe(2);
 expect(requests[1].progress).toEqual({section:0,page:1});
});
