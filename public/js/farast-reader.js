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

  let pdfjsPromise=null;
  const getPdfjs=()=>{
    if(window.pdfjsLib)return Promise.resolve(window.pdfjsLib);
    if(!pdfjsPromise){
      pdfjsPromise=import('/vendor/pdfjs/legacy/build/pdf.mjs')
        .then(module=>{window.pdfjsLib=module;return module;})
        .catch(error=>{pdfjsPromise=null;throw error;});
    }
    return pdfjsPromise;
  };

  let itemIndex=0,zoom=1,rotation=0,pdfDoc=null,pdfPage=1,pdfMatches=[],matchIndex=-1,renderTask=null,continuousMode=false,continuousObserver=null,continuousFallbackHandler=null,continuousFallbackFrame=0,activePdfUrl=null,searchRequest=0;
  const clamp=(n,min,max)=>Math.min(max,Math.max(min,n));
  const activeArticle=()=>articles[itemIndex];
  const isPdf=()=>Boolean(activeArticle()?.querySelector('.farast-pdf-document'));
  const faNumber=n=>Number(n).toLocaleString('fa-IR');
  const productKey=String(root.dataset.productId||location.pathname);
  const storageKey='farast-reader:v1:'+productKey;
  const resumeButton=root.querySelector('#reader-resume');
  const bookmarkButton=root.querySelector('#reader-bookmark-toggle');
  const bookmarksOpenButton=root.querySelector('#reader-bookmarks-open');
  const bookmarksPanel=root.querySelector('#reader-bookmarks-panel');
  let restoringInitial=true;
  let readerState={progress:null,bookmarks:[]};
  try{
    const stored=JSON.parse(localStorage.getItem(storageKey)||'null');
    if(stored&&typeof stored==='object'){
      const saved=stored.progress;
      const bookmarks=Array.isArray(stored.bookmarks)?stored.bookmarks:[];
      readerState={
        progress:saved&&Number.isInteger(saved.section)&&Number.isInteger(saved.page)&&saved.section>=0&&saved.section<articles.length&&saved.page>0?saved:null,
        bookmarks:bookmarks.filter(item=>item&&Number.isInteger(item.section)&&Number.isInteger(item.page)&&item.section>=0&&item.section<articles.length&&item.page>0).slice(0,100)
      };
    }
  }catch(_){readerState={progress:null,bookmarks:[]};}

  const currentPosition=()=>({section:itemIndex,page:isPdf()?pdfPage:1});
  const samePosition=(a,b)=>Boolean(a&&b&&a.section===b.section&&a.page===b.page);
  const persistReaderState=()=>{
    try{localStorage.setItem(storageKey,JSON.stringify(readerState));}catch(_){}
  };
  const remoteStateUrl=root.dataset.readerStateUrl||'';
  let remoteSyncTimer=null;
  const queueRemoteSync=()=>{
    if(!remoteStateUrl)return;
    if(remoteSyncTimer)clearTimeout(remoteSyncTimer);
    remoteSyncTimer=setTimeout(async()=>{
      const csrf=document.querySelector('meta[name="csrf-token"]')?.content||'';
      try{
        await fetch(remoteStateUrl,{
          method:'PUT',
          credentials:'same-origin',
          headers:{'Accept':'application/json','Content-Type':'application/json','X-CSRF-TOKEN':csrf,'X-Requested-With':'XMLHttpRequest'},
          body:JSON.stringify({progress:readerState.progress,bookmarks:readerState.bookmarks})
        });
      }catch(_){}
    },650);
  };
  const loadRemoteReaderState=async()=>{
    if(!remoteStateUrl)return;
    try{
      const response=await fetch(remoteStateUrl,{credentials:'same-origin',headers:{'Accept':'application/json'}});
      if(!response.ok)return;
      const remote=await response.json();
      if(remote.updated_at){
        const saved=remote.progress;
        readerState.progress=saved&&Number.isInteger(saved.section)&&Number.isInteger(saved.page)&&saved.section>=0&&saved.section<articles.length&&saved.page>0?saved:null;
        readerState.bookmarks=(Array.isArray(remote.bookmarks)?remote.bookmarks:[])
          .filter(item=>item&&Number.isInteger(item.section)&&Number.isInteger(item.page)&&item.section>=0&&item.section<articles.length&&item.page>0).slice(0,100);
      }else if(readerState.progress||readerState.bookmarks.length){
        queueRemoteSync();
      }
      persistReaderState();
      renderBookmarks();
      refreshResumeButton();
      refreshBookmarkButton();
    }catch(_){}
  };
  const refreshResumeButton=()=>{
    if(!resumeButton)return;
    const saved=readerState.progress;
    const current=currentPosition();
    const available=saved&&!samePosition(saved,current);
    resumeButton.hidden=!available;
    if(available){
      const label='ادامه از بخش '+faNumber(saved.section+1)+(saved.page>1?' · صفحه '+faNumber(saved.page):'');
      resumeButton.title=label;
      resumeButton.querySelector('span')?.replaceChildren(document.createTextNode(label));
    }
  };
  const saveReaderProgress=()=>{
    if(restoringInitial)return;
    readerState.progress=currentPosition();
    persistReaderState();
    refreshResumeButton();
    queueRemoteSync();
  };
  const isCurrentBookmarked=()=>readerState.bookmarks.some(item=>samePosition(item,currentPosition()));
  const refreshBookmarkButton=()=>{
    if(!bookmarkButton)return;
    const active=isCurrentBookmarked();
    bookmarkButton.setAttribute('aria-pressed',String(active));
    bookmarkButton.title=active?'حذف نشانک این صفحه':'ثبت نشانک این صفحه';
    const icon=bookmarkButton.querySelector('i');
    if(icon){icon.classList.toggle('fa-solid',active);icon.classList.toggle('fa-regular',!active);}
  };
  const renderBookmarks=()=>{
    if(!bookmarksPanel)return;
    bookmarksPanel.replaceChildren();
    if(!readerState.bookmarks.length){
      const empty=document.createElement('span');
      empty.className='farast-reader-bookmarks-empty';
      empty.textContent='هنوز نشانکی ذخیره نشده است.';
      bookmarksPanel.append(empty);
      return;
    }
    readerState.bookmarks.forEach((bookmark,index)=>{
      const item=document.createElement('div');
      item.className='farast-reader-bookmark-item';
      const go=document.createElement('button');
      go.type='button';
      go.textContent='بخش '+faNumber(bookmark.section+1)+(bookmark.page>1?' · صفحه '+faNumber(bookmark.page):'');
      go.addEventListener('click',async()=>{
        try{
          await showItem(bookmark.section);
          if(isPdf()&&pdfDoc)await navigatePdfPage(bookmark.page);
          bookmarksPanel.hidden=true;
          bookmarksOpenButton?.setAttribute('aria-expanded','false');
        }catch(error){showPdfError(error);}
      });
      const remove=document.createElement('button');
      remove.type='button';
      remove.className='farast-reader-bookmark-remove';
      remove.setAttribute('aria-label','حذف نشانک '+(index+1));
      remove.textContent='×';
      remove.addEventListener('click',()=>{
        readerState.bookmarks.splice(index,1);
        persistReaderState();renderBookmarks();refreshBookmarkButton();queueRemoteSync();
      });
      item.append(go,remove);
      bookmarksPanel.append(item);
    });
  };

  const updateProgress=(current,total)=>{
    const percent=Math.round((current/Math.max(total,1))*100);
    if(progress)progress.style.width=percent+'%';
    if(progressBar)progressBar.setAttribute('aria-valuenow',String(percent));
  };

  const setPageControls=(current,total)=>{
    if(pageInput){pageInput.max=String(total);pageInput.value=String(current);}
    if(pageTotal)pageTotal.textContent='/ '+faNumber(total);
    updateProgress(current,total);
    saveReaderProgress();
    refreshBookmarkButton();
    root.dispatchEvent(new CustomEvent('farast:reader-page-change', { detail: { page: Number(current) || 1 } }));
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
    const label=identity+' · FARAST';
    const fontSize=Math.max(20,Math.min(34,Math.round(width/40)));
    context.save();
    context.setTransform(1,0,0,1,0,0);
    context.globalAlpha=.24;
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
        span.style.userSelect='text';span.style.webkitUserSelect='text';
        span.classList.toggle('reader-search-hit',Boolean(query)&&span.textContent.toLocaleLowerCase().includes(query));
      });
    }
    if(pdfLoading){pdfLoading.hidden=true;pdfLoading.style.display='none';}
    setPageControls(pdfPage,pdfDoc.numPages);
  };

  const releaseContinuousPage=wrapper=>{
    if(!wrapper)return;
    wrapper.dataset.keepRendered='0';
    if(wrapper.dataset.rendering==='1')return;
    wrapper.dataset.rendered='0';
    const canvas=wrapper.querySelector('canvas');
    if(canvas){canvas.width=0;canvas.height=0;canvas.style.width='';canvas.style.height='';}
    const text=wrapper.querySelector('.farast-pdf-cont-text');
    if(text){text.innerHTML='';text.style.width='';text.style.height='';}
    // Preserve the measured page box so off-screen pages remain scrollable placeholders.
    const width=Number(wrapper.dataset.renderedWidth)||0;
    const height=Number(wrapper.dataset.renderedHeight)||0;
    wrapper.style.width=width>0?width+'px':'';
    wrapper.style.minHeight=height>0?height+'px':'';
  };

  const clearContinuousFallback=()=>{
    if(continuousFallbackHandler&&stage)stage.removeEventListener('scroll',continuousFallbackHandler);
    continuousFallbackHandler=null;
    if(continuousFallbackFrame){cancelAnimationFrame(continuousFallbackFrame);continuousFallbackFrame=0;}
  };

  const renderContinuousPage=async(number)=>{
    if(!pdfDoc||!continuousHost)return;
    const wrapper=continuousHost.querySelector('[data-pdf-cont-page="'+number+'"]');
    const canvas=wrapper?.querySelector('canvas');
    if(!wrapper||!canvas)return;
    wrapper.dataset.keepRendered='1';
    if(wrapper.dataset.rendered==='1'||wrapper.dataset.rendering==='1')return;
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
    wrapper.dataset.renderedWidth=String(Math.round(viewport.width));
    wrapper.dataset.renderedHeight=String(Math.round(viewport.height));
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
        span.style.userSelect='text';span.style.webkitUserSelect='text';
        span.classList.toggle('reader-search-hit',Boolean(query)&&span.textContent.toLocaleLowerCase().includes(query));
      });
    }
    wrapper.dataset.rendered='1';
    }finally{
      const rerender=wrapper.dataset.rerenderAfterRender==='1'&&wrapper.dataset.keepRendered==='1';
      delete wrapper.dataset.rerenderAfterRender;
      delete wrapper.dataset.rendering;
      if(wrapper.dataset.keepRendered==='0')releaseContinuousPage(wrapper);
      else if(rerender){
        wrapper.dataset.rendered='0';
        requestAnimationFrame(()=>renderContinuousPage(number).catch(showPdfError));
      }
    }
  };

  const buildContinuousPages=async()=>{
    if(!pdfDoc||!continuousHost)return;
    continuousHost.hidden=false;
    continuousObserver?.disconnect();
    continuousObserver=null;
    clearContinuousFallback();
    continuousHost.innerHTML='';
    const fragment=document.createDocumentFragment();
    for(let n=1;n<=pdfDoc.numPages;n++){
      const wrapper=document.createElement('section');
      wrapper.className='farast-pdf-cont-page';
      wrapper.dataset.pdfContPage=String(n);
      wrapper.dataset.rendered='0';
      wrapper.dataset.keepRendered='0';
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
          const number=Number(entry.target.dataset.pdfContPage);
          if(entry.isIntersecting){
            entry.target.dataset.keepRendered='1';
            if(!best||entry.intersectionRatio>best.ratio)best={number,ratio:entry.intersectionRatio};
            renderContinuousPage(number).catch(showPdfError);
          }else{
            releaseContinuousPage(entry.target);
          }
        });
        if(best){pdfPage=best.number;setPageControls(best.number,pdfDoc.numPages);}
      },{root:stage,rootMargin:'650px 0px',threshold:[.05,.15,.35,.6]});
      continuousHost.querySelectorAll('[data-pdf-cont-page]').forEach(node=>continuousObserver.observe(node));
      // Render only pages near the viewport; off-screen canvases are released as they leave the window.
      renderContinuousPage(pdfPage).catch(showPdfError);
    }else{
      continuousFallbackHandler=()=>{
        if(continuousFallbackFrame)return;
        continuousFallbackFrame=requestAnimationFrame(()=>{
          continuousFallbackFrame=0;
          refreshContinuousVisiblePages(false).catch(showPdfError);
        });
      };
      stage?.addEventListener('scroll',continuousFallbackHandler,{passive:true});
      await refreshContinuousVisiblePages();
    }
  };

  const refreshContinuousVisiblePages=async(force=true)=>{
    if(!continuousMode||!stage||!continuousHost)return;
    const stageRect=stage.getBoundingClientRect();
    const nodes=[...continuousHost.querySelectorAll('[data-pdf-cont-page]')];
    const visible=nodes.filter(node=>{
      const rect=node.getBoundingClientRect();
      return rect.bottom>=stageRect.top-180&&rect.top<=stageRect.bottom+180;
    });
    const visibleSet=new Set(visible);
    const pending=[];
    nodes.forEach(node=>{
      if(!visibleSet.has(node)){
        releaseContinuousPage(node);
        return;
      }
      node.dataset.keepRendered='1';
      if(force){
        if(node.dataset.rendering==='1'){
          node.dataset.rerenderAfterRender='1';
        }else{
          releaseContinuousPage(node);
        }
      }
      if(node.dataset.rendered!=='1'&&node.dataset.rendering!=='1')pending.push(node);
    });
    for(const node of pending)await renderContinuousPage(Number(node.dataset.pdfContPage));
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
    // Render only thumbnails entering the sidebar viewport; never rasterize dozens
    // of off-screen pages just because a document was opened.
    const renderThumbnail=async button=>{
      if(!button||button.dataset.thumbRendered==='1')return;
      button.dataset.thumbRendered='1';
      const pageNumber=Number(button.dataset.pdfPage);
      const canvas=button.querySelector('canvas');
      if(!canvas)return;
      try{
        const page=await pdfDoc.getPage(pageNumber);
        const base=page.getViewport({scale:1});
        const viewport=page.getViewport({scale:Math.min(40/base.width,54/base.height)});
        const ctx=canvas.getContext('2d',{alpha:false});
        canvas.width=Math.max(1,Math.floor(viewport.width));
        canvas.height=Math.max(1,Math.floor(viewport.height));
        await page.render({canvasContext:ctx,viewport}).promise;
        paintPdfWatermark(canvas);
      }catch(_){button.dataset.thumbRendered='0';}
    };
    if('IntersectionObserver' in window){
      const observer=new IntersectionObserver(entries=>{
        entries.forEach(entry=>{
          if(entry.isIntersecting){
            observer.unobserve(entry.target);
            renderThumbnail(entry.target);
          }
        });
      },{root:thumbsHost.closest('.farast-reader-sidebar'),rootMargin:'160px 0px'});
      thumbsHost.querySelectorAll('.farast-reader-thumb[data-pdf-page]').forEach(button=>observer.observe(button));
    }else{
      for(const button of [...thumbsHost.querySelectorAll('.farast-reader-thumb[data-pdf-page]')].slice(0,4)){
        await renderThumbnail(button);
      }
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
        const pdfjs=await getPdfjs();
        pdfjs.GlobalWorkerOptions.workerSrc='/vendor/pdfjs/legacy/build/pdf.worker.mjs';
        pdfDoc=await pdfjs.getDocument({url:nextUrl,withCredentials:true,disableAutoFetch:true,disableStream:true,isEvalSupported:false,cMapUrl:'/vendor/pdfjs/cmaps/',cMapPacked:true,standardFontDataUrl:'/vendor/pdfjs/standard_fonts/',wasmUrl:'/vendor/pdfjs/wasm/'}).promise;
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
    const nextIndex=clamp(Number(index)||0,0,articles.length-1);
    if(nextIndex!==itemIndex){searchRequest++;pdfMatches=[];matchIndex=-1;}
    itemIndex=nextIndex;
    articles.forEach((article,i)=>article.classList.toggle('is-active',i===itemIndex));
    root.dispatchEvent(new CustomEvent('farast:reader-section-change', { detail: { section: itemIndex } }));
    if(!isPdf()&&continuousMode){
      continuousMode=false;continuousObserver?.disconnect();continuousObserver=null;clearContinuousFallback();
      pdfSurface?.classList.remove('is-continuous');
      if(continuousHost)continuousHost.innerHTML='';
      scrollModeButton?.setAttribute('aria-pressed','false');
    }
    const pdfActive=isPdf();
    if(!pdfActive&&pdfDoc){
      if(renderTask){try{renderTask.cancel();}catch(_){}renderTask=null;}
      continuousObserver?.disconnect();continuousObserver=null;clearContinuousFallback();continuousMode=false;
      try{await pdfDoc.destroy();}catch(_){}
      pdfDoc=null;activePdfUrl=null;pdfCanvas=null;pdfSurface=null;pdfLoading=null;continuousHost=null;
      scrollModeButton?.setAttribute('aria-pressed','false');
    }
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
    const request=++searchRequest;
    const query=(searchInput?.value||'').trim().toLocaleLowerCase();
    if(!query){
      pdfMatches=[];matchIndex=-1;
      if(searchCount)searchCount.textContent='';
      if(isPdf()&&pdfDoc){
        if(continuousMode)await refreshContinuousVisiblePages();
        else await renderPdfPage(pdfPage);
      }
      return;
    }
    if(!pdfDoc){if(searchCount)searchCount.textContent='سند هنوز بارگذاری نشده';return;}
    pdfMatches=[];matchIndex=-1;
    for(let n=1;n<=pdfDoc.numPages;n++){
      const page=await pdfDoc.getPage(n);
      const content=await page.getTextContent();
      if(request!==searchRequest)return;
      const text=content.items.map(item=>item.str||'').join(' ').toLocaleLowerCase();
      const count=text.split(query).length-1;
      if(count>0)pdfMatches.push({page:n,count});
    }
    if(request!==searchRequest)return;
    const total=pdfMatches.reduce((sum,item)=>sum+item.count,0);
    if(searchCount)searchCount.textContent=total?faNumber(total)+' نتیجه در '+faNumber(pdfMatches.length)+' صفحه':'بدون نتیجه';
    if(pdfMatches.length){matchIndex=0;await navigatePdfPage(pdfMatches[0].page);if(request===searchRequest&&searchCount)searchCount.textContent=faNumber(matchIndex+1)+' / '+faNumber(pdfMatches.length)+' صفحه نتیجه';}
    else if(isPdf()&&pdfDoc){
      if(continuousMode)await refreshContinuousVisiblePages();
      else await renderPdfPage(pdfPage);
    }
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
    if(isPdf()&&pdfDoc)navigatePdfPage(value).catch(showPdfError);
    else showItem(value-1);
  });
  root.querySelector('#reader-zoom-in')?.addEventListener('click',()=>setZoom(zoom===0?1.1:zoom+.1).catch(showPdfError));
  root.querySelector('#reader-zoom-out')?.addEventListener('click',()=>setZoom(zoom===0?.9:zoom-.1).catch(showPdfError));
  root.querySelector('#reader-fit')?.addEventListener('click',()=>fitWidth().catch(showPdfError));
  root.querySelector('#reader-rotate')?.addEventListener('click',()=>{rotation=(rotation+90)%360;if(isPdf()&&pdfDoc){if(continuousMode)refreshContinuousVisiblePages().catch(showPdfError);else renderPdfPage(pdfPage).catch(showPdfError);}else activeArticle()?.querySelector('img')?.style.setProperty('transform','rotate('+rotation+'deg) scale('+zoom+')');});
  scrollModeButton?.addEventListener('click',async()=>{
    if(!isPdf()||!pdfDoc||scrollModeButton.disabled)return;
    scrollModeButton.disabled=true;
    try{
      continuousMode=!continuousMode;
      scrollModeButton.setAttribute('aria-pressed',String(continuousMode));
      scrollModeButton.title=continuousMode?'بازگشت به تک‌صفحه‌ای':'پیمایش پیوسته';
      if(continuousMode){
        await buildContinuousPages();
        await navigatePdfPage(pdfPage);
      }else{
        continuousObserver?.disconnect();continuousObserver=null;clearContinuousFallback();
        pdfSurface?.classList.remove('is-continuous');
        if(continuousHost){continuousHost.innerHTML='';continuousHost.hidden=true;}
        if(pdfSurface){pdfSurface.style.width='';pdfSurface.style.minHeight='';}
        await renderPdfPage(pdfPage);
      }
    }finally{
      scrollModeButton.disabled=false;
    }
  });
  const moveSearchMatch=async(direction)=>{
    if(!pdfMatches.length)return;
    matchIndex=(matchIndex+direction+pdfMatches.length)%pdfMatches.length;
    await navigatePdfPage(pdfMatches[matchIndex].page);
    if(searchCount)searchCount.textContent=faNumber(matchIndex+1)+' / '+faNumber(pdfMatches.length)+' صفحه نتیجه';
  };
  bookmarkButton?.addEventListener('click',()=>{
    const current=currentPosition();
    const index=readerState.bookmarks.findIndex(item=>samePosition(item,current));
    if(index>=0)readerState.bookmarks.splice(index,1);
    else readerState.bookmarks.unshift(current);
    readerState.bookmarks=readerState.bookmarks.slice(0,100);
    persistReaderState();renderBookmarks();refreshBookmarkButton();queueRemoteSync();
  });
  bookmarksOpenButton?.addEventListener('click',()=>{
    const open=bookmarksPanel?.hidden!==false;
    if(bookmarksPanel){bookmarksPanel.hidden=!open;if(open)renderBookmarks();}
    bookmarksOpenButton.setAttribute('aria-expanded',String(open));
  });
  resumeButton?.addEventListener('click',async()=>{
    const saved=readerState.progress;
    if(!saved)return;
    try{
      await showItem(saved.section);
      if(isPdf()&&pdfDoc&&saved.page>1)await navigatePdfPage(saved.page);
      refreshResumeButton();
    }catch(error){showPdfError(error);}
  });
  root.querySelector('#reader-search')?.addEventListener('click',()=>searchPdf().catch(showPdfError));
  root.querySelector('#reader-search-prev')?.addEventListener('click',()=>moveSearchMatch(-1).catch(showPdfError));
  root.querySelector('#reader-search-next')?.addEventListener('click',()=>moveSearchMatch(1).catch(showPdfError));
  searchInput?.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();searchPdf().catch(showPdfError);}});
  root.querySelector('#reader-thumbs-toggle')?.addEventListener('click',e=>{const hidden=root.classList.toggle('is-sidebar-hidden');e.currentTarget.setAttribute('aria-expanded',String(!hidden));});
  root.querySelector('#reader-theme')?.addEventListener('click',()=>{root.dataset.theme=root.dataset.theme==='light'?'dark':'light';});
  root.querySelector('#reader-fs')?.addEventListener('click',async()=>{try{if(!document.fullscreenElement)await root.requestFullscreen?.();else await document.exitFullscreen?.();}catch(_){root.classList.toggle('is-fullscreen-fallback');}});
  const isEditableTarget=target=>target instanceof Element&&Boolean(target.closest('input,textarea,select,[contenteditable="true"]'));
  const isReaderSpeechTarget=target=>target instanceof Element&&Boolean(target.closest('.farast-pdf-text-layer,.farast-pdf-cont-text,[data-reader-text-layer],.reader-document-word,.reader-document-sentence,.reader-document-paragraph,.reader-document-heading,.reader-document-cell,.reader-document-row'));
  document.addEventListener('keydown',e=>{
    const key=e.key.toLowerCase();
    const modifier=e.ctrlKey||e.metaKey;
    // Print/save must stay blocked even while focus is inside the search or page controls.
    if(modifier&&['p','s'].includes(key)){e.preventDefault();e.stopPropagation();return;}
    if(isEditableTarget(e.target)||isReaderSpeechTarget(e.target))return;
    if(e.key==='ArrowLeft'||e.key==='ArrowDown'||e.key==='PageDown'){e.preventDefault();root.querySelector('#reader-next')?.click();}
    if(e.key==='ArrowRight'||e.key==='ArrowUp'||e.key==='PageUp'){e.preventDefault();root.querySelector('#reader-prev')?.click();}
    if(e.key==='Home'){
      e.preventDefault();
      if(isPdf()){if(pdfDoc)navigatePdfPage(1).catch(showPdfError);}
      else showItem(0).catch(showPdfError);
    }
    if(e.key==='End'){
      e.preventDefault();
      if(isPdf()){if(pdfDoc)navigatePdfPage(pdfDoc.numPages).catch(showPdfError);}
      else showItem(articles.length-1).catch(showPdfError);
    }
    if(e.key==='+'||e.key==='='){e.preventDefault();root.querySelector('#reader-zoom-in')?.click();}
    if(e.key==='-'){e.preventDefault();root.querySelector('#reader-zoom-out')?.click();}
    if(key==='f'){e.preventDefault();root.querySelector('#reader-fs')?.click();}
    if(key==='b'){e.preventDefault();bookmarkButton?.click();}
    if(modifier&&['c','x'].includes(key))e.preventDefault();
  });
  root.addEventListener('contextmenu',e=>e.preventDefault());
  root.addEventListener('copy',e=>e.preventDefault());
  root.addEventListener('cut',e=>e.preventDefault());
  root.addEventListener('paste',e=>{if(!isEditableTarget(e.target))e.preventDefault();});
  root.addEventListener('selectstart',e=>{if(!isEditableTarget(e.target)&&!isReaderSpeechTarget(e.target))e.preventDefault();});
  root.addEventListener('dragstart',e=>e.preventDefault());
  window.addEventListener('resize',()=>{if(isPdf()&&pdfDoc&&zoom===0){if(continuousMode)refreshContinuousVisiblePages().catch(showPdfError);else renderPdfPage(pdfPage).catch(showPdfError);}});
  setZoomLabel();
  renderBookmarks();
  refreshBookmarkButton();
  loadRemoteReaderState().finally(()=>showItem(0)).then(()=>{
    restoringInitial=false;
    refreshResumeButton();
    refreshBookmarkButton();
  }).catch(showPdfError);
})();
