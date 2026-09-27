(()=>{
  'use strict';
  const BASE='/assets/sounds/';
  const FILES={
    info:'01-notification-info.ogg',success:'02-notification-success.ogg',warning:'03-notification-warning.ogg',error:'04-notification-error.ogg',
    uploadStart:'05-upload-start.ogg',uploadComplete:'06-upload-complete.ogg',processingStart:'07-processing-start.ogg',processingComplete:'08-processing-complete.ogg',processingError:'09-processing-error.ogg',
    aiStart:'10-ai-start.ogg',aiComplete:'11-ai-complete.ogg',aiWarning:'12-ai-warning.ogg',saveSuccess:'13-save-success.ogg',exportComplete:'14-export-complete.ogg',
    message:'15-message-received.ogg',support:'16-support-message.ogg',login:'17-login-success.ogg',logout:'18-logout.ogg',button:'19-button-soft.ogg',
    toggleOn:'20-toggle-on.ogg',toggleOff:'21-toggle-off.ogg',modalOpen:'22-modal-open.ogg',modalClose:'23-modal-close.ogg',autosave:'24-editor-autosave.ogg',
    voiceStart:'25-voice-start.ogg',voiceStop:'26-voice-stop.ogg',voiceError:'27-voice-error.ogg'
  };

  /*
   * FARAST keeps its sonic identity package available, but routine UI sounds are
   * intentionally silent. Firefox/Chromium own the tab audio indicator and a web
   * page cannot legitimately hide it while audible media is playing. Therefore
   * automatic click/upload/status sounds are disabled; the API remains available
   * only for a future explicit media preference controlled by the user.
   */
  const state={enabled:false,volume:.72,unlocked:false,last:new Map()};
  const pool=new Map();

  function audio(name){
    if(!FILES[name])return null;
    if(!pool.has(name)){
      const a=new Audio(BASE+FILES[name]);
      a.preload='none';
      a.volume=state.volume;
      pool.set(name,a);
    }
    return pool.get(name);
  }

  function unlock(){state.unlocked=true;}
  function play(name,opts={}){
    if(!state.enabled||!state.unlocked)return Promise.resolve(false);
    const now=Date.now(),cooldown=Number(opts.cooldown??120),last=state.last.get(name)||0;
    if(now-last<cooldown)return Promise.resolve(false);
    state.last.set(name,now);
    const base=audio(name);if(!base)return Promise.resolve(false);
    let a=base;
    if(!base.paused&&!base.ended)a=base.cloneNode(true);else{try{base.currentTime=0}catch{}}
    a.volume=Math.max(0,Math.min(1,state.volume*Number(opts.gain??1)));
    return a.play().then(()=>true).catch(()=>false);
  }
  function setEnabled(value){state.enabled=!!value;}
  function setVolume(value){state.volume=Math.max(0,Math.min(1,Number(value)||0));pool.forEach(a=>a.volume=state.volume);}

  window.FarastSound={play,setEnabled,setVolume,isEnabled:()=>state.enabled,unlock,files:{...FILES}};
  ['pointerdown','keydown','touchstart'].forEach(ev=>window.addEventListener(ev,unlock,{once:true,capture:true,passive:true}));
})();
