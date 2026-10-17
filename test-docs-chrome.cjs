/* تست jsdom: تداخل پنل قدیمی حذف شود، دکمهٔ میکروفون دیده و کار کند، فایرفاکس به موتور ابری برود */
const fs = require('fs');
const { JSDOM } = require('jsdom');

const hubSrc = fs.readFileSync('public/js/editor-voice-hub.js', 'utf8');
const chromeSrc = fs.readFileSync('public/js/editor-docs-chrome.js', 'utf8');

const EDITOR_HTML = `<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="csrf-token" content="tok"></head>
<body class="editor-page-body"><div id="app-main">
<div id="farastWord" data-authenticated="__AUTH__" data-project-id="" data-document-id="">
  <header class="farast-appbar"><input id="docTitle" value="سند جدید"></header>
  <section class="farast-ribbon" id="ribbon"></section>
  <main class="farast-workspace"><section class="farast-canvas-shell"><div class="farast-page-viewport" id="pagesViewport"></div></section></main>
  <footer class="farast-statusbar"><button id="mic" type="button">mic</button><span id="statusWords">0</span><span id="totalPages">1</span><span id="saveState">آماده</span></footer>
</div></div></body></html>`;

let pass = 0, fail = 0;
const ok = (name, cond) => { if (cond) { pass++; console.log('PASS', name); } else { fail++; console.log('FAIL', name); } };

async function build({ auth = '1', withSR = false }) {
  const dom = new JSDOM(EDITOR_HTML.replace('__AUTH__', auth), { url: 'http://localhost/editor', runScripts: 'dangerously', pretendToBeVisual: true });
  const w = dom.window;
  // شبیه‌سازی Runtime قدیمی: پنل fake با کلاس is-open + listener روی #mic که آن را باز می‌کند
  w.eval(`
    window.FarastVoiceRuntime = { _panel:null, isOpen(){ return this._panel && this._panel.classList.contains('is-open') }, close(){ this._panel && this._panel.classList.remove('is-open') }, open(){ this._panel && this._panel.classList.add('is-open') } };
    var p=document.createElement('div'); p.id='farastVoicePanel'; document.body.appendChild(p); window.FarastVoiceRuntime._panel=p;
    document.getElementById('mic').addEventListener('click', function(ev){ ev.preventDefault(); window.FarastVoiceRuntime.open(); });
  `);
  if (withSR) {
    w.eval(`window.SpeechRecognition = function(){ this.start=function(){}; this.stop=function(){}; this.abort=function(){}; }; window.webkitSpeechRecognition = window.SpeechRecognition;`);
  }
  w.eval(hubSrc);
  w.eval(chromeSrc);
  await new Promise(r => setTimeout(r, 50));
  return { dom, w };
}

(async () => {
  /* ===== سناریوی Chrome-like (موتور بومی دارد) ===== */
  {
    const { w } = await build({ auth: '1', withSR: true });
    const d = w.document;
    ok('chrome: هدر داکس ساخته شد', !!d.querySelector('.fd-top'));
    const micBtn = d.querySelector('[data-tool=voice]');
    ok('chrome: دکمه میکروفون نوار ابزار وجود دارد', !!micBtn);
    ok('chrome: دکمه میکروفون SVG+متن دارد', !!micBtn && micBtn.querySelector('svg') && /تایپ صوتی/.test(micBtn.textContent));
    ok('chrome: قرص پایین صفحه وجود دارد', !!d.querySelector('#fdMicOpen'));
    // کلیک روی دکمهٔ کلاسیک #mic نباید پنل قدیمی را باز کند
    d.getElementById('mic').dispatchEvent(new w.MouseEvent('click', { bubbles: true, cancelable: true }));
    await new Promise(r => setTimeout(r, 30));
    ok('chrome: گارد — پنل قدیمی با کلیک #mic باز نشد', !d.getElementById('farastVoicePanel').classList.contains('is-open'));
    ok('chrome: گارد — پنل جدید با کلیک #mic باز شد', d.getElementById('fdVoiceBox').classList.contains('show'));
    // کلیک دوباره ببندد
    d.getElementById('mic').dispatchEvent(new w.MouseEvent('click', { bubbles: true, cancelable: true }));
    await new Promise(r => setTimeout(r, 20));
    ok('chrome: کلیک دوم پنل جدید را بست', !d.getElementById('fdVoiceBox').classList.contains('show'));
    // دکمهٔ نوار ابزار هم پنل را باز کند
    micBtn.dispatchEvent(new w.MouseEvent('click', { bubbles: true, cancelable: true }));
    await new Promise(r => setTimeout(r, 20));
    ok('chrome: دکمهٔ نوار ابزار پنل را باز می‌کند', d.getElementById('fdVoiceBox').classList.contains('show'));
  }

  /* ===== سناریوی Firefox-like (بدون SR، لاگین‌شده) ===== */
  {
    const { w } = await build({ auth: '1', withSR: false });
    const d = w.document;
    ok('ff: موتور بومی نیست', !w.FarastVoiceHub.hasNative());
    ok('ff: لاگین شناسایی شد', w.FarastVoiceHub.isAuthed() === true);
    // پیام پنل باید موتور ابری را پیشنهاد دهد، نه بن‌بست
    d.querySelector('#fdMicOpen').dispatchEvent(new w.MouseEvent('click', { bubbles: true, cancelable: true }));
    await new Promise(r => setTimeout(r, 20));
    const msg = d.getElementById('fdVMsg').textContent;
    ok('ff: پیام راهنمای موتور ابری', /ابر/.test(msg));
    // startVoice باید مسیر cloud را انتخاب کند (fetch mock شده تا خطا ندهد)
    let called = '';
    w.fetch = async (url) => { called = url; return { ok: false, status: 500, json: async () => ({ message: 'down' }) }; };
    w.navigator.mediaDevices = { getUserMedia: async () => { throw new Error('no mic in test'); } };
    w.MediaRecorder = function () {}; w.MediaRecorder.isTypeSupported = () => true;
    await w.eval(`window.__t = window.FarastDocsChrome.voice.start()`);
    await new Promise(r => setTimeout(r, 60));
    ok('ff: تلاش برای موتور ابری (/editor/voice/stream-token)', called.includes('/editor/voice/stream-token'));
  }

  /* ===== سناریوی Firefox بدون لاگین ===== */
  {
    const { w } = await build({ auth: '0', withSR: false });
    const d = w.document;
    d.querySelector('#fdMicOpen').dispatchEvent(new w.MouseEvent('click', { bubbles: true, cancelable: true }));
    await new Promise(r => setTimeout(r, 20));
    const msg = d.getElementById('fdVMsg').textContent;
    ok('anon: پیام Whisper محلی یا راهنمای میکروفون', /Whisper|HTTPS/.test(msg));
    ok('anon: هیچ بن‌بست «فقط Chrome» نیست', !/Chrome یا Edge/.test(d.body.textContent));
  }

  console.log(`\n${pass} passed, ${fail} failed`);
  process.exit(fail ? 1 : 0);
})();
