(()=>{'use strict';
if(window.FarastReaderDurableStore)return;
const DB_NAME='farast-reader-device-data',DB_VERSION=1,STORE='records';
let databasePromise=null,persistPromise=null;
const invalidateDatabase=db=>{
 if(db&&databasePromise){databasePromise.then(current=>{if(current===db)databasePromise=null;}).catch(()=>{databasePromise=null;});}
 else databasePromise=null;
 try{db?.close();}catch(_){}
};
const open=()=>{
 if(!('indexedDB'in window))return Promise.reject(new Error('IndexedDB is unavailable'));
 if(databasePromise)return databasePromise;
 const pending=new Promise((resolve,reject)=>{
  const request=indexedDB.open(DB_NAME,DB_VERSION);
  request.onupgradeneeded=()=>{
   const db=request.result;
   if(!db.objectStoreNames.contains(STORE))db.createObjectStore(STORE,{keyPath:'key'});
  };
  request.onsuccess=()=>{
   const db=request.result;
   db.onversionchange=()=>invalidateDatabase(db);
   resolve(db);
  };
  request.onerror=()=>reject(request.error||new Error('IndexedDB open failed'));
  request.onblocked=()=>window.dispatchEvent(new CustomEvent('farast:storage-blocked'));
 });
 databasePromise=pending;
 pending.catch(()=>{if(databasePromise===pending)databasePromise=null;});
 return pending;
};
const transaction=(mode,work,retry=true)=>open().then(db=>{
 let tx;
 try{
  try{tx=db.transaction(STORE,mode,{durability:'strict'});}
  catch(error){
   if(error?.name!=='TypeError')throw error;
   tx=db.transaction(STORE,mode);
  }
 }catch(error){
  if(retry&&['InvalidStateError','NotFoundError'].includes(error?.name)){
   invalidateDatabase(db);
   return transaction(mode,work,false);
  }
  throw error;
 }
 return new Promise((resolve,reject)=>{
  let requestResult;
  let settled=false;
  const fail=error=>{if(settled)return;settled=true;reject(error||new Error('IndexedDB transaction failed'));};
  tx.oncomplete=()=>{
   if(settled)return;
   settled=true;
   try{resolve(requestResult&&typeof requestResult==='object'&&'result'in requestResult?(requestResult.result??null):(requestResult??null));}
   catch(error){reject(error);}
  };
  tx.onerror=()=>fail(tx.error||new Error('IndexedDB transaction failed'));
  tx.onabort=()=>fail(tx.error||new Error('IndexedDB transaction aborted'));
  try{requestResult=work(tx.objectStore(STORE),tx);}
  catch(error){try{tx.abort();}catch(_){}fail(error);}
 });
});
const emitStorageError=(type,key,error)=>{
 window.dispatchEvent(new CustomEvent(type,{detail:{key:key==null?null:String(key),name:error?.name||'StorageError',message:error?.message||'IndexedDB operation failed'}}));
};
const get=key=>transaction('readonly',store=>store.get(String(key))).catch(error=>{emitStorageError('farast:storage-read-error',key,error);throw error;});
const set=(key,value)=>transaction('readwrite',store=>store.put({key:String(key),value,updatedAt:Date.now()})).then(()=>true).catch(error=>{emitStorageError('farast:storage-write-error',key,error);throw error;});
const remove=key=>transaction('readwrite',store=>store.delete(String(key))).then(()=>true).catch(error=>{emitStorageError('farast:storage-write-error',key,error);throw error;});
const entries=()=>transaction('readonly',store=>store.getAll()).catch(error=>{emitStorageError('farast:storage-read-error',null,error);throw error;});
const prefixes=['farast-reader:annotations:v1:','farast-reader:pressure-ink:v1:','farast-reader:pressure-settings:v1:','farast-reader:studio-favorites:v1:','farast-reader:studio-recent:v1:','farast-reader:two-page:v1:','farast-reader:v1:','farast.reader.personal-tools.v1:'];
const productKey=(key,product)=>typeof key==='string'&&prefixes.some(prefix=>key===prefix+String(product));
const productRecords=async product=>(await entries()).filter(record=>productKey(record.key,product));
const exportProduct=async product=>{
 try{
  for(let i=0;i<localStorage.length;i++){
   const key=localStorage.key(i);
   if(productKey(key,product))await migrate(key,localStorage.getItem(key));
  }
 }catch(error){emitStorageError('farast:storage-backup-warning',null,error);}
 return {format:'farast-reader-local-backup',version:2,productId:String(product),createdAt:new Date().toISOString(),records:await productRecords(product)};
};
const importProduct=async(data,product)=>{
 if(!data||data.format!=='farast-reader-local-backup'||data.version!==2||data.productId!==String(product)||!Array.isArray(data.records)||data.records.length>30)throw new Error('Invalid local workspace backup');
 const seen=new Set();let bytes=0;
 for(const record of data.records){
  if(!record||!productKey(record.key,product)||record.key.length>240||typeof record.value!=='string'||seen.has(record.key))throw new Error('Invalid local workspace record');
  seen.add(record.key);bytes+=record.value.length;
  if(bytes>20*1024*1024)throw new Error('Local workspace backup is too large');
 }
 await transaction('readwrite',store=>{
  for(const record of data.records)store.put({key:record.key,value:record.value,updatedAt:Date.now()});
  return true;
 }).catch(error=>{emitStorageError('farast:storage-write-error',null,error);throw error;});
 return data.records.length;
};
const requestPersistence=()=>{
 if(persistPromise)return persistPromise;
 const pending=(async()=>{
  let persisted=false,granted=false,reason=null,estimate=null;
  try{
   if(navigator.storage?.estimate)estimate=await navigator.storage.estimate();
   if(navigator.storage?.persisted)persisted=await navigator.storage.persisted();
   if(!persisted&&navigator.storage?.persist){granted=await navigator.storage.persist();persisted=granted;}
   else if(!navigator.storage?.persist)reason='unsupported';
  }catch(error){reason=error?.name||'request-failed';}
  const result={supported:!!navigator.storage?.persist,persisted,granted,reason,usage:estimate?.usage??null,quota:estimate?.quota??null};
  if(!persisted)persistPromise=null;
  window.dispatchEvent(new CustomEvent('farast:storage-persistence-status',{detail:result}));
  if(persisted)window.dispatchEvent(new Event('farast:storage-persistence-ready'));
  return result;
 })();
 persistPromise=pending;
 return pending;
};
let persistenceRetryUsed=false;
const retryPersistence=()=>{if(persistenceRetryUsed)return;persistenceRetryUsed=true;requestPersistence().catch(()=>{});};
document.addEventListener('pointerdown',retryPersistence,{once:true,capture:true});
document.addEventListener('keydown',retryPersistence,{once:true,capture:true});
const estimate=async()=>{try{return await navigator.storage?.estimate?.()||null;}catch(_){return null;}};
const restore=async key=>{const record=await get(key);return record&&Object.prototype.hasOwnProperty.call(record,'value')?record.value:null;};
const migrate=async(key,legacyValue)=>{
 const existing=await restore(key);
 if(existing!==null)return existing;
 if(legacyValue!==null&&legacyValue!==undefined){await set(key,legacyValue);return legacyValue;}
 return null;
};
window.FarastReaderDurableStore=Object.freeze({version:3,open,get,set,remove,restore,migrate,requestPersistence,estimate,entries,productRecords,exportProduct,importProduct});
})();