(()=>{
  const meta=document.querySelector('meta[name="farast-capabilities"]');
  let caps={can_type:true,can_ai:true,can_voice:true,can_export_docx:true,can_export_pdf:true,can_feedback:true};
  try{if(meta?.content)caps=JSON.parse(meta.content)}catch(e){}
  const $=s=>document.querySelector(s);
  const disable=(el,disabled=true)=>{if(!el)return;el.disabled=disabled;el.classList.toggle('cap-disabled',disabled);el.setAttribute('aria-disabled',disabled?'true':'false')};
  const editor=$('#editor');
  if(editor){editor.contentEditable=caps.can_type?'true':'false';if(!caps.can_type){editor.classList.add('read-only');editor.setAttribute('data-lock-message','ویرایش برای این حساب فعال نیست')}}
  disable($('#analyze'),!caps.can_ai);disable($('#mic'),!caps.can_voice);disable($('#feedbackPanelBtn'),!caps.can_feedback);disable($('#feedbackBad'),!caps.can_feedback);disable($('#feedbackGood'),!caps.can_feedback);disable($('#rate5'),!caps.can_feedback);disable($('#rate1'),!caps.can_feedback);disable($('#exportDocx'),!caps.can_export_docx);disable($('#exportPdf'),!caps.can_export_pdf);
  const paperSize=$('#paperSize');if(paperSize){paperSize.innerHTML='<option value="A4" selected>A4</option><option value="A5">A5</option>';paperSize.value='A4'}
  document.querySelectorAll('[data-cmd],[data-style],#fontName,#fontSize,#lineSpacing,#direction,#pageBreak,#insertDate,#insertLink,#clearLink').forEach(el=>{if(!caps.can_type)disable(el,true)});
  document.querySelectorAll('.ai-run').forEach(el=>el.title=caps.can_ai?'پردازش واقعی با Gemini':'پردازش AI برای این حساب غیرفعال است');
  document.querySelectorAll('.word-editor,.word-page').forEach(el=>el.style.transition='box-shadow .25s ease,transform .25s ease');
  document.addEventListener('click',e=>{const b=e.target.closest('.cap-disabled');if(!b)return;e.preventDefault();e.stopPropagation();if(b.dataset.capToast)return;b.dataset.capToast='1';const note=document.createElement('div');note.className='cap-toast';note.textContent='این قابلیت از طرف مدیر اصلی برای حساب شما غیرفعال شده است.';document.body.appendChild(note);setTimeout(()=>{note.classList.add('out');setTimeout(()=>note.remove(),250)},2200)},true);
})();
