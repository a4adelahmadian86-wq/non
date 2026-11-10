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
 let result;const tx=db.transaction(STORE,mode),store=tx.objectStore(STORE);
 try{result=work(store);}catch(error){reject(error);return;}
 tx.oncomplete=()=>resolve(result?.result??result??null);
 tx.onerror=()=>reject(tx.error||new Error('IndexedDB transaction failed'));
 tx.onabort=()=>reject(tx.error||new Error('IndexedDB transaction aborted'));
}));
const get=key=>transaction('readonly',store=>{const request=store.get(String(key));return request;});
const set=(key,value)=>transaction('readwrite',store=>store.put({key:String(key),value,updatedAt:Date.now()})).then(()=>true);
const remove=key=>transaction('readwrite',store=>store.delete(String(key))).then(()=>true);
const requestPersistence=()=>{
 if(persistPromise)return persistPromise;
 persistPromise=(async()=>{
  let persisted=false,granted=false;
  try{if(navigator.storage?.persisted)persisted=await navigator.storage.persisted();if(!persisted&&navigator.storage?.persist){granted=await navigator.storage.persist();persisted=granted;}}catch(_){}
  return{supported:!!navigator.storage?.persist,persisted,granted};
 })();
 return persistPromise;
};
const estimate=async()=>{try{return await navigator.storage?.estimate?.()||null;}catch(_){return null;}};
const restore=async key=>{const record=await get(key);return record&&Object.prototype.hasOwnProperty.call(record,'value')?record.value:null;};
const migrate=async(key,legacyValue)=>{const existing=await restore(key);if(existing!==null)return existing;if(legacyValue!==null&&legacyValue!==undefined){await set(key,legacyValue);return legacyValue;}return null;};
window.FarastReaderDurableStore=Object.freeze({version:1,open,get,set,remove,restore,migrate,requestPersistence,estimate});
})();