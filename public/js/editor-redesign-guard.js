(()=>{
'use strict';
const ready=fn=>document.readyState==='loading'?document.addEventListener('DOMContentLoaded',fn,{once:true}):fn();
ready(()=>{
 const api=window.FarastEditorRedesign;
 const replace=(id,events)=>{
  const old=document.getElementById(id);if(!old)return null;
  const fresh=old.cloneNode(true);fresh.onclick=null;fresh.onchange=null;fresh.oninput=null;fresh.onmousedown=null;fresh.onmouseup=null;old.replaceWith(fresh);
  events.forEach(([name,fn])=>fresh.addEventListener(name,fn));return fresh;
 };
 if(api){
  const source=replace('source',[['change',e=>{const f=e.target.files?.[0];if(f)api.uploadSource(f)}]]);
  const analyze=replace('analyze',[['click',e=>{e.preventDefault();api.runPreflight()}]]);
  replace('mic',[['click',e=>{e.preventDefault();api.startVoice()}]]);
  if(source)source.setAttribute('accept','image/jpeg,image/png,image/webp,application/pdf,application/zip,.jpg,.jpeg,.png,.webp,.pdf,.zip');
  if(analyze && source?.dataset.path)analyze.disabled=false;
 }
 // The realtime voice client is the authoritative editor voice implementation.
 // Load it after the @stack('scripts') editor code has attached its legacy handlers,
 // then clone #mic so those old target listeners cannot survive.
 setTimeout(()=>{
  if(window.__farastRealtimeVoiceLoaded)return;
  const mic=document.getElementById('mic');
  if(!mic)return;
  const fresh=mic.cloneNode(true);
  mic.replaceWith(fresh);
  window.__farastRealtimeVoiceLoaded=true;
  const script=document.createElement('script');
  script.src='/js/farast-voice.js?v=20260916';
  script.async=false;
  document.head.appendChild(script);
 },0);
 // Never allow the legacy GET form of the estimate endpoint to survive.
 const fetch0=window.fetch.bind(window);
 window.fetch=(input,init={})=>{
  const url=typeof input==='string'?input:(input?.url||'');
  if(url.includes('/editor/preflight/estimate')){
   const headers=new Headers(init.headers||{});headers.set('Accept','application/json');
   if(!headers.has('X-CSRF-TOKEN'))headers.set('X-CSRF-TOKEN',document.querySelector('meta[name="csrf-token"]')?.content||'');
   init={...init,method:'POST',headers};
  }
  return fetch0(input,init);
 };
});
})();
