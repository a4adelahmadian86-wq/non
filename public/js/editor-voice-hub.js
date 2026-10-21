/* FARAST · Voice Hub
 * Local Whisper is the default speech path. Quantized ONNX model weights are downloaded
 * once per browser origin and cached by Transformers.js; inference runs on the user's device.
 * Cloud/native speech engines are explicit opt-in fallbacks. OCR remains local via Tesseract.
 * The shared local ASR API is also used by the global voice conversation UI.
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
 * مسیر اصلیِ محلی: صدا روی دستگاه پردازش می‌شود؛ هیچ صوتی به‌طور خودکار به سرور ارسال نمی‌شود.
 * ضبط‌های کوتاه و پی‌درپی، دیکد به PCM و اجرای Whisper quantized روی WASM.
 * رایگان و بدون کلید؛ دانلود اولیه نیازمند اینترنت است و فایل‌ها در کش مرورگر نگه‌داری می‌شوند. */
let whr = null, whrName = '';
/* Local Whisper runs in a dedicated Worker so model inference does not block the editor UI. */
let whr = null, whrName = '', whrLoad = null;
let whisperWorker = null, whisperRequestId = 0;
const whisperPending = new Map();

function getWhisperWorker() {
  if (whisperWorker) return whisperWorker;
  if (!window.Worker) throw new Error('این مرورگر Web Worker را پشتیبانی نمی‌کند.');
  whisperWorker = new Worker('/js/farast-whisper-worker.js?v=20261021worker1', { type: 'module' });
  whisperWorker.onmessage = event => {
    const message = event.data || {};
    const pending = whisperPending.get(message.id);
    if (!pending) return;
    if (message.type === 'progress') {
      pending.onStatus?.(message.message || 'در حال بارگذاری مدل محلی…');
      return;
    }
    whisperPending.delete(message.id);
    if (message.type === 'error') pending.reject(new Error(message.message || 'Whisper محلی ناموفق بود.'));
    else pending.resolve(message.output ?? true);
  };
  whisperWorker.onerror = event => {
    const error = new Error(event.message || 'کارگر محلی Whisper متوقف شد.');
    whr = null; whrName = ''; whrLoad = null;
    whisperPending.forEach(pending => pending.reject(error));
    whisperPending.clear();
    whisperWorker = null;
  };
  return whisperWorker;
}

function whisperWorkerRequest(type, payload, onStatus) {
  const worker = getWhisperWorker();
  const id = ++whisperRequestId;
  return new Promise((resolve, reject) => {
    whisperPending.set(id, { resolve, reject, onStatus });
    const transfer = payload.audio instanceof Float32Array ? [payload.audio.buffer] : [];
    try { worker.postMessage({ id, type, ...payload }, transfer); }
    catch (error) { whisperPending.delete(id); reject(error); }
  });
}

async function ensureWhisper(onStatus, model) {
  if (whr && whrName === model) return whr;
  if (whrLoad) return whrLoad;
  onStatus?.('آماده‌سازی مدل Whisper در Worker؛ دانلود اولیه ممکن است ده‌ها تا صدها مگابایت باشد…');
  whrLoad = whisperWorkerRequest('load', { model }, onStatus).then(() => {
    whrName = model;
    whr = (pcm, options) => whisperWorkerRequest('transcribe', { model, audio: pcm, options }, onStatus);
    return whr;
  }).finally(() => { whrLoad = null; });
  return whrLoad;
}
async function decodeToPcm16k(audioBlob, onStatus) {
  const AC = window.AudioContext || window.webkitAudioContext;
  if (!AC) throw new Error('AudioContext در این مرورگر نیست.');
  const ab = await audioBlob.arrayBuffer();
  const acx = new AC();
  let buf;
  try { buf = await acx.decodeAudioData(ab); } finally { try { await acx.close(); } catch {} }
  // Transformers.js ASR expects raw Float32 PCM samples at 16 kHz, not WAV-file bytes.
  // Passing a Uint8Array containing a WAV header can be interpreted as sample values and
  // produce unrelated/hallucinated words, so decode and resample explicitly.
  const channels = Math.max(1, Number(buf.numberOfChannels) || 1);
  const mono = new Float32Array(buf.length);
  if (!mono.length) return new Float32Array(0);
  for (let channel = 0; channel < channels; channel++) {
    const source = buf.getChannelData(channel);
    for (let i = 0; i < source.length; i++) mono[i] += source[i] / channels;
  }
  const sourceRate = Number(buf.sampleRate) || 16000;
  const targetRate = 16000;
  if (sourceRate === targetRate) return mono;
  const outLength = Math.max(1, Math.floor(mono.length * targetRate / sourceRate));
  const pcm = new Float32Array(outLength);
  const scale = sourceRate / targetRate;
  for (let i = 0; i < outLength; i++) {
    const position = i * scale;
    const left = Math.min(mono.length - 1, Math.floor(position));
    const right = Math.min(mono.length - 1, left + 1);
    const fraction = position - left;
    pcm[i] = mono[left] * (1 - fraction) + mono[right] * fraction;
  }
  return pcm;
}

