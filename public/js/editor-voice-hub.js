/* FARAST · Voice Hub (معماری چندلایهٔ تایپ صوتی — رایگان، همهٔ مرورگرها)
 * ============================================================
 * لایه‌ها به ترتیب اولویت:
 *  ۱) موتور بومی مرورگر (Web Speech API) — رایگان، بدون سرور (Chrome/Edge/Safari/Opera/Samsung)
 *     عین الگوی persian-docs-editor.html (نمونهٔ مورد نظر کاربر): continuous + interim،
 *     قطع نشدن با ویرایش دستی، بازآغاز خودکار در صورت end زودهنگام.
 *  ۲) موتور ابری فراست (WebSocket → /editor/voice/stream-token یا HTTP تکه‌ای → /editor/voice/transcribe)
 *     برای Firefox و هر مرورگر بدون SR — همان موتور Google/Gemini سایت.
 *  ۳) OCR رایگان handwriting: Tesseract.js (محلی در مرورگر، fa+eng) — فایل/تصویر دست‌نویس
 *  ۴) فالبک نهایی: ضبط صدا و تبدیل گفتار به متن با Whisper محلی (transformers.js + whisper)
 *     روی دستگاه کاربر — کاملاً رایگان، بدون کلید API؛ در صورت نبود CDN آفلاین،
 *     راهنمای نصب محلی نمایش داده می‌شود.
 * تشخیص خودکار: اگر موتور ۱ موجود نبود، پنل به‌جای پیام «پشتیبانی نمی‌شود»،
 * مسیر جایگزین را خودش فعال می‌کند. هستهٔ سند از طریق window.FarastEditor دستکاری می‌شود.
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

/* ---------- وضعیت موتورهای قابل استفاده ---------- */
/* موتور بومی افزودهٔ فایرفاکس (Firefox SpeechRecognition) — رایگان، بدون سرور.
 * مرجع: https://github.com/Synesthesiallc/Firefox-Speech-Recognition — در Firefox 135+
 * با فعال‌سازی general.subframe... و speech.enabled در about:config در دسترس است؛ اگر نبود،
 * خودکار به لایهٔ بعدی (ابری/Whisper محلی) می‌رویم. */
