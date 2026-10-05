(()=>{
'use strict';
const ready=fn=>document.readyState==='loading'?document.addEventListener('DOMContentLoaded',fn,{once:true}):fn();
ready(()=>{
const app=document.getElementById('farastWord');if(!app)return;
/* RESTORE_MARKER_START - file too large for single API content arg in this session; see follow-up */
window.FarastEditor={state:{},execute(){return false},commands:new Map()};
console.error('[FARAST] editor-core incomplete restore - use commit 7e856a blob');
});
})();
