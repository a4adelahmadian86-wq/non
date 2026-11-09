(()=> {
  'use strict';
  const root=document.querySelector('[data-reader-root]');
  const form=document.querySelector('#reader-discussion-form');
  if(!root||!form)return;
  const quoteInput=form.querySelector('#reader-discussion-quote');
  const pageInput=form.querySelector('#reader-discussion-page');
  const sectionInput=form.querySelector('#reader-discussion-section');
  const startInput=form.querySelector('#reader-discussion-start');
  const endInput=form.querySelector('#reader-discussion-end');
  const contextInput=form.querySelector('#reader-discussion-context');
  const preview=document.querySelector('#reader-discussion-selection');
  const textArea=form.querySelector('#reader-discussion-body');
  const readerPageInput=root.querySelector('#reader-page-number');
  const clampText=value=>String(value||'').replace(/\s+/g,' ').trim().slice(0,500);
  const activePage=()=>root.querySelector('.farast-reader-page-item.is-active');
  const getTextContainer=page=>page?.querySelector('.farast-pdf-text-layer,.farast-pdf-cont-text,[data-reader-text-layer],.farast-reader-page-content');
  const clearAnchor=()=>{
    quoteInput.value='';startInput.value='';endInput.value='';contextInput.value='';
    if(preview)preview.textContent='ابتدا بخشی از متن را در خوانشگر انتخاب کنید.';
  };
  const updatePdfTextSelection=()=>{
    root.querySelectorAll('.farast-pdf-text-layer,.farast-pdf-cont-text').forEach(layer=>{
      layer.style.pointerEvents='auto';
      layer.querySelectorAll('span').forEach(span=>{span.style.pointerEvents='auto';span.style.userSelect='text';span.style.webkitUserSelect='text';});
    });
  };
  const syncSelection=()=>{
    const selection=window.getSelection();
    if(!selection||selection.isCollapsed)return;
    const quote=clampText(selection.toString());
    if(quote.length<2)return;
    const page=activePage();
    if(!page)return;
    const container=getTextContainer(page);
    if(!container||!container.contains(selection.anchorNode)||!container.contains(selection.focusNode))return;
    const range=selection.getRangeAt(0),before=document.createRange();
    before.selectNodeContents(container);before.setEnd(range.startContainer,range.startOffset);
    const start=before.toString().length,end=start+range.toString().length;
    const fullText=(container.innerText||container.textContent||'').replace(/\s+/g,' ');
    const section=Math.max(0,Number(page.dataset.index)||0);
    const pageNumber=Math.max(1,Number(readerPageInput?.value)||section+1);
    quoteInput.value=quote;pageInput.value=String(pageNumber);sectionInput.value=String(section);
    startInput.value=String(Math.max(0,start));endInput.value=String(Math.max(start,end));
    contextInput.value=fullText.slice(Math.max(0,start-120),Math.min(fullText.length,end+120)).slice(0,500);
    if(preview)preview.textContent='نقل‌قول پیوست‌شده · بخش '+(section+1)+' · صفحه '+pageNumber+' · «'+quote.slice(0,180)+(quote.length>180?'…':'')+'»';
  };
  document.addEventListener('selectionchange',syncSelection);
  root.addEventListener('mouseup',()=>window.setTimeout(syncSelection,0));
  root.addEventListener('touchend',()=>window.setTimeout(syncSelection,0),{passive:true});
  root.addEventListener('farast:reader-page-change',event=>{
    const page=activePage(),section=Math.max(0,Number(page?.dataset.index)||0);
    const nextPage=Math.max(1,Number(event.detail?.page)||section+1);
    const currentPage=Number(pageInput?.value)||0;
    if(pageInput&&currentPage!==nextPage){
      pageInput.value=String(nextPage);
      if(sectionInput)sectionInput.value=String(section);
      clearAnchor();
      window.getSelection()?.removeAllRanges();
    }
    updatePdfTextSelection();
  });
  root.addEventListener('farast:reader-section-change',event=>{
    const section=Math.max(0,Number(event.detail?.section)||0);
    if(sectionInput.value!==String(section)){sectionInput.value=String(section);clearAnchor();window.getSelection()?.removeAllRanges();}
    window.setTimeout(updatePdfTextSelection,0);
  });
  const findQuoteRange=(container,quote,expectedStart=null)=>{
    if(!container||!quote)return null;
    const walker=document.createTreeWalker(container,NodeFilter.SHOW_TEXT),chars=[],positions=[];let node,rawOffset=0;
    while((node=walker.nextNode())){
      const value=node.nodeValue||'';
      for(let offset=0;offset<value.length;offset++){
        const character=value[offset],position={node,offset,rawOffset:rawOffset+offset};
        if(/\s/.test(character)){
          if(!chars.length||chars[chars.length-1]===' ')continue;
          chars.push(' ');positions.push(position);
        }else{chars.push(character);positions.push(position);}
      }
      rawOffset+=value.length;
    }
    const leading=chars.findIndex(character=>character!==' '),normalized=chars.join('').trim();
    const normalizedQuote=String(quote).replace(/\s+/g,' ').trim();
    if(leading<0||!normalizedQuote)return null;
    const candidates=[];let from=0,index;
    while((index=normalized.indexOf(normalizedQuote,from))!==-1){
      const start=positions[leading+index],end=positions[leading+index+normalizedQuote.length-1];
      if(start&&end)candidates.push({start,end,distance:Number.isFinite(Number(expectedStart))?Math.abs(start.rawOffset-Number(expectedStart)):candidates.length});
      from=index+1;
    }
    if(!candidates.length)return null;
    candidates.sort((a,b)=>a.distance-b.distance);
    const {start,end}=candidates[0],range=document.createRange();
    range.setStart(start.node,start.offset);range.setEnd(end.node,end.offset+1);return range;
  };
  const waitForReaderEvent=(name,predicate)=>new Promise((resolve,reject)=>{
    const timer=window.setTimeout(()=>{root.removeEventListener(name,listener);reject(new Error('Reader navigation timed out'));},10000);
    const listener=event=>{if(!predicate(event))return;window.clearTimeout(timer);root.removeEventListener(name,listener);resolve(event);};
    root.addEventListener(name,listener);
  });
  const jumpToDiscussion=async button=>{
    const targetSection=Math.max(0,Number(button.dataset.section)||0),targetPage=Math.max(1,Number(button.dataset.page)||1);
    const currentSection=Math.max(0,Number(activePage()?.dataset.index)||0);
    button.disabled=true;button.setAttribute('aria-busy','true');
    try{
      if(currentSection!==targetSection){
        const sectionChanged=waitForReaderEvent('farast:reader-section-change',event=>Number(event.detail?.section)===targetSection);
        const pageChanged=waitForReaderEvent('farast:reader-page-change',()=>true);
        const sectionThumb=root.querySelector('.farast-reader-thumb[data-goto="'+targetSection+'"]');
        if(!sectionThumb)throw new Error('Reader section control is unavailable');
        sectionThumb.click();
        await sectionChanged;await pageChanged;
      }
      const page=activePage();
      if(page?.classList.contains('is-pdf')&&readerPageInput&&Number(readerPageInput.value)!==targetPage){
        const changed=waitForReaderEvent('farast:reader-page-change',event=>Number(event.detail?.page)===targetPage);
        readerPageInput.value=String(targetPage);readerPageInput.dispatchEvent(new Event('change',{bubbles:true}));await changed;
      }
      const active=activePage(),range=findQuoteRange(getTextContainer(active),button.dataset.quote||'',button.dataset.start===''||button.dataset.start===undefined?null:Number(button.dataset.start));
      if(range){const selection=window.getSelection();selection.removeAllRanges();selection.addRange(range);range.startContainer.parentElement?.scrollIntoView({behavior:'smooth',block:'center'});}
      else{active?.scrollIntoView({behavior:'smooth',block:'start'});if(preview)preview.textContent='بخش مربوط باز شد، اما نقل‌قول در متن فعلی پیدا نشد.';}
    }finally{button.disabled=false;button.removeAttribute('aria-busy');}
  };
  document.querySelectorAll('[data-reader-jump]').forEach(button=>button.addEventListener('click',()=>jumpToDiscussion(button).catch(()=>{
    if(preview)preview.textContent='بازکردن محل دیدگاه انجام نشد؛ دوباره تلاش کنید.';
    button.disabled=false;button.removeAttribute('aria-busy');
  })));
  form.addEventListener('submit',event=>{
    const quote=clampText(quoteInput.value),section=Number(sectionInput.value);
    if(quote.length<2||!Number.isInteger(section)||section<0){event.preventDefault();if(preview)preview.textContent='برای پیوند دیدگاه، ابتدا بخشی از متن خوانشگر را انتخاب کنید.';return;}
    quoteInput.value=quote;
    if(!textArea.value.trim()){event.preventDefault();textArea.focus();}
  });
  updatePdfTextSelection();
  root.addEventListener('farast:reader-section-change',()=>window.setTimeout(updatePdfTextSelection,0));
})();