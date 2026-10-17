/* FARAST conversational companion. Authenticated requests use the server AI pipeline; speech is progressive-enhancement. */
(function () {
  'use strict';
  if (document.getElementById('farast-global-assistant')) return;
  var root = document.createElement('div');
  root.id = 'farast-global-assistant';
  root.innerHTML =
    '<button class="farast-assistant-launcher" type="button" aria-label="گفت‌وگو با همراه فراست" aria-expanded="false" title="همراه هوشمند فراست"><span class="fa-solid fa-sparkles" aria-hidden="true"></span><span class="farast-assistant-pulse" aria-hidden="true"></span></button>' +
    '<section class="farast-assistant-panel" aria-label="همراه هوشمند فراست" aria-modal="false" hidden>' +
    '<header class="farast-assistant-head"><div class="farast-assistant-avatar"><i class="fa-solid fa-microphone-lines" aria-hidden="true"></i></div><div class="farast-assistant-brand"><strong>همراه فراست</strong><small><span class="farast-assistant-online"></span> راهنمای هوشمند شما</small></div><button class="farast-assistant-close" type="button" aria-label="بستن">×</button></header>' +
    '<div class="farast-assistant-intro"><b>سلام، خوش اومدی 👋</b><p>بگو دنبال چه چیزی هستی؛ قدم‌به‌قدم کنارت هستم.</p></div>' +
    '<div class="farast-assistant-messages" data-fa-messages aria-live="polite" aria-relevant="additions"></div>' +
    '<div class="farast-assistant-suggestions"><button type="button" data-fa-text="برای خرید فایل راهنمایی‌ام کن">🛍️ خرید فایل</button><button type="button" data-fa-text="چطور از خدمات تایپ استفاده کنم؟">✍️ خدمات تایپ</button><button type="button" data-fa-text="قیمت خدمات چقدر است؟">💎 قیمت‌ها</button></div>' +
    '<div class="farast-assistant-status" data-fa-status role="status">برای گفت‌وگو وارد حساب کاربری‌ات شو.</div>' +
    '<form class="farast-assistant-form" autocomplete="off"><button class="farast-assistant-mic" type="button" aria-label="شروع گفتار" title="گفتار به متن"><i class="fa-solid fa-microphone"></i></button><input name="message" maxlength="1200" placeholder="اینجا بنویس یا صحبت کن…" aria-label="پیام شما"><button class="farast-assistant-send" type="submit" aria-label="ارسال پیام"><i class="fa-solid fa-arrow-up"></i></button></form>' +
    '<footer class="farast-assistant-foot">پاسخ‌های هوش مصنوعی ممکن است نیاز به بررسی داشته باشند.</footer></section>';
  document.body.appendChild(root);
  var panel=root.querySelector('.farast-assistant-panel'), launcher=root.querySelector('.farast-assistant-launcher'),
      list=root.querySelector('[data-fa-messages]'), form=root.querySelector('form'), input=form.querySelector('input'),
      status=root.querySelector('[data-fa-status]'), busy=false, recognition=null, history=[];
  var csrf=document.querySelector('meta[name="csrf-token"]');
  function esc(s){return String(s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];});}
  function message(role,text){var el=document.createElement('div');el.className='farast-assistant-msg '+role;el.textContent=String(text||'');list.appendChild(el);list.scrollTop=list.scrollHeight;return el;}
  function setOpen(v){panel.hidden=!v;panel.classList.toggle('is-open',v);launcher.setAttribute('aria-expanded',v?'true':'false');if(v)setTimeout(function(){input.focus();},120);}
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
  launcher.addEventListener('click',function(){setOpen(panel.hidden);});
  root.querySelector('.farast-assistant-close').addEventListener('click',function(){setOpen(false);});
  form.addEventListener('submit',function(e){e.preventDefault();send(input.value);});
  root.querySelectorAll('[data-fa-text]').forEach(function(b){b.addEventListener('click',function(){send(b.getAttribute('data-fa-text'));});});
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