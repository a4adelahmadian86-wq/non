/* FARAST conversational companion. Authenticated requests use the server AI pipeline; speech is progressive-enhancement. */
(function () {
  'use strict';
  if (document.getElementById('farast-global-assistant')) return;
  var root = document.createElement('div');
  root.id = 'farast-global-assistant';
  root.innerHTML =
    '<button class="farast-assistant-launcher" type="button" aria-label="شروع گفت‌وگوی صوتی با فراست" aria-expanded="false" title="گفت‌وگوی صوتی فراست"><span class="fa-solid fa-microphone-lines" aria-hidden="true"></span><span class="farast-assistant-pulse" aria-hidden="true"></span></button>' +
    '<section class="farast-assistant-panel farast-assistant-voice-panel" aria-label="گفت‌وگوی صوتی فراست" aria-modal="false" hidden>' +
    '<header class="farast-assistant-head"><div class="farast-assistant-avatar"><i class="fa-solid fa-wave-square" aria-hidden="true"></i></div><div class="farast-assistant-brand"><strong>همراه صوتی فراست</strong><small><span class="farast-assistant-online"></span> گفت‌وگوی صوتی</small></div><button class="farast-assistant-close" type="button" aria-label="بستن">×</button></header>' +
    '<div class="farast-voice-stage"><div class="farast-voice-orb" aria-hidden="true"><span></span><span></span><span></span></div><strong class="farast-voice-title" data-fa-voice-title>آمادهٔ گفت‌وگو هستم</strong><p class="farast-voice-caption" data-fa-voice-caption>برای شروع، میکروفون را لمس کن و صحبت کن.</p><div class="farast-assistant-status" data-fa-status role="status" aria-live="polite">برای گفت‌وگو وارد حساب کاربری‌ات شو.</div><button class="farast-assistant-mic farast-voice-mic" type="button" aria-label="شروع صحبت" title="برای شروع یا توقف صحبت لمس کن"><i class="fa-solid fa-microphone" aria-hidden="true"></i><span>صحبت کن</span></button><button class="farast-voice-stop" type="button" data-fa-stop-speech>توقف پخش صدا</button></div>' +
    '<div class="farast-assistant-messages" data-fa-messages hidden aria-live="polite" aria-relevant="additions"></div>' +
    '<div class="farast-assistant-intro" hidden><b>همراه صوتی فراست</b><p>گفت‌وگوی صوتی</p></div>' +
    '<div class="farast-assistant-suggestions" hidden><button type="button" data-fa-tour>راهنمای سایت</button><button type="button" data-fa-text="برای خرید فایل راهنمایی‌ام کن">خرید فایل</button></div>' +
    '<form class="farast-assistant-form" autocomplete="off" hidden><button class="farast-assistant-mic" type="button" aria-label="شروع گفتار"><i class="fa-solid fa-microphone"></i></button><input name="message" maxlength="1200" aria-label="پیام شما"><button class="farast-assistant-send" type="submit" aria-label="ارسال پیام"><i class="fa-solid fa-arrow-up"></i></button></form>' +
    '<div hidden><button class="farast-assistant-memory" type="button" data-fa-memory>حافظه +</button><button class="farast-assistant-memory-manage" type="button" data-fa-memory-manage>مدیریت حافظه</button><button class="farast-assistant-clear" type="button" aria-label="پاک کردن سابقه">پاک کردن سابقه</button></div>' +
    '<footer class="farast-assistant-foot">گفت‌وگوی صوتی؛ برای استفاده باید وارد حساب کاربری باشی.</footer></section>';  document.body.appendChild(root);
  var panel=root.querySelector('.farast-assistant-panel'), launcher=root.querySelector('.farast-assistant-launcher'),
      list=root.querySelector('[data-fa-messages]'), form=root.querySelector('form'), input=form.querySelector('input'),
      status=root.querySelector('[data-fa-status]'), busy=false, recognition=null, history=[], historyLoaded=false, assistantTurn=0;
  var csrf=document.querySelector('meta[name="csrf-token"]');
  function esc(s){return String(s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];});}
  var voiceTitle=root.querySelector('[data-fa-voice-title]'), voiceCaption=root.querySelector('[data-fa-voice-caption]');
  function message(role,text){var el=document.createElement('div');el.className='farast-assistant-msg '+role;el.textContent=String(text||'');list.appendChild(el);if(role==='bot')voiceCaption.textContent='پاسخ صوتی در حال آماده‌شدن است.';return el;}
  function setOpen(v){panel.hidden=!v;panel.classList.toggle('is-open',v);launcher.setAttribute('aria-expanded',v?'true':'false');if(v){if(document.body.dataset.assistantAuth==='1'){voiceTitle.textContent='آمادهٔ گفت‌وگو هستم';status.textContent='صحبت کن؛ برای مکث یا پایان، دکمهٔ فراست را دوباره بزن.';}else{voiceTitle.textContent='ابتدا وارد حساب شو';voiceCaption.textContent='برای استفاده از همراه صوتی، ورود به حساب کاربری لازم است.';status.textContent='ورود برای استفاده از همراه هوشمند الزامی است.';}}}
  var tourState={steps:[{href:'/store',title:'فروشگاه فایل',body:'برای دیدن فایل‌ها و محصولات دیجیتال، از این بخش وارد فروشگاه شو.'},{href:'/editor',title:'تایپ و ویرایش',body:'از این بخش می‌توانی وارد فضای تایپ و ویرایش اسناد شوی.'},{href:'/pricing',title:'قیمت خدمات',body:'پیش از استفاده، تعرفه‌ها و شرایط خدمات را اینجا بررسی کن.'},{href:'/dashboard',title:'فضای کاربری',body:'مدیریت اسناد و دسترسی‌های حساب کاربری از این بخش انجام می‌شود.'},{href:'/support',title:'پشتیبانی',body:'اگر به راهنمایی بیشتری نیاز داشتی، مسیر پشتیبانی را باز کن.'}],index:0,element:null,card:null};
  function closeTour(){
    if(tourState.element)tourState.element.classList.remove('farast-tour-highlight');
    if(tourState.card)tourState.card.remove();
    tourState.element=null;tourState.card=null;
  }
  function renderTour(){
    closeTour();
    while(tourState.index<tourState.steps.length){
      var step=tourState.steps[tourState.index],target=document.querySelector('a[href="'+step.href+'"]');
      if(target)break;tourState.index++;
    }
    if(tourState.index>=tourState.steps.length){status.textContent='راهنمای سایت به پایان رسید.';return;}
    var current=tourState.steps[tourState.index];
    tourState.element=document.querySelector('a[href="'+current.href+'"]');
    tourState.element.classList.add('farast-tour-highlight');
    try{tourState.element.scrollIntoView({behavior:window.matchMedia('(prefers-reduced-motion: reduce)').matches?'auto':'smooth',block:'center'});}catch(e){}
    var card=document.createElement('div');card.className='farast-tour-card';card.setAttribute('role','dialog');card.setAttribute('aria-live','polite');
    var title=document.createElement('strong');title.textContent=current.title;
    var body=document.createElement('p');body.textContent=current.body;
    var actions=document.createElement('div');actions.className='farast-tour-actions';
    var close=document.createElement('button');close.type='button';close.className='farast-tour-close';close.textContent='پایان راهنما';close.addEventListener('click',closeTour);
    var next=document.createElement('button');next.type='button';next.className='farast-tour-next';next.textContent=tourState.index===tourState.steps.length-1?'پایان':'مرحله بعد';
    next.addEventListener('click',function(){tourState.index++;if(tourState.index>=tourState.steps.length){closeTour();status.textContent='راهنمای سایت به پایان رسید.';}else renderTour();});
    var count=document.createElement('span');count.className='farast-tour-count';count.textContent=(tourState.index+1)+' از '+tourState.steps.length;
    actions.append(close,next,count);card.append(title,body,actions);document.body.appendChild(card);tourState.card=card;
    status.textContent='راهنمای تعاملی فعال است؛ مورد مشخص‌شده را دنبال کن.';
  }
  function startTour(){tourState.index=0;renderTour();}
  async function loadHistory(){
    if(historyLoaded||document.body.dataset.assistantAuth!=='1')return;
    historyLoaded=true;
    try{
      var response=await fetch('/assistant/history',{credentials:'same-origin',headers:{'Accept':'application/json'}});
      if(!response.ok)return;
      var data=await response.json();
      if(!Array.isArray(data.messages)||!data.messages.length)return;
      list.innerHTML='';
      data.messages.forEach(function(m){if((m.role==='user'||m.role==='assistant')&&typeof m.text==='string'){message(m.role==='user'?'user':'bot',m.text);history.push({role:m.role==='assistant'?'assistant':'user',text:m.text});}});
      if(history.length>10)history=history.slice(-10);
      status.textContent='سابقه گفت‌وگو از نشست‌های قبلی بازیابی شد.';
    }catch(e){historyLoaded=false;}
  }
  async function loadMemories(){
    if(document.body.dataset.assistantAuth!=='1')return [];
    try{
      var response=await fetch('/assistant/memories',{credentials:'same-origin',headers:{'Accept':'application/json'}});
      if(!response.ok)return [];
      var data=await response.json();
      return Array.isArray(data.memories)?data.memories:[];
    }catch(e){return [];}
  }
  async function saveMemory(){
    if(document.body.dataset.assistantAuth!=='1'){status.textContent='برای ذخیره حافظه ابتدا وارد حساب کاربری شو.';return;}
    var content=window.prompt('چه نکته‌ای را می‌خواهی برای گفتگوهای بعدی به خاطر بسپارم؟\nمثلاً: پاسخ‌ها را کوتاه و مرحله‌به‌مرحله بده.');
    if(content===null)return;
    content=content.trim();
    if(content.length<2){status.textContent='متن حافظه خیلی کوتاه است.';return;}
    try{
      var response=await fetch('/assistant/memories',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':csrf?csrf.content:''},body:JSON.stringify({content:content})});
      var data=await response.json().catch(function(){return {};});
      if(!response.ok)throw new Error(data.message||'ذخیره حافظه ناموفق بود.');
      status.textContent=data.message||'نکته برای گفتگوهای بعدی ذخیره شد.';
    }catch(e){status.textContent=e.message||'ذخیره حافظه انجام نشد.';}
  }
  async function manageMemories(){
    if(document.body.dataset.assistantAuth!=='1'){status.textContent='برای مدیریت حافظه ابتدا وارد حساب کاربری شو.';return;}
    var memories=await loadMemories();
    if(!memories.length){status.textContent='هنوز نکته شخصی ذخیره‌شده‌ای وجود ندارد. از «حافظه +» استفاده کن.';return;}
    var summary=memories.map(function(m,i){return (i+1)+'. '+m.content;}).join('\n');
    if(!window.confirm('حافظه شخصی ذخیره‌شده:\n\n'+summary+'\n\nبرای پاک‌کردن همه موارد، تأیید کن. برای نگه‌داشتن آن‌ها «لغو» را بزن.'))return;
    try{
      var response=await fetch('/assistant/memories',{method:'DELETE',credentials:'same-origin',headers:{'Accept':'application/json','X-CSRF-TOKEN':csrf?csrf.content:''}});
      var data=await response.json().catch(function(){return {};});
      if(!response.ok)throw new Error(data.message||'پاک‌کردن حافظه ناموفق بود.');
      status.textContent=data.message||'حافظه شخصی پاک شد.';
    }catch(e){status.textContent=e.message||'پاک‌کردن حافظه انجام نشد.';}
  }
  async function clearHistory(){
    if(document.body.dataset.assistantAuth!=='1')return;
    if(!window.confirm('سابقه گفت‌وگوی ذخیره‌شده در حساب کاربری پاک شود؟'))return;
    try{
      var response=await fetch('/assistant/history',{method:'DELETE',credentials:'same-origin',headers:{'Accept':'application/json','X-CSRF-TOKEN':csrf?csrf.content:''}});
      if(!response.ok)throw new Error();
      list.innerHTML='';history=[];historyLoaded=true;
      message('bot','سابقه پاک شد. از اینجا می‌توانیم گفت‌وگوی تازه‌ای شروع کنیم.');
      status.textContent='سابقه ذخیره‌شده پاک شد.';
    }catch(e){status.textContent='پاک کردن سابقه انجام نشد؛ دوباره تلاش کن.';}
  }
  var assistantAudio=null, assistantAudioUrl=null, conversationActive=false, resumeListeningTimer=null, chatAbortController=null, activePendingMessage=null;
  function stopConversation(){conversationActive=false;assistantTurn++;busy=false;if(chatAbortController){chatAbortController.abort();chatAbortController=null;}stopLiveRecognition();if(resumeListeningTimer){window.clearTimeout(resumeListeningTimer);resumeListeningTimer=null;}if(mediaRecorder&&mediaRecorder.state==='recording'){discardCurrentRecording=true;try{mediaRecorder.stop();}catch(e){}}stopMediaTracks();panel.classList.remove('is-listening','is-speaking');root.querySelector('.farast-assistant-mic').classList.remove('is-recording');}
  function scheduleNextListening(){if(!conversationActive||panel.hidden||document.body.dataset.assistantAuth!=='1')return;if(resumeListeningTimer)window.clearTimeout(resumeListeningTimer);resumeListeningTimer=window.setTimeout(function(){resumeListeningTimer=null;if(conversationActive&&!panel.hidden)startLiveRecognition();},450);}
  async function say(text){
    var speechTurn=assistantTurn;
    if(document.body.dataset.assistantAuth!=='1'){status.textContent='برای پخش پاسخ صوتی ابتدا وارد حساب شو.';return;}
    try{
      if(assistantAudio){assistantAudio.pause();assistantAudio=null;}
      if(assistantAudioUrl){URL.revokeObjectURL(assistantAudioUrl);assistantAudioUrl=null;}
      var response=await fetch('/assistant/speak',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','Accept':'audio/*, application/json','X-CSRF-TOKEN':csrf?csrf.content:''},body:JSON.stringify({text:String(text||'').slice(0,6000)})});
      if(speechTurn!==assistantTurn)return;
      if(!response.ok){var err=await response.json().catch(function(){return {};});throw new Error(err.message||'موتور صدای ثابت در دسترس نیست.');}
      var blob=await response.blob();
      if(speechTurn!==assistantTurn)return;
      if(!blob.size||(!/^audio\//i.test(blob.type)&&blob.type!=='application/octet-stream'))throw new Error('موتور صدا فایل صوتی معتبر برنگرداند.');
      assistantAudioUrl=URL.createObjectURL(blob);assistantAudio=new Audio();assistantAudio.preload='auto';assistantAudio.src=assistantAudioUrl;
      assistantAudio.onplay=function(){panel.classList.add('is-speaking');voiceTitle.textContent='در حال صحبت';voiceCaption.textContent='پاسخ با صدای ثابت فراست در حال پخش است.';status.textContent='برای قطع صدا، «توقف پخش صدا» را لمس کن.';};
      assistantAudio.onended=function(){panel.classList.remove('is-speaking');voiceTitle.textContent=conversationActive?'نوبت توست':'آمادهٔ گفت‌وگو هستم';voiceCaption.textContent=conversationActive?'بعد از پاسخ، دوباره گوش می‌دهم؛ صحبتت را شروع کن.':'هر وقت آماده بودی، دوباره صحبت کن.';status.textContent=conversationActive?'برای ادامهٔ مکالمه دوباره گوش می‌دهم…':'آماده‌ام؛ دوباره صحبت کن.';scheduleNextListening();};
      assistantAudio.onerror=function(){panel.classList.remove('is-speaking');voiceTitle.textContent='پخش صدا ناموفق بود';voiceCaption.textContent='مرورگر نتوانست فایل صوتی را رمزگشایی کند.';status.textContent=voiceCaption.textContent;scheduleNextListening();};
      await new Promise(function(resolve,reject){var settled=false;function done(error){if(settled)return;settled=true;assistantAudio.removeEventListener('canplay',ready);assistantAudio.removeEventListener('error',failed);if(error)reject(error);else resolve();}function ready(){done();}function failed(){done(new Error('فایل دریافت شد، اما مرورگر نمی‌تواند آن را به صدای قابل پخش تبدیل کند.'));}assistantAudio.addEventListener('canplay',ready,{once:true});assistantAudio.addEventListener('error',failed,{once:true});assistantAudio.load();window.setTimeout(function(){if(!settled)done(new Error('بارگذاری صدای فارسی بیش از حد طول کشید؛ دوباره تلاش کن.'));},130000);});
      if(speechTurn!==assistantTurn)return;
      if(!assistantAudio.duration||!isFinite(assistantAudio.duration)||assistantAudio.duration<=0)throw new Error('فایل صوتی دریافت شد، اما مدت‌زمان قابل پخش ندارد.');
      await assistantAudio.play();
    }catch(e){if(speechTurn!==assistantTurn)return;panel.classList.remove('is-speaking');enableTextFallback(e.message||'صدای ثابت فراست فعلاً در دسترس نیست؛ پاسخ را می‌توانی به‌صورت متنی بخوانی.');scheduleNextListening();}
  }
    async function send(text){
    text=String(text||'').trim();if(!text||busy)return;
    if(document.body.dataset.assistantAuth !== '1'){
      voiceTitle.textContent='ابتدا وارد حساب شو';voiceCaption.textContent='برای استفاده از همراه صوتی، ورود به حساب کاربری لازم است.';status.textContent='ورود برای استفاده از همراه هوشمند الزامی است.';
      var link=root.querySelector('.farast-voice-login');if(!link){link=document.createElement('a');link.href='/login?redirect='+encodeURIComponent(location.pathname+location.search);link.className='farast-voice-login';link.textContent='ورود به حساب کاربری';root.querySelector('.farast-voice-stage').appendChild(link);}return;
    }
    input.value='';busy=true;var turn=++assistantTurn;var requestController=new AbortController();chatAbortController=requestController;voiceTitle.textContent='در حال پاسخ‌گویی';status.textContent='دارم به حرفت فکر می‌کنم…';var pending=message('bot','در حال آماده‌کردن پاسخ صوتی…');activePendingMessage=pending;panel.classList.add('is-speaking');
    try{
      var prompt='تو همراه هوشمند وب‌سایت فارسی فراست هستی. به فارسی روان، صمیمی، دقیق و مختصر پاسخ بده. درباره خدمات سایت راهنمایی عملی بده؛ اگر مطمئن نیستی ادعا نکن. مسیرهای شناخته‌شده: فروشگاه /store، سبد /cart، خدمات تایپ /editor، قیمت‌ها /pricing، پشتیبانی /support، فضای کاربر /dashboard. مکالمه اخیر:\n'+history.slice(-8).map(function(m){return (m.role==='user'?'کاربر':'همراه')+': '+m.text;}).join('\n')+'\nکاربر: '+text;
      var response=await fetch('/assistant/chat',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':csrf?csrf.content:''},body:JSON.stringify({message:text,context:history.slice(-8),prompt:prompt}),signal:requestController.signal});
      var data=await response.json().catch(function(){return {};});
      if(turn!==assistantTurn)return;
      if(response.status===401||response.status===419){var loginMessage='نشست شما منقضی شده است. لطفاً دوباره وارد حساب کاربری شوید.';pending.textContent=loginMessage;voiceTitle.textContent='ورود دوباره لازم است';voiceCaption.textContent=loginMessage;status.textContent='ورود مجدد لازم است.';say(loginMessage);}
      else if(!response.ok){throw new Error(data.message||'سرویس گفت‌وگو فعلاً آماده نیست.');}
      else {var answer=String(data.reply||data.text||data.message||'پاسخی دریافت نشد.');pending.textContent=answer;history.push({role:'user',text:text},{role:'assistant',text:answer});if(history.length>20)history=history.slice(-20);voiceTitle.textContent='پاسخ فراست';voiceCaption.textContent='در حال پخش پاسخ صوتی…';status.textContent='در حال پخش پاسخ صوتی…';say(answer);}
    }catch(e){if(turn!==assistantTurn)return;var spokenError='متأسفم، در حال حاضر نمی‌توانم پاسخ را از سرویس هوش مصنوعی دریافت کنم. لطفاً بعداً دوباره تلاش کن.';pending.textContent=spokenError;voiceTitle.textContent='پاسخ‌گویی موقتاً در دسترس نیست';voiceCaption.textContent=spokenError;status.textContent='اتصال به سرویس پاسخ‌گویی ناموفق بود.';say(spokenError);}
    finally{if(turn===assistantTurn){busy=false;if(chatAbortController===requestController)chatAbortController=null;if(activePendingMessage===pending)activePendingMessage=null;}}
  }
  launcher.addEventListener('click',function(){var opening=panel.hidden;if(!opening){stopConversation();if(assistantAudio){assistantAudio.pause();assistantAudio=null;}if(assistantAudioUrl){URL.revokeObjectURL(assistantAudioUrl);assistantAudioUrl=null;}setOpen(false);return;}setOpen(true);loadHistory();if(document.body.dataset.assistantAuth==='1'){conversationActive=true;liveTurnEnding=false;voiceTitle.textContent='در حال اتصال به میکروفون';voiceCaption.textContent='فقط صحبت کن؛ بعد از مکث کوتاه پاسخ می‌دهم.';status.textContent='در حال آماده‌سازی گفت‌وگوی صوتی…';startLiveRecognition();}});
  root.querySelector('.farast-assistant-close').addEventListener('click',function(){stopConversation();if(assistantAudio){assistantAudio.pause();assistantAudio=null;}if(assistantAudioUrl){URL.revokeObjectURL(assistantAudioUrl);assistantAudioUrl=null;}setOpen(false);});
  root.querySelector('[data-fa-stop-speech]').addEventListener('click',function(){stopConversation();if(assistantAudio){assistantAudio.pause();assistantAudio.currentTime=0;assistantAudio=null;}if(assistantAudioUrl){URL.revokeObjectURL(assistantAudioUrl);assistantAudioUrl=null;}panel.classList.remove('is-speaking');voiceTitle.textContent='مکالمه متوقف شد';status.textContent='گفت‌وگوی پیوسته متوقف شد.';voiceCaption.textContent='برای شروع دوباره، میکروفون را لمس کن.';});
  root.querySelector('.farast-assistant-clear').addEventListener('click',clearHistory);
  root.querySelector('[data-fa-memory]').addEventListener('click',saveMemory);
  root.querySelector('[data-fa-memory-manage]').addEventListener('click',manageMemories);
  form.addEventListener('submit',function(e){e.preventDefault();send(input.value);});
  root.querySelectorAll('[data-fa-text]').forEach(function(b){b.addEventListener('click',function(){send(b.getAttribute('data-fa-text'));});});
  root.querySelector('[data-fa-tour]').addEventListener('click',startTour);
  var mediaRecorder=null, mediaStream=null, recordedChunks=[], recordingTimeout=null, discardCurrentRecording=false;
  var liveRecognition=null, liveFinalText='', liveSilenceTimer=null, liveStarting=false, liveTurnEnding=false;
  var gatewayWs=null,gatewayMediaStream=null,gatewayAudioContext=null,gatewaySourceNode=null,gatewayWorkletNode=null,gatewayScriptProcessorNode=null,gatewayPcmBuffer=[],gatewayStarting=false,gatewayFinalText='',gatewaySilenceTimer=null,gatewayLastVoiceAt=0,gatewayGeneration=0;
  var LiveSpeechRecognition=window.SpeechRecognition||window.webkitSpeechRecognition;
  function stopGatewayRecognition(){
    gatewayGeneration++;gatewayStarting=false;
    if(gatewaySilenceTimer){window.clearTimeout(gatewaySilenceTimer);gatewaySilenceTimer=null;}
    if(gatewayWorkletNode){try{gatewayWorkletNode.disconnect();}catch(e){}gatewayWorkletNode=null;}
    if(gatewayScriptProcessorNode){try{gatewayScriptProcessorNode.disconnect();gatewayScriptProcessorNode.onaudioprocess=null;}catch(e){}gatewayScriptProcessorNode=null;}
    gatewayPcmBuffer=[];
    if(gatewaySourceNode){try{gatewaySourceNode.disconnect();}catch(e){}gatewaySourceNode=null;}
    if(gatewayAudioContext){try{gatewayAudioContext.close();}catch(e){}gatewayAudioContext=null;}
    if(gatewayMediaStream){gatewayMediaStream.getTracks().forEach(function(track){track.stop();});gatewayMediaStream=null;}
    var socket=gatewayWs;gatewayWs=null;
    if(socket){try{socket.onopen=socket.onmessage=socket.onerror=socket.onclose=null;if(socket.readyState===WebSocket.OPEN)socket.send(JSON.stringify({type:'stop'}));socket.close(1000,'assistant_turn_end');}catch(e){}}
    gatewayFinalText='';gatewayLastVoiceAt=0;
  }
  function scheduleGatewayTurnEnd(generation){if(generation!==gatewayGeneration||!gatewayFinalText.trim()||!gatewayLastVoiceAt)return;if(gatewaySilenceTimer)window.clearTimeout(gatewaySilenceTimer);var remaining=Math.max(0,1400-(Date.now()-gatewayLastVoiceAt));gatewaySilenceTimer=window.setTimeout(function(){finishGatewayTurn(generation);},remaining);}
  function noteGatewayAudioActivity(generation,pcm){if(generation!==gatewayGeneration||!pcm)return;var samples=pcm instanceof Int16Array?pcm:new Int16Array(pcm);if(!samples.length)return;var total=0;for(var i=0;i<samples.length;i++)total+=Math.abs(samples[i]);if(total/samples.length<420)return;gatewayLastVoiceAt=Date.now();scheduleGatewayTurnEnd(generation);}
  async function startGatewayLiveRecognition(){
    if(gatewayStarting||gatewayWs)return true;
    var AudioContextClass=window.AudioContext||window.webkitAudioContext;
    if(!window.WebSocket||!AudioContextClass||(!window.AudioWorkletNode&&!(AudioContextClass.prototype&&AudioContextClass.prototype.createScriptProcessor))||!navigator.mediaDevices||!navigator.mediaDevices.getUserMedia)return false;
    gatewayStarting=true;var generation=++gatewayGeneration;
    try{
      // Ask for microphone access immediately from the user's tap, before any network waits.
      gatewayMediaStream=await navigator.mediaDevices.getUserMedia({audio:{channelCount:1,echoCancellation:true,noiseSuppression:true,autoGainControl:true},video:false});
      if(generation!==gatewayGeneration||!conversationActive||panel.hidden){stopGatewayRecognition();return true;}
      var statusResponse=await fetch('/editor/voice/status',{credentials:'same-origin',headers:{'Accept':'application/json'},cache:'no-store'});
      var service=await statusResponse.json().catch(function(){return {};});
      if(!statusResponse.ok||service.mode!=='gateway'){stopGatewayRecognition();return false;}
      var tokenResponse=await fetch('/assistant/voice/stream-token',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':csrf?csrf.content:''},body:JSON.stringify({locale:'fa-IR'})});
      var tokenData=await tokenResponse.json().catch(function(){return {};});
      if(!tokenResponse.ok||!tokenData.token||!tokenData.websocket_url)throw new Error(tokenData.message||'درگاه تشخیص زنده آماده نیست.');
      if(generation!==gatewayGeneration||!conversationActive||panel.hidden){gatewayStarting=false;return true;}
      var wsUrl=tokenData.websocket_url.replace(/^http:/,'ws:').replace(/^https:/,'wss:');
      var socket=new WebSocket(wsUrl);gatewayWs=socket;socket.binaryType='arraybuffer';
      await new Promise(function(resolve,reject){
        var settled=false;var timer=window.setTimeout(function(){finish(new Error('مهلت اتصال درگاه صوتی تمام شد.'));},12000);
        function finish(error){if(settled)return;settled=true;window.clearTimeout(timer);socket.removeEventListener('open',opened);socket.removeEventListener('error',failed);if(error)reject(error);else resolve();}
        function opened(){finish();}
        function failed(){finish(new Error('اتصال درگاه گفتار برقرار نشد.'));}
        socket.addEventListener('open',opened,{once:true});socket.addEventListener('error',failed,{once:true});
      });
      if(generation!==gatewayGeneration||!conversationActive||panel.hidden){stopGatewayRecognition();return true;}
      socket.onmessage=function(event){
        if(generation!==gatewayGeneration||typeof event.data!=='string')return;var data;try{data=JSON.parse(event.data);}catch(e){return;}
        if(data.type==='ready'){voiceTitle.textContent='گوش می‌دهم';voiceCaption.textContent='گفتار زنده از مسیر سرور پردازش می‌شود؛ صحبت کن.';status.textContent='گفت‌وگوی زنده فعال است.';panel.classList.add('is-listening');root.querySelector('.farast-assistant-mic').classList.add('is-recording');}
        else if(data.type==='interim'){var visible=(gatewayFinalText+' '+String(data.text||'')).trim();if(visible){voiceCaption.textContent=visible;status.textContent='در حال تشخیص زندهٔ گفتار…';}}
        else if(data.type==='final'){var finalPart=String(data.text||'').trim();if(finalPart)gatewayFinalText+=(gatewayFinalText?' ':'')+finalPart;if(gatewayFinalText.trim())scheduleGatewayTurnEnd(generation);}
        else if(data.type==='provider_error'){status.textContent='در حال تغییر موتور تشخیص گفتار…';}
        else if(data.type==='error'){status.textContent='درگاه گفتار موقتاً در دسترس نیست؛ مسیر جایگزین را امتحان می‌کنم.';stopGatewayRecognition();if(conversationActive&&!panel.hidden)startLiveRecognition(true);}
      };
      socket.onerror=function(){if(generation===gatewayGeneration&&conversationActive){stopGatewayRecognition();startLiveRecognition(true);}};
      socket.onclose=function(){if(generation===gatewayGeneration&&conversationActive&&!liveTurnEnding){stopGatewayRecognition();startLiveRecognition(true);}};
      socket.send(JSON.stringify({type:'start',token:tokenData.token,locale:'fa-IR',sequence:0}));
      if(generation!==gatewayGeneration||!conversationActive||panel.hidden){stopGatewayRecognition();return true;}
      gatewayAudioContext=new(window.AudioContext||window.webkitAudioContext)({latencyHint:'interactive',sampleRate:48000});
      gatewaySourceNode=gatewayAudioContext.createMediaStreamSource(gatewayMediaStream);
      if(window.AudioWorkletNode&&gatewayAudioContext.audioWorklet&&typeof gatewayAudioContext.audioWorklet.addModule==='function'){
        await gatewayAudioContext.audioWorklet.addModule('/js/farast-voice-worklet.js?v=20261023live15');
        if(generation!==gatewayGeneration||!conversationActive||panel.hidden){stopGatewayRecognition();return true;}
        gatewayWorkletNode=new AudioWorkletNode(gatewayAudioContext,'farast-pcm-processor',{numberOfInputs:1,numberOfOutputs:0});
        gatewayWorkletNode.port.onmessage=function(event){if(generation!==gatewayGeneration||socket.readyState!==WebSocket.OPEN||!conversationActive)return;var bytes=new Uint8Array(event.data);if(bytes.byteLength>24000){for(var i=0;i<bytes.byteLength;i+=24000){socket.send(bytes.slice(i,Math.min(i+24000,bytes.byteLength)));noteGatewayAudioActivity(generation,bytes.slice(i,Math.min(i+24000,bytes.byteLength)).buffer);}}else if(bytes.byteLength){socket.send(bytes);noteGatewayAudioActivity(generation,event.data);}};
        gatewaySourceNode.connect(gatewayWorkletNode);
      }else{
        gatewayScriptProcessorNode=gatewayAudioContext.createScriptProcessor(4096,1,1);
        gatewayScriptProcessorNode.onaudioprocess=function(event){
          if(generation!==gatewayGeneration||socket.readyState!==WebSocket.OPEN||!conversationActive)return;
          var input=event.inputBuffer.getChannelData(0),ratio=gatewayAudioContext.sampleRate/16000;
          for(var i=0;i<input.length;i+=ratio){var sample=Math.max(-1,Math.min(1,input[Math.floor(i)]));gatewayPcmBuffer.push(sample<0?sample*32768:sample*32767);}
          while(gatewayPcmBuffer.length>=1600){var frame=new Int16Array(gatewayPcmBuffer.splice(0,1600));socket.send(frame.buffer);noteGatewayAudioActivity(generation,frame);}
          event.outputBuffer.getChannelData(0).fill(0);
        };
        gatewaySourceNode.connect(gatewayScriptProcessorNode);gatewayScriptProcessorNode.connect(gatewayAudioContext.destination);
      }
      if(gatewayAudioContext.state==='suspended')await gatewayAudioContext.resume();
      if(generation!==gatewayGeneration||!conversationActive||panel.hidden){stopGatewayRecognition();return true;}
      gatewayStarting=false;return true;
    }catch(e){
      if(generation===gatewayGeneration)stopGatewayRecognition();
      gatewayStarting=false;
      if(e&&(e.name==='NotAllowedError'||e.name==='SecurityError')){
        conversationActive=false;
        enableTextFallback('دسترسی میکروفون مجاز نیست؛ می‌توانی همین‌جا با نوشتن پیام ادامه بدهی.');
        return true;
      }
      return false;
    }
  }
  function finishGatewayTurn(generation){
    if(generation!==gatewayGeneration||!conversationActive||liveTurnEnding)return;
    var text=gatewayFinalText.trim();if(!text)return;
    liveTurnEnding=true;panel.classList.remove('is-listening');root.querySelector('.farast-assistant-mic').classList.remove('is-recording');
    stopGatewayRecognition();send(text);
  }
  function enableTextFallback(reason){
    form.hidden=false;list.hidden=false;
    var formMic=form.querySelector('.farast-assistant-mic');if(formMic)formMic.hidden=true;
    input.placeholder='پیامت را اینجا بنویس…';input.setAttribute('aria-label','پیام متنی شما');
    voiceTitle.textContent='گفت‌وگوی متنی در دسترس است';
    voiceCaption.textContent=reason||'این مرورگر امکان ضبط یا تشخیص گفتار را فراهم نکرد؛ می‌توانی با متن ادامه بدهی.';
    status.textContent='گفت‌وگو با متن فعال است؛ پاسخ‌ها همچنان از مسیر دستیار فراست دریافت می‌شوند.';
  }
  function clearLiveSilence(){if(liveSilenceTimer){window.clearTimeout(liveSilenceTimer);liveSilenceTimer=null;}}
  function stopLiveRecognition(){clearLiveSilence();stopGatewayRecognition();liveTurnEnding=true;liveStarting=false;var current=liveRecognition;liveRecognition=null;if(current){try{current.onresult=null;current.onerror=null;current.onend=null;current.stop();}catch(e){}}}
  function startLiveRecognition(useFallback){
    if(!conversationActive||panel.hidden||document.body.dataset.assistantAuth!=='1')return;
    if(!useFallback&&!gatewayWs&&!gatewayStarting){startGatewayLiveRecognition().then(function(started){if(!started&&conversationActive&&!panel.hidden)startLiveRecognition(true);}).catch(function(){if(conversationActive&&!panel.hidden)startLiveRecognition(true);});return;}
    if(!LiveSpeechRecognition){startServerRecording();return;}
    if(liveRecognition||liveStarting)return;
    liveStarting=true;liveTurnEnding=false;liveFinalText='';
    try{
      var instance=new LiveSpeechRecognition();liveRecognition=instance;
      instance.lang='fa-IR';instance.continuous=true;instance.interimResults=true;instance.maxAlternatives=1;
      instance.onstart=function(){liveStarting=false;voiceTitle.textContent='گوش می‌دهم';voiceCaption.textContent='متن صحبتت هم‌زمان نمایش داده می‌شود؛ بعد از مکث کوتاه پاسخ می‌دهم. تشخیص گفتار را مرورگر فراهم می‌کند و ممکن است آنلاین باشد.';status.textContent='گفت‌وگوی زنده فعال است؛ صحبت کن.';panel.classList.add('is-listening');root.querySelector('.farast-assistant-mic').classList.add('is-recording');};
      instance.onresult=function(event){
        var interim='';
        for(var i=event.resultIndex;i<event.results.length;i++){var part=String(event.results[i][0]&&event.results[i][0].transcript||'').trim();if(!part)continue;if(event.results[i].isFinal)liveFinalText+=(liveFinalText?' ':'')+part;else interim+=(interim?' ':'')+part;}
        var visible=(liveFinalText+' '+interim).trim();
        if(visible){voiceCaption.textContent=visible;status.textContent=interim?'در حال تشخیص زندهٔ گفتار…':'گفتار تشخیص داده شد؛ منتظر پایان جمله…';}
        if(liveFinalText.trim()){clearLiveSilence();liveSilenceTimer=window.setTimeout(function(){finishLiveTurn();},1100);}
      };
      instance.onerror=function(event){
        if(liveRecognition!==instance)return;
        if(event&&event.error==='not-allowed'){conversationActive=false;liveRecognition=null;liveStarting=false;panel.classList.remove('is-listening');enableTextFallback('مجوز میکروفون یا تشخیص گفتار داده نشد؛ برای ادامه می‌توانی پیام را بنویسی.');return;}
        if(event&&(event.error==='service-not-allowed'||event.error==='network'||event.error==='language-not-supported')){
          liveRecognition=null;liveStarting=false;liveTurnEnding=true;clearLiveSilence();panel.classList.remove('is-listening');root.querySelector('.farast-assistant-mic').classList.remove('is-recording');
          status.textContent='تشخیص زندهٔ مرورگر در دسترس نیست؛ مسیر سمت سرور را امتحان می‌کنم.';
          voiceTitle.textContent='تغییر مسیر تشخیص گفتار';voiceCaption.textContent='گفت‌وگو ادامه می‌یابد؛ صدا برای رونویسی به سرویس سمت سرور ارسال می‌شود.';
          try{instance.onresult=null;instance.onerror=null;instance.onend=null;instance.stop();}catch(e){}
          window.setTimeout(function(){if(conversationActive&&!panel.hidden)startServerRecording();},150);return;
        }
      };
      instance.onend=function(){
        if(liveRecognition!==instance)return;
        liveRecognition=null;liveStarting=false;panel.classList.remove('is-listening');root.querySelector('.farast-assistant-mic').classList.remove('is-recording');
        if(liveFinalText.trim()&&!liveTurnEnding){finishLiveTurn();return;}
        if(conversationActive&&!busy&&!panel.classList.contains('is-speaking')&&!liveTurnEnding){window.setTimeout(startLiveRecognition,250);}
      };
      instance.start();
    }catch(e){liveRecognition=null;liveStarting=false;startServerRecording();}
  }
  function finishLiveTurn(){
    clearLiveSilence();if(!conversationActive||liveTurnEnding)return;
    var text=liveFinalText.trim();if(!text)return;
    liveTurnEnding=true;var current=liveRecognition;liveRecognition=null;liveStarting=false;panel.classList.remove('is-listening');root.querySelector('.farast-assistant-mic').classList.remove('is-recording');
    if(current){try{current.onresult=null;current.onerror=null;current.onend=null;current.stop();}catch(e){}}
    liveFinalText='';send(text);
  }
  function stopMediaTracks(){if(mediaStream){mediaStream.getTracks().forEach(function(track){track.stop();});mediaStream=null;}}
  async function startServerRecording(){
    if(document.body.dataset.assistantAuth!=='1'){status.textContent='برای استفاده از میکروفون ابتدا وارد حساب کاربری شو.';return;}
    var config=null;
    try{var sr=await fetch('/assistant/voice/status',{credentials:'same-origin',headers:{'Accept':'application/json'}});if(sr.ok)config=await sr.json();}catch(e){}
    if(!config||!config.server_stt||!config.server_tts||!navigator.mediaDevices||!navigator.mediaDevices.getUserMedia||!window.MediaRecorder){var unavailable='ضبط یا سرویس صوتی در این مرورگر در دسترس نیست. می‌توانی گفت‌وگو را با نوشتن پیام ادامه بدهی.';enableTextFallback(unavailable);return;}
    if(config.server_stt_token_configured===false||config.server_tts_token_configured===false){conversationActive=false;var tokenError='تنظیمات احراز هویت سرویس صوتی کامل نیست؛ گفت‌وگو را فعلاً با متن ادامه بده.';enableTextFallback(tokenError);return;}
    try{
      mediaStream=await navigator.mediaDevices.getUserMedia({audio:{echoCancellation:true,noiseSuppression:true,autoGainControl:true}});
      var mime='audio/webm';
      if(window.MediaRecorder.isTypeSupported('audio/webm;codecs=opus'))mime='audio/webm;codecs=opus';
      else if(window.MediaRecorder.isTypeSupported('audio/mp4'))mime='audio/mp4';
      mediaRecorder=new MediaRecorder(mediaStream,window.MediaRecorder.isTypeSupported(mime)?{mimeType:mime}:{});
      recordedChunks=[];discardCurrentRecording=false;
      mediaRecorder.ondataavailable=function(e){if(e.data&&e.data.size)recordedChunks.push(e.data);};
      mediaRecorder.onerror=function(){if(recordingTimeout)window.clearTimeout(recordingTimeout);recordingTimeout=null;status.textContent='ضبط صدا ناموفق بود؛ دوباره تلاش کن.';stopMediaTracks();mediaRecorder=null;root.querySelector('.farast-assistant-mic').classList.remove('is-recording');panel.classList.remove('is-listening');};
      mediaRecorder.onstop=async function(){
        root.querySelector('.farast-assistant-mic').classList.remove('is-recording');panel.classList.remove('is-listening');
        if(discardCurrentRecording){discardCurrentRecording=false;recordedChunks=[];if(recordingTimeout)window.clearTimeout(recordingTimeout);recordingTimeout=null;stopMediaTracks();mediaRecorder=null;return;}
        if(recordingTimeout)window.clearTimeout(recordingTimeout);recordingTimeout=null;
        stopMediaTracks();
        var recorder=mediaRecorder;mediaRecorder=null;
        if(!recordedChunks.length){status.textContent='صدایی ضبط نشد؛ دوباره تلاش کن.';return;}
        var blob=new Blob(recordedChunks,{type:recorder&&recorder.mimeType?recorder.mimeType:'audio/webm'});recordedChunks=[];
        if(blob.size>15*1024*1024){status.textContent='فایل صوتی بیش از حد بزرگ است؛ کوتاه‌تر صحبت کن.';return;}
        status.textContent='در حال تبدیل گفتار به متن… فایل صوتی برای رونویسی به سرویس صوتی ارسال می‌شود.';
        var fd=new FormData();var ext=blob.type.indexOf('mp4')!==-1?'mp4':(blob.type.indexOf('ogg')!==-1?'ogg':'webm');
        fd.append('audio',blob,'farast-assistant.'+ext);fd.append('locale','fa-IR');
        try{
          var response=await fetch('/assistant/transcribe',{method:'POST',credentials:'same-origin',headers:{'Accept':'application/json','X-CSRF-TOKEN':csrf?csrf.content:''},body:fd});
          var data=await response.json().catch(function(){return {};});
          if(!response.ok||!data.text)throw new Error(data.message||'رونویسی انجام نشد.');
          if(!conversationActive)return;input.value=String(data.text).trim();status.textContent='گفتار به متن تبدیل شد؛ در حال ارسال پیام…';send(input.value);
        }catch(e){conversationActive=false;var voiceError=e&&e.message?e.message:'رونویسی از سرویس صوتی انجام نشد.';enableTextFallback(voiceError);}
      };
      mediaRecorder.start(250);
      voiceTitle.textContent='گوش می‌دهم';panel.classList.add('is-listening');status.textContent=conversationActive?'گوش می‌دهم؛ بعد از پایان صحبت، میکروفون را بزن یا تا ۲۵ ثانیه صحبت کن.':'گوش می‌دهم؛ برای توقف دوباره روی میکروفون بزن.';
      root.querySelector('.farast-assistant-mic').classList.add('is-recording');
      recordingTimeout=window.setTimeout(function(){if(mediaRecorder&&mediaRecorder.state==='recording')mediaRecorder.stop();},25000);
    }catch(e){stopMediaTracks();mediaRecorder=null;enableTextFallback('میکروفون در دسترس نیست؛ مجوز میکروفون و اتصال امن HTTPS را بررسی کن یا پیام را بنویس.');}
  }
  root.querySelector('.farast-assistant-mic').addEventListener('click',function(){
    if(mediaRecorder&&mediaRecorder.state==='recording'){mediaRecorder.stop();root.querySelector('.farast-assistant-mic').classList.remove('is-recording');return;}
    if(liveRecognition){if(assistantAudio){assistantAudio.pause();assistantAudio.currentTime=0;assistantAudio=null;}if(assistantAudioUrl){URL.revokeObjectURL(assistantAudioUrl);assistantAudioUrl=null;}panel.classList.remove('is-speaking');return;}
    if(busy){assistantTurn++;busy=false;if(chatAbortController){chatAbortController.abort();chatAbortController=null;}if(activePendingMessage){activePendingMessage.textContent='پاسخ قبلی قطع شد؛ دارم به صحبتت گوش می‌دهم.';activePendingMessage=null;}panel.classList.remove('is-speaking');}
    if(recognition){recognition.stop();recognition=null;return;}
    if(resumeListeningTimer){window.clearTimeout(resumeListeningTimer);resumeListeningTimer=null;}
    if(assistantAudio){assistantAudio.pause();assistantAudio.currentTime=0;assistantAudio=null;}
    if(assistantAudioUrl){URL.revokeObjectURL(assistantAudioUrl);assistantAudioUrl=null;}
    panel.classList.remove('is-speaking');
    conversationActive=true;liveTurnEnding=false;voiceTitle.textContent='شروع گفت‌وگوی زنده';voiceCaption.textContent='گفتار به‌صورت زنده تشخیص داده می‌شود و بعد از مکث کوتاه پاسخ می‌دهم. برای پایان، «توقف پخش صدا» را لمس کن.';startLiveRecognition();
  });
  document.addEventListener('keydown',function(e){if(e.key==='Escape'&&!panel.hidden){stopConversation();if(assistantAudio){assistantAudio.pause();assistantAudio=null;}if(assistantAudioUrl){URL.revokeObjectURL(assistantAudioUrl);assistantAudioUrl=null;}setOpen(false);}});
  voiceCaption.textContent='برای شروع، میکروفون را لمس کن و به فارسی صحبت کن.';
})();