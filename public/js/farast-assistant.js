/* FARAST conversational companion. Authenticated requests use the server AI pipeline; speech is progressive-enhancement. */
(function () {
  'use strict';
  if (document.getElementById('farast-global-assistant')) return;
  var root = document.createElement('div');
  root.id = 'farast-global-assistant';
  root.innerHTML =
    '<button class="farast-assistant-launcher" id="farast-assistant-launcher" type="button" aria-controls="farast-assistant-panel" aria-label="شروع گفت‌وگوی صوتی با فراست" aria-expanded="false" title="گفت‌وگوی صوتی فراست"><span class="fa-solid fa-microphone-lines" aria-hidden="true"></span><span class="farast-assistant-pulse" aria-hidden="true"></span></button>' +
    '<section class="farast-assistant-panel farast-assistant-voice-panel" id="farast-assistant-panel" role="dialog" aria-labelledby="farast-assistant-title" aria-modal="false" aria-label="گفت‌وگوی صوتی فراست" hidden>' +
    '<header class="farast-assistant-head"><div class="farast-assistant-avatar"><i class="fa-solid fa-wave-square" aria-hidden="true"></i></div><div class="farast-assistant-brand"><strong id="farast-assistant-title">همراه صوتی فراست</strong><small><span class="farast-assistant-online"></span> گفت‌وگوی زنده + متن</small></div><button class="farast-assistant-close" type="button" aria-label="بستن">×</button></header>' +
    '<div class="farast-voice-stage"><div class="farast-voice-orb" aria-hidden="true"><span></span><span></span><span></span></div><strong class="farast-voice-title" data-fa-voice-title>آمادهٔ گفت‌وگو هستم</strong><p class="farast-voice-caption" data-fa-voice-caption>برای شروع، میکروفون را لمس کن و صحبت کن.</p><div class="farast-assistant-status" data-fa-status role="status" aria-live="polite">برای گفت‌وگو وارد حساب کاربری‌ات شو.</div><button class="farast-assistant-mic farast-voice-mic" type="button" aria-label="شروع صحبت" title="برای شروع یا توقف صحبت لمس کن"><i class="fa-solid fa-microphone" aria-hidden="true"></i><span>صحبت کن</span></button><button class="farast-voice-stop" type="button" data-fa-stop-speech aria-label="توقف کامل مکالمه">توقف مکالمه</button></div>' +
    '<div class="farast-assistant-messages" data-fa-messages aria-live="polite" aria-relevant="additions"></div>' +
    '<div class="farast-assistant-intro" hidden><b>همراه صوتی فراست</b><p>گفت‌وگوی صوتی</p></div>' +
    '<div class="farast-assistant-suggestions"><button type="button" data-fa-tour>راهنمای سایت</button><button type="button" data-fa-text="برای خرید فایل راهنمایی‌ام کن">خرید فایل</button></div>' +
    '<form class="farast-assistant-form" autocomplete="off"><input name="message" maxlength="1200" aria-label="پیام شما" placeholder="پیامت را اینجا بنویس…"><button class="farast-assistant-send" type="submit" aria-label="ارسال پیام"><i class="fa-solid fa-arrow-up"></i></button></form>' +
    '<div hidden><button class="farast-assistant-memory" type="button" data-fa-memory>حافظه +</button><button class="farast-assistant-memory-manage" type="button" data-fa-memory-manage>مدیریت حافظه</button><button class="farast-assistant-clear" type="button" aria-label="پاک کردن سابقه">پاک کردن سابقه</button></div>' +
    '<footer class="farast-assistant-foot">برای استفاده از گفت‌وگو وارد حساب شو؛ ضبط دسته‌ای خودکار انجام نمی‌شود.</footer></section>';  document.body.appendChild(root);
  var panel=root.querySelector('.farast-assistant-panel'), launcher=root.querySelector('.farast-assistant-launcher'),
      list=root.querySelector('[data-fa-messages]'), form=root.querySelector('form'), input=form.querySelector('input'),
      status=root.querySelector('[data-fa-status]'), busy=false, history=[], historyLoaded=false, assistantTurn=0;
  var csrf=document.querySelector('meta[name="csrf-token"]');
  function esc(s){return String(s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];});}
  var voiceTitle=root.querySelector('[data-fa-voice-title]'), voiceCaption=root.querySelector('[data-fa-voice-caption]');
  function message(role,text){var el=document.createElement('div');el.className='farast-assistant-msg '+role;el.textContent=String(text||'');list.appendChild(el);list.scrollTop=list.scrollHeight;if(role==='bot')voiceCaption.textContent='پاسخ صوتی در حال آماده‌شدن است.';return el;}
  function setOpen(v){panel.hidden=!v;panel.classList.toggle('is-open',v);launcher.setAttribute('aria-expanded',v?'true':'false');if(v){if(document.body.dataset.assistantAuth==='1'){voiceTitle.textContent='آمادهٔ گفت‌وگو هستم';status.textContent='صحبت کن؛ برای مکث یا پایان، دکمهٔ فراست را دوباره بزن.';}else{voiceTitle.textContent='ابتدا وارد حساب شو';voiceCaption.textContent='برای استفاده از همراه صوتی، ورود به حساب کاربری لازم است.';status.textContent='ورود برای استفاده از همراه هوشمند الزامی است.';}}if(v){root.querySelector('.farast-assistant-close').focus();}else{launcher.focus();}}
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
  async function loadAssistantStatus(){
    if(document.body.dataset.assistantAuth!=='1')return;
    try{
      var response=await fetch('/assistant/voice/status',{credentials:'same-origin',headers:{'Accept':'application/json'},cache:'no-store'});
      if(!response.ok)return;
      var data=await response.json();
      if(data.local_only&&data.chat_gateway_ready===false&&!gatewayStarting&&!gatewayWs&&!busy){
        voiceTitle.textContent='درگاه پاسخ‌گویی تنظیم نشده';
        voiceCaption.textContent=data.chat_gateway_message||'برای پاسخ‌گویی خصوصی، درگاه مدل زبانی خودمیزبان باید تنظیم شود.';
        status.textContent='هیچ درخواست ابری ارسال نمی‌شود؛ تنظیمات خصوصی سرور لازم است.';
      }
    }catch(e){}
  }
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
  var assistantAudio=null, assistantAudioUrl=null, conversationActive=false, resumeListeningTimer=null, chatAbortController=null, activePendingMessage=null, speechGeneration=0, speechRequestController=null;
  var batchRecorder=null,batchMediaStream=null,batchChunks=[],batchRecording=false,batchStopTimer=null;
  function stopConversation(){if(batchStopTimer){window.clearTimeout(batchStopTimer);batchStopTimer=null;}if(batchRecorder&&batchRecorder.state!=='inactive'){try{batchRecorder.onstop=null;batchRecorder.stop();}catch(e){}}if(batchMediaStream){batchMediaStream.getTracks().forEach(function(track){track.stop();});batchMediaStream=null;}batchRecorder=null;batchChunks=[];batchRecording=false;conversationActive=false;assistantTurn++;speechGeneration++;busy=false;if(chatAbortController){chatAbortController.abort();chatAbortController=null;}if(speechRequestController){speechRequestController.abort();speechRequestController=null;}stopLiveRecognition();if(resumeListeningTimer){window.clearTimeout(resumeListeningTimer);resumeListeningTimer=null;}if(assistantAudio){try{assistantAudio.pause();}catch(e){}assistantAudio=null;}if(assistantAudioUrl){URL.revokeObjectURL(assistantAudioUrl);assistantAudioUrl=null;}panel.classList.remove('is-listening','is-speaking');root.querySelector('.farast-assistant-mic').classList.remove('is-recording');}
  function scheduleNextListening(){if(resumeListeningTimer){window.clearTimeout(resumeListeningTimer);resumeListeningTimer=null;}if(!conversationActive||panel.hidden||document.body.dataset.assistantAuth!=='1')return;if(gatewayWs&&gatewayWs.readyState===WebSocket.OPEN){voiceTitle.textContent='گوش می‌دهم';voiceCaption.textContent='میکروفون روشن است؛ هر وقت صحبت کنی، نوبت تو را تشخیص می‌دهم.';status.textContent='در حال گوش دادن؛ برای قطع کامل مکالمه، دکمهٔ توقف را لمس کن.';panel.classList.add('is-listening');return;}voiceTitle.textContent='نوبت توست';voiceCaption.textContent='درگاه زنده قطع شده است؛ برای تلاش دوباره دکمهٔ «صحبت کن» را لمس کن.';status.textContent='میکروفون خاموش است؛ اتصال زنده را دوباره برقرار کن.';}
  function interruptAssistantForBargeIn(){
    if(!busy&&!assistantAudio&&!speechRequestController&&!chatAbortController)return;
    assistantTurn++;speechGeneration++;busy=false;
    if(chatAbortController){chatAbortController.abort();chatAbortController=null;}
    if(speechRequestController){speechRequestController.abort();speechRequestController=null;}
    if(assistantAudio){try{assistantAudio.pause();assistantAudio.currentTime=0;}catch(e){}assistantAudio=null;}
    if(assistantAudioUrl){URL.revokeObjectURL(assistantAudioUrl);assistantAudioUrl=null;}
    if(activePendingMessage){activePendingMessage.textContent+='\n\n[پاسخ با شروع صحبت شما متوقف شد]';activePendingMessage=null;}
    panel.classList.remove('is-speaking');
    voiceTitle.textContent='گوش می‌دهم';
    voiceCaption.textContent='پاسخ قطع شد؛ ادامه بده، دارم گوش می‌دهم.';
    status.textContent='با تشخیص صدای شما، پخش و تولید پاسخ قبلی قطع شد.';
  }
  function splitSpeechChunks(value){
    var text=String(value||'').replace(/\s+/g,' ').trim();
    if(!text)return [];
    var sentences=text.match(/[^.!?؟۔]+[.!?؟۔]*/gu)||[text];
    var chunks=[],pending='';
    sentences.forEach(function(sentence){
      sentence=sentence.trim();if(!sentence)return;
      if(sentence.length>260){
        if(pending){chunks.push(pending);pending='';}
        var words=sentence.split(/\s+/),part='';
        words.forEach(function(word){
          if(part&&((part+' '+word).length>260)){chunks.push(part);part=word;}
          else part=part?(part+' '+word):word;
        });
        if(part)chunks.push(part);
      }else if(pending&&(pending+' '+sentence).length>260){chunks.push(pending);pending=sentence;}
      else pending=pending?(pending+' '+sentence):sentence;
    });
    if(pending)chunks.push(pending);
    return chunks;
  }
  async function say(text,streamState){
    var speechTurn=streamState&&streamState.speechTurn?streamState.speechTurn:++speechGeneration,requestTurn=assistantTurn;
    if(document.body.dataset.assistantAuth!=='1'){status.textContent='برای پخش پاسخ صوتی ابتدا وارد حساب شو.';return;}
    var chunks=splitSpeechChunks(text);
    if(!chunks.length)return;
    async function synthesizeChunk(chunk){
      var controller=new AbortController();speechRequestController=controller;
      var timeout=window.setTimeout(function(){controller.abort();},30000);
      try{
        var response=await fetch('/assistant/speak',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','Accept':'audio/*, application/json','X-CSRF-TOKEN':csrf?csrf.content:''},body:JSON.stringify({text:chunk}),signal:controller.signal});
        if(speechTurn!==speechGeneration||requestTurn!==assistantTurn)return {cancelled:true};
        if(!response.ok){var err=await response.json().catch(function(){return {};});throw new Error(err.message||'موتور صدای ثابت در دسترس نیست.');}
        var blob=await response.blob();
        if(!blob.size||(!/^audio\//i.test(blob.type)&&blob.type!=='application/octet-stream'))throw new Error('موتور صدا فایل صوتی معتبر برنگرداند.');
        return {blob:blob};
      }catch(error){return {error:error};}
      finally{window.clearTimeout(timeout);if(speechRequestController===controller)speechRequestController=null;}
    }
    try{
      if(assistantAudio){assistantAudio.pause();assistantAudio=null;}
      if(assistantAudioUrl){URL.revokeObjectURL(assistantAudioUrl);assistantAudioUrl=null;}
      var pendingBlob=streamState&&streamState.prefetched&&streamState.prefetched.chunk===chunks[0]?streamState.prefetched.promise:synthesizeChunk(chunks[0]);if(streamState&&streamState.prefetched&&streamState.prefetched.chunk===chunks[0])streamState.prefetched=null;
      for(var i=0;i<chunks.length;i++){
        if(speechTurn!==speechGeneration||requestTurn!==assistantTurn)return;
        var result=await pendingBlob;
        if(result.cancelled||speechTurn!==speechGeneration||requestTurn!==assistantTurn)return;
        if(result.error)throw result.error;
        var blob=result.blob;
        assistantAudioUrl=URL.createObjectURL(blob);
        var audio=new Audio();assistantAudio=audio;audio.preload='auto';audio.src=assistantAudioUrl;
        audio.onplay=function(){panel.classList.add('is-speaking');voiceTitle.textContent='در حال صحبت';voiceCaption.textContent='اگر وسط پاسخ صحبت کنی، پخش صدا خودکار قطع می‌شود و گوش می‌دهم.';status.textContent='گوش‌دادن فعال است؛ برای قطع کامل مکالمه، «توقف مکالمه» را لمس کن.';};
        var readyPromise=new Promise(function(resolve,reject){
          var settled=false,timeout;
          function done(error){if(settled)return;settled=true;if(timeout)window.clearTimeout(timeout);audio.removeEventListener('canplay',ready);audio.removeEventListener('error',failed);if(error)reject(error);else resolve();}
          function ready(){done();}
          function failed(){done(new Error('فایل دریافت شد، اما مرورگر نمی‌تواند آن را به صدای قابل پخش تبدیل کند.'));}
          audio.addEventListener('canplay',ready,{once:true});audio.addEventListener('error',failed,{once:true});audio.load();
          timeout=window.setTimeout(function(){done(new Error('بارگذاری صدای فارسی بیش از حد طول کشید؛ دوباره تلاش کن.'));},7000);
        });
        await readyPromise;
        if(speechTurn!==speechGeneration||requestTurn!==assistantTurn)return;
        if(!audio.duration||!isFinite(audio.duration)||audio.duration<=0)throw new Error('فایل صوتی دریافت شد، اما مدت‌زمان قابل پخش ندارد.');
        // Fetch the next sentence while this one is playing to minimize silence
        // between segments without buffering the whole reply before playback.
        var streamPrefetch=null;
        if(i+1<chunks.length)pendingBlob=synthesizeChunk(chunks[i+1]);
        else if(streamState){
          streamPrefetch=(async function(){
            while(!streamState.queue.length&&!streamState.done&&speechTurn===speechGeneration&&requestTurn===assistantTurn){
              await new Promise(function(resolve){streamState.notify=resolve;});
            }
            if(streamState.queue.length&&speechTurn===speechGeneration&&requestTurn===assistantTurn){
              var nextChunk=streamState.queue[0];return {chunk:nextChunk,promise:synthesizeChunk(nextChunk)};
            }
            return null;
          })();
        }
        await audio.play();
        await new Promise(function(resolve){
          var finished=false;
          function done(){if(finished)return;finished=true;audio.removeEventListener('ended',done);audio.removeEventListener('pause',done);audio.removeEventListener('error',done);resolve();}
          audio.addEventListener('ended',done,{once:true});audio.addEventListener('pause',done,{once:true});audio.addEventListener('error',done,{once:true});
          if(audio.ended)done();
        });
        if(streamPrefetch){var prepared=await streamPrefetch;if(prepared)streamState.prefetched=prepared;}
        if(assistantAudio===audio)assistantAudio=null;
        if(assistantAudioUrl){URL.revokeObjectURL(assistantAudioUrl);assistantAudioUrl=null;}
        if(speechTurn!==speechGeneration||requestTurn!==assistantTurn)return;
      }
      panel.classList.remove('is-speaking');
      voiceTitle.textContent=conversationActive?'نوبت توست':'آمادهٔ گفت‌وگو هستم';
      voiceCaption.textContent=conversationActive?'میکروفون خاموش است؛ برای پیام بعدی دکمهٔ «صحبت کن» را لمس کن.':'هر وقت آماده بودی، دوباره صحبت کن.';
      status.textContent=conversationActive?'میکروفون خاموش است و تا لمس دکمه دوباره فعال نمی‌شود.':'آماده‌ام؛ دوباره صحبت کن.';
      scheduleNextListening();
    }catch(e){
      if(speechTurn!==speechGeneration||requestTurn!==assistantTurn)return;
      if(assistantAudio){try{assistantAudio.pause();}catch(ignore){}assistantAudio=null;}
      if(assistantAudioUrl){URL.revokeObjectURL(assistantAudioUrl);assistantAudioUrl=null;}
      panel.classList.remove('is-speaking');
      enableTextFallback(e.message||'صدای ثابت فراست فعلاً در دسترس نیست؛ پاسخ را می‌توانی به‌صورت متنی بخوانی.');scheduleNextListening();

    }
  }
  async function send(text){
    text=String(text||'').trim();if(!text||busy)return;
    if(document.body.dataset.assistantAuth !== '1'){
      voiceTitle.textContent='ابتدا وارد حساب شو';voiceCaption.textContent='برای استفاده از همراه صوتی، ورود به حساب کاربری لازم است.';status.textContent='ورود برای استفاده از همراه هوشمند الزامی است.';
      var link=root.querySelector('.farast-voice-login');if(!link){link=document.createElement('a');link.href='/login?redirect='+encodeURIComponent(location.pathname+location.search);link.className='farast-voice-login';link.textContent='ورود به حساب کاربری';root.querySelector('.farast-voice-stage').appendChild(link);}return;
    }
    input.value='';busy=true;var turn=++assistantTurn;var requestController=new AbortController();chatAbortController=requestController;
    message('user',text);
    voiceTitle.textContent='در حال پاسخ‌گویی';status.textContent='دارم به حرفت فکر می‌کنم…';
    var pending=message('bot','دارم گوش می‌دهم و پاسخ را آماده می‌کنم…');activePendingMessage=pending;panel.classList.add('is-speaking');
    var queueState={queue:[],done:false,notify:null,prefetched:null,speechTurn:++speechGeneration};
    function wakeQueue(){if(queueState.notify){var wake=queueState.notify;queueState.notify=null;wake();}}
    function enqueueSpeech(chunk){chunk=String(chunk||'').trim();if(chunk){queueState.queue.push(chunk);wakeQueue();}}
    async function drainSpeechQueue(){
      while(turn===assistantTurn){
        if(queueState.queue.length){await say(queueState.queue.shift(),queueState);continue;}
        if(queueState.done)return;
        await new Promise(function(resolve){queueState.notify=resolve;});
      }
    }
    var playback=drainSpeechQueue();
    var answer='',pendingSpeech='';
    function acceptDelta(delta){
      if(typeof delta!=='string'||!delta)return;
      answer+=delta;pending.textContent=answer;voiceCaption.textContent='پاسخ در حال تولید است؛ صدا به‌تدریج پخش می‌شود.';
      pendingSpeech+=delta;
      while(true){
        var match=pendingSpeech.match(/^([\s\S]*?[.!?؟۔])(?:\s+|$)/u);
        if(match&&match[1].trim()){
          enqueueSpeech(match[1].trim());pendingSpeech=pendingSpeech.slice(match[0].length).trimStart();continue;
        }
        if(pendingSpeech.length>300){
          var splitAt=pendingSpeech.lastIndexOf(' ',280);
          if(splitAt>40){enqueueSpeech(pendingSpeech.slice(0,splitAt));pendingSpeech=pendingSpeech.slice(splitAt+1);continue;}
        }
        break;
      }
    }
    try{
      var assistantStatusResponse=await fetch('/assistant/voice/status',{credentials:'same-origin',headers:{'Accept':'application/json'},cache:'no-store'});
      var assistantStatus=await assistantStatusResponse.json().catch(function(){return {};});
      if(assistantStatusResponse.ok&&assistantStatus.local_only&&assistantStatus.chat_gateway_ready===false){
        throw new Error(assistantStatus.chat_gateway_message||'درگاه خصوصی Qwen آماده نیست؛ voice-stream/start.cmd را اجرا کن و سلامت سرویس را بررسی کن.');
      }
      var prompt='تو همراه هوشمند وب‌سایت فارسی فراست هستی. به فارسی روان، صمیمی، دقیق و مختصر پاسخ بده. درباره خدمات سایت راهنمایی عملی بده؛ اگر مطمئن نیستی ادعا نکن. مسیرهای شناخته‌شده: فروشگاه /store، سبد /cart، خدمات تایپ /editor، قیمت‌ها /pricing، پشتیبانی /support، فضای کاربری /dashboard. مکالمه اخیر:\n'+history.slice(-8).map(function(m){return (m.role==='user'?'کاربر':'همراه')+': '+m.text;}).join('\n')+'\nکاربر: '+text;
      var response=await fetch('/assistant/chat/stream',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','Accept':'text/event-stream, application/json','X-CSRF-TOKEN':csrf?csrf.content:''},body:JSON.stringify({message:text,context:history.slice(-8),prompt:prompt}),signal:requestController.signal});
      if(turn!==assistantTurn)return;
      if(response.status===401||response.status===419)throw new Error('نشست شما منقضی شده است. لطفاً دوباره وارد حساب کاربری شوید.');
      if(!response.ok){var failure=await response.json().catch(function(){return {};});var serverMessage=typeof failure.message==='string'?failure.message.trim():'';throw new Error(serverMessage||'سرویس گفت‌وگو فعلاً آماده نیست (HTTP '+response.status+').');}
      var contentType=(response.headers.get('Content-Type')||'').toLowerCase();
      if(contentType.indexOf('application/json')!==-1){
        var fallback=await response.json();answer=String(fallback.reply||fallback.text||fallback.message||'پاسخی دریافت نشد.');
        pending.textContent=answer;splitSpeechChunks(answer).forEach(enqueueSpeech);queueState.done=true;wakeQueue();
      }else{
        var reader=response.body.getReader(),decoder=new TextDecoder(),buffer='';
        while(true){
          var part=await reader.read();if(part.done){buffer+=decoder.decode();break;}
          buffer+=decoder.decode(part.value,{stream:true});
          var boundary;
          while((boundary=buffer.indexOf('\n\n'))!==-1){
            var block=buffer.slice(0,boundary);buffer=buffer.slice(boundary+2);
            block.split(/\r?\n/).forEach(function(line){
              if(line.indexOf('data:')!==0)return;
              var event;try{event=JSON.parse(line.slice(5).trim());}catch(ignore){return;}
              if(event.error)throw new Error(event.error);
              if(event.delta)acceptDelta(event.delta);
              if(event.done){if(pendingSpeech.trim())enqueueSpeech(pendingSpeech);pendingSpeech='';queueState.done=true;wakeQueue();}
            });
          }
          if(turn!==assistantTurn)return;
        }
        if(buffer.trim()){
          buffer.split(/\r?\n/).forEach(function(line){if(line.indexOf('data:')!==0)return;try{var event=JSON.parse(line.slice(5).trim());if(event.delta)acceptDelta(event.delta);if(event.error)throw new Error(event.error);if(event.done){if(pendingSpeech.trim())enqueueSpeech(pendingSpeech);pendingSpeech='';queueState.done=true;wakeQueue();}}catch(error){if(error&&error.message)throw error;}});
        }
        if(pendingSpeech.trim())enqueueSpeech(pendingSpeech);
        queueState.done=true;wakeQueue();
      }
      await playback;
      if(turn!==assistantTurn)return;
      if(!answer.trim())throw new Error('پاسخی از سرویس دریافت نشد.');
      history.push({role:'user',text:text},{role:'assistant',text:answer});if(history.length>20)history=history.slice(-20);
      voiceTitle.textContent=gatewayWs?'گوش می‌دهم':'پاسخ فراست';voiceCaption.textContent=gatewayWs?'پاسخ تمام شد؛ میکروفون همچنان روشن است. هر وقت خواستی صحبت کن.':'پاسخ کامل شد؛ برای ادامه دکمهٔ صحبت را لمس کن.';status.textContent=gatewayWs?'در حال گوش دادن؛ می‌توانی بدون لمس دوباره صحبت کنی.':'پاسخ کامل شد.';
    }catch(e){
      queueState.done=true;wakeQueue();
      if(turn!==assistantTurn)return;
      var detail=e&&e.message&&e.message!=='Failed to fetch'?e.message:'ارتباط با مسیر پاسخ‌گویی برقرار نشد؛ اتصال شبکه یا تنظیمات سرویس گفت‌وگو را بررسی کنید.';
      if(answer.trim()){pending.textContent=answer;voiceCaption.textContent='پاسخ متوقف شد؛ بخشی از متن دریافت شد.';}else{pending.textContent='دریافت پاسخ ناموفق بود. '+detail;voiceCaption.textContent=detail;}
      voiceTitle.textContent='پاسخ‌گویی ناموفق بود';status.textContent=detail+' می‌توانی پس از بررسی اتصال دوباره تلاش کنی.';panel.classList.remove('is-speaking');
    }finally{
      if(turn===assistantTurn){busy=false;if(chatAbortController===requestController)chatAbortController=null;if(activePendingMessage===pending)activePendingMessage=null;}
    }
  }
  launcher.addEventListener('click',function(){var opening=panel.hidden;if(!opening){stopConversation();if(assistantAudio){assistantAudio.pause();assistantAudio=null;}if(assistantAudioUrl){URL.revokeObjectURL(assistantAudioUrl);assistantAudioUrl=null;}setOpen(false);return;}setOpen(true);loadHistory();loadAssistantStatus();if(document.body.dataset.assistantAuth==='1'){conversationActive=true;liveTurnEnding=false;voiceTitle.textContent='آمادهٔ گفت‌وگو هستم';voiceCaption.textContent='میکروفون خاموش است. برای شروع، دکمهٔ «صحبت کن» را لمس کن.';status.textContent='تا وقتی دکمه را لمس نکنی، میکروفون فعال نمی‌شود.';}});
  root.querySelector('.farast-assistant-close').addEventListener('click',function(){stopConversation();if(assistantAudio){assistantAudio.pause();assistantAudio=null;}if(assistantAudioUrl){URL.revokeObjectURL(assistantAudioUrl);assistantAudioUrl=null;}setOpen(false);});
  root.querySelector('[data-fa-stop-speech]').addEventListener('click',function(){stopConversation();if(assistantAudio){assistantAudio.pause();assistantAudio.currentTime=0;assistantAudio=null;}if(assistantAudioUrl){URL.revokeObjectURL(assistantAudioUrl);assistantAudioUrl=null;}panel.classList.remove('is-speaking');voiceTitle.textContent='مکالمه متوقف شد';status.textContent='گفت‌وگوی پیوسته متوقف شد.';voiceCaption.textContent='برای شروع دوباره، میکروفون را لمس کن.';});
  root.querySelector('.farast-assistant-clear').addEventListener('click',clearHistory);
  root.querySelector('[data-fa-memory]').addEventListener('click',saveMemory);
  root.querySelector('[data-fa-memory-manage]').addEventListener('click',manageMemories);
  form.addEventListener('submit',function(e){e.preventDefault();send(input.value);});
  root.querySelectorAll('[data-fa-text]').forEach(function(b){b.addEventListener('click',function(){send(b.getAttribute('data-fa-text'));});});
  root.querySelector('[data-fa-tour]').addEventListener('click',startTour);
  var liveTurnEnding=false,gatewayStatusMessage='';
  var gatewayWs=null,gatewayMediaStream=null,gatewayAudioContext=null,gatewaySourceNode=null,gatewayWorkletNode=null,gatewayScriptProcessorNode=null,gatewayPcmBuffer=[],gatewayStarting=false,gatewayFinalText='',gatewaySilenceTimer=null,gatewayLastVoiceAt=0,gatewayVoiceFrames=0,gatewayGeneration=0,gatewayStopTimer=null,gatewayStopping=false;
  function releaseGatewayCapture(){
    if(gatewayWorkletNode){try{gatewayWorkletNode.disconnect();}catch(e){}gatewayWorkletNode=null;}
    if(gatewayScriptProcessorNode){try{gatewayScriptProcessorNode.disconnect();gatewayScriptProcessorNode.onaudioprocess=null;}catch(e){}gatewayScriptProcessorNode=null;}
    gatewayPcmBuffer=[];
    if(gatewaySourceNode){try{gatewaySourceNode.disconnect();}catch(e){}gatewaySourceNode=null;}
    if(gatewayAudioContext){try{gatewayAudioContext.close();}catch(e){}gatewayAudioContext=null;}
    if(gatewayMediaStream){gatewayMediaStream.getTracks().forEach(function(track){track.stop();});gatewayMediaStream=null;}
  }
  function stopGatewayRecognition(flushFinal){
    var socket=gatewayWs;
    if(flushFinal&&socket&&socket.readyState===WebSocket.OPEN){
      gatewayWs=null;gatewayStopping=true;gatewayStarting=true;
      if(gatewaySilenceTimer){window.clearTimeout(gatewaySilenceTimer);gatewaySilenceTimer=null;}
      releaseGatewayCapture();
      try{socket.send(JSON.stringify({type:'stop'}));}catch(e){}
      if(gatewayStopTimer)window.clearTimeout(gatewayStopTimer);
      gatewayStopTimer=window.setTimeout(function(){
        if(!gatewayStopping)return;
        gatewayStopping=false;gatewayStarting=false;gatewayGeneration++;
        try{socket.onopen=socket.onmessage=socket.onerror=socket.onclose=null;socket.close(1000,'assistant_stop_timeout');}catch(e){}
        gatewayFinalText='';gatewayLastVoiceAt=0;gatewayVoiceFrames=0;
        voiceTitle.textContent='میکروفون خاموش است';voiceCaption.textContent='برای شروع دوباره، دکمهٔ «صحبت کن» را لمس کن.';status.textContent='میکروفون خاموش شد.';
      },8000);
      return;
    }
    gatewayGeneration++;gatewayStarting=false;gatewayStopping=false;
    if(gatewayStopTimer){window.clearTimeout(gatewayStopTimer);gatewayStopTimer=null;}
    if(gatewaySilenceTimer){window.clearTimeout(gatewaySilenceTimer);gatewaySilenceTimer=null;}
    releaseGatewayCapture();
    gatewayWs=null;
    if(socket){try{socket.onopen=socket.onmessage=socket.onerror=socket.onclose=null;if(socket.readyState===WebSocket.OPEN)socket.send(JSON.stringify({type:'stop'}));socket.close(1000,'assistant_turn_end');}catch(e){}}
    gatewayFinalText='';gatewayLastVoiceAt=0;gatewayVoiceFrames=0;
  }
  function scheduleGatewayTurnEnd(generation){if(generation!==gatewayGeneration||!gatewayFinalText.trim()||!gatewayLastVoiceAt)return;if(gatewaySilenceTimer)window.clearTimeout(gatewaySilenceTimer);var remaining=Math.max(0,1400-(Date.now()-gatewayLastVoiceAt));gatewaySilenceTimer=window.setTimeout(function(){finishGatewayTurn(generation);},remaining);}
  function noteGatewayAudioActivity(generation,pcm){if(generation!==gatewayGeneration||!pcm)return;var samples=pcm instanceof Int16Array?pcm:new Int16Array(pcm);if(!samples.length)return;var total=0;for(var i=0;i<samples.length;i++)total+=Math.abs(samples[i]);var level=total/samples.length;if(level<420){gatewayVoiceFrames=0;return;}gatewayLastVoiceAt=Date.now();if(level>=650)gatewayVoiceFrames=Math.min(6,gatewayVoiceFrames+1);else gatewayVoiceFrames=0;if(busy&&gatewayVoiceFrames>=3)interruptAssistantForBargeIn();scheduleGatewayTurnEnd(generation);}
  async function startBatchRecording(){
    if(batchRecording)return true;
    if(!navigator.mediaDevices||!navigator.mediaDevices.getUserMedia||!window.MediaRecorder){
      gatewayStatusMessage='این مرورگر ضبط صوت را پشتیبانی نمی‌کند یا صفحه در بستر امن HTTPS اجرا نشده است.';
      return false;
    }
    try{
      var readinessResponse=await fetch('/assistant/voice/status',{credentials:'same-origin',headers:{'Accept':'application/json'},cache:'no-store'});
      var readiness=await readinessResponse.json().catch(function(){return {};});
      if(!readinessResponse.ok||!readiness.remote_batch_enabled||!readiness.server_stt){
        gatewayStatusMessage=readiness.chat_gateway_message||'سرویس تشخیص گفتار دسته‌ای Render فعال نیست؛ تنظیمات ASR و اجازه ارسال دسته‌ای را بررسی کن.';
        return false;
      }
      var stream=await navigator.mediaDevices.getUserMedia({audio:{channelCount:1,echoCancellation:true,noiseSuppression:true,autoGainControl:true},video:false});
      var mimeCandidates=['audio/webm;codecs=opus','audio/webm','audio/mp4'];
      var options={};
      for(var i=0;i<mimeCandidates.length;i++){if(MediaRecorder.isTypeSupported&&MediaRecorder.isTypeSupported(mimeCandidates[i])){options.mimeType=mimeCandidates[i];break;}}
      var recorder=new MediaRecorder(stream,options);
      batchMediaStream=stream;batchRecorder=recorder;batchChunks=[];batchRecording=true;
      recorder.ondataavailable=function(event){if(event.data&&event.data.size)batchChunks.push(event.data);};
      recorder.onerror=function(){gatewayStatusMessage='ضبط صدا در مرورگر با خطا متوقف شد.';};
      recorder.onstop=async function(){
        if(batchStopTimer){window.clearTimeout(batchStopTimer);batchStopTimer=null;}
        var chunks=batchChunks.slice(),blobType=recorder.mimeType||'audio/webm';
        batchChunks=[];batchRecorder=null;batchRecording=false;
        if(batchMediaStream){batchMediaStream.getTracks().forEach(function(track){track.stop();});batchMediaStream=null;}
        panel.classList.remove('is-listening');root.querySelector('.farast-assistant-mic').classList.remove('is-recording');
        if(!chunks.length){status.textContent='صدایی ضبط نشد؛ دوباره تلاش کن.';voiceTitle.textContent='ضبط خالی بود';return;}
        var blob=new Blob(chunks,{type:blobType});
        if(!blob.size){status.textContent='فایل ضبط‌شده خالی است؛ دوباره تلاش کن.';return;}
        voiceTitle.textContent='در حال تشخیص گفتار';voiceCaption.textContent='صدا به سرویس تشخیص گفتار Render ارسال می‌شود.';status.textContent='در حال تبدیل صدا به متن…';
        try{
          var formData=new FormData();
          var extension=blobType.indexOf('mp4')!==-1?'mp4':'webm';
          formData.append('audio',blob,'farast-assistant.'+extension);
          formData.append('locale','fa-IR');
          var response=await fetch('/assistant/transcribe',{method:'POST',credentials:'same-origin',headers:{'Accept':'application/json','X-CSRF-TOKEN':csrf?csrf.content:''},body:formData});
          var data=await response.json().catch(function(){return {};});
          if(!response.ok||!data.ok||!String(data.text||'').trim())throw new Error(data.message||'تشخیص گفتار از طریق Render ناموفق بود (HTTP '+response.status+').');
          status.textContent='متن تشخیص داده شد؛ در حال ارسال به دستیار…';
          send(String(data.text).trim());
        }catch(error){
          voiceTitle.textContent='تشخیص گفتار ناموفق بود';
          voiceCaption.textContent=error&&error.message?error.message:'ارتباط با سرویس تشخیص گفتار برقرار نشد.';
          status.textContent=voiceCaption.textContent;
        }
      };
      recorder.start(1000);
      panel.classList.add('is-listening');
      root.querySelector('.farast-assistant-mic').classList.add('is-recording');
      voiceTitle.textContent='در حال ضبط';voiceCaption.textContent='صحبت کن؛ برای پایان ضبط، دکمه میکروفون را دوباره لمس کن.';
      status.textContent='ضبط محلی مرورگر فعال است؛ فایل فقط پس از توقف برای تشخیص گفتار به Render فرستاده می‌شود.';
      batchStopTimer=window.setTimeout(function(){if(batchRecorder&&batchRecorder.state!=='inactive')stopBatchRecording();},60000);
      return true;
    }catch(error){
      if(batchMediaStream){batchMediaStream.getTracks().forEach(function(track){track.stop();});batchMediaStream=null;}
      batchRecorder=null;batchChunks=[];batchRecording=false;
      if(error&&(error.name==='NotAllowedError'||error.name==='SecurityError'))gatewayStatusMessage='دسترسی میکروفون مجاز نیست؛ مجوز میکروفون مرورگر و HTTPS را بررسی کن.';
      else gatewayStatusMessage=error&&error.message?error.message:'شروع ضبط صدا ناموفق بود.';
      return false;
    }
  }
  function stopBatchRecording(){
    if(!batchRecorder||batchRecorder.state==='inactive')return;
    if(batchStopTimer){window.clearTimeout(batchStopTimer);batchStopTimer=null;}
    voiceTitle.textContent='در حال پایان ضبط';voiceCaption.textContent='چند لحظه صبر کن تا صدا ارسال و تبدیل شود.';status.textContent='در حال نهایی‌کردن فایل صوتی…';
    try{batchRecorder.stop();}catch(error){gatewayStatusMessage='توقف ضبط ناموفق بود.';}
  }
  async function startGatewayLiveRecognition(){
    if(gatewayStarting||gatewayWs)return true;
    var AudioContextClass=window.AudioContext||window.webkitAudioContext;
    if(!window.WebSocket||!AudioContextClass||(!window.AudioWorkletNode&&!(AudioContextClass.prototype&&AudioContextClass.prototype.createScriptProcessor))||!navigator.mediaDevices||!navigator.mediaDevices.getUserMedia)return false;
    gatewayStarting=true;var generation=++gatewayGeneration;
    try{
      // Check server readiness before requesting microphone permission. This avoids
      // opening the microphone when this deployment has no live gateway configured.
      var statusResponse=await fetch('/editor/voice/status',{credentials:'same-origin',headers:{'Accept':'application/json'},cache:'no-store'});
      var service=await statusResponse.json().catch(function(){return {};});
      if(!statusResponse.ok||service.mode!=='gateway'){gatewayStatusMessage=String(service.message||'');if(!gatewayStatusMessage&&service.reason==='local_voice_gateway_unavailable')gatewayStatusMessage='درگاه زنده Sherpa آماده نیست.';if(!gatewayStatusMessage)gatewayStatusMessage='درگاه گفتار زنده هنوز آماده نیست.';stopGatewayRecognition();return await startBatchRecording();}gatewayStatusMessage='';
      var tokenResponse=await fetch('/assistant/voice/stream-token',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':csrf?csrf.content:''},body:JSON.stringify({locale:'fa-IR'})});
      var tokenData=await tokenResponse.json().catch(function(){return {};});
      if(!tokenResponse.ok||!tokenData.token||!tokenData.websocket_url)throw new Error(tokenData.message||'درگاه تشخیص زنده آماده نیست.');
      if(generation!==gatewayGeneration||!conversationActive||panel.hidden){gatewayStarting=false;return true;}
      var wsUrl=tokenData.websocket_url.replace(/^http:/,'ws:').replace(/^https:/,'wss:').replace(/\/$/,'');
      // Laravel intentionally stores the gateway origin; the ASGI stream lives at /v1/stream.
      // Accept a full endpoint too, so deployments that already include the path are not doubled.
      if(!/\/v1\/stream(?:\?|$)/.test(wsUrl))wsUrl+='/v1/stream';
      var socket=new WebSocket(wsUrl);gatewayWs=socket;socket.binaryType='arraybuffer';
      await new Promise(function(resolve,reject){
        var settled=false;var timer=window.setTimeout(function(){finish(new Error('مهلت اتصال درگاه صوتی تمام شد.'));},12000);
        function finish(error){if(settled)return;settled=true;window.clearTimeout(timer);socket.removeEventListener('open',opened);socket.removeEventListener('error',failed);if(error)reject(error);else resolve();}
        function opened(){finish();}
        function failed(){finish(new Error('اتصال درگاه گفتار برقرار نشد.'));}
        socket.addEventListener('open',opened,{once:true});socket.addEventListener('error',failed,{once:true});
      });
      if(generation!==gatewayGeneration||!conversationActive||panel.hidden){stopGatewayRecognition();return true;}
      var providerReadyResolve=null,providerReadyReject=null;
      var providerReadyPromise=new Promise(function(resolve,reject){providerReadyResolve=resolve;providerReadyReject=reject;});
      var providerReadyTimer=window.setTimeout(function(){if(providerReadyReject)providerReadyReject(new Error('موتور تشخیص گفتار روی سرور در زمان مقرر آماده نشد.'));},15000);
      socket.onmessage=function(event){
        if(generation!==gatewayGeneration||typeof event.data!=='string')return;var data;try{data=JSON.parse(event.data);}catch(e){return;}
        if(data.type==='ready'){voiceTitle.textContent='گوش می‌دهم';voiceCaption.textContent='مدل گفتار فقط روی سرور اجرا می‌شود؛ هیچ مدل یا بستهٔ زبانی روی دستگاه دانلود نمی‌شود. میکروفون در حال فعال‌سازی است.';status.textContent='گفت‌وگوی زنده فعال می‌شود.';panel.classList.add('is-listening');root.querySelector('.farast-assistant-mic').classList.add('is-recording');if(providerReadyResolve){providerReadyResolve();providerReadyResolve=null;providerReadyReject=null;}}
        else if(data.type==='interim'){var interimText=String(data.text||'').trim();if(busy&&interimText.length>=2)interruptAssistantForBargeIn();var visible=(gatewayFinalText+' '+interimText).trim();if(visible){voiceCaption.textContent=visible;status.textContent='در حال تشخیص زندهٔ گفتار…';}}
        else if(data.type==='final'){var finalPart=String(data.text||'').trim();if(finalPart&&busy)interruptAssistantForBargeIn();if(finalPart)gatewayFinalText+=(gatewayFinalText?' ':'')+finalPart;if(gatewayFinalText.trim())scheduleGatewayTurnEnd(generation);}
        else if(data.type==='provider_error'){if(providerReadyReject)providerReadyReject(new Error('موتور تشخیص گفتار پاسخ موفق نداد.'));stopGatewayRecognition();panel.classList.remove('is-listening');root.querySelector('.farast-assistant-mic').classList.remove('is-recording');voiceTitle.textContent='میکروفون خاموش است';voiceCaption.textContent='پردازش این بخش از صدا ناموفق بود؛ برای تلاش دوباره دکمهٔ «صحبت کن» را لمس کن.';status.textContent='موتور تشخیص گفتار پاسخ موفق نداد؛ میکروفون خاموش شد و تغییر موتور خودکار انجام نشد.';}
        else if(data.type==='error'){if(providerReadyReject)providerReadyReject(new Error('اتصال موتور تشخیص گفتار قطع شد.'));stopGatewayRecognition();panel.classList.remove('is-listening');root.querySelector('.farast-assistant-mic').classList.remove('is-recording');voiceTitle.textContent='میکروفون خاموش است';voiceCaption.textContent='اتصال گفتار قطع شد؛ برای تلاش دوباره دکمهٔ «صحبت کن» را لمس کن.';status.textContent='میکروفون برای حفظ حریم خصوصی خاموش شد.';}
      };
      socket.onerror=function(){if(providerReadyReject)providerReadyReject(new Error('اتصال درگاه گفتار برقرار نشد.'));if(generation===gatewayGeneration&&conversationActive){stopGatewayRecognition();panel.classList.remove('is-listening');root.querySelector('.farast-assistant-mic').classList.remove('is-recording');voiceTitle.textContent='میکروفون خاموش است';voiceCaption.textContent='اتصال گفتار برقرار نشد؛ برای تلاش دوباره دکمهٔ «صحبت کن» را لمس کن.';status.textContent='میکروفون خاموش شد؛ اتصال را دوباره امتحان کن.';}};
      socket.onclose=function(){if(providerReadyReject)providerReadyReject(new Error('درگاه گفتار پیش از آماده‌شدن بسته شد.'));if(generation!==gatewayGeneration)return;if(gatewayStopping){if(gatewayStopTimer){window.clearTimeout(gatewayStopTimer);gatewayStopTimer=null;}if(gatewayFinalText.trim())finishGatewayTurn(generation);else{voiceTitle.textContent='میکروفون خاموش است';voiceCaption.textContent='برای شروع دوباره، دکمهٔ «صحبت کن» را لمس کن.';status.textContent='میکروفون خاموش شد.';}gatewayStopping=false;gatewayStarting=false;gatewayGeneration++;return;}if(conversationActive&&!liveTurnEnding){stopGatewayRecognition();panel.classList.remove('is-listening');root.querySelector('.farast-assistant-mic').classList.remove('is-recording');voiceTitle.textContent='میکروفون خاموش است';voiceCaption.textContent='اتصال گفتار قطع شد؛ برای شروع دوباره دکمهٔ «صحبت کن» را لمس کن.';status.textContent='میکروفون خاموش شد؛ برای ادامه دوباره لمس کن.';}};
      socket.send(JSON.stringify({type:'start',token:tokenData.token,locale:'fa-IR',sequence:0}));
      try{await providerReadyPromise;}finally{window.clearTimeout(providerReadyTimer);providerReadyResolve=null;providerReadyReject=null;}
      if(generation!==gatewayGeneration||!conversationActive||panel.hidden){stopGatewayRecognition();return true;}
      gatewayMediaStream=await navigator.mediaDevices.getUserMedia({audio:{channelCount:1,echoCancellation:true,noiseSuppression:true,autoGainControl:true},video:false});
      if(generation!==gatewayGeneration||!conversationActive||panel.hidden){stopGatewayRecognition();return true;}
      gatewayAudioContext=new(window.AudioContext||window.webkitAudioContext)({latencyHint:'interactive',sampleRate:48000});
      gatewaySourceNode=gatewayAudioContext.createMediaStreamSource(gatewayMediaStream);
      if(window.AudioWorkletNode&&gatewayAudioContext.audioWorklet&&typeof gatewayAudioContext.audioWorklet.addModule==='function'){
        await gatewayAudioContext.audioWorklet.addModule('/js/farast-voice-worklet.js?v=20261031vad2');
        if(generation!==gatewayGeneration||!conversationActive||panel.hidden){stopGatewayRecognition();return true;}
        gatewayWorkletNode=new AudioWorkletNode(gatewayAudioContext,'farast-pcm-processor',{numberOfInputs:1,numberOfOutputs:1,outputChannelCount:[1]});
        gatewayWorkletNode.port.onmessage=function(event){if(generation!==gatewayGeneration||socket.readyState!==WebSocket.OPEN||!conversationActive)return;var bytes=new Uint8Array(event.data);if(bytes.byteLength>24000){for(var i=0;i<bytes.byteLength;i+=24000){socket.send(bytes.slice(i,Math.min(i+24000,bytes.byteLength)));noteGatewayAudioActivity(generation,bytes.slice(i,Math.min(i+24000,bytes.byteLength)).buffer);}}else if(bytes.byteLength){socket.send(bytes);noteGatewayAudioActivity(generation,event.data);}};
        gatewaySourceNode.connect(gatewayWorkletNode);gatewayWorkletNode.connect(gatewayAudioContext.destination);
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
      // A failed live connection must never downgrade to a two-step recording flow.
      panel.classList.remove('is-listening');
      root.querySelector('.farast-assistant-mic').classList.remove('is-recording');
      voiceTitle.textContent='میکروفون خاموش است';
      voiceCaption.textContent='اتصال گفتار برقرار نشد؛ برای تلاش دوباره دکمهٔ «صحبت کن» را لمس کن.';
      status.textContent='میکروفون خاموش شد؛ اتصال زنده را بررسی کن و دوباره تلاش کن.';
      return false;
    }
  }
  function finishGatewayTurn(generation){
    if(generation!==gatewayGeneration||!conversationActive||liveTurnEnding)return;
    if(gatewaySilenceTimer){window.clearTimeout(gatewaySilenceTimer);gatewaySilenceTimer=null;}
    var text=gatewayFinalText.trim();if(!text)return;
    liveTurnEnding=true;gatewayFinalText='';gatewayLastVoiceAt=0;gatewayVoiceFrames=0;
    // Keep the same authenticated stream and microphone open during generation and playback.
    // This allows server-side interim ASR to detect a new user utterance and barge in.
    liveTurnEnding=false;send(text);
  }
  function enableTextFallback(reason){
    form.hidden=false;list.hidden=false;
    input.placeholder='پیامت را اینجا بنویس…';input.setAttribute('aria-label','پیام متنی شما');
    voiceTitle.textContent='ورودی متنی در دسترس است';
    voiceCaption.textContent=reason||'این مرورگر امکان ضبط یا تشخیص گفتار را فراهم نکرد؛ می‌توانی با متن ادامه بدهی.';
    status.textContent='ورودی متنی در دسترس است؛ اتصال موتور پاسخ‌گویی هنگام ارسال بررسی می‌شود.';
  }
  function stopLiveRecognition(){stopGatewayRecognition();liveTurnEnding=true;}
  function startLiveRecognition(){
    if(!conversationActive||panel.hidden||document.body.dataset.assistantAuth!=='1')return;
    if(gatewayWs||gatewayStarting)return;
    liveTurnEnding=false;
    startGatewayLiveRecognition().then(function(started){
      if(!started&&conversationActive&&!panel.hidden){
        conversationActive=false;
        enableTextFallback((gatewayStatusMessage||'درگاه گفتار زنده در دسترس نیست.')+' ضبط دومرحله‌ای به‌طور خودکار فعال نمی‌شود؛ برای تلاش دوباره دکمهٔ میکروفون را لمس کن.');
      }
    }).catch(function(error){
      if(!conversationActive||panel.hidden)return;
      conversationActive=false;
      enableTextFallback((error&&error.message?error.message:'درگاه گفتار زنده پاسخ نداد.')+' ضبط دومرحله‌ای به‌طور خودکار فعال نمی‌شود؛ برای تلاش دوباره دکمهٔ میکروفون را لمس کن.');
    });
  }
  root.querySelector('.farast-assistant-mic').addEventListener('click',function(){
    if(batchRecording){stopBatchRecording();return;}
    if(gatewayWs||gatewayStarting){
      if(busy){interruptAssistantForBargeIn();if(gatewayFinalText.trim())scheduleGatewayTurnEnd(gatewayGeneration);return;}
      if(gatewayFinalText.trim()){finishGatewayTurn(gatewayGeneration);return;}
      stopGatewayRecognition(true);panel.classList.remove('is-listening');root.querySelector('.farast-assistant-mic').classList.remove('is-recording');status.textContent='در حال نهایی‌کردن آخرین جمله…';voiceTitle.textContent='میکروفون در حال خاموش‌شدن';voiceCaption.textContent='آخرین بخش گفتار روی سرور نهایی می‌شود.';return;
    }
    if(busy){interruptAssistantForBargeIn();}
    if(resumeListeningTimer){window.clearTimeout(resumeListeningTimer);resumeListeningTimer=null;}
    if(speechRequestController){speechRequestController.abort();speechRequestController=null;}
    speechGeneration++;
    if(assistantAudio){assistantAudio.pause();assistantAudio.currentTime=0;assistantAudio=null;}
    if(assistantAudioUrl){URL.revokeObjectURL(assistantAudioUrl);assistantAudioUrl=null;}
    panel.classList.remove('is-speaking');
    conversationActive=true;liveTurnEnding=false;voiceTitle.textContent='شروع گفت‌وگوی زنده';voiceCaption.textContent='گفتار به‌صورت زنده تشخیص داده می‌شود و بعد از مکث کوتاه پاسخ می‌دهم. برای پایان، «توقف مکالمه» را لمس کن.';startLiveRecognition();
  });
  document.addEventListener('keydown',function(e){if(e.key==='Escape'&&!panel.hidden){stopConversation();if(assistantAudio){assistantAudio.pause();assistantAudio=null;}if(assistantAudioUrl){URL.revokeObjectURL(assistantAudioUrl);assistantAudioUrl=null;}setOpen(false);}});
  voiceCaption.textContent='برای شروع، میکروفون را لمس کن و به فارسی صحبت کن.';
})();