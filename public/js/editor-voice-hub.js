/* FARAST · Voice Hub (تایپ صوتی با پردازش سمت سرور)
 * ============================================================
 * ۱) Web Speech API بومی مرورگر فقط در صورت پشتیبانی مرورگر (سرویس/مدل آن تحت کنترل FARAST نیست).
 * ۲) موتور ابری فراست: WebSocket یا HTTP به API سمت سرور.
 * ۳) هیچ مدل اختصاصی گفتار/OCR یا وزن مدل هوش مصنوعی روی دستگاه کاربر دانلود نمی‌شود.
 *    مصرف CPU/RAM محدود به رابط کاربری، ضبط و پردازش معمول مرورگر است.
 * OCR باید از API سمت سرور ارائه شود؛ تا زمان آماده شدن endpoint، مسیر محلی غیرفعال است.
 * هستهٔ سند از طریق window.FarastEditor دستکاری می‌شود.
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
/* Browser-native SpeechRecognition is intentionally disabled. Some browsers can download
 * language packs when local processing is requested; FARAST uses server-side inference only. */
const SR = null;
function isAuthed() { return $('#farastWord')?.dataset.authenticated === '1'; }
function hasMic() {
  // در jsdom/تست و محیط‌های بدون mediaDevices، با MediaRecorder چک می‌شود؛ در غیر این صورت false
  try { return !!(navigator.mediaDevices?.getUserMedia && window.MediaRecorder); } catch { return false; }
}

/* ===================== سیاست مدل‌های هوش مصنوعی =====================
 * هیچ مدل گفتار یا OCR و هیچ بستهٔ دادهٔ زبانیِ مربوط به FARAST روی مرورگر دانلود نمی‌شود.
 * OCR باید از API سمت سرور پیاده‌سازی شود؛ تا وقتی endpoint سروری آماده نیست، خطای روشن می‌دهیم.
 */
async function ensureTesseract() {
  throw new Error('OCR سمت سرور هنوز پیکربندی نشده است؛ برای جلوگیری از دانلود مدل روی دستگاه، OCR محلی غیرفعال است.');
}
async function ocrImage() {
  throw new Error('OCR سمت سرور هنوز پیکربندی نشده است؛ هیچ مدل هوش مصنوعی روی دستگاه دانلود نمی‌شود.');
}
function guessHandwritten(file) {
  const n = (file?.name || '').toLowerCase();
  return /^image\//.test(file?.type || '') || /\.(jpe?g|png|webp|heic|bmp|tiff?)$/.test(n) || /\.pdf$/i.test(n);
}

function engines() {
  const list = [];
  // موتور ابری سایت در هر مرورگری کار می‌کند (WebSocket یا HTTP تکه‌ای) — فقط نیازمند ورود به حساب
  if (isAuthed() && hasMic()) list.push({ id: 'cloud', label: 'موتور ابری فراست (بدون محدودیت مرورگر)' });
  // مدل‌های گفتار روی دستگاه بازدیدکننده دانلود نمی‌شوند؛ گفتار باید از API سمت سرور پردازش شود.
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
 * تنها مسیر تشخیص گفتار، API احراز هویت‌شدهٔ سمت سرور است. مدل یا بستهٔ زبان روی مرورگر دانلود نمی‌شود. */
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
  if (!app || app.dataset.authenticated !== '1') {
    throw Object.assign(new Error('برای تایپ صوتی سروری ابتدا وارد حساب شوید.'), { fatal: true, code: 'auth' });
  }

  let statusInfo = null;
  try {
    statusInfo = await cloudStatus();
  } catch {}
  if (!statusInfo) {
    throw Object.assign(new Error('وضعیت موتور گفتار سمت سرور قابل بررسی نیست؛ اتصال سرویس را بررسی کنید.'), { fatal: true, code: 'server_status_unavailable' });
  }
  if (statusInfo.mode === 'none') {
    const message = statusInfo.reason === 'gateway_token_missing'
      ? 'توکن درگاه مرکزی هوش مصنوعی در سرور تنظیم نشده است.'
      : statusInfo.reason === 'self_hosted_token_missing'
        ? 'توکن موتور گفتار سمت سرور تنظیم نشده است.'
        : 'موتور گفتار سمت سرور هنوز پیکربندی نشده است.';
    throw Object.assign(new Error(message), { fatal: true, code: 'server_not_configured' });
  }
  if (statusInfo.chunked) {
    ctx.onStatus(statusInfo.self_hosted ? 'موتور گفتار خودمیزبان فعال شد…' : 'موتور گفتار سمت سرور فعال شد…');
    return await startCloudChunks(ctx, csrf, statusInfo.self_hosted ? 'self-hosted-unavailable' : 'gateway-unavailable', !!statusInfo.self_hosted);
  }
  throw Object.assign(new Error('این سرور مسیر گفتار موردنیاز را فعال نکرده است.'), { fatal: true, code: 'server_mode_unsupported' });
}

/* ---------- ضبط مرورگر و تشخیص گفتار سمت سرور ----------
 * MediaRecorder فقط صدا را ضبط می‌کند؛ مدل تشخیص گفتار روی سرور اجرا می‌شود.
 * حلقه بی‌وقفه: همزمان که قطعهٔ n در سرور پردازش می‌شود، قطعهٔ n+1 در حال ضبط است. */
async function startCloudChunks(ctx, csrf, gwErr, strictSelfHosted = false) {
  if (!navigator.mediaDevices?.getUserMedia || !window.MediaRecorder) {
    throw Object.assign(new Error(gwErr || 'این مرورگر امکان ضبط صدا را ندارد.'), { fatal: strictSelfHosted });
  }

  // Accuracy-first fallback: keep ONE continuous MediaRecorder session and transcribe the
  // complete recording on stop. Independent 4-second files lose linguistic context and can
  // cause Whisper/ASR to hallucinate unrelated Persian words. The server-side WebSocket is the live mode;
  // this HTTP fallback intentionally commits text after Stop. Browser-native speech recognition is disabled.
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
 * این مسیر فقط به endpoint صریحاً تنظیم‌شده توسط کاربر درخواست می‌فرستد. */
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
