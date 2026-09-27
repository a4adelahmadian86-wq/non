(()=>{
'use strict';
const KEY='farast.active-tab.v1';
const CHANNEL='farast-tab-guard-v1';
const HEARTBEAT=2500;
const STALE=8000;
const id=sessionStorage.getItem('farast.tab.id')||crypto.randomUUID?.()||(`${Date.now()}-${Math.random()}`);
sessionStorage.setItem('farast.tab.id',id);
let blocked=false,timer=null,bc=null;

function read(){try{return JSON.parse(localStorage.getItem(KEY)||'null')}catch{return null}}
function write(){try{localStorage.setItem(KEY,JSON.stringify({id,ts:Date.now(),url:location.href}))}catch{}}
function stale(lock){return !lock||!lock.id||Date.now()-Number(lock.ts||0)>STALE}
function own(){const lock=read();return !lock||lock.id===id||stale(lock)}
function overlay(){
  let el=document.getElementById('farastSingleTabGuard');
  if(el)return el;
  el=document.createElement('div');el.id='farastSingleTabGuard';el.innerHTML=`<div class="farast-tab-guard-card" role="alertdialog" aria-modal="true"><div class="farast-tab-guard-icon"><i class="fa-solid fa-window-restore"></i></div><strong>فراست در تب دیگری باز است</strong><p>برای جلوگیری از تداخل و از دست رفتن اطلاعات، در هر مرورگر فقط یک تب یا پنجره فراست می‌تواند فعال باشد.</p><div><button type="button" data-farast-takeover>فعال‌سازی این تب</button><button type="button" data-farast-close>بستن این تب</button></div></div>`;
  document.body.appendChild(el);
  el.querySelector('[data-farast-takeover]')?.addEventListener('click',()=>{write();bc?.postMessage({type:'takeover',id});location.reload()});
  el.querySelector('[data-farast-close]')?.addEventListener('click',()=>{window.close();el.querySelector('p').textContent='اگر مرورگر اجازه بستن خودکار نداد، این تب را دستی ببندید.'});
  return el;
}
function setBlocked(value){blocked=value;document.documentElement.classList.toggle('farast-tab-blocked',value);const el=document.getElementById('farastSingleTabGuard');if(value)overlay().hidden=false;else if(el)el.hidden=true}
function tick(){
  const lock=read();
  if(!lock||lock.id===id||stale(lock)){write();setBlocked(false);bc?.postMessage({type:'heartbeat',id});return}
  setBlocked(true);
}
try{bc=new BroadcastChannel(CHANNEL);bc.onmessage=e=>{if(e.data?.type==='takeover'&&e.data.id!==id)setBlocked(true);if(e.data?.type==='heartbeat'&&e.data.id!==id)tick()}}catch{}
window.addEventListener('storage',e=>{if(e.key===KEY)tick()});
window.addEventListener('focus',tick);
window.addEventListener('pageshow',tick);
document.addEventListener('visibilitychange',()=>{if(!document.hidden)tick()});
window.addEventListener('beforeunload',()=>{const lock=read();if(lock?.id===id){try{localStorage.removeItem(KEY)}catch{}}});

tick();timer=setInterval(()=>{if(!blocked)write();else tick()},HEARTBEAT);
})();
