(()=>{
  'use strict';

  const boot=()=>{
    const app=document.getElementById('farastWord');
    if(!app || app.dataset.workspaceProReady==='1') return;
    app.dataset.workspaceProReady='1';

    const panels=[...app.querySelectorAll('.ribbon[id^="ribbon-"]')];
    const tabs=[...app.querySelectorAll('.word-tab[data-tab]')];
    const editor=document.getElementById('editor');
    const viewport=document.getElementById('pagesViewport');

    const tipMap={
      cut:'برش متن انتخاب‌شده',copy:'کپی متن انتخاب‌شده',selectAll:'انتخاب همه متن',undo:'واگرد',redo:'انجام دوباره',
      bold:'پررنگ',italic:'کج',underline:'زیرخط',strikeThrough:'خط‌خورده',superscript:'بالانویس',subscript:'پایین‌نویس',
      justifyRight:'تراز راست',justifyCenter:'تراز وسط',justifyLeft:'تراز چپ',justifyFull:'تراز دوطرفه',
      insertUnorderedList:'فهرست نشانه‌دار',insertOrderedList:'فهرست شماره‌دار',indent:'افزایش تورفتگی',outdent:'کاهش تورفتگی',
      removeFormat:'حذف قالب‌بندی',insertHorizontalRule:'خط افقی'
    };

    const labelTooltips=()=>{
      app.querySelectorAll('.ribbon button,.ribbon select,.ribbon label').forEach(el=>{
        if(el.dataset.tip) return;
        const cmd=el.dataset.cmd;
        let text=cmd && tipMap[cmd] ? tipMap[cmd] : (el.getAttribute('aria-label') || el.getAttribute('title') || el.textContent || 'ابزار ویرایش');
        text=text.replace(/\s+/g,' ').trim();
        if(text && text.length<80) el.dataset.tip=text;
      });
    };

    const setTab=(name,focus=false)=>{
      if(name==='file') return;
      let active=panels.find(p=>p.id==='ribbon-'+name);
      if(!active){ name='home'; active=panels.find(p=>p.id==='ribbon-home'); }
      panels.forEach(panel=>{
        const on=panel===active;
        panel.classList.toggle('hidden',!on);
        panel.setAttribute('aria-hidden',on?'false':'true');
        panel.hidden=!on;
      });
      tabs.forEach(tab=>{
        const on=tab.dataset.tab===name;
        tab.classList.toggle('active',on);
        tab.setAttribute('aria-selected',on?'true':'false');
        tab.setAttribute('tabindex',on?'0':'-1');
      });
      app.dataset.activeRibbon=name;
      if(focus && active) active.querySelector('button,select,input')?.focus({preventScroll:true});
    };

    app.addEventListener('click',(event)=>{
      const tab=event.target.closest?.('.word-tab[data-tab]');
      if(!tab || !app.contains(tab) || tab.dataset.tab==='file') return;
      event.preventDefault();
      event.stopImmediatePropagation();
      setTab(tab.dataset.tab);
    },true);

    setTab((tabs.find(t=>t.classList.contains('active'))||tabs[0])?.dataset.tab||'home');
    labelTooltips();

    const enforceScroll=()=>{
      if(!viewport) return;
      viewport.style.overflow='auto';
      viewport.style.overflowY='auto';
      viewport.style.overflowX='auto';
      viewport.style.minHeight='0';
      const area=document.getElementById('documentArea');
      if(area) area.style.minHeight='0';
      const body=app.querySelector('.word-body');
      if(body) body.style.minHeight='0';
    };
    enforceScroll();
    if(window.ResizeObserver) new ResizeObserver(enforceScroll).observe(app);

    const menu=document.createElement('div');
    menu.className='farast-editor-context-menu';
    menu.hidden=true;
    menu.setAttribute('role','menu');
    menu.innerHTML=`
      <button type="button" data-action="undo"><i class="fa-solid fa-rotate-left"></i><span>واگرد</span></button>
      <button type="button" data-action="redo"><i class="fa-solid fa-rotate-right"></i><span>انجام دوباره</span></button>
      <div class="ctx-sep"></div>
      <button type="button" data-action="cut"><i class="fa-solid fa-scissors"></i><span>برش</span></button>
      <button type="button" data-action="copy"><i class="fa-regular fa-copy"></i><span>کپی</span></button>
      <button type="button" data-action="paste"><i class="fa-regular fa-clipboard"></i><span>چسباندن</span></button>
      <button type="button" data-action="selectAll"><i class="fa-solid fa-check-double"></i><span>انتخاب همه</span></button>
      <div class="ctx-sep"></div>
      <button type="button" data-action="bold"><i class="fa-solid fa-bold"></i><span>پررنگ</span></button>
      <button type="button" data-action="italic"><i class="fa-solid fa-italic"></i><span>کج</span></button>
      <button type="button" data-action="underline"><i class="fa-solid fa-underline"></i><span>زیرخط</span></button>
      <button type="button" data-action="removeFormat"><i class="fa-solid fa-eraser"></i><span>حذف قالب‌بندی</span></button>
      <div class="ctx-sep"></div>
      <button type="button" data-action="pageBreak"><i class="fa-regular fa-file-lines"></i><span>شکست صفحه</span></button>
      <button type="button" data-action="find"><i class="fa-solid fa-magnifying-glass"></i><span>یافتن</span></button>
      <div class="ctx-hint">منوی راست‌کلیک فراست</div>`;
    app.appendChild(menu);

    let savedRange=null;
    const rememberSelection=()=>{
      const sel=window.getSelection?.();
      if(!sel || !sel.rangeCount || !editor || !editor.contains(sel.anchorNode)) return;
      savedRange=sel.getRangeAt(0).cloneRange();
    };
    const restoreSelection=()=>{
      if(!savedRange) return;
      const sel=window.getSelection();
      sel.removeAllRanges();
      sel.addRange(savedRange);
    };
    const command=(cmd,value=null)=>{
      restoreSelection();
      try{ document.execCommand(cmd,false,value); }catch(_e){}
      editor?.dispatchEvent(new Event('input',{bubbles:true}));
      rememberSelection();
    };
    const insertText=(text)=>command('insertText',text);

    const showMenu=(x,y)=>{
      menu.hidden=false;
      const w=menu.offsetWidth,h=menu.offsetHeight;
      menu.style.left=Math.max(8,Math.min(x,innerWidth-w-8))+'px';
      menu.style.top=Math.max(8,Math.min(y,innerHeight-h-8))+'px';
      menu.querySelector('button')?.focus({preventScroll:true});
    };
    const hideMenu=()=>{menu.hidden=true;};

    editor?.addEventListener('mouseup',rememberSelection);
    editor?.addEventListener('keyup',rememberSelection);
    editor?.addEventListener('input',rememberSelection);
    editor?.addEventListener('contextmenu',(event)=>{
      event.preventDefault();
      rememberSelection();
      showMenu(event.clientX,event.clientY);
    });
    app.addEventListener('contextmenu',(event)=>{
      if(event.target.closest('.farast-editor-context-menu')) return;
      event.preventDefault();
    },true);
    document.addEventListener('mousedown',(event)=>{if(!menu.contains(event.target)) hideMenu();},true);
    document.addEventListener('scroll',hideMenu,true);
    window.addEventListener('resize',hideMenu);

    menu.addEventListener('click',(event)=>{
      const btn=event.target.closest('button[data-action]');
      if(!btn) return;
      event.preventDefault();
      const a=btn.dataset.action;
      if(a==='paste'){
        navigator.clipboard?.readText?.().then(text=>insertText(text)).catch(()=>{});
      }else if(a==='pageBreak'){
        restoreSelection();
        const el=document.createElement('div');
        el.className='page-break';
        el.textContent='شکست صفحه';
        el.setAttribute('contenteditable','false');
        const sel=window.getSelection();
        if(sel?.rangeCount){const range=sel.getRangeAt(0);range.deleteContents();range.insertNode(el);range.collapse(false);}
        editor?.dispatchEvent(new Event('input',{bubbles:true}));
      }else if(a==='find'){
        document.getElementById('findText')?.click();
      }else{
        command(a);
      }
      hideMenu();
    });

    editor?.addEventListener('keydown',(event)=>{
      if((event.ctrlKey||event.metaKey) && !event.altKey){
        const k=event.key.toLowerCase();
        const map={b:'bold',i:'italic',u:'underline',z:'undo',y:'redo'};
        if(map[k]){event.preventDefault();command(map[k]);}
        else if(k==='a'){event.preventDefault();command('selectAll');}
      }
    });

    const addGroup=(panelId,title,items)=>{
      const panel=document.getElementById(panelId);
      if(!panel || [...panel.querySelectorAll('.ribbon-group')].some(g=>g.dataset.workspaceGroup===title)) return;
      const group=document.createElement('div');
      group.className='ribbon-group';
      group.dataset.group=title;
      group.dataset.workspaceGroup=title;
      items.forEach(item=>{
        const b=document.createElement('button');
        b.type='button';
        b.dataset.tip=item.tip;
        b.innerHTML=item.html||item.label;
        if(item.handler) b.addEventListener('click',item.handler);
        group.appendChild(b);
      });
      panel.appendChild(group);
    };

    addGroup('ribbon-home','فونت پیشرفته',[
      {html:'A⁺',tip:'افزایش اندازه قلم',handler:()=>command('fontSize','5')},
      {html:'A⁻',tip:'کاهش اندازه قلم',handler:()=>command('fontSize','2')},
      {html:'x²',tip:'بالانویس',handler:()=>command('superscript')},
      {html:'x₂',tip:'پایین‌نویس',handler:()=>command('subscript')},
      {html:'پاک',tip:'حذف همه قالب‌بندی',handler:()=>command('removeFormat')}
    ]);
    addGroup('ribbon-home','پاراگراف پیشرفته',[
      {html:'≡→',tip:'تراز راست',handler:()=>command('justifyRight')},
      {html:'≡↔',tip:'تراز وسط',handler:()=>command('justifyCenter')},
      {html:'←≡',tip:'تراز چپ',handler:()=>command('justifyLeft')},
      {html:'☰',tip:'تراز دوطرفه',handler:()=>command('justifyFull')},
      {html:'نیم‌فاصله',tip:'درج نیم‌فاصله',handler:()=>insertText('\u200c')}
    ]);
    addGroup('ribbon-insert','عناصر سند',[
      {html:'سربرگ',tip:'درج سربرگ سند',handler:()=>insertText('\nسربرگ سند\n')},
      {html:'پابرگ',tip:'درج پابرگ سند',handler:()=>insertText('\nپابرگ سند\n')},
      {html:'# صفحه',tip:'درج شماره صفحه',handler:()=>insertText('صفحه ۱')},
      {html:'¶',tip:'نمایش نشانه‌های قالب‌بندی',handler:()=>editor?.classList.toggle('show-guides')}
    ]);
    addGroup('ribbon-layout','تنظیمات پیشرفته صفحه',[
      {html:'شکست ستون',tip:'درج شکست ستون',handler:()=>insertText('\n\n')},
      {html:'↕ پاراگراف',tip:'افزایش فاصله خطوط',handler:()=>{if(editor) editor.style.lineHeight='2.1';}},
      {html:'بازنشانی',tip:'بازنشانی فاصله خطوط',handler:()=>{if(editor) editor.style.lineHeight='1.9';}}
    ]);
    addGroup('ribbon-review','حاشیه‌نویسی',[
      {html:'یادداشت',tip:'افزودن یادداشت به متن انتخاب‌شده',handler:()=>{const note=window.prompt('متن یادداشت را وارد کنید');if(note) insertText(` [یادداشت: ${note}] `);}},
      {html:'برجسته',tip:'برجسته‌کردن متن انتخاب‌شده',handler:()=>command('hiliteColor','#fff2a8')},
      {html:'حذف برجسته',tip:'حذف رنگ زمینه متن',handler:()=>command('hiliteColor','transparent')}
    ]);
    labelTooltips();

    const observer=new MutationObserver(()=>{
      if(app.dataset.activeRibbon==='file') return;
      const active=tabs.find(t=>t.classList.contains('active'))?.dataset.tab || app.dataset.activeRibbon || 'home';
      const visible=panels.filter(p=>!p.classList.contains('hidden'));
      if(visible.length!==1 || visible[0].id!=='ribbon-'+active) setTab(active);
    });
    observer.observe(app,{subtree:true,childList:false,attributes:true,attributeFilter:['class','hidden']});

    window.FarastEditorWorkspace={version:'1.0.1',setTab,enforceScroll,showContextMenu:showMenu};
  };

  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',boot,{once:true});
  else boot();
})();
