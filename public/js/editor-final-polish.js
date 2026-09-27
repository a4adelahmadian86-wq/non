(()=>{
'use strict';

const boot=()=>{
 const app=document.getElementById('farastWord');
 if(!app)return;
 const $=(s,r=document)=>r.querySelector(s);
 const $$=(s,r=document)=>[...r.querySelectorAll(s)];
 const editor=$('#editor',app);

 /* Word-like application chrome: title bar + quick access + tabs + ribbon. */
 const title=$('.word-titlebar',app);
 if(title){
   title.setAttribute('role','banner');
   let qa=$('.word-quick-access',title);
   if(!qa){
     qa=document.createElement('div');qa.className='word-quick-access';qa.setAttribute('aria-label','دسترسی سریع');
     [['undo','fa-solid fa-rotate-left','واگرد — Ctrl+Z'],['redo','fa-solid fa-rotate-right','انجام دوباره — Ctrl+Y'],['save','fa-solid fa-floppy-disk','ذخیره — Ctrl+S']].forEach(([action,icon,tip])=>{
       const b=document.createElement('button');b.type='button';b.className='word-quick-btn word-tool';b.dataset.action=action;b.dataset.tip=tip;b.setAttribute('aria-label',tip);b.innerHTML=`<i class="${icon}"></i>`;qa.appendChild(b);
     });
     title.prepend(qa);
   }
 }

 /* Ensure the complete primary Word tab set exists. */
 const tabs=[['home','خانه'],['insert','درج'],['draw','رسم'],['design','طراحی'],['layout','طرح‌بندی'],['references','مراجع'],['mailings','نامه‌نگاری'],['review','بازبینی'],['view','نمایش'],['help','راهنما']];
 const nav=$('.word-tabs',app);
 if(nav){
   const current=nav.querySelector('.word-tab.active')?.dataset.tab||'home';
   nav.innerHTML='';
   tabs.forEach(([key,label])=>{
     const b=document.createElement('button');b.type='button';b.className='word-tab'+(key===current?' active':'');b.dataset.tab=key;b.setAttribute('aria-selected',key===current?'true':'false');b.textContent=label;nav.appendChild(b);
   });
 }

 /* No fake horizontal ribbon scrolling. Ribbon groups wrap exactly like a desktop ribbon. */
 $$('.ribbon',app).forEach(panel=>{
   panel.setAttribute('role','toolbar');
   panel.style.overflowX='hidden';
   panel.style.overflowY='visible';
   $$('.word-tool',panel).forEach(btn=>{
     btn.type='button';
     btn.setAttribute('aria-label',btn.dataset.tip||btn.getAttribute('aria-label')||'ابزار');
     btn.querySelectorAll('svg').forEach(svg=>svg.setAttribute('aria-hidden','true'));
   });
 });

 /* Required document defaults: B Nazanin 16pt, RTL, A4, normal margins. */
 if(editor){
   editor.style.fontFamily='B Nazanin, BNazanin, serif';
   editor.style.fontSize='16pt';
   editor.style.lineHeight='1.15';
   editor.style.direction='rtl';
   editor.style.textAlign='right';
   editor.dataset.defaultFont='B Nazanin';
   editor.dataset.defaultFontSize='16';
   editor.dataset.paperSize='A4';
   editor.dataset.pageWidthMm='210';
   editor.dataset.pageHeightMm='297';
   editor.dataset.marginMm='25.4';
 }

 /* Keep document scroll as the only vertical editor scroll surface. */
 const viewport=$('.pages-viewport',app);
 if(viewport){viewport.style.overflow='auto';viewport.style.overflowX='hidden';}
 $$('.word-page',app).forEach(page=>{page.style.boxSizing='border-box';page.style.width='210mm';page.style.minHeight='297mm';page.style.maxWidth='calc(100vw - 32px)';});

 /* Hide obsolete legacy controls if the new ribbon has already rebuilt their actions. */
 $$('.ribbon .ribbon-label, .ribbon .ribbon-file',app).forEach(x=>x.classList.add('legacy-ribbon-control'));

 /* Preserve selection before toolbar interaction where the browser otherwise collapses it. */
 let savedRange=null;
 app.addEventListener('selectionchange',()=>{
   const s=window.getSelection?.();
   if(!s||!s.rangeCount||!editor||!editor.contains(s.anchorNode))return;
   savedRange=s.getRangeAt(0).cloneRange();
 },true);
 app.addEventListener('mousedown',e=>{
   if(e.target.closest('.word-tool'))e.preventDefault();
 },true);
 app.addEventListener('click',e=>{
   const b=e.target.closest('.word-quick-btn');
   if(!b)return;
   if(b.dataset.action==='save')$('#saveNow',app)?.click();
 },true);

 /* Restore the last selection after color/style menus close. */
 window.FarastRestoreEditorSelection=()=>{
   if(!savedRange)return;
   const s=window.getSelection?.();if(!s)return;
   try{s.removeAllRanges();s.addRange(savedRange);editor?.focus()}catch(_){/* selection may have become invalid */}
 };

 /* Ctrl+S belongs to the document, not the browser page. */
 document.addEventListener('keydown',e=>{
   if(e.ctrlKey&&!e.altKey&&e.key.toLowerCase()==='s'&&editor?.contains(document.activeElement)){
     e.preventDefault();$('#saveNow',app)?.click();
   }
 });

 /* Mark the active page layout mode for CSS without inventing a second editor. */
 app.dataset.wordLayoutReady='1';
};

if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',boot,{once:true});else boot();
})();
