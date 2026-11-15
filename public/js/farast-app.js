(()=>{
'use strict';
const q=s=>document.querySelector(s),box=q('#farastConnectivity'),title=q('#farastConnectivityTitle'),msg=q('#farastConnectivityMessage'),spin=q('#farastConnectivitySpinner'),retry=q('#farastConnectivityRetry');
let lastOnline=navigator.onLine,hadFailure=!navigator.onLine,cart=[];
try{cart=JSON.parse(localStorage.getItem('farast.cart')||'[]');if(!Array.isArray(cart))cart=[]}catch{cart=[]}

function showConnectivity(online,{recovered=false}={}){
  if(!box)return;
  if(online&&!recovered){box.hidden=true;return}
  box.hidden=false;box.classList.toggle('is-online',online);
  title.textContent=online?'اتصال اینترنت برقرار شد':'اتصال اینترنت در دسترس نیست';
  msg.textContent=online?'فراست دوباره به سرویس‌های آنلاین متصل است.':'بخش‌های ذخیره‌شده در دسترس می‌مانند؛ عملیات نیازمند اینترنت پس از اتصال دوباره ادامه پیدا می‌کند.';
  if(spin)spin.style.display=online?'none':'block';
  if(online)setTimeout(()=>{if(navigator.onLine)box.hidden=true},1800);
}
function notice(head,text){if(!box)return;box.classList.remove('is-online');box.hidden=false;title.textContent=head;msg.textContent=text;if(spin)spin.style.display='none';setTimeout(()=>{if(navigator.onLine)box.hidden=true},4200)}
async function probe(initial=false){
  if(!navigator.onLine){hadFailure=true;lastOnline=false;showConnectivity(false);return false}
  let timer;
  try{
    const c=new AbortController();timer=setTimeout(()=>c.abort(),5000);
    const r=await fetch('/up?farast_probe='+Date.now(),{method:'GET',cache:'no-store',signal:c.signal,credentials:'same-origin',headers:{'Accept':'text/html,application/json','X-Farast-Probe':'1'}});
    clearTimeout(timer);
    const ok=r.ok;
    if(ok){const recovered=hadFailure||(!lastOnline&&!initial);lastOnline=true;hadFailure=false;showConnectivity(true,{recovered});}
    else{hadFailure=true;lastOnline=false;showConnectivity(false)}
    return ok;
  }catch{if(timer)clearTimeout(timer);hadFailure=true;lastOnline=false;showConnectivity(false);return false}
}
addEventListener('offline',()=>{hadFailure=true;lastOnline=false;showConnectivity(false)});
addEventListener('online',()=>setTimeout(()=>probe(false),300));
retry?.addEventListener('click',()=>probe(false));
setInterval(()=>{if(document.visibilityState!=='hidden')probe(false)},30000);

function save(){localStorage.setItem('farast.cart',JSON.stringify(cart));renderCart()}
function esc(s){return String(s??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;')}
function renderCart(){const count=q('#farastCartCount'),body=q('#farastCartBody'),total=q('#farastCartTotal'),checkout=q('#farastCartCheckout');if(!count||!body)return;count.textContent=String(cart.reduce((s,x)=>s+Number(x.qty||1),0));const sum=cart.reduce((s,x)=>s+(Number(x.price)||0)*(Number(x.qty)||1),0);if(total)total.textContent=sum?new Intl.NumberFormat('fa-IR').format(sum)+' تومان':'۰ تومان';if(checkout)checkout.disabled=!cart.length;if(!cart.length){body.innerHTML='<div class="farast-cart-empty"><i class="fa-solid fa-bag-shopping"></i><strong>سبد خرید خالی است</strong><span>محصولات دیجیتال را از ویترین انتخاب کنید.</span></div>';return}body.innerHTML=cart.map((x,i)=>`<div class="farast-cart-row"><div><strong>${esc(x.title)}</strong><small>${new Intl.NumberFormat('fa-IR').format(x.price||0)} تومان</small></div><button type="button" data-cart-remove="${i}" aria-label="حذف"><i class="fa-solid fa-trash-can"></i></button></div>`).join('');body.querySelectorAll('[data-cart-remove]').forEach(b=>b.onclick=()=>{cart.splice(Number(b.dataset.cartRemove),1);save()})}
function openCart(){const d=q('#farastCartDrawer'),b=q('#farastCartBackdrop');if(!d)return;d.classList.add('is-open');d.setAttribute('aria-hidden','false');if(b)b.hidden=false;renderCart()}
function closeCart(){const d=q('#farastCartDrawer'),b=q('#farastCartBackdrop');d?.classList.remove('is-open');d?.setAttribute('aria-hidden','true');if(b)b.hidden=true}
q('#farastCartButton')?.addEventListener('click',openCart);q('[data-cart-close]')?.addEventListener('click',closeCart);q('#farastCartBackdrop')?.addEventListener('click',closeCart);
window.FarastCart={add(item){if(!item?.id)return;const old=cart.find(x=>String(x.id)===String(item.id));if(old)old.qty=(old.qty||1)+1;else cart.push({...item,qty:1});save();openCart()}};
document.addEventListener('click',e=>{const b=e.target.closest('[data-cart-add]');if(b)FarastCart.add({id:b.dataset.cartAdd,title:b.dataset.title||'محصول دیجیتال',price:Number(b.dataset.price||0)})});

const search=q('#farastGlobalSearch'),searchForm=q('#farastGlobalSearchForm'),suggestions=q('#farastSearchSuggestions');
let searchTimer=null,searchController=null,activeSearchOption=-1;
const closeSearchSuggestions=()=>{if(!suggestions||!search)return;suggestions.hidden=true;search.setAttribute('aria-expanded','false');search.removeAttribute('aria-activedescendant');activeSearchOption=-1};
const getSearchOptions=()=>Array.from(suggestions?.querySelectorAll('a[role="option"]')||[]);
const activateSearchOption=index=>{const options=getSearchOptions();if(!options.length){activeSearchOption=-1;return}activeSearchOption=(index+options.length)%options.length;options.forEach((option,i)=>option.setAttribute('aria-selected',String(i===activeSearchOption)));const active=options[activeSearchOption];search.setAttribute('aria-activedescendant',active.id);active.scrollIntoView({block:'nearest'})};
const renderSearchSuggestions=payload=>{
  if(!suggestions||!search)return;
  suggestions.replaceChildren();activeSearchOption=-1;let optionIndex=0;
  (payload.groups||[]).forEach(group=>{
    const section=document.createElement('section');section.className='farast-search-suggestion-group';section.setAttribute('role','group');section.setAttribute('aria-label',group.label||'نتایج');
    const heading=document.createElement('div');heading.className='farast-search-suggestion-heading';heading.textContent=group.label||'';section.append(heading);
    (group.items||[]).forEach(item=>{
      const link=document.createElement('a');link.className='farast-search-suggestion';link.href=item.url;link.id='farast-search-option-'+optionIndex++;link.setAttribute('role','option');link.setAttribute('aria-selected','false');
      const icon=document.createElement('i');icon.className='fa-solid '+(item.icon||'fa-magnifying-glass');icon.setAttribute('aria-hidden','true');
      const copy=document.createElement('span');copy.className='farast-search-suggestion-copy';
      const title=document.createElement('strong');title.textContent=item.title||'';
      const detail=document.createElement('small');detail.textContent=[item.description,item.meta].filter(Boolean).join(' · ');
      copy.append(title);if(detail.textContent)copy.append(detail);link.append(icon,copy);section.append(link);
    });
    if(group.items?.length)suggestions.append(section);
  });
  if(!(payload.groups||[]).some(group=>group.items?.length)){
    const empty=document.createElement('div');empty.className='farast-search-suggestion-empty';empty.textContent='نتیجه‌ای پیدا نشد؛ عبارت دیگری را امتحان کنید.';suggestions.append(empty);
  }
  if(payload.all_results_url){
    const all=document.createElement('a');all.className='farast-search-all-results';all.href=payload.all_results_url;all.textContent='مشاهده همه نتایج';all.setAttribute('role','option');all.id='farast-search-option-'+optionIndex;all.setAttribute('aria-selected','false');suggestions.append(all);
  }
  suggestions.hidden=false;search.setAttribute('aria-expanded','true');
};
search?.addEventListener('input',()=>{
  if(!suggestions)return;
  clearTimeout(searchTimer);searchController?.abort();
  const term=search.value.trim();
  if(term.length<2){closeSearchSuggestions();return}
  searchTimer=setTimeout(async()=>{
    const controller=new AbortController();searchController=controller;
    try{
      const response=await fetch('/search/suggestions?q='+encodeURIComponent(term),{headers:{Accept:'application/json'},credentials:'same-origin',signal:controller.signal});
      if(!response.ok)throw new Error('search_unavailable');
      const payload=await response.json();
      if(search.value.trim()!==term)return;
      renderSearchSuggestions(payload);
    }catch(error){if(error.name!=='AbortError')closeSearchSuggestions()}
  },180);
});
search?.addEventListener('keydown',event=>{
  const options=getSearchOptions();
  if(event.key==='ArrowDown'&&options.length){event.preventDefault();activateSearchOption(activeSearchOption+1)}
  else if(event.key==='ArrowUp'&&options.length){event.preventDefault();activateSearchOption(activeSearchOption<=0?options.length-1:activeSearchOption-1)}
  else if(event.key==='Enter'&&activeSearchOption>=0&&options[activeSearchOption]){event.preventDefault();options[activeSearchOption].click()}
  else if(event.key==='Escape')closeSearchSuggestions();
});
searchForm?.addEventListener('submit',closeSearchSuggestions);
document.addEventListener('click',event=>{if(searchForm&&!searchForm.contains(event.target))closeSearchSuggestions()});
q('#farastVoiceSearch')?.addEventListener('click',()=>{const SR=window.SpeechRecognition||window.webkitSpeechRecognition;if(!SR){notice('جستجوی صوتی مستقیم در این مرورگر در دسترس نیست','در بخش تایپ صوتی، مسیر جایگزین سروری فعال است.');return}const r=new SR();r.lang='fa-IR';r.interimResults=false;r.maxAlternatives=1;r.onresult=e=>{if(!search)return;search.value=e.results[0][0].transcript;searchForm?.requestSubmit()};r.onerror=()=>notice('جستجوی صوتی انجام نشد','دسترسی میکروفن و اتصال مرورگر را بررسی کنید.');r.start()});

if('serviceWorker' in navigator)addEventListener('load',()=>navigator.serviceWorker.register('/sw.js').catch(()=>{}));
document.addEventListener('click',async e=>{const a=e.target.closest('a[href]');if(!a||a.target==='_blank'||a.hasAttribute('download')||a.origin!==location.origin||navigator.onLine)return;try{if(await caches.match(a.href))return}catch{}e.preventDefault();showConnectivity(false)},{capture:true});
renderCart();if(!navigator.onLine)showConnectivity(false);else setTimeout(()=>probe(true),450);
})();
