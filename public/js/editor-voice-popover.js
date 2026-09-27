(()=>{
  'use strict';
  /* The editor layout loads farast-voice.js directly. This file remains as a compatibility hook only. */
  if(window.__farastVoicePopoverHookInstalled)return;
  window.__farastVoicePopoverHookInstalled=true;
  const verify=()=>{
    if(document.getElementById('farastVoicePanel'))return;
    const mic=document.getElementById('mic');
    if(!mic)console.warn('[FARAST VOICE] Microphone button was not found yet.');
  };
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',verify,{once:true});
  else verify();
})();
