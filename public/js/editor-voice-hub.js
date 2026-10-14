/* FARAST · Voice Hub (معماری چندلایهٔ تایپ صوتی — رایگان، همهٔ مرورگرها)
 * ============================================================
 * لایه‌ها به ترتیب اولویت:
 *  ۱) موتور بومی مرورگر (Web Speech API) — رایگان، بدون سرور (Chrome/Edge/Safari/Opera/Samsung)
 *  ۲) موتور ابری فراست (WebSocket → /editor/voice/stream-token) — برای Firefox و هر مرورگر بدون SR
 *  ۳) دیکته با میکروفون + خروجی متنی (آماده اتصال به هر سرویس STT دلخواه)
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
};
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
  let t = fa ? normalizeFaText(raw) : String(raw).trim();
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

/* نمایش پیش‌نمایش موقت (interim) خاکستری مثل گوگل داکس */
let interimEl = null;
function clearInterim() { if (interimEl) { try { interimEl.remove(); } catch {} interimEl = null; } }
function showInterim(t) {
  if (!t) return clearInterim();
  const ed = surfaceEl(); if (!ed) return;
  if (!interimEl || !ed.contains(interimEl)) {
    restoreSel();
    const s = getSelection(); if (!s.rangeCount) return;
    const r = s.getRangeAt(0); r.collapse(false);
    interimEl = document.createElement('span'); interimEl.className = 'fd-vi'; interimEl.setAttribute('contenteditable', 'false');
    try { r.insertNode(interimEl); } catch { return; }
    const nr = document.createRange(); nr.setStartAfter(interimEl); nr.collapse(true); s.removeAllRanges(); s.addRange(nr);
  }
  interimEl.textContent = t;
}

/* ---------- وضعیت موتورهای قابل استفاده ---------- */
const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
function engines() {
  const list = [];
  if (SR) list.push({ id: 'native', label: 'موتور بومی مرورگر (رایگان)' });
  const tokenable = !!document.querySelector('meta[name="csrf-token"]') && ($('#farastWord')?.dataset.authenticated === '1');
  if (!SR && tokenable) list.push({ id: 'cloud', label: 'موتور ابری فراست (WebSocket)' });
  if (!list.length && navigator.mediaDevices?.getUserMedia) list.push({ id: 'stt-api', label: 'اتصال به سرویس تبدیل گفتار' });
  return list;
}
window.FarastVoiceHub = {
  version: '1.0.0',
  hasNative: () => !!SR,
  engines,
  processFinalText, insertIntoDoc, showInterim, clearInterim, surfaceEl,
};

/* ===================== موتور ابری فراست (فايرفاکس و بقیه) =====================
 * دقیقاً همان پروتکل FarastVoiceRuntime: توکن یک‌بارمصرف + WebSocket + PCM 48k.
 * اگر Runtime اصلی در دسترس باشد، همان باز می‌شود (بدون تکرار پیاده‌سازی). */
async function startCloud(ctx) {
  const rt = window.FarastVoiceRuntime;
  if (rt?.open) { rt.open(); ctx.onStatus('پنل حرفه‌ای فراست باز شد'); return { mode: 'runtime' }; }
  const csrf = $('meta[name="csrf-token"]')?.content || '';
  const app = $('#farastWord');
  if (!app || app.dataset.authenticated !== '1') throw new Error('برای موتور ابری وارد حساب شوید.');
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
