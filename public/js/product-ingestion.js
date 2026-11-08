(() => {
  const form = document.getElementById('productFileIngestionForm');
  if (!form || form.dataset.ingestionReady === '1') return;
  form.dataset.ingestionReady = '1';
  const input = document.getElementById('productIngestionInput');
  const dropzone = document.getElementById('productFileDropzone');
  const start = document.getElementById('productIngestionStart');
  const selection = document.getElementById('productIngestionSelection');
  const modal = document.getElementById('productIngestionModal');
  const fileName = document.getElementById('productIngestionFileName');
  const fileMeta = document.getElementById('productIngestionFileMeta');
  const status = document.getElementById('productIngestionStatus');
  const timeline = document.getElementById('productIngestionTimeline');
  const facts = document.getElementById('productIngestionFacts');
  const entries = document.getElementById('productIngestionEntries');
  const warnings = document.getElementById('productIngestionWarnings');
  const confirmButton = document.getElementById('productIngestionConfirm');
  const cancelButton = document.getElementById('productIngestionCancel');
  const csrf = form.querySelector('input[name="_token"]')?.value || '';
  let queue = [], active = null, busy = false, accepted = 0;

  const bytes = n => n >= 1048576 ? (n/1048576).toFixed(2)+' MB' : (n/1024).toFixed(1)+' KB';
  const escapeHtml = value => String(value).replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
  const setQueue = files => {
    queue = Array.from(files || []);
    selection.textContent = queue.length ? queue.length+' فایل انتخاب شده · '+queue.map(f=>f.name).slice(0,3).join('، ')+(queue.length>3?' و ...':'') : 'هنوز فایلی انتخاب نشده است.';
    start.disabled = !queue.length || busy;
  };
  const openModal = () => { modal.hidden = false; modal.setAttribute('aria-hidden','false'); document.body.style.overflow='hidden'; };
  const closeModal = () => { modal.hidden = true; modal.setAttribute('aria-hidden','true'); document.body.style.overflow=''; };
  const renderTimeline = (current, pendingLast) => {
    const steps = [
      ['preflight','بررسی اولیه سایت','fa-magnifying-glass'],
      ['security_scan','کنترل امنیتی داخلی','fa-shield-halved'],
      ['storage_upload','ذخیره نسخه اصلی','fa-cloud-arrow-up'],
      ['content_study','مطالعه محتوای فایل','fa-book-open']
    ];
    timeline.innerHTML = steps.map((step,index) => {
      const done = current > index;
      const running = current === index && !(pendingLast && index === 3);
      const pending = pendingLast && index === 3;
      const label = pending ? 'مطالعه محتوای فایل · در انتظار' : step[1];
      return '<div class="product-ingestion-step '+(done?'is-done':running?'is-running':pending?'is-pending':'')+'"><i class="fa-solid '+step[2]+'"></i><span>'+label+'</span></div>';
    }).join('');
  };
  const showInspection = (file, inspection) => {
    fileName.textContent = file.name;
    fileMeta.textContent = bytes(file.size)+' · '+(inspection.detected_mime || file.type || 'نوع نامشخص');
    status.textContent = 'آماده تأیید';
    status.style.background = '#dcfce7'; status.style.color = '#166534';
    renderTimeline(1);
    const rows = [
      ['نوع شناسایی‌شده',inspection.kind || '—'],
      ['حجم فایل',inspection.size_label || bytes(file.size)],
      ['تعداد صفحات',inspection.page_count ?? 'قابل تشخیص نیست'],
      ['ابعاد تصویر',inspection.width ? inspection.width+' × '+inspection.height : '—'],
      ['تعداد اجزای آرشیو',inspection.archive?.entry_count ?? '—'],
      ['حجم پس از بازشدن',inspection.archive?.expanded_bytes ? bytes(inspection.archive.expanded_bytes) : '—']
    ];
    facts.innerHTML = rows.map(row=>'<div class="product-ingestion-fact"><small>'+row[0]+'</small><strong>'+escapeHtml(row[1])+'</strong></div>').join('');
    const list = inspection.archive?.entries || [];
    entries.hidden = !list.length;
    entries.innerHTML = list.length ? '<h4>محتویات شناسایی‌شده ('+inspection.archive.entry_count+')</h4><div class="product-ingestion-entry-list">'+list.map(entry=>'<div class="product-ingestion-entry"><span>'+escapeHtml(entry.name)+'</span><small>'+bytes(entry.size_bytes || 0)+'</small></div>').join('')+'</div>' : '';
    const warningList = inspection.warnings || [];
    warnings.hidden = !warningList.length;
    warnings.textContent = warningList.join(' · ');
    confirmButton.disabled = false;
  };
  const postFile = file => new Promise((resolve,reject) => {
    const data = new FormData();
    data.append('file',file,file.name);
    const xhr = new XMLHttpRequest();
    xhr.open('POST',form.action,true);
    xhr.setRequestHeader('X-CSRF-TOKEN',csrf);
    xhr.setRequestHeader('Accept','application/json');
    xhr.setRequestHeader('X-Requested-With','XMLHttpRequest');
    xhr.upload.onprogress = event => {
      if (event.lengthComputable) status.textContent = 'ارسال امن به سایت · '+Math.round(event.loaded/event.total*100)+'٪';
    };
    xhr.onload = () => {
      let payload={};
      try { payload=JSON.parse(xhr.responseText); } catch (_) { payload={message:'پاسخ سرور قابل خواندن نیست.'}; }
      if (xhr.status < 200 || xhr.status >= 300 || !payload.ok) reject(new Error(payload.message || 'بررسی اولیه فایل ناموفق بود.'));
      else resolve(payload);
    };
    xhr.onerror = () => reject(new Error('ارتباط با سایت هنگام ارسال فایل قطع شد.'));
    xhr.send(data);
  });
  const confirmUpload = async () => {
    if (!active || busy) return;
    busy = true; confirmButton.disabled = true; cancelButton.disabled = true;
    status.textContent = 'در حال ذخیره'; renderTimeline(2);
    try {
      const url = form.dataset.confirmUrl.replace('__TOKEN__',encodeURIComponent(active.token));
      const isPrimary = !!form.querySelector('[name="is_primary"]')?.checked && accepted===0;
      const response = await fetch(url,{method:'POST',headers:{'X-CSRF-TOKEN':csrf,'Accept':'application/json','X-Requested-With':'XMLHttpRequest','Content-Type':'application/json'},body:JSON.stringify({is_primary:isPrimary}),credentials:'same-origin'});
      const result = await response.json().catch(()=>({message:'پاسخ سرور قابل خواندن نیست.'}));
      if (!response.ok || !result.ok) throw new Error(result.message || 'ذخیره فایل انجام نشد.');
      status.textContent = 'ذخیره شد · مطالعه محتوا در انتظار'; status.style.background='#dcfce7'; status.style.color='#166534';
      renderTimeline(3,true); accepted++;
      confirmButton.textContent = 'ثبت شد';
      setTimeout(async () => { closeModal(); active=null; await processNext(); },650);
    } catch(error) {
      status.textContent = 'متوقف شد'; status.style.background='#fee2e2'; status.style.color='#991b1b';
      warnings.hidden=false; warnings.textContent=error.message;
      confirmButton.disabled=false;
    } finally { busy=false; cancelButton.disabled=false; }
  };
  const rejectActive = async () => {
    if (busy) return;
    if (active?.token) {
      const url=form.dataset.cancelUrl.replace('__TOKEN__',encodeURIComponent(active.token));
      await fetch(url,{method:'DELETE',headers:{'X-CSRF-TOKEN':csrf,'Accept':'application/json'},credentials:'same-origin'}).catch(()=>{});
    }
    closeModal(); active=null; await processNext();
  };
  const processNext = async () => {
    if (!queue.length) {
      busy=false; start.disabled=false; start.textContent='بررسی فایل‌های انتخاب‌شده';
      selection.textContent=accepted ? accepted+' فایل با موفقیت ثبت شد.' : 'فرایند انتخاب فایل پایان یافت.';
      if (accepted) window.location.reload();
      return;
    }
    const file=queue.shift();
    busy=true; start.disabled=true; start.textContent='در حال بررسی '+file.name;
    openModal(); confirmButton.disabled=true; confirmButton.textContent='تأیید و ذخیره فایل'; cancelButton.disabled=false;
    fileName.textContent=file.name; fileMeta.textContent=bytes(file.size); status.textContent='در حال بررسی اولیه';
    status.style.background='#e0f2fe'; status.style.color='#075985';
    facts.innerHTML=''; entries.hidden=true; warnings.hidden=true; warnings.textContent=''; renderTimeline(0);
    try {
      active=await postFile(file);
      showInspection(file,active.inspection);
    } catch(error) {
      status.textContent='رد شد'; status.style.background='#fee2e2'; status.style.color='#991b1b';
      warnings.hidden=false; warnings.textContent=error.message;
      confirmButton.disabled=true; active=null;
    } finally { busy=false; start.disabled=true; }
  };
  dropzone.addEventListener('click',()=>input.click());
  dropzone.addEventListener('keydown',event=>{if(event.key==='Enter'||event.key===' '){event.preventDefault();input.click();}});
  input.addEventListener('change',()=>setQueue(input.files));
  start.addEventListener('click',()=>{accepted=0;processNext();});
  confirmButton.addEventListener('click',confirmUpload);
  cancelButton.addEventListener('click',rejectActive);
  modal.querySelectorAll('[data-ingestion-close]').forEach(el=>el.addEventListener('click',()=>{if(!busy)rejectActive();}));
  document.addEventListener('keydown',event=>{if(event.key==='Escape'&&!modal.hidden&&!busy)rejectActive();});
  ['dragenter','dragover'].forEach(eventName=>document.addEventListener(eventName,event=>{
    if(event.dataTransfer?.types?.includes('Files')){event.preventDefault();dropzone.classList.add('is-dragging');}
  }));
  ['dragleave','drop'].forEach(eventName=>document.addEventListener(eventName,event=>{
    if(eventName==='drop'&&event.dataTransfer?.files?.length){event.preventDefault();setQueue(event.dataTransfer.files);dropzone.classList.remove('is-dragging');}
    else if(eventName==='dragleave'&&!dropzone.contains(event.relatedTarget)){dropzone.classList.remove('is-dragging');}
  }));
  form.addEventListener('submit',event=>event.preventDefault());
})();