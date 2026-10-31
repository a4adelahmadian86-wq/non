/* FARAST · Server-only Voice Hub
 * Speech recognition runs on the FARAST server. The browser only captures microphone
 * audio, performs lightweight PCM conversion and streams frames; it never installs
 * browser speech packs or downloads/executes an ASR model. OCR is an independent feature.
 */
(() => {
'use strict';
if (window.__farastVoiceHubLoaded) return;
window.__farastVoiceHubLoaded = true;

const $ = (s, r = document) => r.querySelector(s);
const toFaDigits = s => String(s).replace(/[0-9]/g, d => '۰۱۲۳۴۵۶۷۸۹'[d]);

/* ---------- ابزارهای مشترک متن (بین کروم و هاب یکسان) ---------- */
const CMD_MAP = {
  'نقطه': '.', 'ویرگول': '،', 'علامت سوال': '؟', 'علامت سؤال': '؟', 'علامت پرسش': '؟',
  'علامت تعجب': '!', 'دو نقطه': ':', 'نقطه ویرگول': '؛', 'سه نقطه': '…',
  'خط تیره': '–', 'باز کردن پرانتز': '(', 'بستن پرانتز': ')',
  'باز کردن گیومه': '«', 'بستن گیومه': '»',
  // واژه‌های خالیِ فارسی که موتورهای STT به‌جای علامت برمی‌گردانند → نشانه‌گذاری واقعی
  'سکوت': '', 'خط جدید': '\n', 'پاراگراف': '\n\n',
  'پررنگ': '__FMT:bold', 'کج': '__FMT:italic', 'زیرخط': '__FMT:underline',
};
// الگوی گوگل‌داکس: هر کلمهٔ خالی فارسی در ورودی STT باید به نشانه تبدیل شود، نه اینکه تایپ شود
const EMPTY_WORD_RE = /(سکوت|خط جدید|سر خط|پاراگراف جدید|پاراگراف|باز کردن گیومه|بستن گیومه|باز کردن پرانتز|بستن پرانتز|خط تیره|نقطه ویرگول|دو نقطه|سه نقطه|علامت تعجب|علامت سوال|علامت سؤال|علامت پرسش|ویرگول|نقطه)/g;
function replaceEmptyWords(t) {
  return t.replace(EMPTY_WORD_RE, m => CMD_MAP[m] !== undefined ? CMD_MAP[m] : (m === 'نقطه' ? '.' : '،'));
}
const normalizeFaText = t => t.replace(/ي/g, 'ی').replace(/ك/g, 'ک').replace(/\s+/g, ' ').trim();

function prevChar() {
  const s = getSelection(); if (!s || !s.rangeCount) return '';
  const n = s.anchorNode, o = s.anchorOffset; let ch = '';
  if (n && n.nodeType === 3) ch = n.data[o - 1] || '';
  else if (o > 0 && n) { const c = n.childNodes[o - 1]; if (c && c.nodeType === 3) ch = c.data.slice(-1); }
  return ch === '\u200b' ? '' : ch;
}

/* پردازش متن نهایی: دستورات صوتی، ارقام فارسی، فاصله‌گذاری هوشمند */
function processFinalText(raw, opts) {
  const fa = (opts.lang || 'fa-IR').startsWith('fa');
  let t = fa ? normalizeFaText(replaceEmptyWords(String(raw))) : String(raw).trim();
  if (!t) return null;
  if (fa && opts.commands !== false) {
    if (/^(خط جدید|خط بعد|سر خط)$/.test(t)) return '\n';
    if (/^(پاراگراف جدید|پاراگراف بعد)$/.test(t)) return '\n\n';
    if (/^(بازگردانی|برگردان)$/.test(t)) return '__UNDO__';
    if (/^(لغو|توقف)$/.test(t)) return '__STOP__';
    if (/^(آخرین کلمه را پاک کن|پاک کردن کلمه|حذف کلمه)$/.test(t)) return '__DELPREV__';
    if (CMD_MAP[t]) return CMD_MAP[t];
    t = t.replace(/\s*(علامت سوال|علامت سؤال|علامت پرسش)\s*/g, '؟ ').replace(/\s*علامت تعجب\s*/g, '! ')
      .replace(/\s*ویرگول\s*/g, '، ').replace(/\s+(سه نقطه)$/, '…')
      .replace(/(^|\s)(?<!دو |سه |یک )نقطه$/, '.').replace(/\s+(دو نقطه)$/, ':')
      .replace(/\s+([.،؛؟!:…)»,;?])/g, '$1').trim();
  }
  if (fa && opts.digits !== false) t = toFaDigits(t);
  const pc = prevChar();
  if (pc && !/\s/.test(pc) && !/^[\s.،؛؟!:…)»,;?]/.test(t) && !/[(«]/.test(pc)) t = ' ' + t;
  return t || null;
}

/* درج در سند: ترجیحاً DOM زنده (مثل گوگل داکس — سریع و در مکان نشانگر)، سپس رجیستری هسته */
function surfaceEl() {
  const k = window.FarastEditor;
  if (k?.state?.model) return $('#farastWord .farast-editor[contenteditable="true"], #farastWord [contenteditable="true"]');
  return $('#fdFallbackEditor');
}
function restoreSel() {
  const ed = surfaceEl(); if (!ed) return;
  ed.focus({ preventScroll: true });
  if (window.__fdSavedRange) { const s = getSelection(); s.removeAllRanges(); s.addRange(window.__fdSavedRange); }
}
function domInsert(text) {
  restoreSel();
  clearInterim();
  const s = getSelection(); if (!s || !s.rangeCount) return false;
  const r = s.getRangeAt(0);
  try { document.execCommand('insertText', false, text); } catch { return false; }
  const nr = s.anchorNode && (s.anchorNode.nodeType === 3 ? s.anchorNode.parentElement : s.anchorNode);
  if (nr) { const rr = document.createRange(); rr.selectNodeContents(nr); rr.collapse(false); s.removeAllRanges(); s.addRange(rr); window.__fdSavedRange = rr.cloneRange(); }
  return true;
}
function insertIntoDoc(text) {
  const k = window.FarastEditor;
  if (!text) return false;
  if (domInsert(text)) { if (k?.state) { try { k.state.dirty = true; } catch {} } return true; }
  const cv = k?.commands?.has('InsertText') ? 'InsertText' : (k?.commands?.has('insertText') ? 'insertText' : null);
  if (cv) { try { return k.execute(cv, text) !== false; } catch {} }
  return false;
}

/* نمایش پیش‌نمایش موقت (interim) — دقیقاً مثل گوگل داکس: متن کم‌رنگ در مکان نشانگر.
 * الگو از persian-docs-editor.html (نمونهٔ تأییدشدهٔ کاربر): گرهٔ span موقت contenteditable=false.
 * این گره هرگز در مدل سند ذخیره نمی‌شود (پیش از هر capture حذف می‌گردد). */
let interimEl = null;
function hideInterimSel() { /* نگهبان سازگاری API */ }
function clearInterim() {
  if (interimEl) {
    try {
      const p = interimEl.parentNode;
      if (p) { const nx = interimEl.nextSibling; interimEl.remove(); if (nx && nx.nodeType === 3 && !nx.data) { /* متنی */ } }
    } catch {}
    interimEl = null;
  }
}
function showInterim(t) {
  if (!t) return clearInterim();
  const ed = surfaceEl(); if (!ed) return;
  restoreSel();
  const s = getSelection(); if (!s || !s.rangeCount) return;
  const r = s.getRangeAt(0);
  if (!ed.contains(r.startContainer)) return;
  if (!interimEl || !ed.contains(interimEl)) {
    clearInterim();
    interimEl = document.createElement('span');
    interimEl.className = 'fd-vi';
    interimEl.setAttribute('contenteditable', 'false');
    interimEl.setAttribute('data-fd-interim', '1');
    interimEl.setAttribute('aria-hidden', 'true');
    try { r.insertNode(interimEl); } catch { interimEl = null; return; }
    const nr = document.createRange(); nr.setStartAfter(interimEl); nr.collapse(true);
    s.removeAllRanges(); s.addRange(nr); window.__fdSavedRange = nr.cloneRange();
  }
  interimEl.textContent = t;
}
/* پاک‌سازی اضطراری: هر گرهٔ interim جامانده (مثلاً پس از خطا) — قبل از ذخیره/تغییر مدل صدا زده می‌شود */
function sweepInterim(root) {
  const scope = root && root.querySelectorAll ? root : document;
  scope.querySelectorAll('[data-fd-interim], .fd-vi').forEach(n => { try { n.remove(); } catch {} });
  if (!root) interimEl = null;
}

/* ---------- وضعیت موتورهای قابل استفاده ----------
 * تشخیص گفتار فقط از درگاه زندهٔ سرور انجام می‌شود؛ مرورگر هیچ موتور/مدل گفتاری نصب نمی‌کند. */
function isAuthed() { return $('#farastWord')?.dataset.authenticated === '1'; }
function hasMic() {
  // Live PCM streaming needs getUserMedia and Web Audio, not MediaRecorder.
  try { return !!(navigator.mediaDevices?.getUserMedia && (window.AudioContext || window.webkitAudioContext)); } catch { return false; }
}

/* ===================== کتابخانه‌های CDN محلی‌پسند (load-once، graceful) ===================== */
/* چند منبع برای هر کتابخانه: اگر jsdelivr فیلتر/مسدود بود، به unpkg و سپس cdnjs می‌رود.
 * این کار مشکل «در فلان کشور/شبکه کتابخانه لود نمی‌شود» را حل می‌کند — همچنان رایگان. */
const CDN = {
  tesseract: [
    'https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js',
    'https://unpkg.com/tesseract.js@5/dist/tesseract.min.js',
    'https://cdnjs.cloudflare.com/ajax/libs/tesseract.js/5.1.1/tesseract.min.js',
  ],

};
const libCache = {};
function loadOne(src) {
  return new Promise(res => {
    const s = document.createElement('script'); s.src = src; s.async = true; s.crossOrigin = 'anonymous';
    s.onload = () => res(true);
    s.onerror = () => { s.remove(); res(false); }; // بدون throw؛ منبع بعدی امتحان می‌شود
    document.head.appendChild(s);
  });
}
async function loadScript(srcOrList) {
  const urls = Array.isArray(srcOrList) ? srcOrList : [srcOrList];
  const key = urls.join('|');
  if (libCache[key]) return libCache[key];
  libCache[key] = (async () => {
    for (const u of urls) { if (await loadOne(u)) return true; }
    delete libCache[key];
    return false;
  })();
  return libCache[key];
}
async function ensureTesseract(ctx) {
  if (window.Tesseract) return true;
  ctx.onStatus?.('در حال آماده‌سازی OCR رایگان…');
  return await loadScript(CDN.tesseract);
}

/* ===================== OCR رایگان دست‌خط (Tesseract.js — fa+eng، محلی روی دستگاه) =====================
 * مرجع رایگان: https://github.com/naptha/tesseract.js (langPath CDN خودکار، بدون کلید API)
 * supportsHandwriting: زبان «fas» مدل LSTM تesseract از داده‌های دست‌نویس آموزش دیده است. */
let tessWorker = null, tessLang = '';
async function ocrImage(fileOrBlob, opts = {}) {
  const lang = (opts.lang || 'fas+eng').replace(/،/g, ',');
  if (!(await ensureTesseract(opts))) throw new Error('کتابخانهٔ OCR بارگذاری نشد (اینترنت لازم است).');
  if (!tessWorker || tessLang !== lang) {
    if (tessWorker) { try { await tessWorker.terminate(); } catch {} tessWorker = null; }
    tessWorker = await window.Tesseract.createWorker(lang, 1, {
      logger: m => { if (m.status === 'recognizing text' && opts.onProgress) opts.onProgress(Math.round((m.progress || 0) * 100)); },
    });
    tessLang = lang;
  }
  const { data } = await tessWorker.recognize(fileOrBlob);
  const words = (data.words || data.segments || []).map(w => ({
    text: w.text || '', confidence: Number((w.confidence != null ? w.confidence : (w.conf || 0))),
  })).filter(w => w.text);
  let text = String(data.text || '').replace(/\n{3,}/g, '\n\n').trim();
  // ارقام فارسی + نیم‌فاصلهٔ ساده در انتهای OCR (هم‌راستا با پردازش تایپ صوتی)
  text = toFaDigits(text);
  return { text, words };
}
function guessHandwritten(file) {
  // راهنمای هوشمند: عکس موبایل/PDF/اسکن → احتمال دست‌نویس؛ کاربر همیشه می‌تواند دستی انتخاب کند
  const n = (file?.name || '').toLowerCase();
  return /^image\//.test(file?.type || '') || /\.(jpe?g|png|webp|heic|bmp|tiff?)$/.test(n) || /\.pdf$/i.test(n);
}

function engines() {
  const list = [];
  if (isAuthed() && hasMic()) list.push({ id: 'cloud', label: 'تشخیص زنده روی سرور فراست' });
  list.push({ id: 'stt-api', label: 'اتصال به سرویس تبدیل گفتار دلخواه' });
  return list;
}
window.FarastVoiceHub = {
  version: '1.4.0',
  hasNative: () => false,
  isAuthed, hasMic, engines,
  processFinalText, insertIntoDoc, showInterim, clearInterim, sweepInterim, surfaceEl, hideInterimSel,
  ocr: { ocrImage, guessHandwritten, ensureTesseract },
};

/* ---------- کش وضعیت موتور ابری (از /editor/voice/status) ----------
 * تا وقتی پاسخ نیامده undefined است؛ در این حالت رفتار مثل قبل (تلاش و فالبک). */
let cloudStatusCache = null, cloudStatusTtl = 0;
async function cloudStatus(force = false) {
  const now = Date.now();
  if (!force && cloudStatusCache && now < cloudStatusTtl) return cloudStatusCache;
  try {
    const r = await fetch('/editor/voice/status', { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' });
    if (!r.ok) { cloudStatusCache = null; return null; }
    const j = await r.json();
    cloudStatusCache = (j && typeof j.mode === 'string') ? j : null;
    cloudStatusTtl = now + 60000; // یک دقیقه معتبر
  } catch { cloudStatusCache = null; }
  return cloudStatusCache;
}
window.FarastVoiceHub.cloudStatus = cloudStatus;

/* ===================== موتور ابری فراست (فايرفاکس و بقیه) =====================
 * دو مسیر، هر دو روی سرور خود سایت (رایگان برای شما تا سهمیهٔ سرویس‌های ابری):
 *  الف) استریم زندهٔ WebSocket (اگر گیتوی VOICE_STREAM_URL بالا باشد) — interim زنده
 *  ب) Custom STT endpoint: only when explicitly selected; never a live-gateway fallback.
 * اگر Runtime اصلی در دسترس باشد، همان باز می‌شود (بدون تکرار پیاده‌سازی). */
/* ---------- شناسایی خطای سطح‌بالای موتور ابری برای پیام دقیق به کاربر ---------- */
function cloudErrorKind(status, body) {
  const msg = String(body?.message || '').toLowerCase();
  if (status === 401 || msg.includes('authenticate')) return 'auth';                 // لاگین لازم است
  if (status === 402 || status === 403 || msg.includes('quota') || msg.includes('capability') || msg.includes('allow') || msg.includes('pay')) return 'quota'; // سهمیه/سطح حساب
  if (status === 429) return 'rate';                                                  // محدودیت نرخ
  return 'server';                                                                    // خرابی سرور/پیکربندی
}

async function startCloud(ctx) {
  try { if (window.FarastVoiceRuntime?.isOpen?.()) window.FarastVoiceRuntime.close(); } catch {}
  const csrf = $('meta[name="csrf-token"]')?.content || '';
  const app = $('#farastWord');
  if (!app || app.dataset.authenticated !== '1') throw new Error('برای تایپ صوتی زنده وارد حساب شوید.');

  const statusInfo = await cloudStatus(true);
  if (!statusInfo || statusInfo.mode !== 'gateway' || statusInfo.gateway !== true) {
    throw new Error('درگاه زندهٔ گفتار روی سرور آماده نیست؛ برای حفظ حالت زنده، ضبط دسته‌ای یا تشخیص گفتار مرورگر فعال نمی‌شود.');
  }
  if (statusInfo.self_hosted && ctx.lang() !== 'fa-IR') throw new Error('مدل گفتار محلی فعلاً فقط زبان فارسی ایران (fa-IR) را پشتیبانی می‌کند.');

  const response = await fetch('/editor/voice/stream-token', {
    method: 'POST',
    headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json', 'Content-Type': 'application/json' },
    credentials: 'same-origin',
    body: JSON.stringify({ locale: ctx.lang() }),
  });
  const data = await response.json().catch(() => ({}));
  if (!response.ok || !data.token || !data.websocket_url) {
    throw new Error(data.message || 'توکن اتصال به درگاه زندهٔ گفتار دریافت نشد.');
  }
  return await startCloudStream(ctx, csrf, data);
}
async function startCloudStream(ctx, csrf, data) {
  ctx.onStatus('در حال اتصال به موتور زندهٔ سرور…');
  const wsUrl = String(data.websocket_url || '').replace(/^http:/, 'ws:').replace(/^https:/, 'wss:');
  const ws = new WebSocket(`${wsUrl}?v=1`); ws.binaryType = 'arraybuffer';
  let media = null, ac = null, worklet = null, closed = false, stopTimer = null;
  let readyResolve = null, readyReject = null;
  const readyPromise = new Promise((resolve, reject) => { readyResolve = resolve; readyReject = reject; });
  readyPromise.catch(() => {});
  const cleanup = (flush = true) => {
    if (closed) return;
    closed = true;
    if (flush && ws.readyState === WebSocket.OPEN) {
      try { ws.send(JSON.stringify({ type: 'stop' })); } catch {}
      stopTimer = setTimeout(() => { try { ws.close(1000, 'editor_stop_timeout'); } catch {} }, 8000);
    } else {
      try { ws.close(); } catch {}
    }
    try { worklet?.disconnect(); } catch {}
    try { ac?.close(); } catch {}
    media?.getTracks().forEach(t => { try { t.stop(); } catch {} });
    ctx.onStopped();
  };
  ws.onopen = async () => {
    try {
      ws.send(JSON.stringify({ type: 'start', token: data.token, locale: ctx.lang(), sequence: 0 }));
      let timer;
      try {
        await Promise.race([readyPromise, new Promise((_, reject) => { timer = setTimeout(() => reject(new Error('موتور گفتار روی سرور آماده نشد.')), 15000); })]);
      } finally { clearTimeout(timer); }
      if (closed) return;
      media = await navigator.mediaDevices.getUserMedia({ audio: { channelCount: 1, echoCancellation: true, noiseSuppression: true, autoGainControl: true }, video: false });
      ac = new (window.AudioContext || window.webkitAudioContext)({ latencyHint: 'interactive', sampleRate: 48000 });
      await ac.audioWorklet.addModule('/js/farast-voice-worklet.js?v=20261031serveronly4');
      const src = ac.createMediaStreamSource(media);
      worklet = new AudioWorkletNode(ac, 'farast-pcm-processor', { numberOfInputs: 1, numberOfOutputs: 1, outputChannelCount: [1] });
      worklet.port.onmessage = e => { if (ws.readyState === WebSocket.OPEN && !closed) { const b = new Uint8Array(e.data); if (b.byteLength > 24000) { for (let i = 0; i < b.byteLength; i += 24000) ws.send(b.slice(i, Math.min(i + 24000, b.byteLength))); } else ws.send(b); } };
      src.connect(worklet);
      worklet.connect(ac.destination);
      if (ac.state === 'suspended') await ac.resume();
      ctx.onStatus('در حال گوش دادن (ابر)…'); ctx.onLive(true);
    } catch (e) { ctx.onError(e.message || 'میکروفن قابل شروع نیست.'); cleanup(); }
  };
  ws.onmessage = e => {
    let m; try { m = JSON.parse(e.data); } catch { return; }
    if (m.type === 'ready') { if (readyResolve) { readyResolve(); readyResolve = null; readyReject = null; } }
    else if (m.type === 'interim') ctx.onInterim(m.text || '');
    else if (m.type === 'final') ctx.onFinal(m.text || '');
    else if (m.type === 'provider_error' || m.type === 'error') {
      if (readyReject) readyReject(new Error(m.message || 'موتور گفتار سرور در دسترس نیست.'));
      ctx.onError(m.message || 'خطای موتور'); cleanup();
    }
  };
  ws.onerror = () => { if (readyReject) readyReject(new Error('ارتباط با موتور گفتار برقرار نشد.')); ctx.onError('ارتباط با موتور گفتار برقرار نشد.'); cleanup(false); };
  ws.onclose = () => { if (stopTimer) { clearTimeout(stopTimer); stopTimer = null; } if (readyReject) readyReject(new Error('درگاه گفتار پیش از آماده‌شدن بسته شد.')); if (!closed) cleanup(false); };
  return { stop: cleanup, mode: 'cloud' };
}

/* ===================== موتور سوم: میکروفون + سرویس STT قابل پیکربندی =====================
 * برای مرورگر/سیستمی که هیچ‌کدام از دو لایهٔ بالا ندارند: صدا را می‌گیرد و
 * قطعه‌های WebM به آدرس تنظیم‌شده توسط کاربر (localStorage farast.stt.endpoint) می‌فرستد.
 * این راه رایگانِ شخصی است (مثلاً اتصال به Whisper محلی روی شبکهٔ خودتان). */
async function startSttEndpoint(ctx) {
  const endpoint = localStorage.getItem('farast.stt.endpoint');
  if (!endpoint) throw new Error('برای این روش، نشانی سرویس تبدیل گفتار را در تنظیمات وارد کنید.');
  if (!navigator.mediaDevices?.getUserMedia || !window.MediaRecorder) throw new Error('این مرورگر امکان ضبط صدا را ندارد.');
  const media = await navigator.mediaDevices.getUserMedia({ audio: true, video: false });
  const rec = new MediaRecorder(media, { mimeType: MediaRecorder.isTypeSupported('audio/webm') ? 'audio/webm' : '' });
  let stopped = false;
  const flush = () => {
    const chunks = [];
    rec.ondataavailable = e => chunks.push(e.data);
    rec.onstop = async () => {
      if (stopped) return;
      const blob = new Blob(chunks, { type: rec.mimeType || 'audio/webm' });
      if (blob.size > 2000) {
        ctx.onStatus('در حال پردازش…');
        try {
          const fd = new FormData(); fd.append('audio', blob, 'chunk.webm');
          const r = await fetch(endpoint, { method: 'POST', body: fd });
          const j = await r.json().catch(() => ({}));
          const text = j.text || j.transcript || '';
          if (text) ctx.onFinal(text);
        } catch (e) { ctx.onError('ارسال به سرویس ناموفق بود: ' + e.message); }
      }
      if (!stopped) setTimeout(loop, 200);
    };
    rec.start();
  };
  const loop = () => { if (!stopped) flush(); };
  loop();
  ctx.onStatus('در حال گوش دادن (سرویس شما)…'); ctx.onLive(true);
  return { stop: () => { stopped = true; try { rec.stop(); } catch {} media.getTracks().forEach(t => t.stop()); ctx.onStopped(); }, mode: 'stt-api' };
}

window.FarastVoiceHub.startCloud = startCloud;
window.FarastVoiceHub.startSttEndpoint = startSttEndpoint;
})();
