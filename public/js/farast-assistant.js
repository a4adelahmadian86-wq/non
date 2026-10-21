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
  function setOpen(v){panel.hidden=!v;panel.classList.toggle('is-open',v);launcher.setAttribute('aria-expanded',v?'true':'false');if(v){if(document.body.dataset.assistantAuth==='1'){voiceTitle.textContent='آمادهٔ گفت‌وگو هستم';status.textContent='برای صحبت، میکروفون را لمس کن.';}else{voiceTitle.textContent='ابتدا وارد حساب شو';voiceCaption.textContent='برای استفاده از همراه صوتی، ورود به حساب کاربری لازم است.';status.textContent='ورود برای استفاده از همراه هوشمند الزامی است.';}}}
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
  var assistantAudio=null, assistantAudioUrl=null, conversationActive=false, resumeListeningTimer=null;
  function stopConversation(){conversationActive=false;assistantTurn++;busy=false;if(resumeListeningTimer){window.clearTimeout(resumeListeningTimer);resumeListeningTimer=null;}try{window.speechSynthesis&&window.speechSynthesis.cancel();}catch(e){}if(mediaRecorder&&mediaRecorder.state==='recording'){discardCurrentRecording=true;try{mediaRecorder.stop();}catch(e){}}stopMediaTracks();panel.classList.remove('is-listening','is-speaking');root.querySelector('.farast-assistant-mic').classList.remove('is-recording');}
  function scheduleNextListening(){if(!conversationActive||panel.hidden||document.body.dataset.assistantAuth!=='1')return;if(resumeListeningTimer)window.clearTimeout(resumeListeningTimer);resumeListeningTimer=window.setTimeout(function(){resumeListeningTimer=null;if(conversationActive&&!panel.hidden)startServerRecording();},450);}
  function speakWithBrowser(text, speechTurn){
    if(!window.speechSynthesis||!window.SpeechSynthesisUtterance)return false;
    var voices=[];
    try{voices=window.speechSynthesis.getVoices()||[];}catch(e){}
    var persianVoice=voices.find(function(voice){return /^fa[-_]/i.test(String(voice.lang||''));});
    if(!persianVoice)return false;
    try{
      window.speechSynthesis.cancel();
      var utterance=new SpeechSynthesisUtterance(String(text||'').slice(0,6000));
      utterance.lang='fa-IR';utterance.voice=persianVoice;
      utterance.onstart=function(){if(speechTurn!==assistantTurn)return;panel.classList.add('is-speaking');voiceTitle.textContent='در حال صحبت';voiceCaption.textContent='صدای محلی دستگاه در حال پخش است.';status.textContent='برای قطع صدا، «توقف پخش صدا» را لمس کن.';};
      utterance.onend=function(){if(speechTurn!==assistantTurn)return;panel.classList.remove('is-speaking');voiceTitle.textContent=conversationActive?'نوبت توست':'آمادهٔ گفت‌وگو هستم';voiceCaption.textContent=conversationActive?'بعد از پاسخ، دوباره گوش می‌دهم.':'هر وقت آماده بودی، دوباره صحبت کن.';status.textContent=conversationActive?'برای ادامهٔ مکالمه دوباره گوش می‌دهم…':'آماده‌ام؛ دوباره صحبت کن.';scheduleNextListening();};
      utterance.onerror=function(){if(speechTurn!==assistantTurn)return;panel.classList.remove('is-speaking');status.textContent='صدای محلی مرورگر پخش نشد.';if(conversationActive)scheduleNextListening();};
      window.speechSynthesis.speak(utterance);
      return true;
    }catch(e){return false;}
  }
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
      assistantAudio.onerror=function(){panel.classList.remove('is-speaking');status.textContent='مرورگر نتوانست فایل صوتی را رمزگشایی کند.';};
      await new Promise(function(resolve,reject){var settled=false;function done(error){if(settled)return;settled=true;assistantAudio.removeEventListener('canplay',ready);assistantAudio.removeEventListener('error',failed);if(error)reject(error);else resolve();}function ready(){done();}function failed(){done(new Error('فایل دریافت شد، اما مرورگر نمی‌تواند آن را به صدای قابل پخش تبدیل کند.'));}assistantAudio.addEventListener('canplay',ready,{once:true});assistantAudio.addEventListener('error',failed,{once:true});assistantAudio.load();window.setTimeout(function(){if(!settled)done(new Error('بارگذاری صدای فارسی بیش از حد طول کشید؛ دوباره تلاش کن.'));},10000);});
      if(speechTurn!==assistantTurn)return;
      if(!assistantAudio.duration||!isFinite(assistantAudio.duration)||assistantAudio.duration<=0)throw new Error('فایل صوتی دریافت شد، اما مدت‌زمان قابل پخش ندارد.');
      await assistantAudio.play();
    }catch(e){
      if(speechTurn!==assistantTurn)return;
      panel.classList.remove('is-speaking');
      if(assistantAudio){try{assistantAudio.pause();}catch(err){}assistantAudio=null;}
      if(assistantAudioUrl){URL.revokeObjectURL(assistantAudioUrl);assistantAudioUrl=null;}
      if(speakWithBrowser(text,speechTurn)){
        voiceTitle.textContent='صدای سرور در دسترس نیست';
        voiceCaption.textContent='از صدای فارسی نصب‌شده روی دستگاه استفاده می‌کنم.';
        status.textContent=voiceCaption.textContent;
        return;
      }
      voiceTitle.textContent='موتور صدا آماده نیست';
      voiceCaption.textContent=e.message||'صدای ثابت فراست در دسترس نیست و صدای فارسی محلی هم روی دستگاه پیدا نشد.';
      status.textContent=voiceCaption.textContent;
    }
  }
    async function send(text){
    text=String(text||'').trim();if(!text||busy)return;
    if(document.body.dataset.assistantAuth !== '1'){
      voiceTitle.textContent='ابتدا وارد حساب شو';voiceCaption.textContent='برای استفاده از همراه صوتی، ورود به حساب کاربری لازم است.';status.textContent='ورود برای استفاده از همراه هوشمند الزامی است.';
      var link=root.querySelector('.farast-voice-login');if(!link){link=document.createElement('a');link.href='/login?redirect='+encodeURIComponent(location.pathname+location.search);link.className='farast-voice-login';link.textContent='ورود به حساب کاربری';root.querySelector('.farast-voice-stage').appendChild(link);}return;
    }
    input.value='';busy=true;var turn=++assistantTurn;voiceTitle.textContent='در حال پاسخ‌گویی';status.textContent='دارم به حرفت فکر می‌کنم…';var pending=message('bot','در حال آماده‌کردن پاسخ صوتی…');panel.classList.add('is-speaking');
    try{
      var prompt='تو همراه هوشمند وب‌سایت فارسی فراست هستی. به فارسی روان، صمیمی، دقیق و مختصر پاسخ بده. درباره خدمات سایت راهنمایی عملی بده؛ اگر مطمئن نیستی ادعا نکن. مسیرهای شناخته‌شده: فروشگاه /store، سبد /cart، خدمات تایپ /editor، قیمت‌ها /pricing، پشتیبانی /support، فضای کاربر /dashboard. مکالمه اخیر:\n'+history.slice(-8).map(function(m){return (m.role==='user'?'کاربر':'همراه')+': '+m.text;}).join('\n')+'\nکاربر: '+text;
      var response=await fetch('/assistant/chat',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':csrf?csrf.content:''},body:JSON.stringify({message:text,context:history.slice(-8),prompt:prompt})});
      var data=await response.json().catch(function(){return {};});
      if(turn!==assistantTurn)return;
      if(response.status===401||response.status===419){var loginMessage='نشست شما منقضی شده است. لطفاً دوباره وارد حساب کاربری شوید.';pending.textContent='';voiceTitle.textContent='ورود دوباره لازم است';voiceCaption.textContent=loginMessage;status.textContent='ورود مجدد لازم است.';say(loginMessage);}
      else if(!response.ok){throw new Error(data.message||'سرویس گفت‌وگو فعلاً آماده نیست.');}
      else {var answer=String(data.reply||data.text||data.message||'پاسخی دریافت نشد.');pending.textContent='';history.push({role:'user',text:text},{role:'assistant',text:answer});if(history.length>20)history=history.slice(-20);voiceTitle.textContent='پاسخ فراست';voiceCaption.textContent='در حال پخش پاسخ صوتی…';status.textContent='در حال پخش پاسخ صوتی…';say(answer);}
    }catch(e){if(turn!==assistantTurn)return;var spokenError='متأسفم، در حال حاضر نمی‌توانم پاسخ را از سرویس هوش مصنوعی دریافت کنم. لطفاً بعداً دوباره تلاش کن.';pending.textContent='';voiceTitle.textContent='پاسخ‌گویی موقتاً در دسترس نیست';voiceCaption.textContent=spokenError;status.textContent='اتصال به سرویس پاسخ‌گویی ناموفق بود.';say(spokenError);}
    finally{busy=false;}
  }
  launcher.addEventListener('click',function(){var opening=panel.hidden;if(!opening){stopConversation();if(assistantAudio){assistantAudio.pause();assistantAudio=null;}if(assistantAudioUrl){URL.revokeObjectURL(assistantAudioUrl);assistantAudioUrl=null;}}setOpen(opening);if(opening)loadHistory();});
  root.querySelector('.farast-assistant-close').addEventListener('click',function(){stopConversation();if(assistantAudio){assistantAudio.pause();assistantAudio=null;}if(assistantAudioUrl){URL.revokeObjectURL(assistantAudioUrl);assistantAudioUrl=null;}setOpen(false);});
  root.querySelector('[data-fa-stop-speech]').addEventListener('click',function(){stopConversation();if(assistantAudio){assistantAudio.pause();assistantAudio.currentTime=0;assistantAudio=null;}if(assistantAudioUrl){URL.revokeObjectURL(assistantAudioUrl);assistantAudioUrl=null;}panel.classList.remove('is-speaking');voiceTitle.textContent='مکالمه متوقف شد';status.textContent='گفت‌وگوی پیوسته متوقف شد.';voiceCaption.textContent='برای شروع دوباره، میکروفون را لمس کن.';});
  root.querySelector('.farast-assistant-clear').addEventListener('click',clearHistory);
  root.querySelector('[data-fa-memory]').addEventListener('click',saveMemory);
  root.querySelector('[data-fa-memory-manage]').addEventListener('click',manageMemories);
  form.addEventListener('submit',function(e){e.preventDefault();send(input.value);});
  root.querySelectorAll('[data-fa-text]').forEach(function(b){b.addEventListener('click',function(){send(b.getAttribute('data-fa-text'));});});
  root.querySelector('[data-fa-tour]').addEventListener('click',startTour);
  var mediaRecorder=null, mediaStream=null, recordedChunks=[], recordingTimeout=null, discardCurrentRecording=false;
  var vadContext=null, vadSource=null, vadAnalyser=null, vadFrame=0;
  function stopVadMonitor(){
    if(vadFrame){try{cancelAnimationFrame(vadFrame);}catch(e){}vadFrame=0;}
    if(vadSource){try{vadSource.disconnect();}catch(e){}vadSource=null;}
    if(vadContext){try{vadContext.close();}catch(e){}vadContext=null;}
    vadAnalyser=null;
  }
  function stopMediaTracks(){stopVadMonitor();if(mediaStream){mediaStream.getTracks().forEach(function(track){try{track.stop();}catch(e){}});mediaStream=null;}}
  function startVadMonitor(recorder){
    var AC=window.AudioContext||window.webkitAudioContext;
    if(!AC||!mediaStream)return;
    try{
      stopVadMonitor();
      vadContext=new AC();
      vadAnalyser=vadContext.createAnalyser();vadAnalyser.fftSize=512;
      vadSource=vadContext.createMediaStreamSource(mediaStream);vadSource.connect(vadAnalyser);
      var samples=new Uint8Array(vadAnalyser.fftSize),startedAt=Date.now(),speechAt=0,lastVoiceAt=0;
      function tick(){
        if(mediaRecorder!==recorder||recorder.state!=='recording')return;
        vadAnalyser.getByteTimeDomainData(samples);
        var energy=0;
        for(var i=0;i<samples.length;i++){var value=(samples[i]-128)/128;energy+=value*value;}
        var rms=Math.sqrt(energy/samples.length),now=Date.now();
        if(rms>0.015){if(!speechAt)speechAt=now;lastVoiceAt=now;}
        if(speechAt&&now-speechAt>=500&&now-lastVoiceAt>=1200){
          status.textContent='مکث تشخیص داده شد؛ گفتار را پردازش می‌کنم…';
          try{recorder.stop();}catch(e){}
          return;
        }
        if(!speechAt&&now-startedAt>=8000){
          discardCurrentRecording=true;
          status.textContent='صدایی تشخیص داده نشد؛ برای شروع دوباره صحبت کن.';
          try{recorder.stop();}catch(e){}
          return;
        }
        if(now-startedAt>=25000){
          status.textContent='به حد زمان گفتار رسیدیم؛ در حال پردازش…';
          try{recorder.stop();}catch(e){}
          return;
        }
        vadFrame=requestAnimationFrame(tick);
      }
      vadFrame=requestAnimationFrame(tick);
    }catch(e){stopVadMonitor();}
  }
  async function startServerRecording(){
    if(document.body.dataset.assistantAuth!=='1'){status.textContent='برای استفاده از میکروفون ابتدا وارد حساب کاربری شو.';return;}
    var config=null;
    try{var sr=await fetch('/assistant/voice/status',{credentials:'same-origin',headers:{'Accept':'application/json'}});if(sr.ok)config=await sr.json();}catch(e){}
    if(!config)config={};
    var localTtsAvailable=false;
    try{localTtsAvailable=!!(window.speechSynthesis&&window.SpeechSynthesisUtterance&&(window.speechSynthesis.getVoices()||[]).some(function(voice){return /^fa[-_]/i.test(String(voice.lang||''));}));}catch(e){}
    if((!config.server_tts||config.server_tts_token_configured===false)&&!localTtsAvailable||!navigator.mediaDevices||!navigator.mediaDevices.getUserMedia||!window.MediaRecorder){
      var unavailable='موتور صدای پاسخ یا دسترسی میکروفون تنظیم نشده است؛ سرویس صوتی را بررسی کن یا صدای فارسی روی دستگاه نصب کن.';
      status.textContent=unavailable;voiceTitle.textContent='موتور صوتی تنظیم نشده';voiceCaption.textContent=unavailable;return;
    }
    var localAsrReady=false;
    try{
      var localEngine=window.FarastVoiceHub&&window.FarastVoiceHub.localWhisper;
      if(localEngine&&localEngine.ensureWhisper){
        status.textContent='آماده‌سازی مدل تشخیص گفتار روی همین دستگاه (فقط بار اول)…';
        await localEngine.ensureWhisper(function(message){status.textContent=message;},localStorage.getItem('farast.whisper.model')||'Xenova/whisper-base');
        localAsrReady=true;
      }
    }catch(localLoadError){status.textContent='مدل محلی بارگذاری نشد؛ در صورت پیکربندی، از سرویس گفتار پشتیبان استفاده می‌کنم…';}
    if(!localAsrReady&&(!config.server_stt||config.server_stt_token_configured===false)){
      var asrUnavailable='مدل محلی روی دستگاه بارگذاری نشد و سرویس گفتار پشتیبان هم آماده نیست. اتصال دانلود مدل را بررسی کن یا موتور سرور را پیکربندی کن.';
      status.textContent=asrUnavailable;voiceTitle.textContent='تشخیص گفتار در دسترس نیست';voiceCaption.textContent=asrUnavailable;return;
    }
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
        try{
          var transcript='';
          var localEngine=window.FarastVoiceHub&&window.FarastVoiceHub.localWhisper;
          if(localAsrReady&&localEngine&&localEngine.transcribe){
            status.textContent='در حال تشخیص گفتار روی دستگاه شما…';
            try{transcript=await localEngine.transcribe(blob,'fa-IR',function(message){if(conversationActive)status.textContent=message;});}
            catch(localError){status.textContent='تشخیص محلی ناموفق بود؛ بررسی سرویس پشتیبان…';}
          }
          if(!transcript&&config.server_stt&&config.server_stt_token_configured!==false){
            status.textContent='مدل محلی پاسخ نداد؛ صدا برای رونویسی به سرویس پشتیبان ارسال می‌شود…';
            var fd=new FormData();var ext=blob.type.indexOf('mp4')!==-1?'mp4':(blob.type.indexOf('ogg')!==-1?'ogg':'webm');
            fd.append('audio',blob,'farast-assistant.'+ext);fd.append('locale','fa-IR');
            var response=await fetch('/assistant/transcribe',{method:'POST',credentials:'same-origin',headers:{'Accept':'application/json','X-CSRF-TOKEN':csrf?csrf.content:''},body:fd});
            var data=await response.json().catch(function(){return {};});
            if(!response.ok||!data.text)throw new Error(data.message||'رونویسی پشتیبان انجام نشد.');
            transcript=String(data.text).trim();
          }
          if(!transcript)throw new Error('گفتار قابل تشخیصی پیدا نشد؛ دوباره واضح‌تر صحبت کن.');
          if(!conversationActive)return;input.value=transcript;status.textContent='گفتار تشخیص داده شد؛ در حال ارسال پیام…';send(input.value);
        }catch(e){conversationActive=false;var voiceError=e&&e.message?e.message:'رونویسی گفتار انجام نشد.';status.textContent=voiceError;voiceTitle.textContent='موتور صوتی در دسترس نیست';voiceCaption.textContent=voiceError;}
      };
      mediaRecorder.start(250);
      startVadMonitor(mediaRecorder);
      voiceTitle.textContent='گوش می‌دهم';panel.classList.add('is-listening');status.textContent=conversationActive?'گوش می‌دهم؛ بعد از حدود ۱٫۲ ثانیه مکث، گفتار خودکار پردازش می‌شود.':'گوش می‌دهم؛ بعد از مکث گفتار خودکار پردازش می‌شود.';
      root.querySelector('.farast-assistant-mic').classList.add('is-recording');
      recordingTimeout=window.setTimeout(function(){if(mediaRecorder&&mediaRecorder.state==='recording')mediaRecorder.stop();},25000);
    }catch(e){stopMediaTracks();mediaRecorder=null;status.textContent='میکروفون در دسترس نیست؛ مجوز میکروفون و HTTPS سایت را بررسی کن.';voiceTitle.textContent='میکروفون در دسترس نیست';voiceCaption.textContent=status.textContent;}
  }
  root.querySelector('.farast-assistant-mic').addEventListener('click',function(){
    if(mediaRecorder&&mediaRecorder.state==='recording'){mediaRecorder.stop();root.querySelector('.farast-assistant-mic').classList.remove('is-recording');return;}
    if(recognition){recognition.stop();recognition=null;return;}
    if(resumeListeningTimer){window.clearTimeout(resumeListeningTimer);resumeListeningTimer=null;}
    if(assistantAudio){assistantAudio.pause();assistantAudio.currentTime=0;assistantAudio=null;}
    if(assistantAudioUrl){URL.revokeObjectURL(assistantAudioUrl);assistantAudioUrl=null;}
    panel.classList.remove('is-speaking');
    conversationActive=true;voiceTitle.textContent='شروع گفت‌وگوی پیوسته';voiceCaption.textContent='بعد از هر پاسخ، دوباره گوش می‌دهم. برای پایان، «توقف پخش صدا» را لمس کن.';startServerRecording();
  });
  document.addEventListener('keydown',function(e){if(e.key==='Escape'&&!panel.hidden){stopConversation();if(assistantAudio){assistantAudio.pause();assistantAudio=null;}if(assistantAudioUrl){URL.revokeObjectURL(assistantAudioUrl);assistantAudioUrl=null;}setOpen(false);}});
  voiceCaption.textContent='برای شروع، میکروفون را لمس کن و به فارسی صحبت کن.';
})();