function hasUsableSpeech(pcm) {
  if (!(pcm instanceof Float32Array) || pcm.length < 4000) return false;
  let energy = 0;
  const stride = Math.max(1, Math.floor(pcm.length / 12000));
  let count = 0;
  for (let i = 0; i < pcm.length; i += stride) { energy += pcm[i] * pcm[i]; count++; }
  return count > 0 && Math.sqrt(energy / count) >= 0.002;
}

async function transcribeLocalBlob(blob, locale = 'fa-IR', onStatus) {
  if (!blob || blob.size < 2000) return '';
  const model = localStorage.getItem('farast.whisper.model') || 'Xenova/whisper-base';
  onStatus?.('آماده‌سازی Whisper محلی روی دستگاه…');
  const whisper = await ensureWhisper(onStatus, model);
  const pcm = await decodeToPcm16k(blob, onStatus);
  if (!hasUsableSpeech(pcm)) return '';
  const languages = {
    'fa-IR': 'farsi', 'en-US': 'english', 'ar-SA': 'arabic',
    'tr-TR': 'turkish', 'az-AZ': 'azerbaijani'
  };
  const output = await whisper(pcm, {
    task: 'transcribe',
    language: languages[locale],
    chunk_length_s: 30,
    stride_length_s: 5
  });
  return String(output?.text || '').trim();
}

