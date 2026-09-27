(()=>{
'use strict';
const ready=fn=>document.readyState==='loading'?document.addEventListener('DOMContentLoaded',fn,{once:true}):fn();
ready(()=>{
 const app=document.getElementById('farastWord');if(!app)return;
 const $=(s,r=document)=>r.querySelector(s), $$=(s,r=document)=>[...r.querySelectorAll(s)];
 app.dataset.shell='final';
 const style=document.createElement('style');
 style.id='farast-editor-shell-final-runtime';
 style.textContent=`
  html,body.editor-page-body{height:100%!important;overflow:hidden!important}
  body.editor-page-body #app-main{height:100vh!important;height:100dvh!important;overflow:hidden!important;margin:0!important;padding:0!important}
  #farastWord.word-app{position:absolute!important;inset:0!important;height:100vh!important;height:100dvh!important;min-height:0!important;display:grid!important;grid-template-rows:52px 36px 108px minmax(0,1fr) 28px!important;gap:0!important;overflow:hidden!important}
  #farastWord>.word-titlebar,#farastWord>.word-tabs,#farastWord>.ribbon,#farastWord>.word-status,#farastWord>.word-statusbar{position:relative!important;margin:0!important;inset:auto!important;transform:none!important}
  #farastWord>.word-titlebar{grid-row:1!important;height:52px!important;min-height:52px!important}
  #farastWord>.word-tabs{grid-row:2!important;height:36px!important;min-height:36px!important;overflow-x:auto!important;overflow-y:hidden!important;scrollbar-width:none!important}
  #farastWord>.word-tabs::-webkit-scrollbar{display:none!important}
  #farastWord>.ribbon{grid-row:3!important;height:108px!important;min-height:108px!important;max-height:108px!important;margin:0!important;overflow:hidden!important}
  #farastWord>.word-body{grid-row:4!important;min-height:0!important;height:auto!important;overflow:hidden!important}
  #farastWord>.word-status,#farastWord>.word-statusbar{grid-row:5!important;height:28px!important;min-height:28px!important}
  #farastWord .document-area{height:100%!important;min-height:0!important;overflow:hidden!important}
  #farastWord .document-toolbar,#farastWord .upload-zone{display:none!important}
  #farastWord .pages-viewport{height:100%!important;min-height:0!important;overflow:auto!important;overflow-anchor:auto!important;touch-action:pan-x pan-y!important;scroll-behavior:auto!important;-webkit-overflow-scrolling:touch!important}
  #farastWord .word-page{overflow:visible!important}
  #farastWord .word-tabs::before,#farastWord .ribbon::before{content:none!important}
  .farast-ribbon-more{width:27px!important;min-width:27px!important;height:25px!important;border:1px solid transparent!important;border-radius:3px!important;background:transparent!important;color:#596273!important;display:none!important;place-items:center!important;cursor:pointer!important}
  .farast-ribbon-more:hover{background:#e4eaf2!important;border-color:#cbd4df!important;color:#174ea6!important}
  .farast-ribbon-popover{position:fixed;z-index:14000;min-width:190px;max-width:min(340px,calc(100vw - 20px));max-height:min(420px,70vh);overflow:auto;padding:7px;background:#fff;border:1px solid #cdd3dc;border-radius:8px;box-shadow:0 12px 36px rgba(24,34,48,.18);direction:rtl;font-family:Vazirmatn,Arial,sans-serif}
  .farast-ribbon-popover .farast-ribbon-pop-title{font-size:9px;font-weight:800;color:#596273;padding:5px 7px 7px;border-bottom:1px solid #edf0f4;margin-bottom:4px}
  .farast-ribbon-popover button{width:100%;min-height:34px;border:0;border-radius:5px;background:#fff;display:flex;align-items:center;gap:9px;padding:6px 9px;color:#2f3540;font:9px Vazirmatn,Arial,sans-serif;text-align:right;cursor:pointer}
  .farast-ribbon-popover button:hover{background:#eef4fb;color:#174ea6}.farast-ribbon-popover button i{width:20px;text-align:center;font-size:13px}
  #farastWord[data-shell-size="medium"] .ribbon-group .word-tool.farast-shell-overflow,#farastWord[data-shell-size="compact"] .ribbon-group .word-tool.farast-shell-overflow{display:none!important}
  #farastWord[data-shell-size="medium"] .ribbon-group.has-farast-overflow .farast-ribbon-more,#farastWord[data-shell-size="compact"] .ribbon-group.has-farast-overflow .farast-ribbon-more{display:grid!important}
  #farastWord[data-shell-size="medium"]>.word-titlebar{height:48px!important;min-height:48px!important}#farastWord[data-shell-size="medium"]>.word-tabs{height:34px!important;min-height:34px!important}#farastWord[data-shell-size="medium"]>.ribbon{height:104px!important;min-height:104px!important;max-height:104px!important}#farastWord[data-shell-size="medium"]{grid-template-rows:48px 34px 104px minmax(0,1fr) 28px!important}
  #farastWord[data-shell-size="compact"]{grid-template-rows:46px 34px 100px minmax(0,1fr) 28px!important}#farastWord[data-shell-size="compact"]>.word-titlebar{height:46px!important;min-height:46px!important}#farastWord[data-shell-size="compact"]>.word-tabs{height:34px!important;min-height:34px!important}#farastWord[data-shell-size="compact"]>.ribbon{height:100px!important;min-height:100px!important;max-height:100px!important}
  #farastWord[data-shell-size="compact"] .word-tab{min-width:auto!important;padding-inline:10px!important}
 `;
 document.head.appendChild(style);
 const viewport=$('#pagesViewport',app)||$('.pages-viewport',app);
 const getSize=()=>innerWidth<=820?'compact':innerWidth<=1180?'medium':'wide';
 const toolPriority=new Set(['paste','cut','copy','fontName','fontSize','bold','italic','underline','fontColor','highlight','bullets','numbering','alignRight','alignCenter','alignLeft','justify','lineSpacing','find','replace','voice','fileTyping','margins','orientation','size','table','picture','link']);
 let popover=null,scheduled=false;
 const closePopover=()=>{popover?.remove();popover=null};
 const ensureMoreButton=group=>{let more=$('.farast-ribbon-more',group);if(more)return more;more=document.createElement('button');more.type='button';more.className='farast-ribbon-more';more.title='ابزارهای بیشتر';more.setAttribute('aria-label','ابزارهای بیشتر');more.innerHTML='<i class="fa-solid fa-ellipsis"></i>';group.appendChild(more);more.addEventListener('click',e=>{e.preventDefault();e.stopPropagation();openGroupMenu(group,more)});return more};
 const visibleText=button=>button.dataset.tip||button.getAttribute('aria-label')||button.title||button.textContent.trim()||'ابزار';
 const openGroupMenu=(group,anchor)=>{closePopover();const hidden=$$('.word-tool.farast-shell-overflow',group);if(!hidden.length)return;popover=document.createElement('div');popover.className='farast-ribbon-popover';const title=document.createElement('div');title.className='farast-ribbon-pop-title';title.textContent=group.dataset.group||'ابزارهای بیشتر';popover.appendChild(title);hidden.forEach(original=>{const b=document.createElement('button');b.type='button';const i=original.querySelector('i');b.innerHTML=i?'<i class="'+i.className+'"></i><span></span>':'<i class="fa-solid fa-circle-dot"></i><span></span>';b.querySelector('span').textContent=visibleText(original);b.addEventListener('click',()=>{closePopover();original.click()});popover.appendChild(b)});document.body.appendChild(popover);const r=anchor.getBoundingClientRect(),w=popover.offsetWidth,h=popover.offsetHeight;const left=Math.max(8,Math.min(innerWidth-w-8,r.right-w));let top=r.bottom+6;if(top+h>innerHeight-8)top=Math.max(8,r.top-h-6);popover.style.left=left+'px';popover.style.top=top+'px'};
 const rebalanceGroup=(group,size)=>{const tools=$$('.word-tool',group).filter(b=>!b.classList.contains('farast-ribbon-more'));tools.forEach(b=>b.classList.remove('farast-shell-overflow'));group.classList.remove('has-farast-overflow');if(size==='wide'||tools.length<=5)return;const keep=size==='compact'?5:8;const ranked=[...tools].sort((a,b)=>{const ap=toolPriority.has(a.dataset.action||a.id)?0:1,bp=toolPriority.has(b.dataset.action||b.id)?0:1;return ap-bp||tools.indexOf(a)-tools.indexOf(b)});const keepSet=new Set(ranked.slice(0,keep));tools.forEach(t=>{if(!keepSet.has(t))t.classList.add('farast-shell-overflow')});if(tools.some(t=>t.classList.contains('farast-shell-overflow'))){group.classList.add('has-farast-overflow');ensureMoreButton(group)}};
 const normalizeScroll=()=>{if(!viewport)return;viewport.style.overflowY='auto';viewport.style.overflowX='auto';viewport.style.height='100%';viewport.style.minHeight='0'};
 const layout=()=>{scheduled=false;normalizeScroll();const size=getSize();app.dataset.shellSize=size;$$('.ribbon-group',app).forEach(g=>rebalanceGroup(g,size));closePopover()};
 const scheduleLayout=()=>{if(scheduled)return;scheduled=true;requestAnimationFrame(layout)};
 layout();
 const observer=new MutationObserver(scheduleLayout);observer.observe(app,{childList:true,subtree:true});
 if(window.ResizeObserver)new ResizeObserver(scheduleLayout).observe(app);else window.addEventListener('resize',scheduleLayout,{passive:true});
 window.addEventListener('resize',scheduleLayout,{passive:true});
 document.addEventListener('click',e=>{if(popover&&!popover.contains(e.target)&&!e.target.closest('.farast-ribbon-more'))closePopover()});
 document.addEventListener('keydown',e=>{if(e.key==='Escape')closePopover()});
 $$('.word-tab',app).forEach(tab=>tab.addEventListener('click',scheduleLayout));
 app.addEventListener('keydown',e=>{if(!viewport||e.defaultPrevented||e.altKey||e.ctrlKey||e.metaKey)return;if(e.key==='PageDown'){e.preventDefault();viewport.scrollBy({top:Math.max(280,viewport.clientHeight*.82),behavior:'auto'})}else if(e.key==='PageUp'){e.preventDefault();viewport.scrollBy({top:-Math.max(280,viewport.clientHeight*.82),behavior:'auto'})}},true);
});
})();
