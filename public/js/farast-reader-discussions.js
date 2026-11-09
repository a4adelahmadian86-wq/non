(()=> {
  'use strict';
  const root=document.querySelector('[data-reader-root]');
  const form=document.querySelector('#reader-discussion-form');
  if(!root||!form)return;
  const quoteInput=form.querySelector('#reader-discussion-quote');
  const pageInput=form.querySelector('#reader-discussion-page');
  const startInput=form.querySelector('#reader-discussion-start');
  const endInput=form.querySelector('#reader-discussion-end');
  const contextInput=form.querySelector('#reader-discussion-context');
  const preview=document.querySelector('#reader-discussion-selection');
  const textArea=form.querySelector('#reader-discussion-body');
  const clampText=value=>String(value||'').replace(/\s+/g,' ').trim().slice(0,500);
  const activePage=()=>root.querySelector('.farast-reader-page-item.is-active');
  const getTextContainer=page=>page?.querySelector('.farast-pdf-text-layer,[data-reader-text-layer],.farast-reader-page-content');
  const updatePdfTextSelection=()=>{
    root.querySelectorAll('.farast-pdf-text-layer').forEach(layer=>{
      layer.style.pointerEvents='auto';
      layer.querySelectorAll('span').forEach(span=>{
        span.style.pointerEvents='auto';
        span.style.userSelect='text';
        span.style.webkitUserSelect='text';
      });
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
    const range=selection.getRangeAt(0);
    const before=document.createRange();
    before.selectNodeContents(container);
    before.setEnd(range.startContainer,range.startOffset);
    const start=before.toString().length;
    const end=start+range.toString().length;
    const fullText=(container.innerText||container.textContent||'').replace(/\s+/g,' ');
    const normalizedQuote=quote;
    const pageNumber=Number(root.querySelector('#reader-page-number')?.value||Number(page.dataset.index||0)+1);
    quoteInput.value=normalizedQuote;
    pageInput.value=String(Math.max(1,pageNumber));
    startInput.value=String(Math.max(0,start));
    endInput.value=String(Math.max(start,end));
    contextInput.value=fullText.slice(Math.max(0,start-120),Math.min(fullText.length,end+120)).slice(0,500);
    if(preview)preview.textContent='نقل‌قول پیوست‌شده · بخش '+pageInput.value+' · «'+normalizedQuote.slice(0,180)+(normalizedQuote.length>180?'…':'')+'»';
  };
  document.addEventListener('selectionchange',syncSelection);
  root.addEventListener('mouseup',()=>window.setTimeout(syncSelection,0));
  root.addEventListener('touchend',()=>window.setTimeout(syncSelection,0),{passive:true});
  root.addEventListener('farast:reader-page-change',event=>{
    if(window.getSelection()?.isCollapsed!==false){
      const page=activePage();
      const section=Number(page?.dataset.index||0)+1;
      pageInput.value=String(Math.max(1,Number(event.detail?.page)||section));
    }
    updatePdfTextSelection();
  });
  const findQuoteRange=(container,quote)=>{
    if(!container||!quote)return null;
    const walker=document.createTreeWalker(container,NodeFilter.SHOW_TEXT);
    const nodes=[];let text='';let node;
    while((node=walker.nextNode())){
      nodes.push({node,start:text.length,end:text.length+node.nodeValue.length});
      text+=node.nodeValue;
    }
    const index=text.indexOf(quote);
    if(index<0)return null;
    const start=nodes.find(item=>item.start<=index&&item.end>index);
    const endOffset=index+quote.length;
    const end=nodes.find(item=>item.start<endOffset&&item.end>=endOffset);
    if(!start||!end)return null;
    const range=document.createRange();
    range.setStart(start.node,index-start.start);
    range.setEnd(end.node,endOffset-end.start);
    return range;
  };
  document.querySelectorAll('[data-reader-jump]').forEach(button=>{
    button.addEventListener('click',()=>{
      const targetPage=Math.max(1,Number(button.dataset.page)||1);
      const pageInput=root.querySelector('#reader-page-number');
      if(!pageInput)return;
      const navigate=()=>{
        const page=activePage();
        const container=getTextContainer(page);
        const range=findQuoteRange(container,button.dataset.quote||'');
        if(range){
          const selection=window.getSelection();
          selection.removeAllRanges();
          selection.addRange(range);
          const target=range.startContainer.parentElement;
          target?.scrollIntoView({behavior:'smooth',block:'center'});
          return true;
        }
        page?.scrollIntoView({behavior:'smooth',block:'start'});
        return false;
      };
      if(Number(pageInput.value)===targetPage){navigate();return;}
      const onChange=event=>{
        if(Number(event.detail?.page)!==targetPage)return;
        root.removeEventListener('farast:reader-page-change',onChange);
        window.setTimeout(navigate,0);
      };
      root.addEventListener('farast:reader-page-change',onChange);
      pageInput.value=String(targetPage);
      pageInput.dispatchEvent(new Event('change',{bubbles:true}));
      window.setTimeout(()=>{
        root.removeEventListener('farast:reader-page-change',onChange);
        navigate();
      },2500);
    });
  });
  form.addEventListener('submit',event=>{
    const quote=clampText(quoteInput.value);
    if(quote.length<2){
      event.preventDefault();
      if(preview)preview.textContent='برای پیوند دیدگاه، ابتدا حداقل دو نویسه از متن خوانشگر را انتخاب کنید.';
      return;
    }
    quoteInput.value=quote;
    if(!textArea.value.trim()){
      event.preventDefault();
      textArea.focus();
    }
  });
  updatePdfTextSelection();
  root.addEventListener('farast:reader-section-change',()=>window.setTimeout(updatePdfTextSelection,0));
})();
