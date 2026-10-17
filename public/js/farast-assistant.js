/* FARAST conversational companion. Authenticated requests use the server AI pipeline; speech is progressive-enhancement. */
(function () {
  'use strict';
  if (document.getElementById('farast-global-assistant')) return;
  var root = document.createElement('div');
  root.id = 'farast-global-assistant';
  root.innerHTML =
    '<button class="farast-assistant-launcher" type="button" aria-label="گفت‌وگو با همراه فراست" aria-expanded="false" title="همراه هوشمند فراست"><span class="fa-solid fa-sparkles" aria-hidden="true"></span><span class="farast-assistant-pulse" aria-hidden="true"></span></button>' +
    '<section class="farast-assistant-panel" aria-label="همراه هوشمند فراست" aria-modal="false" hidden>' +
    '<header class="farast-assistant-head"><div class="farast-assistant-avatar"><i class="fa-solid fa-microphone-lines" aria-hidden="true"></i></div><div class="farast-assistant-brand"><strong>همراه فراست</strong><small><span class="farast-assistant-online"></span> راهنمای هوشمند شما</small></div><button class="farast-assistant-memory" type="button" data-fa-memory title="ذخیره نکته شخصی برای گفتگوهای بعدی">حافظه +</button><button class="farast-assistant-clear" type="button" aria-label="پاک کردن سابقه" title="پاک کردن سابقه گفتگو">پاک‌کردن سابقه</button><button class="farast-assistant-close" type="button" aria-label="بستن">×</button></header>' +
    '<div class="farast-assistant-intro"><b>سلام، خوش اومدی 👋</b><p>بگو دنبال چه چیزی هستی؛ قدم‌به‌قدم کنارت هستم.</p></div>' +
    '<div class="farast-assistant-messages" data-fa-messages aria-live="polite" aria-relevant="additions"></div>' +
    '<div class="farast-assistant-suggestions"><button type="button" data-fa-tour>🧭 راهنمای تعاملی سایت</button><button type="button" data-fa-text="برای خرید فایل راهنمایی‌ام کن">🛍️ خرید فایل</button><button type="button" data-fa-text="چطور از خدمات تایپ استفاده کنم؟">✍️ خدمات تایپ</button><button type="button" data-fa-text="قیمت خدمات چقدر است؟">💎 قیمت‌ها</button></div>' +
    '<div class="farast-assistant-status" data-fa-status role="status">برای گفت‌وگو وارد حساب کاربری‌ات شو.</div>' +
    '<form class="farast-assistant-form" autocomplete="off"><button class="farast-assistant-mic" type="button" aria-label="شروع گفتار" title="گفتار به متن"><i class="fa-solid fa-microphone"></i></button><input name="message" maxlength="1200" placeholder="اینجا بنویس یا صحبت کن…" aria-label="پیام شما"><button class="farast-assistant-send" type="submit" aria-label="ارسال پیام"><i class="fa-solid fa-arrow-up"></i></button></form>' +
    '<footer class="farast-assistant-foot">پاسخ‌های هوش مصنوعی ممکن است نیاز به بررسی داشته باشند.</footer></section>';
  document.body.appendChild(root);
  var panel=root.querySelector('.farast-assistant-panel'), launcher=root.querySelector('.farast-assistant-launcher'),
      list=root.querySelector('[data-fa-messages]'), form=root.querySelector('form'), input=form.querySelector('input'),
      status=root.querySelector('[data-fa-status]'), busy=false, recognition=null, history=[], historyLoaded=false;
  var csrf=document.querySelector('meta[name="csrf-token"]');
  function esc(s){return String(s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];});}
  function message(role,text){var el=document.createElement('div');el.className='farast-assistant-msg '+role;el.textContent=String(text||'');list.appendChild(el);list.scrollTop=list.scrollHeight;return el;}
  function setOpen(v){panel.hidden=!v;panel.classList.toggle('is-open',v);launcher.setAttribute('aria-expanded',v?'true':'false');if(v)setTimeout(function(){input.focus();},120);}
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
  function say(text){if(!('speechSynthesis' in window))return;try{window.speechSynthesis.cancel();var u=new SpeechSynthesisUtterance(text);u.lang='fa-IR';u.rate=.94;u.pitch=.88;var voices=window.speechSynthesis.getVoices();var fa=voices.filter(function(v){return /^fa(-|$)/i.test(v.lang);});var preferred=fa.find(function(v){return /male|arman|farid|persian/i.test(v.name);});if(preferred)u.voice=preferred;window.speechSynthesis.speak(u);}catch(e){}}
  async function send(text){
    text=String(text||'').trim();if(!text||busy)return;
    if(document.body.dataset.assistantAuth !== '1'){
      message('bot','برای گفت‌وگو و نگهداری سابقه، ابتدا وارد حساب کاربری‌ات شو.');
      status.textContent='ورود برای استفاده از همراه هوشمند الزامی است.';
      var link=document.createElement('a');link.href='/login?redirect='+encodeURIComponent(location.pathname+location.search);link.className='farast-assistant-login';link.textContent='ورود به حساب کاربری';list.appendChild(link);list.scrollTop=list.scrollHeight;return;
    }
    message('user',text);input.value='';busy=true;status.textContent='دارم با دقت فکر می‌کنم…';var pending=message('bot','…');
    try{
      var prompt='تو همراه هوشمند وب‌سایت فارسی فراست هستی. به فارسی روان، صمیمی، دقیق و مختصر پاسخ بده. درباره خدمات سایت راهنمایی عملی بده؛ اگر مطمئن نیستی ادعا نکن. مسیرهای شناخته‌شده: فروشگاه /store، سبد /cart، خدمات تایپ /editor، قیمت‌ها /pricing، پشتیبانی /support، فضای کاربر /dashboard. مکالمه اخیر:\n'+history.slice(-8).map(function(m){return (m.role==='user'?'کاربر':'همراه')+': '+m.text;}).join('\n')+'\nکاربر: '+text;
      var response=await fetch('/assistant/chat',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':csrf?csrf.content:''},body:JSON.stringify({message:text,context:history.slice(-8),prompt:prompt})});
      var data=await response.json().catch(function(){return {};});
      if(response.status===401||response.status===419){pending.textContent='نشست شما منقضی شده است؛ دوباره وارد حساب شوید.';status.textContent='ورود مجدد لازم است.';}
      else if(!response.ok)throw new Error(data.message||'سرویس گفت‌وگو فعلاً آماده نیست.');
      else {var answer=String(data.reply||data.text||data.message||'پاسخ خالی دریافت شد.');pending.textContent=answer;history.push({role:'user',text:text},{role:'assistant',text:answer});if(history.length>20)history=history.slice(-20);status.textContent='آماده‌ام؛ هر وقت خواستی ادامه بده.';say(answer);}
    }catch(e){pending.textContent='الان نتوانستم به سرویس گفت‌وگو وصل شوم. لطفاً کمی بعد دوباره تلاش کن.';status.textContent='اتصال برقرار نشد.';}
    finally{busy=false;list.scrollTop=list.scrollHeight;}
  }
  launcher.addEventListener('click',function(){setOpen(panel.hidden);if(panel.hidden===false)loadHistory();});
  root.querySelector('.farast-assistant-close').addEventListener('click',function(){setOpen(false);});
  root.querySelector('.farast-assistant-clear').addEventListener('click',clearHistory);
  root.querySelector('[data-fa-memory]').addEventListener('click',saveMemory);
  root.querySelector('.farast-assistant-brand').addEventListener('dblclick',manageMemories);
  form.addEventListener('submit',function(e){e.preventDefault();send(input.value);});
  root.querySelectorAll('[data-fa-text]').forEach(function(b){b.addEventListener('click',function(){send(b.getAttribute('data-fa-text'));});});
  root.querySelector('[data-fa-tour]').addEventListener('click',startTour);
  var mediaRecorder=null, mediaStream=null, recordedChunks=[], recordingTimeout=null;
  function stopMediaTracks(){if(mediaStream){mediaStream.getTracks().forEach(function(track){track.stop();});mediaStream=null;}}
  function startBrowserRecognition(){
    var SR=window.SpeechRecognition||window.webkitSpeechRecognition;
    if(!SR){status.textContent='تشخیص گفتار در این مرورگر فعال نیست؛ لطفاً پیام را تایپ کن.';return;}
    try{
      if(recognition){recognition.stop();recognition=null;return;}
      recognition=new SR();recognition.lang='fa-IR';recognition.interimResults=true;recognition.continuous=false;
      status.textContent='گوش می‌دهم؛ برای توقف، دوباره روی میکروفون بزن.';
      recognition.onresult=function(e){var t='';for(var i=e.resultIndex;i<e.results.length;i++)t+=e.results[i][0].transcript;input.value=t;if(e.results[e.results.length-1].isFinal){recognition=null;send(t);}};
      recognition.onerror=function(){status.textContent='تشخیص گفتار ناموفق بود؛ مجوز میکروفون را بررسی یا پیام را تایپ کن.';recognition=null;};
      recognition.onend=function(){recognition=null;if(status.textContent.indexOf('گوش می‌دهم')===0)status.textContent='آماده‌ام.';};
      recognition.start();
    }catch(e){recognition=null;status.textContent='برای میکروفون، مجوز مرورگر را فعال کن.';}
  }
  async function startServerRecording(){
    if(document.body.dataset.assistantAuth!=='1'){status.textContent='برای استفاده از میکروفون ابتدا وارد حساب کاربری شو.';return;}
    var config=null;
    try{var sr=await fetch('/assistant/voice/status',{credentials:'same-origin',headers:{'Accept':'application/json'}});if(sr.ok)config=await sr.json();}catch(e){}
    if(!config||!config.server_stt||!navigator.mediaDevices||!navigator.mediaDevices.getUserMedia||!window.MediaRecorder){startBrowserRecognition();return;}
    try{
      mediaStream=await navigator.mediaDevices.getUserMedia({audio:{echoCancellation:true,noiseSuppression:true,autoGainControl:true}});
      var mime='audio/webm';
      if(window.MediaRecorder.isTypeSupported('audio/webm;codecs=opus'))mime='audio/webm;codecs=opus';
      else if(window.MediaRecorder.isTypeSupported('audio/mp4'))mime='audio/mp4';
      mediaRecorder=new MediaRecorder(mediaStream,window.MediaRecorder.isTypeSupported(mime)?{mimeType:mime}:{});
      recordedChunks=[];
      mediaRecorder.ondataavailable=function(e){if(e.data&&e.data.size)recordedChunks.push(e.data);};
      mediaRecorder.onerror=function(){status.textContent='ضبط صدا ناموفق بود؛ دوباره تلاش کن.';stopMediaTracks();mediaRecorder=null;};
      mediaRecorder.onstop=async function(){
        root.querySelector('.farast-assistant-mic').classList.remove('is-recording');
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
          input.value=String(data.text).trim();status.textContent='گفتار به متن تبدیل شد؛ در حال ارسال پیام…';send(input.value);
        }catch(e){status.textContent=e.message||'تشخیص گفتار فعلاً در دسترس نیست؛ پیام را تایپ کن.';}
      };
      mediaRecorder.start(250);
      status.textContent='گوش می‌دهم؛ برای توقف دوباره روی میکروفون بزن. صدای ضبط‌شده برای رونویسی به سرویس بیرونی ارسال می‌شود.';
      root.querySelector('.farast-assistant-mic').classList.add('is-recording');
      recordingTimeout=window.setTimeout(function(){if(mediaRecorder&&mediaRecorder.state==='recording')mediaRecorder.stop();},25000);
    }catch(e){stopMediaTracks();mediaRecorder=null;status.textContent='میکروفون در دسترس نیست؛ مجوز مرورگر را بررسی کن.';startBrowserRecognition();}
  }
  root.querySelector('.farast-assistant-mic').addEventListener('click',function(){
    if(mediaRecorder&&mediaRecorder.state==='recording'){mediaRecorder.stop();root.querySelector('.farast-assistant-mic').classList.remove('is-recording');return;}
    if(recognition){recognition.stop();recognition=null;return;}
    startServerRecording();
  });
  document.addEventListener('keydown',function(e){if(e.key==='Escape'&&!panel.hidden)setOpen(false);});
  message('bot','من همراه هوشمند فراستم؛ می‌تونم در پیدا کردن خدمات، خرید و استفاده از سایت راهنمایی‌ات کنم. برای شروع، وارد حساب کاربری شو.');
})();