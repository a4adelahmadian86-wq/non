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
 expect(result).toEqual({supported:true,persisted:false,granted:false});
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
