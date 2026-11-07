(()=> {
  'use strict';
  const root=document.querySelector('[data-reader-root]');
  if(!root)return;

  const articles=[...root.querySelectorAll('.farast-reader-page-item')];
  const thumbsHost=root.querySelector('.farast-reader-thumbs');
  const pageInput=root.querySelector('#reader-page-number');
  const pageTotal=root.querySelector('.reader-page-input span:last-child');
  const zoomLabel=root.querySelector('#reader-zoom-label');
  const progress=root.querySelector('#reader-progress');
  const progressBar=root.querySelector('.farast-reader-progress');
  const stage=root.querySelector('#reader-stage');
  const searchInput=root.querySelector('#reader-search-input');
  const searchCount=root.querySelector('#reader-search-count');
  let pdfCanvas=null,pdfSurface=null,pdfLoading=null,continuousHost=null;
  const scrollModeButton=root.querySelector('#reader-scroll-mode');
  const searchGroup=root.querySelector('.reader-search-group');
  const rotateButton=root.querySelector('#reader-rotate');
  const outlineSection=root.querySelector('#reader-outline');
  const outlineHost=root.querySelector('#reader-outline-list');

  const pdfjsReady=window.pdfjsLib
    ? Promise.resolve(window.pdfjsLib)
    : import('https://cdn.jsdelivr.net/npm/pdfjs-dist@6.5.438/legacy/build/pdf.mjs').then(module=>{window.pdfjsLib=module;return module;});

  let itemIndex=0,zoom=1,rotation=0,pdfDoc=null,pdfPage=1,pdfMatches=[],matchIndex=-1,renderTask=null,continuousMode=false,continuousObserver=null,activePdfUrl=null;
  const clamp=(n,min,max)=>Math.min(max,Math.max(min,n));
  const activeArticle=()=>articles[itemIndex];
  const isPdf=()=>Boolean(activeArticle()?.querySelector('.farast-pdf-document'));
  const faNumber=n=>Number(n).toLocaleString('fa-IR');

  const updateProgress=(current,total)=>{
    const percent=Math.round((current/Math.max(total,1))*100);
    if(progress)progress.style.width=percent+'%';
    if(progressBar)progressBar.setAttribute('aria-valuenow',String(percent));
  };

  const setPageControls=(current,total)=>{
    if(pageInput){pageInput.max=String(total);pageInput.value=String(current);}
    if(pageTotal)pageTotal.textContent='/ '+faNumber(total);
    updateProgress(current,total);
    root.querySelectorAll('.farast-reader-thumb[data-pdf-page]').forEach(thumb=>{
      const active=Number(thumb.dataset.pdfPage)===Number(current);
      thumb.classList.toggle('is-active',active);
      thumb.setAttribute('aria-current',active?'page':'false');
    });
  };

  const setZoomLabel=()=>{
    if(zoomLabel)zoomLabel.textContent=zoom===0?'خودکار':faNumber(Math.round(zoom*100))+'٪';
  };

  // Paint the account mark into the rendered page bitmap, not only as removable HTML.
  const paintPdfWatermark=canvas=>{
    if(!canvas?.width||!canvas?.height||activeArticle()?.classList.contains('is-server-watermarked'))return;
    const identity=(root.querySelector('.farast-reader-account span')?.textContent||'حساب کاربر').trim();
    const context=canvas.getContext('2d');
    if(!context)return;
    const width=canvas.width,height=canvas.height;
    const label=identity+' · پیش‌نمایش فراست';
    const fontSize=Math.max(16,Math.min(30,Math.round(width/48)));
    context.save();
    context.setTransform(1,0,0,1,0,0);
    context.globalAlpha=.19;
    context.fillStyle='#26364b';
    context.font='700 '+fontSize+'px Tahoma, Arial, sans-serif';
    context.textAlign='center';
    const stepX=Math.max(260,Math.round(width/2.15));
    const stepY=Math.max(150,Math.round(height/4.2));
    for(let y=stepY/2;y<height+stepY;y+=stepY){
      for(let x=stepX/2;x<width+stepX;x+=stepX){
        context.save();
        context.translate(x,y);
        context.rotate(-Math.PI/7);
        context.fillText(label,0,0);
        context.restore();
      }
    }
    context.restore();
  };

  const renderPdfPage=async(number)=>{
    if(!pdfDoc||!pdfCanvas)return;
    pdfPage=clamp(Number(number)||1,1,pdfDoc.numPages);
    if(renderTask){try{renderTask.cancel();}catch(_){}}
    const page=await pdfDoc.getPage(pdfPage);
    const base=page.getViewport({scale:1,rotation});
    const available=Math.max(280,(stage?.clientWidth||900)-64);
    const scale=zoom===0?Math.min(1.5,available/base.width):zoom;
    const viewport=page.getViewport({scale,rotation});
    const ratio=Math.min(window.devicePixelRatio||1,2);
    const context=pdfCanvas.getContext('2d',{alpha:false});
    pdfCanvas.width=Math.floor(viewport.width*ratio);
    pdfCanvas.height=Math.floor(viewport.height*ratio);
    pdfCanvas.style.width=viewport.width+'px';
    pdfCanvas.style.height=viewport.height+'px';
    if(pdfSurface){pdfSurface.style.width=viewport.width+'px';pdfSurface.style.minHeight=viewport.height+'px';}
    context.setTransform(ratio,0,0,ratio,0,0);
    renderTask=page.render({canvasContext:context,viewport});
    try{await renderTask.promise;}catch(error){if(error?.name!=='RenderingCancelledException')throw error;}
    paintPdfWatermark(pdfCanvas);

    const textLayer=activeArticle()?.querySelector('.farast-pdf-text-layer');
    if(textLayer&&window.pdfjsLib?.TextLayer){
      textLayer.innerHTML='';
      textLayer.classList.add('textLayer');
      textLayer.style.width=viewport.width+'px';
      textLayer.style.height=viewport.height+'px';
      const textContent=await page.getTextContent();
      const layer=new window.pdfjsLib.TextLayer({textContentSource:textContent,container:textLayer,viewport});
      await layer.render();
      const query=(searchInput?.value||'').trim().toLocaleLowerCase();
      textLayer.querySelectorAll('span').forEach(span=>{
        span.style.userSelect='none';span.style.webkitUserSelect='none';
        span.classList.toggle('reader-search-hit',Boolean(query)&&span.textContent.toLocaleLowerCase().includes(query));
      });
    }
    if(pdfLoading){pdfLoading.hidden=true;pdfLoading.style.display='none';}
    setPageControls(pdfPage,pdfDoc.numPages);
  };

  const renderContinuousPage=async(number)=>{
    if(!pdfDoc||!continuousHost)return;
    const wrapper=continuousHost.querySelector('[data-pdf-cont-page="'+number+'"]');
    const canvas=wrapper?.querySelector('canvas');
    if(!wrapper||!canvas||wrapper.dataset.rendered==='1'||wrapper.dataset.rendering==='1')return;
    wrapper.dataset.rendering='1';
    try{
    const page=await pdfDoc.getPage(number);
    const base=page.getViewport({scale:1,rotation});
    const available=Math.max(280,(stage?.clientWidth||900)-64);
    const scale=zoom===0?Math.min(1.5,available/base.width):zoom;
    const viewport=page.getViewport({scale,rotation});
    const ratio=Math.min(window.devicePixelRatio||1,2);
    canvas.width=Math.floor(viewport.width*ratio);
    canvas.height=Math.floor(viewport.height*ratio);
    canvas.style.width=viewport.width+'px';
    canvas.style.height=viewport.height+'px';
    wrapper.style.width=viewport.width+'px';
    wrapper.style.minHeight=viewport.height+'px';
    const context=canvas.getContext('2d',{alpha:false});
    context.setTransform(ratio,0,0,ratio,0,0);
    await page.render({canvasContext:context,viewport}).promise;
    paintPdfWatermark(canvas);
    const textLayer=wrapper.querySelector('.farast-pdf-cont-text');
    if(textLayer&&window.pdfjsLib?.TextLayer){
      textLayer.innerHTML='';
      textLayer.style.width=viewport.width+'px';
      textLayer.style.height=viewport.height+'px';
      const textContent=await page.getTextContent();
      const layer=new window.pdfjsLib.TextLayer({textContentSource:textContent,container:textLayer,viewport});
      await layer.render();
      const query=(searchInput?.value||'').trim().toLocaleLowerCase();
      textLayer.querySelectorAll('span').forEach(span=>{
        span.style.userSelect='none';span.style.webkitUserSelect='none';
        span.classList.toggle('reader-search-hit',Boolean(query)&&span.textContent.toLocaleLowerCase().includes(query));
      });
    }
    wrapper.dataset.rendered='1';
    }finally{
      delete wrapper.dataset.rendering;
    }
  };

  const buildContinuousPages=async()=>{
    if(!pdfDoc||!continuousHost)return;
    continuousObserver?.disconnect();
    continuousHost.innerHTML='';
    const fragment=document.createDocumentFragment();
    for(let n=1;n<=pdfDoc.numPages;n++){
      const wrapper=document.createElement('section');
      wrapper.className='farast-pdf-cont-page';
      wrapper.dataset.pdfContPage=String(n);
      wrapper.dataset.rendered='0';
      wrapper.setAttribute('aria-label','صفحه '+faNumber(n));
      const canvas=document.createElement('canvas');
      const textLayer=document.createElement('div');
      textLayer.className='farast-pdf-cont-text textLayer';
      wrapper.append(canvas,textLayer);
      fragment.append(wrapper);
    }
    continuousHost.append(fragment);
    if(pdfSurface){pdfSurface.classList.add('is-continuous');pdfSurface.style.width='100%';pdfSurface.style.minHeight='0';}
    if(window.IntersectionObserver){
      continuousObserver=new IntersectionObserver(entries=>{
        let best=null;
        entries.forEach(entry=>{
          if(!entry.isIntersecting)return;
          const number=Number(entry.target.dataset.pdfContPage);
          if(!best||entry.intersectionRatio>best.ratio)best={number,ratio:entry.intersectionRatio};
          renderContinuousPage(number).catch(showPdfError);
        });
        if(best){pdfPage=best.number;setPageControls(best.number,pdfDoc.numPages);}
      },{root:stage,threshold:[.05,.15,.35,.6]});
      continuousHost.querySelectorAll('[data-pdf-cont-page]').forEach(node=>continuousObserver.observe(node));
      await renderContinuousPage(pdfPage);
    }else{
      for(let n=1;n<=pdfDoc.numPages;n++)await renderContinuousPage(n);
    }
  };

  const refreshContinuousVisiblePages=async()=>{
    if(!continuousMode||!stage||!continuousHost)return;
    const stageRect=stage.getBoundingClientRect();
    const nodes=[...continuousHost.querySelectorAll('[data-pdf-cont-page]')];
    const visible=nodes.filter(node=>{
      const rect=node.getBoundingClientRect();
      return rect.bottom>=stageRect.top-180&&rect.top<=stageRect.bottom+180;
    });
    nodes.forEach(node=>{
      node.dataset.rendered='0';
      delete node.dataset.rendering;
      const canvas=node.querySelector('canvas');
      if(canvas){canvas.width=0;canvas.height=0;}
      const text=node.querySelector('.farast-pdf-cont-text');
      if(text)text.innerHTML='';
    });
    for(const node of visible)await renderContinuousPage(Number(node.dataset.pdfContPage));
  };

  const navigatePdfPage=async(number)=>{
    if(!pdfDoc)return;
    const target=clamp(Number(number)||1,1,pdfDoc.numPages);
    if(continuousMode){
      pdfPage=target;
      setPageControls(target,pdfDoc.numPages);
      const wrapper=continuousHost?.querySelector('[data-pdf-cont-page="'+target+'"]');
      wrapper?.scrollIntoView({behavior:'smooth',block:'start'});
      await renderContinuousPage(target);
    }else{
      await renderPdfPage(target);
    }
  };

  const buildPdfThumbs=async()=>{
    if(!pdfDoc||!thumbsHost)return;
    thumbsHost.querySelectorAll('.farast-reader-thumb[data-pdf-page]').forEach(t=>t.remove());
    const fragment=document.createDocumentFragment();
    for(let n=1;n<=pdfDoc.numPages;n++){
      const button=document.createElement('button');
      button.type='button';
      button.className='farast-reader-thumb'+(n===1?' is-active':'');
      button.dataset.pdfPage=String(n);
      button.setAttribute('aria-label','رفتن به صفحه '+n);
      button.setAttribute('aria-current',n===1?'page':'false');
      const preview=document.createElement('span');
      preview.className='farast-reader-thumb-preview';
      const canvas=document.createElement('canvas');
      canvas.width=40;canvas.height=54;
      preview.append(canvas);
      const label=document.createElement('span');
      label.textContent='صفحه '+faNumber(n);
      button.append(preview,label);
      button.addEventListener('click',()=>navigatePdfPage(n).catch(showPdfError));
      fragment.append(button);
    }
    thumbsHost.append(fragment);
    // Low-resolution previews are generated in the background to keep the first page responsive.
    for(let n=1;n<=Math.min(pdfDoc.numPages,80);n++){
      const button=thumbsHost.querySelector('[data-pdf-page="'+n+'"]');
      const canvas=button?.querySelector('canvas');
      if(!canvas)continue;
      try{
        const page=await pdfDoc.getPage(n);
        const viewport=page.getViewport({scale:Math.min(40/page.getViewport({scale:1}).width,54/page.getViewport({scale:1}).height)});
        const ctx=canvas.getContext('2d');
        canvas.width=Math.max(1,Math.floor(viewport.width));
        canvas.height=Math.max(1,Math.floor(viewport.height));
        await page.render({canvasContext:ctx,viewport}).promise;
        // Keep thumbnail previews attributable too; they expose the same page content.
        paintPdfWatermark(canvas);
      }catch(_){}
    }
  };

  const loadPdfOutline=async()=>{
    if(!pdfDoc||!outlineHost||!outlineSection)return;
    try{
      const outline=await pdfDoc.getOutline();
      outlineHost.innerHTML='';
      if(!outline?.length){outlineSection.hidden=true;return;}
      const appendItems=async(items,depth=0)=>{
        for(const item of items||[]){
          let destination=item.dest;
          if(typeof destination==='string')destination=await pdfDoc.getDestination(destination);
          if(Array.isArray(destination)&&destination[0]){
            try{
              const page=(await pdfDoc.getPageIndex(destination[0]))+1;
              const button=document.createElement('button');
              button.type='button';
              button.textContent=item.title||('صفحه '+faNumber(page));
              button.style.paddingInlineStart=(8+depth*14)+'px';
              button.addEventListener('click',()=>navigatePdfPage(page).catch(showPdfError));
              outlineHost.append(button);
            }catch(_){}
          }
          if(item.items?.length)await appendItems(item.items,Math.min(depth+1,6));
        }
      };
      await appendItems(outline);
      outlineSection.hidden=!outlineHost.childElementCount;
    }catch(_){outlineSection.hidden=true;}
  };

  const loadPdf=async()=>{
    const surface=activeArticle()?.querySelector('.farast-pdf-document');
    const nextUrl=surface?.dataset.pdfUrl;
    if(!surface||!nextUrl){showPdfError(new Error('PDF preview URL unavailable'));return;}

    if(activePdfUrl!==nextUrl){
      if(renderTask){try{renderTask.cancel();}catch(_){}renderTask=null;}
      if(pdfDoc){try{await pdfDoc.destroy();}catch(_){}pdfDoc=null;}
      continuousObserver?.disconnect();continuousObserver=null;continuousMode=false;
      pdfSurface?.classList.remove('is-continuous');
      if(continuousHost)continuousHost.innerHTML='';
      pdfSurface=surface;
      pdfCanvas=surface.querySelector('.farast-pdf-canvas');
      pdfLoading=surface.querySelector('.farast-pdf-loading');
      continuousHost=surface.querySelector('.farast-pdf-continuous-pages');
      activePdfUrl=nextUrl;
      pdfPage=1;pdfMatches=[];matchIndex=-1;
      scrollModeButton?.setAttribute('aria-pressed','false');
    }else{
      pdfSurface=surface;
      pdfCanvas=surface.querySelector('.farast-pdf-canvas');
      pdfLoading=surface.querySelector('.farast-pdf-loading');
      continuousHost=surface.querySelector('.farast-pdf-continuous-pages');
    }

    try{
      if(!pdfDoc){
        const pdfjs=await pdfjsReady;
        pdfjs.GlobalWorkerOptions.workerSrc='https://cdn.jsdelivr.net/npm/pdfjs-dist@6.5.438/legacy/build/pdf.worker.mjs';
        pdfDoc=await pdfjs.getDocument({url:nextUrl,withCredentials:true,disableAutoFetch:true,disableStream:true}).promise;
      }
      if(pdfLoading)pdfLoading.hidden=true;
      await renderPdfPage(1);
      await loadPdfOutline();
    }catch(error){showPdfError(error);}
  };

  const showPdfError=error=>{
    if(pdfLoading){pdfLoading.hidden=false;pdfLoading.style.display='grid';pdfLoading.textContent='بارگذاری سند انجام نشد. فایل ممکن است خراب یا غیرقابل نمایش باشد.';}
    console.error('FARAST reader PDF error',error);
  };

  const showItem=async(index)=>{
    itemIndex=clamp(Number(index)||0,0,articles.length-1);
    articles.forEach((article,i)=>article.classList.toggle('is-active',i===itemIndex));
    if(!isPdf()&&continuousMode){
      continuousMode=false;continuousObserver?.disconnect();continuousObserver=null;
      pdfSurface?.classList.remove('is-continuous');
      if(continuousHost)continuousHost.innerHTML='';
      scrollModeButton?.setAttribute('aria-pressed','false');
    }
    const pdfActive=isPdf();
    if(scrollModeButton)scrollModeButton.hidden=!pdfActive;
    if(searchGroup)searchGroup.hidden=!pdfActive;
    if(outlineSection&&!pdfActive)outlineSection.hidden=true;
    if(rotateButton)rotateButton.hidden=Boolean(activeArticle()?.querySelector('.farast-xlsx-frame'));
    root.querySelectorAll('.farast-reader-thumb[data-goto]').forEach(thumb=>{
      const active=Number(thumb.dataset.goto)===itemIndex;
      thumb.classList.toggle('is-active',active);
      thumb.setAttribute('aria-current',active?'page':'false');
    });
    root.querySelectorAll('.farast-reader-thumb[data-pdf-page]').forEach(t=>t.remove());
    if(isPdf()){
      await loadPdf();
      if(pdfDoc)await buildPdfThumbs();
    }else{
      setPageControls(itemIndex+1,articles.length);
      zoom=1;rotation=0;setZoomLabel();
      activeArticle()?.style.setProperty('--reader-zoom','1');
    }
    stage?.scrollTo({top:0,behavior:'smooth'});
  };

  const setZoom=async(next)=>{
    zoom=clamp(next,.6,2.5);
    setZoomLabel();
    if(isPdf()&&pdfDoc){if(continuousMode)await refreshContinuousVisiblePages();else await renderPdfPage(pdfPage);}
    else activeArticle()?.style.setProperty('--reader-zoom',String(zoom));
  };

  const fitWidth=async()=>{
    zoom=0;
    setZoomLabel();
    if(isPdf()&&pdfDoc){if(continuousMode)await refreshContinuousVisiblePages();else await renderPdfPage(pdfPage);}
    else setZoom(1);
  };

  const searchPdf=async()=>{
    const query=(searchInput?.value||'').trim().toLocaleLowerCase();
    if(!query){if(searchCount)searchCount.textContent='';return;}
    if(!pdfDoc){if(searchCount)searchCount.textContent='سند هنوز بارگذاری نشده';return;}
    pdfMatches=[];matchIndex=-1;
    for(let n=1;n<=pdfDoc.numPages;n++){
      const page=await pdfDoc.getPage(n);
      const content=await page.getTextContent();
      const text=content.items.map(item=>item.str||'').join(' ').toLocaleLowerCase();
      const count=text.split(query).length-1;
      if(count>0)pdfMatches.push({page:n,count});
    }
    const total=pdfMatches.reduce((sum,item)=>sum+item.count,0);
    if(searchCount)searchCount.textContent=total?faNumber(total)+' نتیجه در '+faNumber(pdfMatches.length)+' صفحه':'بدون نتیجه';
    if(pdfMatches.length){matchIndex=0;await navigatePdfPage(pdfMatches[0].page);if(searchCount)searchCount.textContent=faNumber(matchIndex+1)+' / '+faNumber(pdfMatches.length)+' صفحه نتیجه';}
  };

  root.querySelector('#reader-prev')?.addEventListener('click',()=>{
    if(isPdf()&&pdfDoc)navigatePdfPage(pdfPage-1).catch(showPdfError);
    else showItem(itemIndex-1);
  });
  root.querySelector('#reader-next')?.addEventListener('click',()=>{
    if(isPdf()&&pdfDoc)navigatePdfPage(pdfPage+1).catch(showPdfError);
    else showItem(itemIndex+1);
  });
  root.querySelectorAll('.farast-reader-thumb[data-goto]').forEach(t=>t.addEventListener('click',()=>showItem(Number(t.dataset.goto))));
  pageInput?.addEventListener('change',()=>{
    const value=Number(pageInput.value)||1;
    if(isPdf()&&pdfDoc)renderPdfPage(value).catch(showPdfError);
    else showItem(value-1);
  });
  root.querySelector('#reader-zoom-in')?.addEventListener('click',()=>setZoom(zoom===0?1.1:zoom+.1).catch(showPdfError));
  root.querySelector('#reader-zoom-out')?.addEventListener('click',()=>setZoom(zoom===0?.9:zoom-.1).catch(showPdfError));
  root.querySelector('#reader-fit')?.addEventListener('click',()=>fitWidth().catch(showPdfError));
  root.querySelector('#reader-rotate')?.addEventListener('click',()=>{rotation=(rotation+90)%360;if(isPdf()&&pdfDoc){if(continuousMode)refreshContinuousVisiblePages().catch(showPdfError);else renderPdfPage(pdfPage).catch(showPdfError);}else activeArticle()?.querySelector('img')?.style.setProperty('transform','rotate('+rotation+'deg) scale('+zoom+')');});
  scrollModeButton?.addEventListener('click',async()=>{
    if(!isPdf()||!pdfDoc)return;
    continuousMode=!continuousMode;
    scrollModeButton.setAttribute('aria-pressed',String(continuousMode));
    scrollModeButton.title=continuousMode?'بازگشت به تک‌صفحه‌ای':'پیمایش پیوسته';
    if(continuousMode){
      await buildContinuousPages();
      await navigatePdfPage(pdfPage);
    }else{
      continuousObserver?.disconnect();continuousObserver=null;
      pdfSurface?.classList.remove('is-continuous');
      if(continuousHost)continuousHost.innerHTML='';
      if(pdfSurface){pdfSurface.style.width='';pdfSurface.style.minHeight='';}
      await renderPdfPage(pdfPage);
    }
  });
  const moveSearchMatch=async(direction)=>{
    if(!pdfMatches.length)return;
    matchIndex=(matchIndex+direction+pdfMatches.length)%pdfMatches.length;
    await navigatePdfPage(pdfMatches[matchIndex].page);
    if(searchCount)searchCount.textContent=faNumber(matchIndex+1)+' / '+faNumber(pdfMatches.length)+' صفحه نتیجه';
  };
  root.querySelector('#reader-search')?.addEventListener('click',()=>searchPdf().catch(showPdfError));
  root.querySelector('#reader-search-prev')?.addEventListener('click',()=>moveSearchMatch(-1).catch(showPdfError));
  root.querySelector('#reader-search-next')?.addEventListener('click',()=>moveSearchMatch(1).catch(showPdfError));
  searchInput?.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();searchPdf().catch(showPdfError);}});
  root.querySelector('#reader-thumbs-toggle')?.addEventListener('click',e=>{const hidden=root.classList.toggle('is-sidebar-hidden');e.currentTarget.setAttribute('aria-expanded',String(!hidden));});
  root.querySelector('#reader-theme')?.addEventListener('click',()=>{root.dataset.theme=root.dataset.theme==='light'?'dark':'light';});
  root.querySelector('#reader-fs')?.addEventListener('click',async()=>{try{if(!document.fullscreenElement)await root.requestFullscreen?.();else await document.exitFullscreen?.();}catch(_){root.classList.toggle('is-fullscreen-fallback');}});
  document.addEventListener('keydown',e=>{
    if(e.target&&['INPUT','TEXTAREA','SELECT'].includes(e.target.tagName))return;
    if(e.key==='ArrowLeft'||e.key==='ArrowDown'){e.preventDefault();root.querySelector('#reader-next')?.click();}
    if(e.key==='ArrowRight'||e.key==='ArrowUp'){e.preventDefault();root.querySelector('#reader-prev')?.click();}
    if(e.key==='+'||e.key==='='){e.preventDefault();root.querySelector('#reader-zoom-in')?.click();}
    if(e.key==='-'){e.preventDefault();root.querySelector('#reader-zoom-out')?.click();}
    if(e.key.toLowerCase()==='f'){e.preventDefault();root.querySelector('#reader-fs')?.click();}
    if((e.ctrlKey||e.metaKey)&&['p','s','c','x'].includes(e.key.toLowerCase()))e.preventDefault();
  });
  root.addEventListener('contextmenu',e=>e.preventDefault());
  root.addEventListener('copy',e=>e.preventDefault());
  root.addEventListener('cut',e=>e.preventDefault());
  root.addEventListener('dragstart',e=>e.preventDefault());
  window.addEventListener('resize',()=>{if(isPdf()&&pdfDoc&&zoom===0){if(continuousMode)refreshContinuousVisiblePages().catch(showPdfError);else renderPdfPage(pdfPage).catch(showPdfError);}});
  setZoomLabel();
  showItem(0).catch(showPdfError);
})();
