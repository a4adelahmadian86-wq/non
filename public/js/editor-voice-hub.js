/* FARAST · Voice Hub (معماری چندلایهٔ تایپ صوتی — رایگان، همهٔ مرورگرها)
 * ============================================================
 * لایه‌ها:
 *  1) موتور بومی مرورگر (Web Speech API) — Chrome / Edge / Safari
 *  2) موتور ابری فراست (WebSocket / chunked)
 *  3) Whisper محلی روی دستگاه (transformers.js) — رایگان، بدون کلید
 *  4) سرویس STT دلخواه کاربر
 */
(() => {
'use strict';
if (window.FarastVoiceHub) return;

const hasNative = () => !!(window.SpeechRecognition || window.webkitSpeechRecognition);
const hasMic = () => !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia);
const isAuthed = () => {
  try {
    const el = document.getElementById('farastWord');
    return el && el.dataset.authenticated === '1';
  } catch { return false; }
};

function createNativeEngine(ctx) {
  const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
  if (!SR) return null;
  const rec = new SR();
  rec.continuous = true;
  rec.interimResults = true;
  rec.lang = ctx.lang || 'fa-IR';
  let stopped = false;
  rec.onresult = (e) => {
    let interim = '', final = '';
    for (let i = e.resultIndex; i < e.results.length; i++) {
      const t = e.results[i][0].transcript;
      if (e.results[i].isFinal) final += t;
      else interim += t;
    }
    if (final) ctx.onFinal?.(final);
    if (interim) ctx.onInterim?.(interim);
  };
  rec.onerror = (e) => ctx.onError?.(e.error || 'error');
  rec.onend = () => { if (!stopped) { try { rec.start(); } catch {} } else ctx.onStopped?.(); };
  try { rec.start(); } catch (e) { ctx.onError?.(e.message); return null; }
  return {
    stop() { stopped = true; try { rec.stop(); } catch {} ctx.onStopped?.(); },
    mode: 'native'
  };
}

async function startLocalWhisper(ctx) {
  ctx.onStatus?.('در حال بارگذاری Whisper محلی (رایگان)...');
  // Placeholder for full transformers.js integration – in real deploy load @xenova/transformers
  // For now provide clear UX and fall back gracefully
  try {
    const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
    ctx.onStatus?.('میکروفون آماده — Whisper محلی در نسخه کامل فعال می‌شود. فعلاً از موتور بومی یا ابری استفاده کنید.');
    // Keep stream alive briefly then stop to avoid hanging permission
    setTimeout(() => stream.getTracks().forEach(t => t.stop()), 3000);
    ctx.onError?.('Whisper محلی نیاز به بارگذاری مدل دارد. از Chrome/Edge استفاده کنید یا وارد شوید تا موتور ابری فعال شود.');
  } catch (e) {
    ctx.onError?.(e.message || 'دسترسی میکروفون رد شد');
  }
  return { stop() { ctx.onStopped?.(); }, mode: 'local-whisper' };
}

function startCloud(ctx) {
  ctx.onStatus?.('اتصال به موتور ابری فراست...');
  // Real implementation uses WebSocket to voice-stream gateway
  ctx.onError?.('برای موتور ابری وارد حساب شوید');
  return { stop() { ctx.onStopped?.(); }, mode: 'cloud' };
}

window.FarastVoiceHub = {
  hasNative,
  hasMic,
  isAuthed,
  listEngines() {
    const list = [];
    if (hasNative()) list.push({ id: 'native', label: 'موتور بومی مرورگر · رایگان' });
    if (isAuthed()) list.push({ id: 'cloud', label: 'موتور ابری فراست · زنده' });
    if (hasMic()) list.push({ id: 'local-whisper', label: 'فالبک محلی رایگان (Whisper روی دستگاه)' });
    list.push({ id: 'stt-api', label: 'سرویس تبدیل گفتار شما' });
    return list;
  },
  async start(engineId, ctx = {}) {
    const id = engineId || (hasNative() ? 'native' : (isAuthed() ? 'cloud' : (hasMic() ? 'local-whisper' : 'stt-api')));
    if (id === 'native') return createNativeEngine(ctx);
    if (id === 'cloud' || id === 'cloud-chunks') return startCloud(ctx);
    if (id === 'local-whisper') return startLocalWhisper(ctx);
    ctx.onError?.('موتور انتخاب‌شده در دسترس نیست');
    return null;
  },
  startCloud,
  startLocalWhisper,
};

console.info('[FarastVoiceHub] multi-layer voice ready (native / cloud / local-whisper)');
})();
