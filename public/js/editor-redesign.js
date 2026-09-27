(()=>{
'use strict';

const ready=fn=>document.readyState==='loading'?document.addEventListener('DOMContentLoaded',fn,{once:true}):fn();
const csrf=()=>document.querySelector('meta[name="csrf-token"]')?.content||'';
const $=s=>document.querySelector(s);
const $$=s=>[...document.querySelectorAll(s)];

const TOOLS={
 home:[
  ['clipboard','کلیپ‌بورد',[
   ['paste','fa-regular fa-clipboard','چسباندن — Ctrl+V'],['cut','fa-solid fa-scissors','برش — Ctrl+X'],['copy','fa-regular fa-copy','کپی — Ctrl+C'],['formatPainter','fa-solid fa-paintbrush','قلم قالب‌بندی'],
   ['pasteSpecial','fa-solid fa-clipboard-check','چسباندن ویژه'],['keepSource','fa-solid fa-file-import','حفظ قالب منبع'],['mergeFormatting','fa-solid fa-code-merge','ادغام قالب‌بندی'],['keepText','fa-solid fa-font','فقط متن'],['defaultPaste','fa-solid fa-thumbtack','تنظیم نوع چسباندن پیش‌فرض']]],
  ['font','قلم',[
   ['fontName','fa-solid fa-font','انتخاب قلم'],['fontSize','fa-solid fa-text-height','اندازه قلم'],['growFont','fa-solid fa-arrow-up-a-z','افزایش اندازه قلم — Ctrl+Shift+>'],['shrinkFont','fa-solid fa-arrow-down-z-a','کاهش اندازه قلم — Ctrl+Shift+<'],['changeCase','fa-solid fa-arrow-right-arrow-left','تغییر حالت حروف'],['clearFormat','fa-solid fa-eraser','حذف همه قالب‌بندی — Ctrl+Space'],
   ['bold','fa-solid fa-bold','پررنگ — Ctrl+B'],['italic','fa-solid fa-italic','کج — Ctrl+I'],['underline','fa-solid fa-underline','زیرخط — Ctrl+U'],['strike','fa-solid fa-strikethrough','خط‌خورده'],['subscript','fa-solid fa-subscript','پایین‌نویس'],['superscript','fa-solid fa-superscript','بالانویس'],['textEffects','fa-solid fa-wand-magic-sparkles','جلوه‌های متن و تایپوگرافی'],['highlight','fa-solid fa-highlighter','رنگ زمینه متن'],['fontColor','fa-solid fa-font','رنگ قلم']]],
  ['paragraph','پاراگراف',[
   ['bullets','fa-solid fa-list-ul','فهرست نشانه‌دار'],['numbering','fa-solid fa-list-ol','فهرست شماره‌دار'],['multilevel','fa-solid fa-list','فهرست چندسطحی'],['outdent','fa-solid fa-outdent','کاهش تورفتگی'],['indent','fa-solid fa-indent','افزایش تورفتگی'],['sort','fa-solid fa-arrow-down-a-z','مرتب‌سازی'],['showMarks','fa-solid fa-paragraph','نمایش یا پنهان‌کردن ¶'],['alignRight','fa-solid fa-align-right','تراز راست'],['alignCenter','fa-solid fa-align-center','تراز وسط'],['alignLeft','fa-solid fa-align-left','تراز چپ'],['justify','fa-solid fa-align-justify','تراز دوطرفه'],['lineSpacing','fa-solid fa-arrows-up-down','فاصله خطوط و پاراگراف'],['shading','fa-solid fa-fill-drip','سایه‌روشن پاراگراف'],['borders','fa-solid fa-border-all','حاشیه‌های پاراگراف']]],
  ['styles','سبک‌ها',[
   ['stylesGallery','fa-solid fa-table-cells-large','گالری سبک‌ها'],['normal','fa-solid fa-paragraph','Normal — متن عادی'],['noSpacing','fa-solid fa-minus','No Spacing — بدون فاصله'],['title','fa-solid fa-heading','Title — عنوان'],['subtitle','fa-solid fa-heading','Subtitle — زیرعنوان'],['h1','fa-solid fa-1','Heading 1'],['h2','fa-solid fa-2','Heading 2'],['h3','fa-solid fa-3','Heading 3'],['h4','fa-solid fa-4','Heading 4'],['h5','fa-solid fa-5','Heading 5'],['h6','fa-solid fa-6','Heading 6'],['h7','fa-solid fa-7','Heading 7'],['h8','fa-solid fa-8','Heading 8'],['h9','fa-solid fa-9','Heading 9'],['quote','fa-solid fa-quote-right','Quote — نقل‌قول'],['intenseQuote','fa-solid fa-quote-left','Intense Quote — نقل‌قول پررنگ'],['listParagraph','fa-solid fa-list','List Paragraph'],['createStyle','fa-solid fa-plus','ساخت سبک جدید'],['applyStyles','fa-solid fa-wand-magic-sparkles','اعمال سبک'],['manageStyles','fa-solid fa-sliders','مدیریت سبک‌ها']]],
  ['editing','ویرایش',[
   ['find','fa-solid fa-magnifying-glass','یافتن — Ctrl+F'],['navigation','fa-solid fa-compass','پنل پیمایش'],['replace','fa-solid fa-right-left','جایگزینی — Ctrl+H'],['replaceAll','fa-solid fa-arrows-rotate','جایگزینی همه'],['select','fa-solid fa-arrow-pointer','انتخاب'],['selectAll','fa-solid fa-check-double','انتخاب همه — Ctrl+A'],['selectObjects','fa-solid fa-vector-square','انتخاب اشیاء'],['selectionPane','fa-solid fa-layer-group','پنل انتخاب']]],
  ['typing','تایپ',[
   ['voice','fa-solid fa-microphone','تایپ صوتی فارسی'],['fileTyping','fa-solid fa-file-arrow-up','تایپ از روی فایل — تصویر، PDF یا ZIP']]],
 ],
 insert:[
  ['pages','صفحات',[['cover','fa-regular fa-file-lines','صفحه جلد'],['blankPage','fa-regular fa-file','صفحه خالی'],['pageBreak','fa-solid fa-file-circle-plus','شکست صفحه']]],
  ['tables','جدول',[['table','fa-solid fa-table-cells','جدول'],['insertTable','fa-solid fa-table','درج جدول'],['drawTable','fa-solid fa-pen-ruler','رسم جدول'],['textToTable','fa-solid fa-table-list','تبدیل متن به جدول'],['excel','fa-solid fa-file-excel','صفحه گسترده Excel'],['quickTables','fa-solid fa-table-cells-large','جداول سریع']]],
  ['illustrations','تصویر و شکل',[['picture','fa-regular fa-image','تصویر'],['onlinePicture','fa-solid fa-images','تصاویر آنلاین'],['shapes','fa-solid fa-shapes','اشکال'],['icons','fa-solid fa-icons','نمادها'],['models3d','fa-solid fa-cube','مدل‌های سه‌بعدی'],['smartArt','fa-solid fa-diagram-project','SmartArt'],['chart','fa-solid fa-chart-column','نمودار'],['screenshot','fa-regular fa-window-maximize','تصویر صفحه']]],
  ['addons','افزونه',[['getAddins','fa-solid fa-puzzle-piece','دریافت افزونه'],['myAddins','fa-solid fa-puzzle-piece','افزونه‌های من']]],
  ['media','رسانه',[['video','fa-solid fa-circle-play','ویدئوی آنلاین']]],
  ['links','پیوند',[['link','fa-solid fa-link','پیوند — Ctrl+K'],['bookmark','fa-regular fa-bookmark','نشانک'],['crossReference','fa-solid fa-arrow-up-right-from-square','ارجاع متقابل']]],
  ['comments','نظر',[['comment','fa-regular fa-comment','نظر جدید']]],
  ['headerFooter','سربرگ و پابرگ',[['header','fa-solid fa-heading','سربرگ'],['footer','fa-solid fa-align-center','پابرگ'],['pageNumber','fa-solid fa-hashtag','شماره صفحه']]],
  ['text','متن',[['textBox','fa-regular fa-square','کادر متن'],['quickParts','fa-solid fa-cubes','اجزای سریع'],['wordArt','fa-solid fa-font','WordArt'],['dropCap','fa-solid fa-a','حرف آغازین'],['signature','fa-solid fa-signature','خط امضا'],['dateTime','fa-regular fa-calendar','تاریخ و زمان'],['object','fa-regular fa-object-group','شیء']]],
  ['symbols','نماد',[['equation','fa-solid fa-square-root-variable','معادله'],['symbol','fa-solid fa-omega','نماد']]],
 ],
 draw:[
  ['tools','ابزار',[['touch','fa-solid fa-hand-pointer','رسم با لمس'],['selectDraw','fa-solid fa-arrow-pointer','انتخاب'],['lasso','fa-solid fa-vector-square','انتخاب کمندی'],['eraser','fa-solid fa-eraser','پاک‌کن']]],
  ['pens','قلم‌ها',[['pen','fa-solid fa-pen','قلم'],['pencil','fa-solid fa-pencil','مداد'],['highlighter','fa-solid fa-highlighter','هایلایتر'],['penSettings','fa-solid fa-sliders','تنظیم رنگ و ضخامت قلم']]],
  ['convert','تبدیل',[['inkShape','fa-solid fa-shapes','جوهر به شکل'],['inkMath','fa-solid fa-square-root-variable','جوهر به ریاضی'],['inkText','fa-solid fa-font','جوهر به متن']]],
  ['replay','بازپخش',[['inkReplay','fa-solid fa-play','بازپخش جوهر']]],
 ],
 design:[
  ['formatting','قالب‌بندی سند',[['themes','fa-solid fa-palette','پوسته‌ها'],['colors','fa-solid fa-circle-half-stroke','رنگ‌ها'],['fonts','fa-solid fa-font','قلم‌های سند'],['paragraphSpacing','fa-solid fa-arrows-up-down','فاصله پاراگراف'],['effects','fa-solid fa-wand-magic','جلوه‌ها'],['defaultDesign','fa-solid fa-thumbtack','تنظیم به‌عنوان پیش‌فرض']]],
  ['background','پس‌زمینه صفحه',[['watermark','fa-solid fa-droplet','واترمارک'],['pageColor','fa-solid fa-fill-drip','رنگ صفحه'],['pageBorders','fa-solid fa-border-all','کادر صفحه']]],
 ],
 layout:[
  ['setup','تنظیم صفحه',[['margins','fa-solid fa-arrows-left-right-to-line','حاشیه‌ها'],['orientation','fa-solid fa-right-left','جهت صفحه'],['size','fa-regular fa-file','اندازه کاغذ'],['columns','fa-solid fa-columns','ستون‌ها'],['breaks','fa-solid fa-arrows-split-up-and-left','شکست‌ها'],['lineNumbers','fa-solid fa-list-ol','شماره خطوط'],['hyphenation','fa-solid fa-spell-check','شکست واژه‌ها']]],
  ['paragraphLayout','پاراگراف',[['indentLeft','fa-solid fa-indent','تورفتگی چپ'],['indentRight','fa-solid fa-outdent','تورفتگی راست'],['spaceBefore','fa-solid fa-arrow-up-wide-short','فاصله قبل'],['spaceAfter','fa-solid fa-arrow-down-wide-short','فاصله بعد']]],
  ['arrange','چیدمان اشیاء',[['position','fa-solid fa-arrows-up-down-left-right','موقعیت'],['wrap','fa-solid fa-align-left','پیچش متن'],['bringForward','fa-solid fa-arrow-up','آوردن جلو'],['sendBackward','fa-solid fa-arrow-down','فرستادن عقب'],['selectionPane','fa-solid fa-layer-group','پنل انتخاب'],['alignObjects','fa-solid fa-align-center','تراز اشیاء'],['group','fa-solid fa-object-group','گروه‌بندی'],['rotate','fa-solid fa-rotate','چرخش']]],
 ],
 references:[
  ['toc','فهرست مطالب',[['toc','fa-solid fa-list-ol','فهرست مطالب'],['addText','fa-solid fa-plus','افزودن متن به فهرست'],['updateToc','fa-solid fa-arrows-rotate','به‌روزرسانی فهرست']]],
  ['footnotes','پاورقی',[['footnote','fa-solid fa-note-sticky','درج پاورقی'],['endnote','fa-solid fa-file-lines','درج یادداشت پایانی'],['nextFootnote','fa-solid fa-arrow-down','پاورقی بعدی'],['showNotes','fa-solid fa-eye','نمایش یادداشت‌ها']]],
  ['research','پژوهش',[['researchSearch','fa-solid fa-magnifying-glass','جستجو'],['smartLookup','fa-solid fa-lightbulb','جستجوی هوشمند']]],
  ['citations','ارجاعات و کتابنامه',[['citation','fa-solid fa-quote-left','درج ارجاع'],['sources','fa-solid fa-book','مدیریت منابع'],['citationStyle','fa-solid fa-list','سبک ارجاع'],['bibliography','fa-solid fa-book-open','کتابنامه']]],
  ['captions','عنوان‌ها',[['caption','fa-solid fa-tag','درج عنوان'],['figures','fa-solid fa-list','فهرست شکل‌ها'],['updateFigures','fa-solid fa-arrows-rotate','به‌روزرسانی فهرست'],['crossReference','fa-solid fa-arrow-up-right-from-square','ارجاع متقابل']]],
  ['index','نمایه',[['markEntry','fa-solid fa-bookmark','علامت‌گذاری مدخل'],['insertIndex','fa-solid fa-list','درج نمایه'],['updateIndex','fa-solid fa-arrows-rotate','به‌روزرسانی نمایه']]],
  ['authority','جدول مراجع قانونی',[['markCitation','fa-solid fa-gavel','علامت‌گذاری ارجاع'],['insertAuthority','fa-solid fa-table-list','درج جدول مراجع'],['updateAuthority','fa-solid fa-arrows-rotate','به‌روزرسانی مراجع']]],
 ],
 mailings:[
  ['create','ایجاد',[['envelopes','fa-regular fa-envelope','پاکت‌ها'],['labels','fa-solid fa-tags','برچسب‌ها']]],
  ['merge','ادغام نامه',[['startMerge','fa-solid fa-envelopes-bulk','شروع ادغام نامه'],['recipients','fa-solid fa-users','انتخاب گیرندگان'],['editRecipients','fa-solid fa-user-pen','ویرایش فهرست گیرندگان']]],
  ['fields','فیلدها',[['highlightFields','fa-solid fa-highlighter','برجسته‌کردن فیلدها'],['addressBlock','fa-regular fa-address-card','بلوک نشانی'],['greeting','fa-regular fa-hand','خط سلام'],['mergeField','fa-solid fa-code','درج فیلد ادغام'],['rules','fa-solid fa-code-branch','قواعد'],['matchFields','fa-solid fa-link','تطبیق فیلدها'],['updateLabels','fa-solid fa-arrows-rotate','به‌روزرسانی برچسب‌ها']]],
  ['preview','پیش‌نمایش',[['previewResults','fa-regular fa-eye','پیش‌نمایش نتایج'],['findRecipient','fa-solid fa-magnifying-glass','یافتن گیرنده'],['checkErrors','fa-solid fa-circle-check','بررسی خطاها']]],
  ['finish','پایان',[['finishMerge','fa-solid fa-flag-checkered','پایان و ادغام'],['individualDocs','fa-regular fa-file-lines','ویرایش اسناد جداگانه'],['printDocs','fa-solid fa-print','چاپ اسناد'],['sendEmail','fa-regular fa-paper-plane','ارسال ایمیل']]],
 ],
 review:[
  ['proofing','بازبینی متن',[['editor','fa-solid fa-spell-check','Editor'],['spelling','fa-solid fa-check-double','املا و دستور زبان'],['thesaurus','fa-solid fa-book-open','واژه‌نامه مترادف'],['wordCount','fa-solid fa-chart-simple','شمارش واژه‌ها']]],
  ['speech','گفتار',[['readAloud','fa-solid fa-volume-high','خواندن با صدای بلند']]],
  ['accessibility','دسترسی‌پذیری',[['accessibility','fa-solid fa-universal-access','بررسی دسترسی‌پذیری']]],
  ['language','زبان',[['translate','fa-solid fa-language','ترجمه'],['language','fa-solid fa-globe','زبان'],['proofLanguage','fa-solid fa-spell-check','زبان بررسی املا']]],
  ['commentsReview','نظرها',[['comment','fa-regular fa-comment','نظر جدید'],['deleteComment','fa-regular fa-trash-can','حذف نظر'],['previousComment','fa-solid fa-arrow-right','قبلی'],['nextComment','fa-solid fa-arrow-left','بعدی'],['showComments','fa-regular fa-comments','نمایش نظرها']]],
  ['tracking','پیگیری',[['trackChanges','fa-solid fa-pen-to-square','پیگیری تغییرات'],['displayReview','fa-solid fa-display','نمایش برای بازبینی'],['showMarkup','fa-solid fa-list-check','نمایش نشانه‌گذاری'],['reviewPane','fa-solid fa-table-columns','پنل بازبینی']]],
  ['changes','تغییرات',[['accept','fa-solid fa-check','پذیرش'],['reject','fa-solid fa-xmark','رد'],['previousChange','fa-solid fa-arrow-right','قبلی'],['nextChange','fa-solid fa-arrow-left','بعدی']]],
  ['compare','مقایسه',[['compare','fa-solid fa-code-compare','مقایسه'],['combine','fa-solid fa-code-merge','ادغام']]],
  ['protect','محافظت',[['restrict','fa-solid fa-lock','محدودکردن ویرایش'],['blockAuthors','fa-solid fa-user-lock','مسدودکردن نویسندگان']]],
  ['ink','جوهر',[['hideInk','fa-solid fa-eye-slash','پنهان‌کردن جوهر']]],
 ],
 view:[
  ['views','نماها',[['readMode','fa-solid fa-book-open','حالت مطالعه'],['printLayout','fa-solid fa-print','طرح چاپ'],['webLayout','fa-solid fa-globe','طرح وب'],['outline','fa-solid fa-list','طرح کلی'],['draft','fa-regular fa-file-lines','پیش‌نویس']]],
  ['immersive','تمرکز',[['focus','fa-solid fa-expand','تمرکز'],['immersiveReader','fa-solid fa-book-reader','خواننده فراگیر']]],
  ['movement','حرکت صفحه',[['vertical','fa-solid fa-arrows-up-down','عمودی'],['sideToSide','fa-solid fa-arrows-left-right','کنار هم']]],
  ['show','نمایش',[['ruler','fa-solid fa-ruler-horizontal','خط‌کش'],['gridlines','fa-solid fa-grip','خطوط شبکه'],['navigation','fa-solid fa-compass','پنل پیمایش']]],
  ['zoom','بزرگ‌نمایی',[['zoom','fa-solid fa-magnifying-glass-plus','بزرگ‌نمایی'],['zoom100','fa-solid fa-1','۱۰۰٪'],['onePage','fa-regular fa-file','یک صفحه'],['multiplePages','fa-solid fa-table-cells','چند صفحه'],['pageWidth','fa-solid fa-arrows-left-right','عرض صفحه']]],
  ['window','پنجره',[['newWindow','fa-regular fa-window-maximize','پنجره جدید'],['arrangeAll','fa-solid fa-table-cells','چیدمان همه'],['split','fa-solid fa-table-columns','تقسیم پنجره'],['sideBySide','fa-solid fa-table-columns','کنار هم'],['syncScroll','fa-solid fa-arrows-left-right','پیمایش همزمان'],['resetWindow','fa-solid fa-rotate','بازنشانی موقعیت'],['switchWindow','fa-solid fa-window-restore','تعویض پنجره']]],
  ['macros','ماکرو',[['macros','fa-solid fa-code','ماکروها'],['viewMacros','fa-solid fa-eye','نمایش ماکروها'],['recordMacro','fa-solid fa-circle','ضبط ماکرو'],['pauseMacro','fa-solid fa-pause','مکث ضبط']]],
 ],
 help:[
  ['help','راهنما',[['help','fa-regular fa-circle-question','راهنما — F1'],['getHelp','fa-solid fa-headset','دریافت کمک'],['support','fa-solid fa-life-ring','ارتباط با پشتیبانی']]],
  ['training','آموزش',[['training','fa-solid fa-graduation-cap','آموزش']]],
  ['feedback','بازخورد',[['feedback','fa-regular fa-message','بازخورد'],['sendFeedback','fa-regular fa-paper-plane','ارسال بازخورد']]],
 ]
};

const ICONS={undo:'fa-solid fa-rotate-left',redo:'fa-solid fa-rotate-right',paste:'fa-regular fa-clipboard',cut:'fa-solid fa-scissors',copy:'fa-regular fa-copy',bold:'fa-solid fa-bold',italic:'fa-solid fa-italic',underline:'fa-solid fa-underline'};

function message(text,type='info'){
 let el=$('#farastEditorMessage');
 if(!el){el=document.createElement('div');el.id='farastEditorMessage';el.className='farast-inline-message';document.body.appendChild(el)}
 el.textContent=text;el.className='farast-inline-message '+type;clearTimeout(el._t);el._t=setTimeout(()=>el.remove(),4200);
}
function iconButton(item){
 const [action,icon,tip]=item; const b=document.createElement('button'); b.type='button';b.className='word-tool';b.dataset.action=action;b.dataset.tip=tip;b.setAttribute('aria-label',tip);b.innerHTML=`<i class="${icon}"></i>`;return b;
}
function group(title,items){
 const g=document.createElement('div');g.className='ribbon-group';g.dataset.group=title;items.forEach(i=>g.appendChild(iconButton(i)));return g;
}
function rebuildRibbons(app){
 Object.entries(TOOLS).forEach(([tab,groups])=>{
  const panel=app.querySelector('#ribbon-'+tab);if(!panel)return;
  panel.innerHTML='';groups.forEach(([key,title,items])=>{
   const g=group(title,items);g.dataset.groupKey=key;panel.appendChild(g);
  });
 });
}
function ensureTabs(app){
 const nav=app.querySelector('.word-tabs'); if(!nav)return;
 const labels={home:'خانه',insert:'درج',draw:'رسم',design:'طراحی',layout:'طرح‌بندی',references:'مراجع',mailings:'نامه‌نگاری',review:'بازبینی',view:'نمایش',help:'راهنما'};
 nav.innerHTML='';Object.entries(labels).forEach(([key,label])=>{const b=document.createElement('button');b.type='button';b.className='word-tab'+(key==='home'?' active':'');b.dataset.tab=key;b.setAttribute('aria-selected',key==='home'?'true':'false');b.textContent=label;nav.appendChild(b)});
}
function createMissingPanels(app){
 ['home','insert','draw','design','layout','references','mailings','review','view','help'].forEach(k=>{
  let p=app.querySelector('#ribbon-'+k);if(!p){p=document.createElement('section');p.id='ribbon-'+k;p.className='ribbon hidden';app.querySelector('.word-tabs')?.after(p)}
 });
}
function exec(cmd,val=null){
 const ed=$('#editor');if(!ed)return;ed.focus();try{document.execCommand(cmd,false,val)}catch(e){}ed.dispatchEvent(new Event('input',{bubbles:true}));
}
function selectionText(){const s=window.getSelection?.();return s&&s.toString()?s.toString():''}
function applyBlock(tag){exec('formatBlock',tag)}
function setFontSize(px){const ed=$('#editor');if(!ed)return;ed.focus();try{document.execCommand('fontSize',false,'7');$$('#editor font[size="7"]').forEach(x=>{x.removeAttribute('size');x.style.fontSize=px+'px'})}catch(e){ed.style.fontSize=px+'px'}ed.dispatchEvent(new Event('input',{bubbles:true}))}
function insertAtCursor(text){exec('insertText',text)}
function pageBreak(){const ed=$('#editor');if(!ed)return;ed.focus();const el=document.createElement('div');el.className='page-break';el.contentEditable='false';el.innerHTML='<span>شکست صفحه</span>';const s=window.getSelection();if(s&&s.rangeCount){const r=s.getRangeAt(0);r.deleteContents();r.insertNode(el);r.setStartAfter(el);r.collapse(true);s.removeAllRanges();s.addRange(r)}else ed.appendChild(el);ed.dispatchEvent(new Event('input',{bubbles:true}))}
function insertTable(){const rows=Math.max(1,parseInt(prompt('تعداد سطر', '3')||'3',10));const cols=Math.max(1,parseInt(prompt('تعداد ستون','3')||'3',10));let html='<table><tbody>';for(let r=0;r<rows;r++){html+='<tr>';for(let c=0;c<cols;c++)html+='<td>&nbsp;</td>';html+='</tr>'}html+='</tbody></table>';const ed=$('#editor');if(ed){ed.focus();document.execCommand('insertHTML',false,html);ed.dispatchEvent(new Event('input',{bubbles:true}))}}
function insertLink(){const url=prompt('نشانی پیوند را وارد کنید');if(url)exec('createLink',url)}
function changeCase(){const t=selectionText();if(!t){message('ابتدا بخشی از متن را انتخاب کنید.','error');return}insertAtCursor(t===t.toUpperCase()?t.toLowerCase():t.toUpperCase())}
function showWordCount(){const ed=$('#editor');if(!ed)return;const text=ed.innerText.trim();const words=text?text.split(/\s+/u).length:0;const chars=text.length;message(`تعداد واژه: ${words}  |  نویسه: ${chars}`,'info')}
function setPageStyle(action){const page=$('.word-page');if(!page)return;
 if(action==='pageColor'){const c=prompt('رنگ صفحه به صورت HEX','#ffffff');if(/^#[0-9a-f]{6}$/i.test(c||''))page.style.backgroundColor=c}
 else if(action==='pageBorders')page.classList.toggle('no-page-border');
 else if(action==='margins'){const v=prompt('حاشیه صفحه بر حسب میلی‌متر','25.4');const n=parseFloat(v);if(Number.isFinite(n)&&n>=5&&n<=60)$('#editor').style.padding=n+'mm'}
 else if(action==='orientation'){page.classList.toggle('page-landscape')}
 else if(action==='columns'){const v=prompt('تعداد ستون: 1، 2 یا 3','1');const n=parseInt(v,10);if([1,2,3].includes(n))$('#editor').style.columnCount=n}
 else if(action==='lineSpacing'){const v=prompt('فاصله خطوط: 1.0، 1.15، 1.5 یا 2','1.15');if(/^([1-2](\.\d+)?)$/.test(v||''))$('#editor').style.lineHeight=v}
}
function action(a){
 switch(a){
  case'undo':exec('undo');break;case'redo':exec('redo');break;case'paste':navigator.clipboard?.readText?.().then(insertAtCursor).catch(()=>message('اجازه دسترسی به کلیپ‌بورد داده نشد.','error'));break;case'cut':exec('cut');break;case'copy':exec('copy');break;case'selectAll':exec('selectAll');break;
  case'bold':exec('bold');break;case'italic':exec('italic');break;case'underline':exec('underline');break;case'strike':exec('strikeThrough');break;case'superscript':exec('superscript');break;case'subscript':exec('subscript');break;case'clearFormat':exec('removeFormat');break;
  case'alignRight':exec('justifyRight');break;case'alignCenter':exec('justifyCenter');break;case'alignLeft':exec('justifyLeft');break;case'justify':exec('justifyFull');break;case'bullets':exec('insertUnorderedList');break;case'numbering':exec('insertOrderedList');break;case'indent':exec('indent');break;case'outdent':exec('outdent');break;case'showMarks':$('#editor')?.classList.toggle('show-guides');break;
  case'growFont':setFontSize(Math.min(72,parseInt(getComputedStyle($('#editor')).fontSize,10)+2));break;case'shrinkFont':setFontSize(Math.max(8,parseInt(getComputedStyle($('#editor')).fontSize,10)-2));break;case'changeCase':changeCase();break;
  case'h1':applyBlock('h1');break;case'h2':applyBlock('h2');break;case'h3':applyBlock('h3');break;case'h4':applyBlock('h4');break;case'h5':applyBlock('h5');break;case'h6':applyBlock('h6');break;case'h7':applyBlock('h7');break;case'h8':applyBlock('h8');break;case'h9':applyBlock('h9');break;case'normal':applyBlock('p');break;case'quote':applyBlock('blockquote');break;case'intenseQuote':applyBlock('blockquote');break;
  case'find':findText();break;case'replace':replaceText(false);break;case'replaceAll':replaceText(true);break;case'navigation':toggleNavigation();break;case'wordCount':showWordCount();break;
  case'pageBreak':case'breaks':pageBreak();break;case'table':case'insertTable':case'quickTables':insertTable();break;case'link':insertLink();break;case'clearLink':exec('unlink');break;case'pageColor':case'pageBorders':case'margins':case'orientation':case'columns':case'lineSpacing':setPageStyle(a);break;
  case'pageNumber':insertAtCursor('صفحه ');break;case'header':insertAtCursor('\nسربرگ سند\n');break;case'footer':insertAtCursor('\nپابرگ سند\n');break;case'dateTime':insertAtCursor(new Intl.DateTimeFormat('fa-IR',{dateStyle:'short',timeStyle:'short'}).format(new Date()));break;
  case'footnote':case'endnote':insertAtCursor('[یادداشت] ');break;case'comment':insertAtCursor('[نظر] ');break;case'citation':insertAtCursor('[ارجاع] ');break;case'caption':insertAtCursor('[عنوان شکل یا جدول] ');break;
  case'toc':buildToc();break;case'updateToc':buildToc();break;case'focus':toggleFocus();break;case'ruler':$('#editor')?.classList.toggle('show-ruler');break;case'gridlines':$('#editor')?.classList.toggle('show-grid');break;case'zoom100':setZoom(1);break;case'zoom':setZoom(1.1);break;case'onePage':setZoom(.9);break;case'multiplePages':setZoom(.75);break;case'pageWidth':setZoom(1);break;
  case'printLayout':message('ویرایشگر در حالت طرح چاپ قرار گرفت.','success');break;case'readMode':toggleFocus();break;case'webLayout':message('طرح وب در همین ویرایشگر اعمال شد.','success');break;case'vertical':message('حرکت صفحات عمودی فعال است.','success');break;case'sideToSide':message('نمایش کنارهم برای صفحات انتخاب شد.','success');break;
  case'voice':startVoice();break;case'fileTyping':$('#source')?.click();break;case'help':message('راهنمای فراست: روی هر نماد مکث کنید تا توضیح فارسی و میانبر آن نمایش داده شود.','info');break;case'support':location.href='/support';break;case'feedback':$('#feedbackPanelBtn')?.click();break;
  default:message('این ابزار در این مرحله آماده اتصال به ماژول تخصصی خود است؛ عملیات ساختاری سند همچنان از همین محیط انجام می‌شود.','info');
 }
}
function findText(){const q=prompt('عبارت مورد جستجو');if(!q)return;const ed=$('#editor');const walker=document.createTreeWalker(ed,NodeFilter.SHOW_TEXT);let n;while(n=walker.nextNode()){const i=n.nodeValue.indexOf(q);if(i>=0){const r=document.createRange();r.setStart(n,i);r.setEnd(n,i+q.length);const s=getSelection();s.removeAllRanges();s.addRange(r);n.parentElement.scrollIntoView({block:'center'});return}}message('عبارت پیدا نشد.','error')}
function replaceText(all){const from=prompt('عبارت مورد جستجو');if(!from)return;const to=prompt('جایگزین با');if(to===null)return;const ed=$('#editor');let html=ed.innerHTML;const safeFrom=from.replace(/[.*+?^${}()|[\]\\]/g,'\\$&');const re=new RegExp(safeFrom,all?'gu':'u');ed.innerHTML=html.replace(re,to);ed.dispatchEvent(new Event('input',{bubbles:true}));message(all?'همه موارد جایگزین شدند.':'یک مورد جایگزین شد.','success')}
function buildToc(){const ed=$('#editor');if(!ed)return;const hs=[...ed.querySelectorAll('h1,h2,h3,h4,h5,h6,h7,h8,h9')];if(!hs.length){message('عنوانی برای ساخت فهرست پیدا نشد.','error');return}let html='<div class="farast-toc"><strong>فهرست مطالب</strong><ol>';hs.forEach((h,i)=>{h.id=h.id||`farast-heading-${i+1}`;html+=`<li><a href="#${h.id}">${h.textContent}</a></li>`});html+='</ol></div>';ed.insertAdjacentHTML('afterbegin',html);ed.dispatchEvent(new Event('input',{bubbles:true}))}
function setZoom(z){const ed=$('.pages-viewport');if(ed)ed.style.zoom=String(z);message(`بزرگ‌نمایی ${Math.round(z*100)}٪`,'info')}
function toggleFocus(){document.body.classList.toggle('farast-focus-mode')}
function toggleNavigation(){let p=$('.navigation-pane');if(!p){p=document.createElement('aside');p.className='navigation-pane';p.innerHTML='<strong>پنل پیمایش</strong><small>عنوان‌های سند</small><div id="farastNavItems"></div>';$('.word-body')?.prepend(p)}p.classList.toggle('hidden');if(!p.classList.contains('hidden'))refreshNav()}
function refreshNav(){const box=$('#farastNavItems');const ed=$('#editor');if(!box||!ed)return;box.innerHTML='';ed.querySelectorAll('h1,h2,h3').forEach((h,i)=>{h.id=h.id||`nav-${i}`;const b=document.createElement('button');b.className='nav-item';b.textContent=h.textContent;b.onclick=()=>document.getElementById(h.id)?.scrollIntoView({behavior:'smooth',block:'center'});box.appendChild(b)})}
async function post(url,body){const opt={method:'POST',headers:{'X-CSRF-TOKEN':csrf(),'Accept':'application/json'}};if(body instanceof FormData)opt.body=body;else{opt.headers['Content-Type']='application/json';opt.body=JSON.stringify(body||{})}const r=await fetch(url,opt);let data={};try{data=await r.json()}catch(_){}if(!r.ok)throw new Error(data.message||`خطای ${r.status}`);return data}
async function uploadSource(file){
 const fd=new FormData();fd.append('source',file);message('در حال بارگذاری فایل…','info');
 try{const d=await post('/editor/upload',fd);$('#source').dataset.path=d.path;$('#source').dataset.mime=d.mime;$('#source').dataset.name=d.name;$('#analyze').disabled=false;message('فایل آماده تحلیل است. روی نماد تایپ از فایل بزنید.','success');return d}catch(e){message(e.message,'error');return null}
}
async function runPreflight(){
 const input=$('#source');if(!input?.dataset.path){message('ابتدا فایل را انتخاب کنید.','error');return}
 try{
  // IMPORTANT: this endpoint is POST; never call it as GET.
  const quote=await post('/editor/preflight/estimate',{});
  if(quote.mode==='accepted'||quote.mode==='free')return acceptAndAnalyze(quote);
  const amount=new Intl.NumberFormat('fa-IR').format(quote.payable_estimate_rials||0);
  const deposit=quote.deposit_rials?`\nمبلغ شروع سفارش: ${new Intl.NumberFormat('fa-IR').format(quote.deposit_rials)} ریال` : '';
  if(confirm(`تعداد صفحات: ${quote.pages}\nبرآورد خدمت: ${new Intl.NumberFormat('fa-IR').format(quote.estimate_rials)} ریال\nمبلغ قابل پرداخت: ${amount} ریال${deposit}\n\nبرای ادامه تأیید کنید.`))return acceptAndAnalyze(quote);
  await post('/editor/preflight/decline',{});message('درخواست متوقف شد. هر زمان بخواهید می‌توانید فایل دیگری را بررسی کنید.','info');
 }catch(e){message(e.message,'error')}
}
async function acceptAndAnalyze(quote){
 try{const a=await post('/editor/preflight/accept',{accept:1});if(a.action==='deposit'){location.href=a.checkout_url;return}await analyzeFile(quote)}catch(e){message(e.message,'error')}
}
async function analyzeFile(quote){
 const input=$('#source');const fd=new FormData();fd.append('path',input.dataset.path);fd.append('mime',input.dataset.mime||'application/octet-stream');fd.append('source_name',input.dataset.name||'فایل');
 message('در حال پردازش و استخراج متن…','info');const d=await post('/editor/analyze',fd);if(d.html&&$('#editor')){$('#editor').innerHTML=d.html;$('#editor').dispatchEvent(new Event('input',{bubbles:true}));refreshNav();message('تایپ از فایل با موفقیت انجام شد؛ سند به‌صورت پیش‌نویس ذخیره شد.','success')}return d
}
let recorder=null,chunks=[];
async function startVoice(){
 const mic=$('#mic');
 if(recorder&&recorder.state==='recording'){recorder.stop();mic?.classList.remove('recording');return}
 if(!navigator.mediaDevices?.getUserMedia||!window.MediaRecorder){message('مرورگر فعلی ضبط صوت را پشتیبانی نمی‌کند.','error');return}
 try{
  const stream=await navigator.mediaDevices.getUserMedia({audio:true});chunks=[];recorder=new MediaRecorder(stream,{mimeType:MediaRecorder.isTypeSupported('audio/webm;codecs=opus')?'audio/webm;codecs=opus':''});
  recorder.ondataavailable=e=>{if(e.data.size)chunks.push(e.data)};
  recorder.onstop=async()=>{stream.getTracks().forEach(t=>t.stop());const blob=new Blob(chunks,{type:recorder.mimeType||'audio/webm'});await sendVoice(blob)};
  recorder.start();mic?.classList.add('recording');message('ضبط صوت فعال است؛ برای پایان دوباره روی میکروفون بزنید.','info');
 }catch(e){message('دسترسی میکروفون داده نشد یا ضبط صدا در دسترس نیست.','error')}
}
async function sendVoice(blob){const fd=new FormData();fd.append('audio',blob,'farast-voice.webm');fd.append('locale','fa-IR');try{message('در حال تبدیل صدا به متن…','info');const d=await post('/editor/voice/transcribe',fd);if(d.text)insertAtCursor(d.text+' ');message('تایپ صوتی انجام شد.','success')}catch(e){message(e.message,'error')}finally{$('#mic')?.classList.remove('recording')}}
function installHandlers(app){
 app.addEventListener('click',e=>{const t=e.target.closest('.word-tab');if(t){e.preventDefault();const name=t.dataset.tab;$$('.word-tab').forEach(x=>{x.classList.toggle('active',x===t);x.setAttribute('aria-selected',x===t?'true':'false')});$$('.ribbon').forEach(p=>{const on=p.id==='ribbon-'+name;p.classList.toggle('hidden',!on);p.hidden=!on});app.dataset.activeRibbon=name;return}
 const b=e.target.closest('.word-tool');if(b){e.preventDefault();action(b.dataset.action)}});
 const source=$('#source');if(source){source.addEventListener('change',e=>{const f=e.target.files?.[0];if(f)uploadSource(f)})}
 const analyze=$('#analyze');if(analyze){analyze.addEventListener('click',e=>{e.preventDefault();runPreflight()})}
 const mic=$('#mic');if(mic){mic.addEventListener('click',e=>{e.preventDefault();startVoice()})}
 $('#saveNow')?.addEventListener('click',()=>$('#saveBtn')?.click());
 // Repair accidental legacy GET calls from older editor scripts.
 const nativeFetch=window.fetch.bind(window);window.fetch=async(input,init={})=>{const url=typeof input==='string'?input:input?.url||'';if(url.includes('/editor/preflight/estimate')&&(!init.method||String(init.method).toUpperCase()==='GET')){init={...init,method:'POST',headers:{...(init.headers||{}),'X-CSRF-TOKEN':csrf(),'Accept':'application/json'}}}return nativeFetch(input,init)};
}
function defaults(){const ed=$('#editor');if(!ed)return;ed.style.fontFamily='B Nazanin, BNazanin, serif';ed.style.fontSize='14pt';ed.style.lineHeight='1.15';ed.style.padding='25.4mm';ed.setAttribute('spellcheck','true');const page=$('.word-page');page?.classList.remove('no-page-border');}
ready(()=>{
 const app=$('#farastWord');if(!app)return;
 createMissingPanels(app);ensureTabs(app);rebuildRibbons(app);defaults();installHandlers(app);
 const input=$('#source');if(input)input.setAttribute('accept','image/jpeg,image/png,image/webp,application/pdf,application/zip,.jpg,.jpeg,.png,.webp,.pdf,.zip');
 // The file picker stays in the Ribbon; no drag/drop overlay or in-page drop box.
 const drop=document.querySelector('.global-file-drag');drop?.classList.remove('global-file-drag');
 window.FarastEditorRedesign={version:'1.0.0',runPreflight,uploadSource,startVoice};
});
})();