const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
function isAuthed() { return $('#farastWord')?.dataset.authenticated === '1'; }
function hasMic() {
  // در jsdom/تست و محیط‌های بدون mediaDevices، با MediaRecorder چک می‌شود؛ در غیر این صورت false
  try { return !!(navigator.mediaDevices?.getUserMedia && window.MediaRecorder); } catch { return false; }
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
  transformers: [
    'https://cdn.jsdelivr.net/npm/@huggingface/transformers@3.5.0/dist/transformers.min.js',
    'https://unpkg.com/@huggingface/transformers@3.5.0/dist/transformers.min.js',
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

/* ===================== فالبک نهاییِ گفتار: Whisper محلی (transformers.js) =====================
 * برای مرورگرهایی که Web Speech ندارند و موتور ابری هم در دسترس نیست (آفلاین/بدون حساب):
 * ضبط با MediaRecorder → دیکد به PCM با AudioContext.decodeAudioData → Whisper tiny/base روی WASM.
 * رایگان، بدون سرور، بدون کلید. مدل از HF Hub کش می‌شود (first run نیازمند اینترنت). */
let whr = null, whrName = '';
/* موتور Whisper محلی — فالبک نهایی. کتابخانهٔ transformers.js به‌صورت باندل IIFE با CDNهای
 * پشتیبانی‌نشده کار نمی‌کند (خطای dynamic import); لذا ESM از esm.sh بارگذاری و روی window.transformers
 * قرار داده می‌شود تا کد موجود بدون تغییر کار کند. چند CDN برای شبکه‌های محدود. */
const WHISPER_ESM = [
  'https://esm.sh/@huggingface/transformers@3.5.0',
  'https://cdn.jsdelivr.net/npm/@huggingface/transformers@3.5.0/+esm',
  'https://unpkg.com/@huggingface/transformers@3.5.0/dist/transformers.js?module',
];
async function loadWhisperLib() {
  if (window.transformers) return true;
  for (const u of WHISPER_ESM) {
    try {
      const mod = await import(/* @vite-ignore */ u);
      if (mod?.pipeline) { window.transformers = mod; return true; }
    } catch {}
  }
  // تلاش آخر: باندل IIFE (در برخی مرورگرها/شبکه‌ها جواب می‌دهد)
  return await loadScript(CDN.transformers);
}

async function ensureWhisper(onStatus, model) {
  const tf = window.transformers || (await loadWhisperLib() ? window.transformers : null);
  if (!tf) throw new Error('کتابخانهٔ transformers.js از هیچ CDNای بارگذاری نشد (اینترنت/فیلتر شبکه را بررسی کنید).');
  if (whr && whrName === model) return whr;
  onStatus?.('آماده‌سازی مدل گفتار روی دستگاه (بار اول، چند مگابایت…)');
  /* تلاش با CDN هافینگ‌فیس؛ در شبکه‌های محدود، mirror داخلی (hf-mirror.com) امتحان می‌شود */
  try {
    whr = await tf.pipeline('automatic-speech-recognition', { model, quantized: true, device: 'wasm' });
  } catch (e1) {
    onStatus?.('مدل از منبع اصلی دانلود نشد؛ در حال امتحان mirror جایگزین…');
    try {
      tf.env?.allowRemoteModels && (tf.env.remoteHost = 'https://hf-mirror.com/');
      tf.env?.remotePathTemplate && (tf.env.remotePathTemplate = '{model}/resolve/{revision}/');
      whr = await tf.pipeline('automatic-speech-recognition', { model, quantized: true, device: 'wasm' });
    } catch (e2) {
      throw new Error('مدل Whisper قابل دانلود نبود (' + (String(e2?.message || e1?.message || '').slice(0, 80)) + '). از موتور ابری یا سرویس شخصی استفاده کنید.');
    }
  }
  whrName = model;
  return whr;
}
async function decodeToWav(audioBlob, onStatus) {
  const AC = window.AudioContext || window.webkitAudioContext;
  if (!AC) throw new Error('AudioContext در این مرورگر نیست.');
  const ab = await audioBlob.arrayBuffer();
  const acx = new AC({ sampleRate: 16000 });
  let buf;
  try { buf = await acx.decodeAudioData(ab); } finally { try { acx.close(); } catch {} }
  const ch = buf.getChannelData(0);
  const sr = buf.sampleRate;
  // تبدیل به WAV 16k mono (قالب ورودی whisper)
  const ratio = Math.max(1, Math.round(sr / 16000));
  const outRate = Math.floor(sr / ratio);
  const len = Math.floor(ch.length / ratio);
  const pcm = new Float32Array(len);
  for (let i = 0; i < len; i++) { let s = 0; for (let j = 0; j < ratio; j++) s += ch[i * ratio + j] || 0; pcm[i] = s / ratio; }
  const ns = 44 + pcm.length * 2, b = new DataView(new ArrayBuffer(ns));
  const str = (o, x) => { for (let i = 0; i < x.length; i++) b.setUint8(o + i, x.charCodeAt(i)); };
  str(0, 'RIFF'); b.setUint32(4, ns - 8, true); str(8, 'WAVE'); str(12, 'fmt ');
  b.setUint32(16, 16, true); b.setUint16(20, 1, true); b.setUint16(22, 1, true);
  b.setUint32(24, outRate, true); b.setUint32(28, outRate * 2, true); b.setUint16(32, 2, true); b.setUint16(34, 16, true);
  str(36, 'data'); b.setUint32(40, pcm.length * 2, true);
  for (let i = 0; i < pcm.length; i++) b.setInt16(44 + i * 2, Math.max(-1, Math.min(1, pcm[i])) * 32767, true);
  return new Uint8Array(b.buffer);
}
async function startLocalWhisper(ctx) {
  if (!hasMic()) throw new Error('امکان ضبط صدا در این مرورگر/سیستم وجود ندارد.');
  /* اگر موتور ابری فراست (سرور خود سایت) موجود است، اول آن — دقیق‌تر و سبک‌تر از Whisper محلی.
     فقط وقتی ابری در دسترس نیست یا شکست خورد، Whisper محلی به‌عنوان فالبک رایگان فعال می‌شود. */
  if (isAuthed()) {
    try { ctx.onStatus('اتصال به موتور ابری فراست…'); return await startCloud(ctx); }
    catch (e) { if (e?.fatal) throw e; ctx.onStatus('موتور ابری در دسترس نبود؛ انتقال به Whisper محلی…'); }
  }
  const model = localStorage.getItem('farast.whisper.model') || 'Xenova/whisper-base';
  let stopped = false, inflight = Promise.resolve(), lastFinal = '';
  const transcribe = async blob => {
    if (!blob || blob.size < 3000) return;
    ctx.onStatus('پردازش محلی (Whisper)…');
    inflight = inflight.then(async () => {
      try {
        const wav = await decodeToWav(blob, ctx.onStatus);
        const pipe = await ensureWhisper(ctx.onStatus, model);
        const out = await pipe(wav, { task: 'transcribe', language: ctx.lang().startsWith('fa') ? 'farsi' : undefined, chunk_length_s: 10, stride_length_s: 5 });
        const text = String(out?.text || '').trim();
        if (text) {
          clearInterim();
          let t = text;
          if (lastFinal && t.startsWith(lastFinal)) t = t.slice(lastFinal.length).trim();
          else if (lastFinal && lastFinal.endsWith(t)) t = '';
          if (t) { lastFinal = text; ctx.onFinal(t); }
        }
      } catch (e) { ctx.onError('فالبک محلی: ' + (e?.message || '')); }
      if (!stopped) ctx.onStatus('در حال گوش دادن (محلی)…');
    });
  };
  const recordOnce = () => new Promise(resolve => {
    navigator.mediaDevices.getUserMedia({ audio: { channelCount: 1, echoCancellation: true, noiseSuppression: true, autoGainControl: true }, video: false })
      .then(media => {
        const mime = ['audio/webm;codecs=opus', 'audio/webm', 'audio/ogg;codecs=opus'].find(m => { try { return MediaRecorder.isTypeSupported(m); } catch { return false; } }) || '';
        const rec = new MediaRecorder(media, mime ? { mimeType: mime } : undefined);
        const chunks = [];
        rec.ondataavailable = e => { if (e.data && e.data.size) chunks.push(e.data); };
        rec.onstop = () => { media.getTracks().forEach(t => { try { t.stop(); } catch {} }); resolve(new Blob(chunks, { type: mime || 'audio/webm' })); };
        try { rec.start(1000); } catch { media.getTracks().forEach(t => { try { t.stop(); } catch {} }); resolve(null); return; }
        setTimeout(() => { try { if (rec.state !== 'inactive') rec.stop(); } catch {} }, 4000);
      })
      .catch(err => { if (!stopped) ctx.onError('دسترسی به میکروفون ممکن نشد: ' + (err?.message || err?.name || '')); resolve(null); });
  });
  (async () => {
    ctx.onStatus('در حال گوش دادن (محلی)…'); ctx.onLive(true);
    while (!stopped) { const blob = await recordOnce(); if (stopped || !blob) break; transcribe(blob); }
  })();
  return { stop: () => { stopped = true; ctx.onStopped(); }, mode: 'local-whisper' };
}

function engines() {
  const list = [];
  if (SR) list.push({ id: 'native', label: 'موتور بومی مرورگر (رایگان)' });
  // موتور ابری سایت در هر مرورگری کار می‌کند (WebSocket یا HTTP تکه‌ای) — فقط نیازمند ورود به حساب
  if (isAuthed() && hasMic()) list.push({ id: 'cloud', label: 'موتور ابری فراست (بدون محدودیت مرورگر)' });
  if (hasMic()) list.push({ id: 'local-whisper', label: 'فالبک محلی رایگان (Whisper روی دستگاه شما)' });
  list.push({ id: 'stt-api', label: 'اتصال به سرویس تبدیل گفتار دلخواه' });
  return list;
}
window.FarastVoiceHub = {
  version: '1.2.0',
  hasNative: () => !!SR,
  isAuthed, hasMic, engines,
  processFinalText, insertIntoDoc, showInterim, clearInterim, sweepInterim, surfaceEl, hideInterimSel,
  ocr: { ocrImage, guessHandwritten, ensureTesseract },
  localWhisper: { ensureWhisper, start: startLocalWhisper },
};

/* ===================== موتور ابری فراست (فايرفاکس و بقیه) =====================
 * دو مسیر، هر دو روی سرور خود سایت (رایگان برای شما تا سهمیهٔ سرویس‌های ابری):
 *  الف) استریم زندهٔ WebSocket (اگر گیتوی VOICE_STREAM_URL بالا باشد) — interim زنده
 *  ب) Fallback خودکار: ضبط قطعه‌ها با MediaRecorder → /editor/voice/transcribe (همان موتور Google/Gemini سایت)
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
  /* موتور ابری فراست (WebSocket/تکه‌ای روی سرور خود سایت) در همهٔ مرورگرها کار می‌کند.
     اگر Runtime قدیمی باز بود، بسته می‌شود تا پنل گوگل‌داكسی تنها رابط فعال بماند. */
  try { if (window.FarastVoiceRuntime?.isOpen?.()) window.FarastVoiceRuntime.close(); } catch {}
  const csrf = $('meta[name="csrf-token"]')?.content || '';
  const app = $('#farastWord');
  if (!app || app.dataset.authenticated !== '1') throw new Error('برای موتور ابری وارد حساب شوید.');
  /* پیش‌بررسی سریع: اگر توکن استریم خطای سطح‌بالا (لاگین/سهمیه) داد، بلافاصله و با پیام درست برگرد.
   * اگر فقط گیتوی پایین باشد، خطای اتصال می‌دهد و حالت تکه‌ای امتحان می‌شود. */
  let preflightErr = null;
  try {
    const pr = await fetch('/editor/voice/stream-token', { method: 'POST', headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json', 'Content-Type': 'application/json' }, body: JSON.stringify({ locale: ctx.lang() }) });
    const pj = await pr.json().catch(() => ({}));
    if (!pr.ok || !pj.token) {
      const kind = cloudErrorKind(pr.status, pj);
      if (kind !== 'server') {
        const friendly = { auth: 'برای تایپ صوتی ابری، ابتدا وارد حساب خود شوید.', quota: 'این حساب فعلاً اجازهٔ تایپ صوتی ابری ندارد (سهمیه/پلن). از «تنظیمات موتور» می‌توانید Whisper محلی رایگان را انتخاب کنید.', rate: 'چند لحظه صبر کنید؛ محدودیت تعداد درخواست صوت فعال شده است.' }[kind];
        throw Object.assign(new Error(friendly), { fatal: true });
      }
      preflightErr = pj.message || 'gateway-down';
    }
  } catch (e) {
    if (e?.fatal) throw e;
    preflightErr = e?.message || 'network'; // شبکه/سرور — باز هم حالت تکه‌ای را امتحان می‌کنیم
  }
  try {
    if (preflightErr) throw new Error(preflightErr); // مستقیم به تکه‌ای
    return await startCloudStream(ctx, csrf);
  } catch (e) {
    if (e?.fatal) throw e;
    // گیتوی در دسترس نیست؟ مسیر HTTP chunked را امتحان کن (همیشه کار می‌کند)
    ctx.onStatus('اتصال زندهٔ وب‌سوکت در دسترس نبود؛ حالت تکه‌ای فعال شد…');
    return await startCloudChunks(ctx, csrf, e?.message || '');
  }
}
async function startCloudStream(ctx, csrf) {
  ctx.onStatus('در حال اتصال به موتور ابری…');
  const r = await fetch('/editor/voice/stream-token', { method: 'POST', headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json', 'Content-Type': 'application/json' }, body: JSON.stringify({ locale: ctx.lang() }) });
  const data = await r.json().catch(() => ({}));
  if (!r.ok || !data.token) throw new Error(data.message || 'توکن تایپ صوتی دریافت نشد.');
  const wsUrl = String(data.websocket_url || '').replace(/^http:/, 'ws:').replace(/^https:/, 'wss:');
  const ws = new WebSocket(`${wsUrl}?v=1`); ws.binaryType = 'arraybuffer';
  let media = null, ac = null, worklet = null, closed = false;
  const cleanup = () => { closed = true; try { ws.close(); } catch {} try { worklet?.disconnect(); } catch {} try { ac?.close(); } catch {} media?.getTracks().forEach(t => { try { t.stop(); } catch {} }); ctx.onStopped(); };
  ws.onopen = async () => {
    try {
      ws.send(JSON.stringify({ type: 'start', token: data.token, locale: ctx.lang(), sequence: 0 }));
      media = await navigator.mediaDevices.getUserMedia({ audio: { channelCount: 1, echoCancellation: true, noiseSuppression: true, autoGainControl: true }, video: false });
      ac = new (window.AudioContext || window.webkitAudioContext)({ latencyHint: 'interactive', sampleRate: 48000 });
      await ac.audioWorklet.addModule('/js/farast-voice-worklet.js');
      const src = ac.createMediaStreamSource(media);
      worklet = new AudioWorkletNode(ac, 'farast-pcm-processor', { numberOfInputs: 1, numberOfOutputs: 0 });
      worklet.port.onmessage = e => { if (ws.readyState === WebSocket.OPEN && !closed) { const b = new Uint8Array(e.data); if (b.byteLength > 24000) { for (let i = 0; i < b.byteLength; i += 24000) ws.send(b.slice(i, Math.min(i + 24000, b.byteLength))); } else ws.send(b); } };
      src.connect(worklet);
      if (ac.state === 'suspended') await ac.resume();
      ctx.onStatus('در حال گوش دادن (ابر)…'); ctx.onLive(true);
    } catch (e) { ctx.onError(e.message || 'میکروفن قابل شروع نیست.'); cleanup(); }
  };
  ws.onmessage = e => {
    let m; try { m = JSON.parse(e.data); } catch { return; }
    if (m.type === 'interim') ctx.onInterim(m.text || '');
    else if (m.type === 'final') ctx.onFinal(m.text || '');
    else if (m.type === 'error') { ctx.onError(m.message || 'خطای موتور'); cleanup(); }
  };
  ws.onerror = () => ctx.onError('ارتباط با موتور ابری برقرار نشد.');
  ws.onclose = () => { if (!closed) cleanup(); };
  return { stop: cleanup, mode: 'cloud' };
}

/* ---------- حالت تکه‌ای ابری (Firefox و هر مرورگر بدون وب‌سوکت/گیتوی) ----------
 * ضبط قطعه‌قطعه با MediaRecorder، ارسال هر قطعه به /editor/voice/transcribe (موتور Google/Gemini خود سایت).
 * حلقه بی‌وقفه: همزمان که قطعهٔ n در سرور پردازش می‌شود، قطعهٔ n+1 در حال ضبط است. */
async function startCloudChunks(ctx, csrf, gwErr) {
  if (!navigator.mediaDevices?.getUserMedia || !window.MediaRecorder) throw new Error(gwErr || 'این مرورگر امکان ضبط صدا را ندارد.');
  const pickMime = () => ['audio/webm;codecs=opus', 'audio/webm', 'audio/ogg;codecs=opus'].find(m => { try { return MediaRecorder.isTypeSupported(m); } catch { return false; } }) || '';
  let stopped = false, inflight = Promise.resolve(), lastFinal = '', seq = 0;
  const transcribe = blob => {
    if (!blob || blob.size < 2000) return;
    ctx.onStatus('در حال پردازش…');
    inflight = inflight.then(async () => {
      try {
        const fd = new FormData(); fd.append('audio', blob, 'chunk-' + (++seq) + '.webm'); fd.append('locale', ctx.lang());
        const r = await fetch('/editor/voice/transcribe', { method: 'POST', headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' }, body: fd });
        const j = await r.json().catch(() => ({}));
        if (!r.ok) {
          // خطای سطح‌بالا (سهمیه/لاگین/نرخ): توقف حلقه با پیام دقیق — نه بی‌صدا تکرار هر ۲.۵ ثانیه
          const kind = cloudErrorKind(r.status, j);
          if (kind !== 'server') {
            stopped = true;
            ctx.onError(kind === 'quota' ? 'این حساب فعلاً اجازهٔ تایپ صوتی ابری ندارد (سهمیه/پلن). از «تنظیمات موتور» → Whisper محلی رایگان استفاده کنید.' : kind === 'rate' ? 'محدودیت تعداد درخواست صوت فعال شد؛ چند لحظه دیگر دوباره امتحان کنید.' : 'برای تایپ صوتی ابری وارد حساب شوید.');
            ctx.onStopped();
            return;
          }
          if (j.message) ctx.onError(j.message);
          if (!stopped) ctx.onStatus('در حال گوش دادن (ابر · تکه‌ای)…');
          return;
        }
        const text = String(j.text || '').trim();
        if (text) {
          clearInterim();
          let t = text; // مرز قطعه‌ها گاهی کلمهٔ مشترک برمی‌دارد؛ تکرار ابتدایی حذف می‌شود
          if (lastFinal && t.startsWith(lastFinal)) t = t.slice(lastFinal.length).trim();
          else if (lastFinal && lastFinal.endsWith(t)) t = '';
          if (t) { lastFinal = text; ctx.onFinal(t); }
        }
      } catch (e) { ctx.onError('ارسال صوت ناموفق بود: ' + (e?.message || '')); }
      if (!stopped) ctx.onStatus('در حال گوش دادن (ابر · تکه‌ای)…');
    });
  };
  const recordOnce = () => new Promise(resolve => {
    let media;
    navigator.mediaDevices.getUserMedia({ audio: { channelCount: 1, echoCancellation: true, noiseSuppression: true, autoGainControl: true }, video: false })
      .then(m => {
        media = m;
        const mime = pickMime();
        const rec = new MediaRecorder(media, mime ? { mimeType: mime } : undefined);
        const chunks = [];
        rec.ondataavailable = e => { if (e.data && e.data.size) chunks.push(e.data); };
        rec.onerror = () => {};
        rec.onstop = () => {
          media.getTracks().forEach(t => { try { t.stop(); } catch {} });
          resolve(new Blob(chunks, { type: mime || 'audio/webm' }));
        };
        try { rec.start(1000); } catch { media.getTracks().forEach(t => { try { t.stop(); } catch {} }); resolve(null); return; }
        setTimeout(() => { try { if (rec.state !== 'inactive') rec.stop(); } catch {} }, 2500);
      })
      .catch(err => { if (!stopped) ctx.onError('دسترسی به میکروفون ممکن نشد: ' + (err?.message || err?.name || '')); resolve(null); });
  });
  const loop = async () => {
    while (!stopped) {
      const blob = await recordOnce();
      if (stopped || !blob) break;
      transcribe(blob);
    }
  };
  ctx.onStatus('در حال گوش دادن (ابر · تکه‌ای)…'); ctx.onLive(true);
  loop();
  return { stop: () => { stopped = true; ctx.onStopped(); }, mode: 'cloud-chunks' };
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
