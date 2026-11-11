(()=>{'use strict';
if(window.FarastReaderDurableStore)return;
const DB_NAME='farast-reader-device-data',DB_VERSION=1,STORE='records';
let databasePromise=null,persistPromise=null;
const open=()=>{
 if(!('indexedDB'in window))return Promise.reject(new Error('IndexedDB is unavailable'));
 if(databasePromise)return databasePromise;
 databasePromise=new Promise((resolve,reject)=>{
  const request=indexedDB.open(DB_NAME,DB_VERSION);
  request.onupgradeneeded=()=>{const db=request.result;if(!db.objectStoreNames.contains(STORE))db.createObjectStore(STORE,{keyPath:'key'});};
  request.onsuccess=()=>{const db=request.result;db.onversionchange=()=>db.close();resolve(db);};
  request.onerror=()=>reject(request.error||new Error('IndexedDB open failed'));
  request.onblocked=()=>reject(new Error('IndexedDB upgrade is blocked'));
 });
 databasePromise.catch(()=>{databasePromise=null;});
 return databasePromise;
};
const transaction=(mode,work)=>open().then(db=>new Promise((resolve,reject)=>{
 let result;let tx;try{tx=db.transaction(STORE,mode,{durability:'strict'});}catch(_){tx=db.transaction(STORE,mode);}const store=tx.objectStore(STORE);
 try{result=work(store);}catch(error){reject(error);return;}
 tx.oncomplete=()=>resolve(result&&typeof result==='object'&&'result'in result?(result.result??null):(result??null));
 tx.onerror=()=>reject(tx.error||new Error('IndexedDB transaction failed'));
 tx.onabort=()=>reject(tx.error||new Error('IndexedDB transaction aborted'));
}));
const get=key=>transaction('readonly',store=>{const request=store.get(String(key));return request;});
const set=(key,value)=>transaction('readwrite',store=>store.put({key:String(key),value,updatedAt:Date.now()})).then(()=>true).catch(error=>{window.dispatchEvent(new CustomEvent('farast:storage-write-error',{detail:{key:String(key),message:error?.message||'IndexedDB write failed'}}));throw error;});
const remove=key=>transaction('readwrite',store=>store.delete(String(key))).then(()=>true);
const entries=()=>transaction('readonly',store=>store.getAll());
const productRecords=async product=>{const suffix=':'+String(product);return (await entries()).filter(record=>typeof record.key==='string'&&record.key.endsWith(suffix));};
const exportProduct=async product=>{const suffix=':'+String(product);try{for(let i=0;i<localStorage.length;i++){const key=localStorage.key(i);if(key&&key.endsWith(suffix))await migrate(key,localStorage.getItem(key));}}catch(_){}return {format:'farast-reader-local-backup',version:2,productId:String(product),createdAt:new Date().toISOString(),records:await productRecords(product)};};
const importProduct=async(data,product)=>{if(!data||data.format!=='farast-reader-local-backup'||data.version!==2||data.productId!==String(product)||!Array.isArray(data.records)||data.records.length>30)throw new Error('Invalid local workspace backup');const suffix=':'+String(product);const seen=new Set();let bytes=0;for(const record of data.records){if(!record||typeof record.key!=='string'||!record.key.endsWith(suffix)||record.key.length>240||typeof record.value!=='string'||seen.has(record.key))throw new Error('Invalid local workspace record');seen.add(record.key);bytes+=record.value.length;if(bytes>20*1024*1024)throw new Error('Local workspace backup is too large');}await transaction('readwrite',store=>{for(const record of data.records)store.put({key:record.key,value:record.value,updatedAt:Date.now()});return true;});return data.records.length;};
const requestPersistence=()=>{
 if(persistPromise)return persistPromise;
 persistPromise=(async()=>{
  let persisted=false,granted=false;
  try{if(navigator.storage?.persisted)persisted=await navigator.storage.persisted();if(!persisted&&navigator.storage?.persist){granted=await navigator.storage.persist();persisted=granted;}}catch(_){}
  const result={supported:!!navigator.storage?.persist,persisted,granted};
  if(!persisted)persistPromise=null;
  window.dispatchEvent(new CustomEvent('farast:storage-persistence-status',{detail:result}));
  if(persisted)window.dispatchEvent(new Event('farast:storage-persistence-ready'));
  return result;
 })();
 return persistPromise;
};
let persistenceRetryUsed=false;
const retryPersistence=()=>{if(persistenceRetryUsed)return;persistenceRetryUsed=true;requestPersistence().catch(()=>{});};
document.addEventListener('pointerdown',retryPersistence,{once:true,capture:true});
document.addEventListener('keydown',retryPersistence,{once:true,capture:true});
const estimate=async()=>{try{return await navigator.storage?.estimate?.()||null;}catch(_){return null;}};
const restore=async key=>{const record=await get(key);return record&&Object.prototype.hasOwnProperty.call(record,'value')?record.value:null;};
const migrate=async(key,legacyValue)=>{const existing=await restore(key);if(existing!==null)return existing;if(legacyValue!==null&&legacyValue!==undefined){await set(key,legacyValue);return legacyValue;}return null;};
window.FarastReaderDurableStore=Object.freeze({version:2,open,get,set,remove,restore,migrate,requestPersistence,estimate,entries,productRecords,exportProduct,importProduct});
})();