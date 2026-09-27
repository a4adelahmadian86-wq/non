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

const search=q('#farastGlobalSearch');
search?.addEventListener('input',()=>{const term=search.value.trim().toLocaleLowerCase('fa');document.querySelectorAll('[data-searchable]').forEach(el=>{el.hidden=!!term&&!el.textContent.toLocaleLowerCase('fa').includes(term)})});
q('#farastVoiceSearch')?.addEventListener('click',()=>{const SR=window.SpeechRecognition||window.webkitSpeechRecognition;if(!SR){notice('جستجوی صوتی مستقیم در این مرورگر در دسترس نیست','در بخش تایپ صوتی، مسیر جایگزین سروری فعال است.');return}const r=new SR();r.lang='fa-IR';r.interimResults=false;r.maxAlternatives=1;r.onresult=e=>{search.value=e.results[0][0].transcript;search.dispatchEvent(new Event('input'))};r.onerror=()=>notice('جستجوی صوتی انجام نشد','دسترسی میکروفن و اتصال مرورگر را بررسی کنید.');r.start()});

if('serviceWorker' in navigator)addEventListener('load',()=>navigator.serviceWorker.register('/sw.js').catch(()=>{}));
document.addEventListener('click',async e=>{const a=e.target.closest('a[href]');if(!a||a.target==='_blank'||a.hasAttribute('download')||a.origin!==location.origin||navigator.onLine)return;try{if(await caches.match(a.href))return}catch{}e.preventDefault();showConnectivity(false)},{capture:true});
renderCart();if(!navigator.onLine)showConnectivity(false);else setTimeout(()=>probe(true),450);
})();