async function startLocalWhisper(ctx) {
  if (!hasMic()) throw new Error('امکان ضبط صدا در این مرورگر/سیستم وجود ندارد.');
  // Local-first: speech is transcribed on this device. Do not silently upload microphone audio.
  // Transformers.js caches model files in the browser; subsequent sessions reuse that cache.
  const model = localStorage.getItem('farast.whisper.model') || 'Xenova/whisper-base';
  ctx.onStatus('بارگذاری مدل Whisper روی دستگاه شما (فقط بار اول)…');
  const whisper = await ensureWhisper(ctx.onStatus, model);
  let stopped = false, inflight = Promise.resolve(), lastFinal = '';
  let activeStream = null, activeRecorder = null, activeRecordTimer = null;
  const transcribe = async blob => {
    if (!blob || blob.size < 3000) return;
    ctx.onStatus('پردازش محلی (Whisper)…');
    inflight = inflight.then(async () => {
      try {
        const pcm = await decodeToPcm16k(blob, ctx.onStatus);
        if (!hasUsableSpeech(pcm)) return;
        const languages = { 'fa-IR': 'farsi', 'en-US': 'english', 'ar-SA': 'arabic', 'tr-TR': 'turkish', 'az-AZ': 'azerbaijani' };
        const out = await whisper(pcm, { task: 'transcribe', language: languages[ctx.lang()], chunk_length_s: 10, stride_length_s: 5 });
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
        if (stopped) { media.getTracks().forEach(t => { try { t.stop(); } catch {} }); resolve(null); return; }
        activeStream = media;
        const mime = ['audio/webm;codecs=opus', 'audio/webm', 'audio/ogg;codecs=opus'].find(m => { try { return MediaRecorder.isTypeSupported(m); } catch { return false; } }) || '';
        const rec = new MediaRecorder(media, mime ? { mimeType: mime } : undefined);
        activeRecorder = rec;
        const chunks = [];
        rec.ondataavailable = e => { if (e.data && e.data.size) chunks.push(e.data); };
        rec.onstop = () => {
          if (activeRecordTimer) { clearTimeout(activeRecordTimer); activeRecordTimer = null; }
          media.getTracks().forEach(t => { try { t.stop(); } catch {} });
          if (activeStream === media) activeStream = null;
          if (activeRecorder === rec) activeRecorder = null;
          resolve(new Blob(chunks, { type: mime || 'audio/webm' }));
        };
        try { rec.start(1000); } catch {
          media.getTracks().forEach(t => { try { t.stop(); } catch {} });
          if (activeStream === media) activeStream = null;
          if (activeRecorder === rec) activeRecorder = null;
          resolve(null); return;
        }
        activeRecordTimer = setTimeout(() => { try { if (rec.state !== 'inactive') rec.stop(); } catch {} }, 5000);
      })
      .catch(err => { if (!stopped) ctx.onError('دسترسی به میکروفون ممکن نشد: ' + (err?.message || err?.name || '')); resolve(null); });
  });
  (async () => {
    ctx.onStatus('در حال گوش دادن (محلی)…'); ctx.onLive(true);
    while (!stopped) { const blob = await recordOnce(); if (stopped || !blob) break; transcribe(blob); }
  })();
  return { stop: () => {
    stopped = true;
    if (activeRecordTimer) { clearTimeout(activeRecordTimer); activeRecordTimer = null; }
    try { if (activeRecorder && activeRecorder.state !== 'inactive') activeRecorder.stop(); } catch {}
    try { activeStream?.getTracks?.().forEach(track => { try { track.stop(); } catch {} }); } catch {}
    activeStream = null;
    activeRecorder = null;
    ctx.onStopped();
  }, mode: 'local-whisper' };
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
  version: '1.4.0',
  hasNative: () => !!SR,
  isAuthed, hasMic, engines,
  processFinalText, insertIntoDoc, showInterim, clearInterim, sweepInterim, surfaceEl, hideInterimSel,
  ocr: { ocrImage, guessHandwritten, ensureTesseract },
  localWhisper: { ensureWhisper, start: startLocalWhisper, transcribe: transcribeLocalBlob },
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
  /* پیش‌بررسی با /editor/voice/status: اگر سرور اصلاً برای صوت پیکربندی نشده باشد، بی‌درنگ و صادقانه
   * خطای «پیکربندی» می‌دهیم (نه پیام مبهم «ارتباط برقرار نشد»). اگر آماده باشد، مسیر توکن/استریم امتحان می‌شود. */
  let statusInfo = null;
  try {
    statusInfo = await cloudStatus();
    if (statusInfo && statusInfo.mode === 'none') {
      const message = statusInfo.reason === 'auth'
        ? 'برای تایپ صوتی، ابتدا وارد حساب خود شوید.'
        : statusInfo.reason === 'self_hosted_token_missing'
          ? 'توکن موتور گفتار خودمیزبان تنظیم نشده است؛ FARAST_VOICE_ASR_TOKEN را در محیط سرور تنظیم کنید. فالبک مرورگر و Whisper محلی برای این مسیر غیرفعال است.'
          : 'موتور گفتار روی این سرور هنوز پیکربندی نشده است.';
      throw Object.assign(new Error(message), { fatal: true, code: 'server_not_configured' });
    }
  } catch (e) {
    if (e?.fatal) throw e;
    statusInfo = null; // endpoint در نسخهٔ قدیمی سرور وجود ندارد — مثل قبل رفتار کن
  }
  /* حالت تکه‌ای مطمئن‌ترین مسیر است؛ اگر وضعیت گفت gateway زنده نیست، مستقیم به تکه‌ای برو. */
  if (statusInfo && statusInfo.chunked && !statusInfo.gateway) {
    ctx.onStatus(statusInfo.self_hosted ? 'موتور گفتار خودمیزبان فعال شد…' : 'حالت تکه‌ای ابری فعال شد…');
    return await startCloudChunks(ctx, csrf, statusInfo.self_hosted ? 'self-hosted-unavailable' : 'gateway-unavailable', !!statusInfo.self_hosted);
  }
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
async function startCloudChunks(ctx, csrf, gwErr, strictSelfHosted = false) {
  if (!navigator.mediaDevices?.getUserMedia || !window.MediaRecorder) {
    throw Object.assign(new Error(gwErr || 'این مرورگر امکان ضبط صدا را ندارد.'), { fatal: strictSelfHosted });
  }

  // Accuracy-first fallback: keep ONE continuous MediaRecorder session and transcribe the
  // complete recording on stop. Independent 4-second files lose linguistic context and can
  // cause Whisper/ASR to hallucinate unrelated Persian words. Native browser SR/WebSocket
  // remain the live modes; this HTTP fallback intentionally commits text after Stop.
  const pickMime = () => ['audio/webm;codecs=opus', 'audio/webm', 'audio/ogg;codecs=opus']
    .find(m => { try { return MediaRecorder.isTypeSupported(m); } catch { return false; } }) || '';
  const sessionRequestId = globalThis.crypto?.randomUUID?.()
    || ('voice-session-' + Date.now() + '-' + Math.random().toString(16).slice(2));
  const maxAudioBytes = 12 * 1024 * 1024;
  let stopped = false, finalizing = false, limitReached = false, stream = null, recorder = null;
  let chunks = [], byteCount = 0;

  const stopTracks = () => {
    try { stream?.getTracks?.().forEach(track => { try { track.stop(); } catch {} }); } catch {}
    stream = null;
  };

  const transcribeCompleteRecording = async blob => {
    if (!blob || blob.size < 2000) {
      ctx.onError('صدای کافی ضبط نشد. کمی طولانی‌تر صحبت کنید و دوباره تلاش کنید.');
      ctx.onStopped();
      return;
    }
    ctx.onStatus('در حال تبدیل کل گفتار به متن برای دقت بیشتر…');
    try {
      const fd = new FormData();
      const type = String(blob.type || '');
      const ext = type.includes('ogg') ? 'ogg' : (type.includes('mp4') ? 'mp4' : 'webm');
      fd.append('audio', blob, 'farast-voice-session.' + ext);
      fd.append('locale', ctx.lang());
      const r = await fetch('/editor/voice/transcribe', {
        method: 'POST',
        headers: {
          'X-CSRF-TOKEN': csrf,
          'Accept': 'application/json',
          'X-Farast-Voice-Request': sessionRequestId,
          'Idempotency-Key': sessionRequestId
        },
        body: fd
      });
      const j = await r.json().catch(() => ({}));
      if (!r.ok) {
        const kind = cloudErrorKind(r.status, j);
        if (strictSelfHosted) {
          throw new Error(j.message || 'موتور گفتار خودمیزبان در دسترس نیست.');
        }
        throw new Error(kind === 'quota'
          ? 'این حساب فعلاً اجازهٔ تایپ صوتی ابری ندارد (سهمیه/پلن).'
          : kind === 'rate'
            ? 'محدودیت تعداد درخواست صوت فعال شد؛ کمی بعد دوباره امتحان کنید.'
            : j.message || 'رونویسی ابری انجام نشد؛ وضعیت سرویس گفتار را بررسی کنید.');
      }
      const text = String(j.text || '').trim();
      if (!text) throw new Error('متن قابل تشخیصی پیدا نشد. در محیط آرام‌تر و با صحبت واضح‌تر دوباره امتحان کنید.');
      clearInterim();
      ctx.onFinal(text);
      if (limitReached) ctx.onError('برای جلوگیری از ارسال فایل بیش از حد بزرگ، ضبط پس از رسیدن به حد مجاز متوقف شد؛ متن ضبط‌شده تا آن لحظه درج شد.');
      ctx.onStatus('رونویسی کامل شد');
    } catch (e) {
      ctx.onError(e?.message || (strictSelfHosted ? 'ارتباط با موتور گفتار خودمیزبان برقرار نشد.' : 'رونویسی ابری ناموفق بود.'));
    } finally {
      ctx.onStopped();
    }
  };

  try {
    stream = await navigator.mediaDevices.getUserMedia({
      audio: { channelCount: 1, echoCancellation: true, noiseSuppression: true, autoGainControl: true },
      video: false
    });
    const mime = pickMime();
    recorder = mime ? new MediaRecorder(stream, { mimeType: mime }) : new MediaRecorder(stream);
    recorder.ondataavailable = event => {
      if (!event.data?.size || finalizing) return;
      chunks.push(event.data);
      byteCount += event.data.size;
      if (byteCount >= maxAudioBytes && !finalizing) {
        limitReached = true;
        ctx.onStatus('حجم ضبط به حد مجاز رسید؛ در حال پایان ضبط و رونویسی…');
        stop();
      }
    };
    recorder.onerror = event => {
      ctx.onError(event?.error?.message || 'ضبط صدا با خطا مواجه شد.');
      stop();
    };
    recorder.onstop = async () => {
      if (finalizing) return;
      finalizing = true;
      stopTracks();
      const blob = new Blob(chunks, { type: recorder?.mimeType || mime || 'audio/webm' });
      chunks = [];
      await transcribeCompleteRecording(blob);
    };
    recorder.start(1000);
    ctx.onStatus('در حال گوش دادن (ابر · ضبط پیوسته؛ درج پس از پایان برای دقت بیشتر)…');
    ctx.onLive(true);
  } catch (error) {
    stopTracks();
    throw Object.assign(error instanceof Error ? error : new Error(String(error)), { fatal: strictSelfHosted });
  }

  function stop() {
    if (stopped) return;
    stopped = true;
    if (recorder && recorder.state !== 'inactive') {
      try { recorder.stop(); return; } catch {}
    }
    if (!finalizing) {
      finalizing = true;
      stopTracks();
      const blob = new Blob(chunks, { type: recorder?.mimeType || 'audio/webm' });
      chunks = [];
      transcribeCompleteRecording(blob);
    }
  }

  return { stop, mode: 'cloud-chunks' };
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
