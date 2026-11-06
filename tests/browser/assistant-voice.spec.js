const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

test('live gateway wakes before requesting microphone and fully releases tracks when switched off', async ({ page }) => {
  const assistantJs = fs.readFileSync(path.join(process.cwd(), 'public/js/farast-assistant.js'), 'utf8');
  await page.setContent(`<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="csrf-token" content="test-csrf"></head><body data-assistant-auth="1">
<script>
  window.__wsInstances = [];
  window.__micRequests = 0;
  window.__micRequestedAfterReady = false;
  window.__trackStopped = false;
  window.__track = { kind: 'audio', enabled: true, readyState: 'live', stop() { this.readyState = 'ended'; window.__trackStopped = true; } };
  class FakeWebSocket extends EventTarget {
    constructor(url) { super(); this.url=url; this.readyState=0; this.sent=[]; window.__wsInstances.push(this); setTimeout(()=>{this.readyState=1;this.dispatchEvent(new Event('open'));},0); }
    send(data) { this.sent.push(data); if(typeof data==='string'&&JSON.parse(data).type==='start')setTimeout(()=>this.emit({type:'ready'}),180); }
    close() { this.readyState=3; }
    emit(data) { this.onmessage?.({data:JSON.stringify(data)}); }
  }
  FakeWebSocket.OPEN=1; window.WebSocket=FakeWebSocket;
  window.AudioContext=class {
    constructor(){this.state='running';this.sampleRate=48000;this.destination={};}
    createMediaStreamSource(){return {connect(){},disconnect(){}};}
    createScriptProcessor(){window.__scriptProcessor={onaudioprocess:null,connect(){},disconnect(){}};return window.__scriptProcessor;}
    resume(){return Promise.resolve();}
    close(){this.state='closed';return Promise.resolve();}
  };
  window.AudioWorkletNode=undefined;
  Object.defineProperty(navigator,'mediaDevices',{configurable:true,value:{getUserMedia:async()=>{
    window.__micRequests++;
    window.__micRequestedAfterReady=window.__wsInstances.length===1&&window.__wsInstances[0].sent.some(x=>typeof x==='string'&&JSON.parse(x).type==='start')&&window.__wsInstances[0].readyState===1&&window.__providerReady;
    return {getTracks:()=>[window.__track],getAudioTracks:()=>[window.__track]};
  }}});
  const NativeWebSocket=FakeWebSocket;
  window.fetch=async(url,options={})=>{
    if(url==='/editor/voice/status')return new Response(JSON.stringify({ok:true,mode:'gateway',provider:'render',websocket_url:'wss://voice.example.test'}),{status:200,headers:{'Content-Type':'application/json'}});
    if(String(url).startsWith('https://voice.example.test/health'))return new Response(JSON.stringify({status:'ok',asr_configured:true,tts_configured:true,chat_configured:true}),{status:200,headers:{'Content-Type':'application/json'}});
    if(url==='/assistant/voice/stream-token')return new Response(JSON.stringify({ok:true,token:'mock-token',websocket_url:'wss://voice.example.test'}),{status:200,headers:{'Content-Type':'application/json'}});
    if(url==='/assistant/history')return new Response(JSON.stringify({ok:true,messages:[]}),{status:200,headers:{'Content-Type':'application/json'}});
    return new Response(JSON.stringify({ok:false}),{status:404,headers:{'Content-Type':'application/json'}});
  };
  const originalEmit=FakeWebSocket.prototype.emit;
  FakeWebSocket.prototype.emit=function(data){if(data.type==='ready')window.__providerReady=true;return originalEmit.call(this,data);};
</script></body></html>`);
  await page.addScriptTag({ content: assistantJs });
  await page.locator('.farast-assistant-launcher').click();
  await page.locator('.farast-voice-mic').click();
  await expect(page.locator('.farast-voice-mic')).toHaveClass(/is-connecting/);
  expect(await page.evaluate(() => window.__micRequests)).toBe(0);
  await expect.poll(() => page.evaluate(() => window.__micRequests), { timeout: 8000 }).toBe(1);
  expect(await page.evaluate(() => window.__micRequestedAfterReady)).toBe(true);
  await expect(page.locator('.farast-voice-mic')).toHaveClass(/is-ready/);
  await expect.poll(() => page.evaluate(() => !!window.__scriptProcessor)).toBe(true);
  await page.locator('.farast-voice-mic').click();
  await expect.poll(() => page.evaluate(() => window.__trackStopped)).toBe(true);
  await expect(page.locator('.farast-voice-mic')).toHaveClass(/is-off/);
  expect(await page.evaluate(() => window.__track.enabled)).toBe(false);
});


test('voice assistant does not start batch recording when server speech services are not configured', async ({ page }) => {
  const assistantJs = fs.readFileSync(path.join(process.cwd(), 'public/js/farast-assistant.js'), 'utf8');
  await page.setContent(`<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="csrf-token" content="test-csrf"></head><body data-assistant-auth="1">
<script>
  window.WebSocket = undefined;
  window.AudioContext = undefined;
  window.__getUserMediaCalls = 0;
  window.__recorderInstances = 0;
  window.__transcribeCalled = false;
  Object.defineProperty(navigator, 'mediaDevices', { configurable: true, value: { getUserMedia: async () => { window.__getUserMediaCalls++; return { getTracks: () => [{ stop() {} }] }; } } });
  window.MediaRecorder = class { constructor() { window.__recorderInstances++; } };
  window.fetch = async (url) => {
    if (url === '/assistant/history') return new Response(JSON.stringify({ ok: true, messages: [] }), { status: 200, headers: { 'Content-Type': 'application/json' } });
    if (url === '/assistant/transcribe') { window.__transcribeCalled = true; return new Response(JSON.stringify({ ok: true, text: 'unexpected' }), { status: 200 }); }
    return new Response(JSON.stringify({ ok: false }), { status: 404 });
  };
</script></body></html>`);
  await page.addScriptTag({ content: assistantJs });
  await page.locator('.farast-assistant-launcher').click();
  await page.locator('.farast-voice-mic').click();
  await expect(page.locator('.farast-assistant-form')).toBeVisible();
  expect(await page.evaluate(() => window.__getUserMediaCalls)).toBe(0);
  expect(await page.evaluate(() => window.__recorderInstances)).toBe(0);
  expect(await page.evaluate(() => window.__transcribeCalled)).toBe(false);
});

test('voice assistant never starts browser speech recognition when the live gateway is unavailable', async ({ page }) => {
  const assistantJs = fs.readFileSync(path.join(process.cwd(), 'public/js/farast-assistant.js'), 'utf8');
  await page.setContent(`<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="csrf-token" content="test-csrf"></head><body data-assistant-auth="1">
<script>
  window.WebSocket = undefined;
  window.AudioContext = undefined;
  window.__recognitionStarted = false;
  window.__micRequests = 0;
  window.SpeechRecognition = class { start() { window.__recognitionStarted = true; } };
  Object.defineProperty(navigator, 'mediaDevices', { configurable: true, value: { getUserMedia: async () => { window.__micRequests++; return { getTracks: () => [{ stop() {} }] }; } } });
  window.fetch = async (url) => {
    if (url === '/assistant/history') return new Response(JSON.stringify({ ok: true, messages: [] }), { status: 200, headers: { 'Content-Type': 'application/json' } });
    return new Response(JSON.stringify({ ok: false }), { status: 404 });
  };
</script></body></html>`);
  await page.addScriptTag({ content: assistantJs });
  await page.locator('.farast-assistant-launcher').click();
  await page.locator('.farast-voice-mic').click();
  await expect(page.locator('.farast-assistant-form')).toBeVisible();
  expect(await page.evaluate(() => window.__recognitionStarted)).toBe(false);
  expect(await page.evaluate(() => window.__micRequests)).toBe(0);
});

test('unsupported microphone APIs switch to text without loading a browser recognizer', async ({ page }) => {
  const assistantJs = fs.readFileSync(path.join(process.cwd(), 'public/js/farast-assistant.js'), 'utf8');
  await page.setContent(`<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="csrf-token" content="test-csrf"></head><body data-assistant-auth="1">
<script>
  window.WebSocket = undefined;
  window.AudioContext = undefined;
  window.__micRequests = 0;
  window.__recognitionStarts = 0;
  window.SpeechRecognition = class { start() { window.__recognitionStarts++; } };
  Object.defineProperty(navigator, 'mediaDevices', { configurable: true, value: { getUserMedia: async () => { window.__micRequests++; throw new Error('must not request'); } } });
  window.fetch = async (url) => {
    if (url === '/assistant/history') return new Response(JSON.stringify({ ok: true, messages: [] }), { status: 200, headers: { 'Content-Type': 'application/json' } });
    return new Response(JSON.stringify({ ok: false }), { status: 404 });
  };
</script></body></html>`);
  await page.addScriptTag({ content: assistantJs });
  await page.locator('.farast-assistant-launcher').click();
  await page.locator('.farast-voice-mic').click();
  await expect(page.locator('.farast-assistant-form')).toBeVisible();
  expect(await page.evaluate(() => window.__micRequests)).toBe(0);
  expect(await page.evaluate(() => window.__recognitionStarts)).toBe(0);
});

test('unsupported voice browsers can continue through an accessible text fallback', async ({ page }) => {
  const assistantJs = fs.readFileSync(path.join(process.cwd(), 'public/js/farast-assistant.js'), 'utf8');
  const assistantCss = fs.readFileSync(path.join(process.cwd(), 'public/css/farast-assistant.css'), 'utf8');
  await page.setContent(`<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="csrf-token" content="test-csrf"></head><body data-assistant-auth="1">
<script>
  window.__chatPayloads = [];
  window.__deviceSpeechCalls = 0;
  window.SpeechSynthesisUtterance = class { constructor(text) { this.text = text; } };
  window.speechSynthesis = { cancel() {}, getVoices() { return []; }, speak() { window.__deviceSpeechCalls++; } };
  window.WebSocket = undefined;
  window.AudioContext = undefined;
  Object.defineProperty(window, 'SpeechRecognition', { configurable: true, value: undefined });
  Object.defineProperty(window, 'webkitSpeechRecognition', { configurable: true, value: undefined });
  Object.defineProperty(navigator, 'mediaDevices', { configurable: true, value: undefined });
  window.fetch = async (url, options = {}) => {
    if (url === '/assistant/history') return new Response(JSON.stringify({ ok: true, messages: [] }), { status: 200, headers: { 'Content-Type': 'application/json' } });
    if (url === '/assistant/voice/status') return new Response(JSON.stringify({ ok: true, server_stt: true, server_tts: true, server_stt_token_configured: true, server_tts_token_configured: true }), { status: 200, headers: { 'Content-Type': 'application/json' } });
    if (url === '/assistant/chat/stream') { window.__chatPayloads.push(JSON.parse(options.body)); return new Response('data: {"delta":"پاسخ متنی قابل مشاهده است."}\\n\\ndata: {"done":true}\\n\\n', { status: 200, headers: { 'Content-Type': 'text/event-stream' } }); }
    if (url === '/assistant/speak') return new Response(JSON.stringify({ ok: false, message: 'TTS unavailable' }), { status: 503, headers: { 'Content-Type': 'application/json' } });
    return new Response(JSON.stringify({ ok: false }), { status: 404, headers: { 'Content-Type': 'application/json' } });
  };
</script></body></html>`);
  await page.addStyleTag({ content: assistantCss });
  await page.addScriptTag({ content: assistantJs });
  await page.locator('.farast-assistant-launcher').click();
  await page.locator('.farast-voice-mic').click();
  await expect(page.locator('.farast-assistant-form')).toBeVisible();
  await expect(page.locator('[data-fa-messages]')).toBeVisible();
  await page.locator('.farast-assistant-form input[name="message"]').fill('سلام، کمک می‌خواهم');
  await page.locator('.farast-assistant-send').click();
  await expect.poll(async () => page.evaluate(() => window.__chatPayloads.length)).toBe(1);
  await expect(page.locator('[data-fa-messages]')).toContainText('پاسخ متنی قابل مشاهده است.');
  expect(await page.evaluate(() => window.__deviceSpeechCalls)).toBe(0);
});


test('text chat wakes the sleeping Render gateway before sending the chat request', async ({ page }) => {
  const assistantJs = fs.readFileSync(path.join(process.cwd(), 'public/js/farast-assistant.js'), 'utf8');
  await page.setContent(`<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="csrf-token" content="test-csrf"></head><body data-assistant-auth="1">
<script>
  window.WebSocket = undefined;
  window.AudioContext = undefined;
  Object.defineProperty(navigator, 'mediaDevices', { configurable: true, value: undefined });
  window.__healthTimeouts = [];
  const originalSetTimeout = window.setTimeout.bind(window);
  window.setTimeout = (callback, delay, ...args) => { if (delay >= 50000) window.__healthTimeouts.push(delay); return originalSetTimeout(callback, delay, ...args); };
  window.__order = [];
  window.__chatAttempts = 0;
  window.__csrfRefreshes = 0;
  window.fetch = async (url, options = {}) => {
    if (url === '/assistant/history') return new Response(JSON.stringify({ ok: true, messages: [] }), { status: 200, headers: { 'Content-Type': 'application/json' } });
    if (url === '/assistant/voice/status') return new Response(JSON.stringify({ ok: true, local_only: false, chat_gateway_ready: true, chat_gateway_health_url: 'https://voice.example.test/health' }), { status: 200, headers: { 'Content-Type': 'application/json' } });
    if (String(url).startsWith('https://voice.example.test/health')) {
      window.__order.push('health');
      if (window.__order.filter(item => item === 'health').length === 1) return new Response(JSON.stringify({ status: 'starting', chat_configured: false }), { status: 503, headers: { 'Content-Type': 'application/json' } });
      return new Response(JSON.stringify({ status: 'ok', chat_configured: true }), { status: 200, headers: { 'Content-Type': 'application/json' } });
    }
    if (url === '/assistant/csrf-token') { window.__csrfRefreshes++; return new Response(JSON.stringify({ token: 'fresh-csrf' }), { status: 200, headers: { 'Content-Type': 'application/json' } }); }
    if (url === '/assistant/chat/stream') { window.__order.push('chat'); window.__chatAttempts++; window.__chatAccept = options.headers?.Accept; if (window.__chatAttempts === 1) return new Response(JSON.stringify({ message: 'CSRF token mismatch.' }), { status: 419, headers: { 'Content-Type': 'application/json' } }); return new Response('data: {"delta":"پاسخ آماده است."}\\n\\ndata: {"done":true}\\n\\n', { status: 200, headers: { 'Content-Type': 'text/event-stream' } }); }
    if (url === '/assistant/speak') return new Response(JSON.stringify({ ok: false, message: 'TTS unavailable' }), { status: 503, headers: { 'Content-Type': 'application/json' } });
    return new Response(JSON.stringify({ ok: false }), { status: 404, headers: { 'Content-Type': 'application/json' } });
  };
</script></body></html>`);
  await page.addScriptTag({ content: assistantJs });
  await page.locator('.farast-assistant-launcher').click();
  await page.locator('.farast-assistant-form input[name="message"]').fill('سلام فراست');
  await page.locator('.farast-assistant-send').click();
  await expect.poll(() => page.evaluate(() => window.__order.includes('chat'))).toBe(true);
  expect(await page.evaluate(() => window.__order)).toEqual(['health', 'health', 'chat', 'chat']);
  expect(await page.evaluate(() => window.__healthTimeouts)).toContain(60000);
  expect(await page.evaluate(() => window.__chatAttempts)).toBe(2);
  expect(await page.evaluate(() => window.__csrfRefreshes)).toBe(1);
  expect(await page.evaluate(() => window.__chatAccept)).toBe('application/json, text/event-stream');
  await expect(page.locator('[data-fa-messages]')).toContainText('پاسخ آماده است.');
});


test('text chat displays JSON errors returned by Laravel instead of hiding them behind a generic HTTP error', async ({ page }) => {
  const assistantJs = fs.readFileSync(path.join(process.cwd(), 'public/js/farast-assistant.js'), 'utf8');
  await page.setContent(`<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="csrf-token" content="test-csrf"></head><body data-assistant-auth="1">
<script>
  window.fetch = async (url) => {
    if (url === '/assistant/history') return new Response(JSON.stringify({ ok: true, messages: [] }), { status: 200, headers: { 'Content-Type': 'application/json' } });
    if (url === '/assistant/voice/status') return new Response(JSON.stringify({ ok: true, local_only: false, chat_gateway_ready: false }), { status: 200, headers: { 'Content-Type': 'application/json' } });
    if (url === '/assistant/chat/stream') return new Response(JSON.stringify({ message: 'خطای تشخیصی Laravel' }), { status: 500, headers: { 'Content-Type': 'application/json' } });
    return new Response(JSON.stringify({ ok: false }), { status: 404, headers: { 'Content-Type': 'application/json' } });
  };
</script></body></html>`);
  await page.addScriptTag({ content: assistantJs });
  await page.locator('.farast-assistant-launcher').click();
  await page.locator('.farast-assistant-form input[name="message"]').fill('سلام فراست');
  await page.locator('.farast-assistant-send').click();
  await expect(page.locator('[data-fa-messages]')).toContainText('خطای تشخیصی Laravel');
});


test('live assistant prefers server WebSocket speech streaming when a gateway is configured', async ({ page }) => {
  const assistantJs = fs.readFileSync(path.join(process.cwd(), 'public/js/farast-assistant.js'), 'utf8');
  await page.setContent(`<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="csrf-token" content="test-csrf"></head><body data-assistant-auth="1">
<script>
  window.__chatPayloads = [];
  window.__wsInstances = [];
  class FakeWebSocket extends EventTarget {
    constructor(url) { super(); this.url = url; this.readyState = 0; this.sent = []; window.__wsInstances.push(this); setTimeout(() => { this.readyState = 1; this.dispatchEvent(new Event('open')); }, 0); }
    send(data) { this.sent.push(data); if (typeof data === 'string' && JSON.parse(data).type === 'start') queueMicrotask(() => this.emit({ type: 'ready', provider: 'mock-provider' })); }
    close() { this.readyState = 3; }
    emit(data) { this.onmessage?.({ data: JSON.stringify(data) }); }
  }
  FakeWebSocket.OPEN = 1;
  window.WebSocket = FakeWebSocket;
  window.AudioContext = class {
    constructor() { this.state = 'running'; this.sampleRate = 48000; this.destination = {}; }
    createMediaStreamSource() { return { connect() {}, disconnect() {} }; }
    createScriptProcessor() { window.__scriptProcessor = { onaudioprocess: null, connect() {}, disconnect() {} }; return window.__scriptProcessor; }
    resume() { return Promise.resolve(); }
    close() { this.state = 'closed'; return Promise.resolve(); }
  };
  window.AudioWorkletNode = undefined;
  Object.defineProperty(navigator, 'mediaDevices', { configurable: true, value: { getUserMedia: async () => ({ getTracks: () => [{ stop() {} }] }) } });
  window.Audio = class {
    constructor() { this.duration = 2; this.handlers = {}; }
    addEventListener(n, fn) { this.handlers[n] = fn; }
    removeEventListener(n) { delete this.handlers[n]; }
    load() { this.handlers.canplay?.(); }
    play() { return Promise.resolve(); }
    pause() {}
  };
  window.__healthTimeouts = [];
  const originalSetTimeout = window.setTimeout.bind(window);
  window.setTimeout = (callback, delay, ...args) => { if (delay >= 50000) window.__healthTimeouts.push(delay); return originalSetTimeout(callback, delay, ...args); };
  window.__wakeOrder = [];
  window.__tokenAttempts = 0;
  window.__csrfRefreshes = 0;
  window.fetch = async (url, options = {}) => {
    if (url === '/editor/voice/status') return new Response(JSON.stringify({ ok: true, mode: 'gateway', provider: 'render', websocket_url: 'wss://voice.example.test' }), { status: 200, headers: { 'Content-Type': 'application/json' } });
    if (String(url).startsWith('https://voice.example.test/health')) { window.__wakeOrder.push('health'); return new Response(JSON.stringify({ status: 'ok', asr_configured: true, tts_configured: true, chat_configured: true }), { status: 200, headers: { 'Content-Type': 'application/json' } }); }
    if (url === '/assistant/csrf-token') { window.__csrfRefreshes++; return new Response(JSON.stringify({ token: 'fresh-csrf' }), { status: 200, headers: { 'Content-Type': 'application/json' } }); }
    if (url === '/assistant/voice/stream-token') { window.__wakeOrder.push('token'); window.__tokenAttempts++; if (options.headers?.['X-CSRF-TOKEN'] !== 'fresh-csrf') return new Response(JSON.stringify({ message: 'CSRF token mismatch.' }), { status: 419, headers: { 'Content-Type': 'application/json' } }); return new Response(JSON.stringify({ ok: true, token: 'mock-signed-token', websocket_url: 'wss://voice.example.test' }), { status: 200, headers: { 'Content-Type': 'application/json' } }); }
    if (url === '/assistant/history') return new Response(JSON.stringify({ ok: true, messages: [] }), { status: 200, headers: { 'Content-Type': 'application/json' } });
    if (url === '/assistant/chat/stream') { window.__chatPayloads.push(JSON.parse(options.body)); return new Response('data: {"delta":"پاسخ زنده"}\\n\\ndata: {"done":true}\\n\\n', { status: 200, headers: { 'Content-Type': 'text/event-stream' } }); }
    if (url === '/assistant/speak') return new Response(new Blob(['audio'], { type: 'audio/mpeg' }), { status: 200, headers: { 'Content-Type': 'audio/mpeg' } });
    return new Response(JSON.stringify({ ok: false }), { status: 404, headers: { 'Content-Type': 'application/json' } });
  };
</script></body></html>`);
  await page.addScriptTag({ content: assistantJs });
  await page.locator('.farast-assistant-launcher').click();
  await page.locator('.farast-voice-mic').click();

  await expect.poll(async () => page.evaluate(() => window.__wsInstances.length)).toBe(1);
  expect(await page.evaluate(() => window.__wsInstances[0].url)).toBe('wss://voice.example.test/v1/stream');
  expect(await page.evaluate(() => window.__wakeOrder)).toEqual(['health', 'token', 'token']);
  expect(await page.evaluate(() => window.__healthTimeouts)).toContain(60000);
  expect(await page.evaluate(() => window.__tokenAttempts)).toBe(2);
  expect(await page.evaluate(() => window.__csrfRefreshes)).toBe(1);
  await expect.poll(async () => page.evaluate(() => !!window.__scriptProcessor)).toBe(true);
  await page.evaluate(() => {
    const inputBuffer = { getChannelData: () => new Float32Array(4096).fill(0.1) };
    const outputBuffer = { getChannelData: () => new Float32Array(4096) };
    window.__scriptProcessor.onaudioprocess({ inputBuffer, outputBuffer });
    window.__scriptProcessor.onaudioprocess({ inputBuffer, outputBuffer });
  });
  expect(await page.evaluate(() => window.__wsInstances[0].sent.some((item) => typeof item !== 'string'))).toBe(true);
  await expect(page.locator('[data-fa-voice-title]')).toContainText('گوش می‌دهم');
  await page.evaluate(() => window.__wsInstances[0].emit({ type: 'interim', text: 'سلام فراست' }));
  await expect(page.locator('[data-fa-voice-caption]')).toContainText('سلام فراست');
  await page.evaluate(() => window.__wsInstances[0].emit({ type: 'final', text: 'سلام فراست' }));
  await expect.poll(async () => page.evaluate(() => window.__chatPayloads.length), { timeout: 5000 }).toBe(1);
  expect(await page.evaluate(() => window.__chatPayloads[0].message)).toBe('سلام فراست');
  await page.evaluate(() => { window.__wsInstances[0].onclose?.({code:1006}); });
  await page.locator('.farast-voice-mic').click();
  await expect.poll(async () => page.evaluate(() => window.__wsInstances.length)).toBe(2);
  expect(await page.evaluate(() => window.__wakeOrder.filter(item => item === 'health').length)).toBe(1);
});


test('live assistant uses a ScriptProcessor fallback when AudioWorklet is unavailable', async ({ page }) => {
  const assistantJs = fs.readFileSync(path.join(process.cwd(), 'public/js/farast-assistant.js'), 'utf8');
  await page.setContent(`<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="csrf-token" content="test-csrf"></head><body data-assistant-auth="1">
<script>
  window.__chatPayloads = [];
  window.__wsInstances = [];
  class FakeWebSocket extends EventTarget {
    constructor(url) { super(); this.url = url; this.readyState = 0; this.sent = []; window.__wsInstances.push(this); setTimeout(() => { this.readyState = 1; this.dispatchEvent(new Event('open')); }, 0); }
    send(data) { this.sent.push(data); if (typeof data === 'string' && JSON.parse(data).type === 'start') queueMicrotask(() => this.emit({ type: 'ready', provider: 'mock-provider' })); }
    close() { this.readyState = 3; }
    emit(data) { this.onmessage?.({ data: JSON.stringify(data) }); }
  }
  FakeWebSocket.OPEN = 1;
  window.WebSocket = FakeWebSocket;
  window.AudioContext = class {
    constructor() { this.state = 'running'; this.sampleRate = 48000; this.destination = {}; }
    createMediaStreamSource() { return { connect() {}, disconnect() {} }; }
    createScriptProcessor() { window.__scriptProcessor = { onaudioprocess: null, connect() {}, disconnect() {} }; return window.__scriptProcessor; }
    resume() { return Promise.resolve(); }
    close() { this.state = 'closed'; return Promise.resolve(); }
  };
  window.AudioWorkletNode = undefined;
  Object.defineProperty(navigator, 'mediaDevices', { configurable: true, value: { getUserMedia: async () => ({ getTracks: () => [{ stop() {} }] }) } });
  window.Audio = class {
    constructor() { this.duration = 2; this.handlers = {}; }
    addEventListener(n, fn) { this.handlers[n] = fn; }
    removeEventListener(n) { delete this.handlers[n]; }
    load() { this.handlers.canplay?.(); }
    play() { return Promise.resolve(); }
    pause() {}
  };
  window.fetch = async (url, options = {}) => {
    if (url === '/editor/voice/status') return new Response(JSON.stringify({ ok: true, mode: 'gateway' }), { status: 200, headers: { 'Content-Type': 'application/json' } });
    if (url === '/assistant/voice/stream-token') return new Response(JSON.stringify({ ok: true, token: 'mock-signed-token', websocket_url: 'wss://voice.example.test' }), { status: 200, headers: { 'Content-Type': 'application/json' } });
    if (url === '/assistant/history') return new Response(JSON.stringify({ ok: true, messages: [] }), { status: 200, headers: { 'Content-Type': 'application/json' } });
    if (url === '/assistant/chat/stream') { window.__chatPayloads.push(JSON.parse(options.body)); return new Response('data: {"delta":"پاسخ زنده"}\\n\\ndata: {"done":true}\\n\\n', { status: 200, headers: { 'Content-Type': 'text/event-stream' } }); }
    if (url === '/assistant/speak') return new Response(new Blob(['audio'], { type: 'audio/mpeg' }), { status: 200, headers: { 'Content-Type': 'audio/mpeg' } });
    return new Response(JSON.stringify({ ok: false }), { status: 404, headers: { 'Content-Type': 'application/json' } });
  };
</script></body></html>`);
  await page.addScriptTag({ content: assistantJs });
  await page.locator('.farast-assistant-launcher').click();
  await page.locator('.farast-voice-mic').click();

  await expect.poll(async () => page.evaluate(() => window.__wsInstances.length)).toBe(1);
  await expect.poll(async () => page.evaluate(() => !!window.__scriptProcessor)).toBe(true);
  await page.evaluate(() => {
    const inputBuffer = { getChannelData: () => new Float32Array(4096).fill(0.1) };
    const outputBuffer = { getChannelData: () => new Float32Array(4096) };
    window.__scriptProcessor.onaudioprocess({ inputBuffer, outputBuffer });
    window.__scriptProcessor.onaudioprocess({ inputBuffer, outputBuffer });
  });
  expect(await page.evaluate(() => window.__wsInstances[0].sent.some((item) => typeof item !== 'string'))).toBe(true);
  await expect(page.locator('[data-fa-voice-title]')).toContainText('گوش می‌دهم');
  await page.evaluate(() => window.__wsInstances[0].emit({ type: 'interim', text: 'سلام فراست' }));
  await expect(page.locator('[data-fa-voice-caption]')).toContainText('سلام فراست');
  await page.evaluate(() => window.__wsInstances[0].emit({ type: 'final', text: 'سلام فراست' }));
  await expect.poll(async () => page.evaluate(() => window.__chatPayloads.length), { timeout: 5000 }).toBe(1);
  expect(await page.evaluate(() => window.__chatPayloads[0].message)).toBe('سلام فراست');
});

test('failed live gateway handshake releases microphone and allows a one-tap retry', async ({ page }) => {
  const assistantJs = fs.readFileSync(path.join(process.cwd(), 'public/js/farast-assistant.js'), 'utf8');
  await page.setContent(`<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="csrf-token" content="test-csrf"></head><body data-assistant-auth="1">
<script>
  window.__wsInstances=[];window.__stoppedTracks=0;window.__micRequests=0;
  class FakeWebSocket extends EventTarget {
    constructor(url){super();this.url=url;this.readyState=0;this.sent=[];window.__wsInstances.push(this);const attempt=window.__wsInstances.length;setTimeout(()=>{if(attempt===1){this.readyState=3;this.dispatchEvent(new Event('error'));}else{this.readyState=1;this.dispatchEvent(new Event('open'));}},0);}
    send(data){this.sent.push(data);if(typeof data==='string'&&JSON.parse(data).type==='start')queueMicrotask(()=>this.onmessage?.({data:JSON.stringify({type:'ready',provider:'mock'})}));}
    close(){this.readyState=3;}
  }
  FakeWebSocket.OPEN=1;window.WebSocket=FakeWebSocket;
  window.AudioContext=class{constructor(){this.state='running';this.sampleRate=48000;this.destination={};}createMediaStreamSource(){return{connect(){},disconnect(){}};}createScriptProcessor(){return{connect(){},disconnect(){},onaudioprocess:null};}resume(){return Promise.resolve();}close(){this.state='closed';return Promise.resolve();}};
  window.AudioWorkletNode=undefined;
  window.Audio=class{play(){return Promise.resolve();}pause(){}};
  Object.defineProperty(navigator,'mediaDevices',{configurable:true,value:{getUserMedia:async()=>{window.__micRequests++;return{getTracks:()=>[{stop(){window.__stoppedTracks++;}}]};}}});
  window.fetch=async(url)=>{
    if(url==='/assistant/history')return new Response(JSON.stringify({ok:true,messages:[]}),{status:200,headers:{'Content-Type':'application/json'}});
    if(url==='/editor/voice/status')return new Response(JSON.stringify({ok:true,mode:'gateway'}),{status:200,headers:{'Content-Type':'application/json'}});
    if(url==='/assistant/voice/stream-token')return new Response(JSON.stringify({ok:true,token:'mock-token',websocket_url:'wss://voice.example.test'}),{status:200,headers:{'Content-Type':'application/json'}});
    return new Response(JSON.stringify({ok:false}),{status:404,headers:{'Content-Type':'application/json'}});
  };
</script></body></html>`);
  await page.addScriptTag({ content: assistantJs });
  await page.locator('.farast-assistant-launcher').click();
  await page.locator('.farast-voice-mic').click();
  await expect.poll(()=>page.evaluate(()=>window.__wsInstances.length),{timeout:5000}).toBe(1);
  await expect(page.locator('[data-fa-voice-title]')).toContainText('ورودی متنی در دسترس است');
  expect(await page.evaluate(()=>window.__micRequests)).toBe(0);
  expect(await page.evaluate(()=>window.__stoppedTracks)).toBe(0);
  await page.locator('.farast-voice-mic').click();
  await expect.poll(()=>page.evaluate(()=>window.__wsInstances.length),{timeout:5000}).toBe(2);
  await expect.poll(()=>page.evaluate(()=>window.__micRequests),{timeout:5000}).toBe(1);
  await expect(page.locator('[data-fa-voice-title]')).toContainText('گوش می‌دهم');
  expect(await page.evaluate(()=>window.__micRequests)).toBe(1);
});

test('text chat primes browser audio in the submit gesture and reuses it for Persian TTS', async ({ page }) => {
  const assistantJs = fs.readFileSync(path.join(process.cwd(), 'public/js/farast-assistant.js'), 'utf8');
  await page.setContent(`<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="csrf-token" content="test-csrf"></head><body data-assistant-auth="1">
<script>
  window.__audioInstances=[];window.__audioOrder=[];
  window.Audio=class {
    constructor(){this.duration=2;this.handlers={};this.paused=true;this.ended=false;this.src='';window.__audioInstances.push(this);}
    addEventListener(name,fn){this.handlers[name]=fn;}
    removeEventListener(name){delete this.handlers[name];}
    load(){this.handlers.canplay?.();}
    play(){window.__audioOrder.push('play');this.playing=true;this.paused=false;this.ended=true;this.onplay?.();this.onended?.();this.handlers.ended?.();return Promise.resolve();}
    pause(){this.paused=true;this.onpause?.();this.handlers.pause?.();}
  };
  window.fetch=async(url,options={})=>{
    if(url==='/assistant/history')return new Response(JSON.stringify({ok:true,messages:[]}),{status:200,headers:{'Content-Type':'application/json'}});
    if(url==='/assistant/voice/status')return new Response(JSON.stringify({ok:true,local_only:false,chat_gateway_ready:true}),{status:200,headers:{'Content-Type':'application/json'}});
    if(url==='/assistant/chat/stream'){window.__audioOrder.push('chat');return new Response('data: {"delta":"پاسخ صوتی فعال است."}\\n\\ndata: {"done":true}\\n\\n',{status:200,headers:{'Content-Type':'text/event-stream'}});}
    if(url==='/assistant/speak')return new Response(new Blob(['audio'],{type:'audio/mpeg'}),{status:200,headers:{'Content-Type':'audio/mpeg'}});
    return new Response(JSON.stringify({ok:false}),{status:404,headers:{'Content-Type':'application/json'}});
  };
</script></body></html>`);
  await page.addScriptTag({ content: assistantJs });
  await page.locator('.farast-assistant-launcher').click();
  await page.locator('.farast-assistant-form input').fill('سلام فراست');
  await page.locator('.farast-assistant-send').click();
  await expect.poll(()=>page.evaluate(()=>window.__audioOrder.filter(x=>x==='play').length),{timeout:5000}).toBeGreaterThanOrEqual(2);
  expect(await page.evaluate(()=>window.__audioInstances.length)).toBe(1);
  expect(await page.evaluate(()=>window.__audioOrder.indexOf('play') < window.__audioOrder.indexOf('chat'))).toBe(true);
  expect(await page.evaluate(()=>window.__audioOrder.filter(x=>x==='play').length)).toBe(2);
});

test('assistant keeps listening during TTS without interrupting the active answer', async ({ page }) => {
  const assistantJs = fs.readFileSync(path.join(process.cwd(), 'public/js/farast-assistant.js'), 'utf8');
  await page.setContent(`<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="csrf-token" content="test-csrf"></head><body data-assistant-auth="1">
<script>
  window.__wsInstances = [];
  window.__sentAudioFrames = 0;
  window.__chatPayloads = [];
  window.__audioInstances = [];
  window.__stoppedTracks = 0;
  window.__getUserMediaCalls = 0;
  window.__order = [];
  window.__track = { enabled: true, stop() { window.__stoppedTracks++; } };
  class FakeWebSocket extends EventTarget {
    constructor(url) { super(); this.url=url; this.readyState=0; this.sent=[]; window.__wsInstances.push(this); setTimeout(()=>{this.readyState=1;this.dispatchEvent(new Event('open'));},0); }
    send(data) { this.sent.push(data); if(data instanceof ArrayBuffer)window.__sentAudioFrames++; if(typeof data==='string' && JSON.parse(data).type==='start') queueMicrotask(()=>this.emit({type:'ready',provider:'mock-local'})); }
    close() { this.readyState=3; }
    emit(data) { this.onmessage?.({data:JSON.stringify(data)}); }
  }
  FakeWebSocket.OPEN=1; window.WebSocket=FakeWebSocket;
  window.AudioContext=class {
    constructor(){window.__order.push('audio-context');this.state='running';this.sampleRate=48000;this.destination={};}
    createMediaStreamSource(){return {connect(){},disconnect(){}};}
    createScriptProcessor(){window.__scriptProcessor={onaudioprocess:null,connect(){},disconnect(){}};return window.__scriptProcessor;}
    resume(){window.__order.push('audio-resume');return Promise.resolve();}
    close(){this.state='closed';return Promise.resolve();}
  };
  window.AudioWorkletNode=undefined;
  Object.defineProperty(navigator,'mediaDevices',{configurable:true,value:{getUserMedia:async()=>{window.__order.push('get-user-media');window.__getUserMediaCalls++;return {getTracks:()=>[window.__track]};}}});
  window.Audio=class {
    constructor(){this.duration=2;this.handlers={};this.paused=false;window.__audioInstances.push(this);}
    addEventListener(name,fn){this.handlers[name]=fn;}
    removeEventListener(name){delete this.handlers[name];}
    load(){this.handlers.canplay?.();}
    play(){this.playing=true;this.onplay?.();return Promise.resolve();}
    pause(){this.paused=true;this.onpause?.();this.handlers.pause?.();}
  };
  window.fetch=async(url,options={})=>{
    if(url==='/editor/voice/status'){window.__order.push('status-request');return new Response(JSON.stringify({ok:true,mode:'gateway'}),{status:200,headers:{'Content-Type':'application/json'}});}
    if(url==='/assistant/voice/stream-token')return new Response(JSON.stringify({ok:true,token:'mock-token',websocket_url:'wss://voice.example.test'}),{status:200,headers:{'Content-Type':'application/json'}});
    if(url==='/assistant/history')return new Response(JSON.stringify({ok:true,messages:[]}),{status:200,headers:{'Content-Type':'application/json'}});
    if(url==='/assistant/chat/stream'){window.__chatPayloads.push(JSON.parse(options.body));return new Response('data: {"delta":"پاسخ در حال پخش است. "}\\n\\ndata: {"done":true}\\n\\n',{status:200,headers:{'Content-Type':'text/event-stream'}});}
    if(url==='/assistant/speak')return new Response(new Blob(['audio'],{type:'audio/mpeg'}),{status:200,headers:{'Content-Type':'audio/mpeg'}});
    return new Response(JSON.stringify({ok:false}),{status:404,headers:{'Content-Type':'application/json'}});
  };
</script></body></html>`);
  await page.addScriptTag({ content: assistantJs });
  await page.locator('.farast-assistant-launcher').click();
  await page.locator('.farast-voice-mic').click();
  await expect.poll(()=>page.evaluate(()=>window.__wsInstances.length)).toBe(1);
  await expect.poll(()=>page.evaluate(()=>!!window.__scriptProcessor)).toBe(true);
  expect(await page.evaluate(()=>window.__order.indexOf('audio-context') < window.__order.indexOf('audio-resume') && window.__order.indexOf('audio-resume') < window.__order.indexOf('status-request') && window.__order.indexOf('status-request') < window.__order.indexOf('get-user-media'))).toBe(true);

  await page.evaluate(()=>{
    const inputBuffer={getChannelData:()=>new Float32Array(4096).fill(0.1)};
    const outputBuffer={getChannelData:()=>new Float32Array(4096)};
    window.__scriptProcessor.onaudioprocess({inputBuffer,outputBuffer});
    inputBuffer.getChannelData=()=>new Float32Array(4096).fill(0.1);
    window.__scriptProcessor.onaudioprocess({inputBuffer,outputBuffer});
    window.__wsInstances[0].emit({type:'final',text:'سلام فراست'});
  });
  await expect.poll(()=>page.evaluate(()=>window.__chatPayloads.length),{timeout:5000}).toBe(1);
  await expect.poll(()=>page.evaluate(()=>window.__audioInstances.length),{timeout:5000}).toBeGreaterThan(0);
  await expect.poll(()=>page.evaluate(()=>window.__audioInstances[0].playing)).toBe(true);
  expect(await page.evaluate(()=>window.__track.enabled)).toBe(true);
  const framesBeforeTtsNoise=await page.evaluate(()=>window.__sentAudioFrames);

  // Microphone frames continue to reach server VAD while TTS is playing.
  await page.evaluate(()=>{
    const inputBuffer={getChannelData:()=>new Float32Array(4096).fill(0.02)};
    const outputBuffer={getChannelData:()=>new Float32Array(4096)};
    for(let i=0;i<8;i++)window.__scriptProcessor.onaudioprocess({inputBuffer,outputBuffer});
  });
  expect(await page.evaluate(()=>window.__audioInstances[0].paused)).toBe(false);
  expect(await page.evaluate(()=>window.__sentAudioFrames)).toBeGreaterThan(framesBeforeTtsNoise);

  // Turning the button red must stop the real MediaStream tracks without interrupting TTS.
  await page.locator('.farast-voice-mic').click();
  expect(await page.evaluate(()=>window.__audioInstances[0].paused)).toBe(false);
  expect(await page.evaluate(()=>window.__track.enabled)).toBe(false);
  expect(await page.evaluate(()=>window.__stoppedTracks)).toBe(1);
  expect(await page.evaluate(()=>window.__scriptProcessor.onaudioprocess)).toBe(null);
  const framesWhileOff=await page.evaluate(()=>window.__sentAudioFrames);
  await page.waitForTimeout(80);
  expect(await page.evaluate(()=>window.__sentAudioFrames)).toBe(framesWhileOff);

  // Re-enabling capture opens a fresh gateway and a fresh microphone stream.
  await page.locator('.farast-voice-mic').click();
  await expect.poll(()=>page.evaluate(()=>window.__wsInstances.length),{timeout:5000}).toBe(2);
  await expect.poll(()=>page.evaluate(()=>window.__getUserMediaCalls),{timeout:5000}).toBe(2);
  await expect.poll(()=>page.evaluate(()=>typeof window.__scriptProcessor?.onaudioprocess==='function')).toBe(true);
  expect(await page.evaluate(()=>window.__track.enabled)).toBe(true);
  expect(await page.evaluate(()=>window.__wsInstances[1].readyState)).toBe(1);
  expect(await page.evaluate(()=>window.__stoppedTracks)).toBe(1);
  await page.keyboard.press('Escape');
  await expect(page.locator('.farast-assistant-panel')).toBeVisible();
  await page.locator('.farast-assistant-launcher').click();
  await expect(page.locator('.farast-assistant-panel')).toBeVisible();
  expect(await page.evaluate(()=>window.__audioInstances[0].paused)).toBe(false);
  await page.locator('.farast-assistant-close').click();
  await expect(page.locator('.farast-assistant-panel')).toBeHidden();
  expect(await page.evaluate(()=>window.__audioInstances[0].paused)).toBe(true);
  expect(await page.evaluate(()=>window.__stoppedTracks)).toBe(2);
});

test('opening the assistant keeps the microphone off until the user taps', async ({ page }) => {
  const assistantJs = fs.readFileSync(path.join(process.cwd(), 'public/js/farast-assistant.js'), 'utf8');
  await page.setContent(`<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="csrf-token" content="test-csrf"></head><body data-assistant-auth="1">
<script>
  window.WebSocket = undefined;
  window.__recognitionStarts = 0;
  window.SpeechRecognition = class { start() { window.__recognitionStarts++; } };
  Object.defineProperty(navigator, 'mediaDevices', { configurable: true, value: undefined });
  window.fetch = async (url) => {
    if (url === '/assistant/history') return new Response(JSON.stringify({ ok: true, messages: [] }), { status: 200, headers: { 'Content-Type': 'application/json' } });
    return new Response(JSON.stringify({ ok: false }), { status: 404, headers: { 'Content-Type': 'application/json' } });
  };
</script></body></html>`);
  await page.addScriptTag({ content: assistantJs });
  await page.locator('.farast-assistant-launcher').click();
  await expect.poll(() => page.evaluate(() => window.__recognitionStarts)).toBe(0);
  await page.locator('.farast-voice-mic').click();
  await expect(page.locator('.farast-assistant-form')).toBeVisible();
  await expect.poll(() => page.evaluate(() => window.__recognitionStarts)).toBe(0);
});


test('gateway connection startup failure falls back to text when batch ASR is unavailable', async ({ page }) => {
  const assistantJs = fs.readFileSync(path.join(process.cwd(), 'public/js/farast-assistant.js'), 'utf8');
  await page.setContent(`<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="csrf-token" content="test-csrf"></head><body data-assistant-auth="1">
<script>
  window.__recognitionStarts = 0;
  class FakeWebSocket extends EventTarget {
    constructor(url) { super(); this.url=url; this.readyState=0; setTimeout(()=>this.dispatchEvent(new Event('error')),0); }
    send() {}
    close() { this.readyState=3; }
  }
  window.WebSocket=FakeWebSocket;
  window.SpeechRecognition = class { start() { window.__recognitionStarts++; this.onstart?.(); } stop() { this.onend?.(); } };
  Object.defineProperty(navigator,'mediaDevices',{configurable:true,value:{getUserMedia:async()=>({getTracks:()=>[{stop(){}}]})}});
  window.AudioContext = class { constructor(){this.state='running';this.sampleRate=48000;this.destination={};} createMediaStreamSource(){return {connect(){},disconnect(){}};} close(){this.state='closed';return Promise.resolve();} };
  window.fetch=async(url)=> {
    if(url==='/editor/voice/status') return new Response(JSON.stringify({ok:true,mode:'gateway'}),{status:200,headers:{'Content-Type':'application/json'}});
    if(url==='/assistant/voice/stream-token') return new Response(JSON.stringify({ok:true,token:'mock-token',websocket_url:'wss://voice.example.test'}),{status:200,headers:{'Content-Type':'application/json'}});
    if(url==='/assistant/history') return new Response(JSON.stringify({ok:true,messages:[]}),{status:200,headers:{'Content-Type':'application/json'}});
    return new Response(JSON.stringify({ok:false}),{status:404,headers:{'Content-Type':'application/json'}});
  };
</script></body></html>`);
  await page.addScriptTag({ content: assistantJs });
  await page.locator('.farast-assistant-launcher').click();
  await page.locator('.farast-voice-mic').click();
  await expect(page.locator('[data-fa-voice-title]')).toContainText('ورودی متنی در دسترس است');
  await expect.poll(() => page.evaluate(() => window.__recognitionStarts)).toBe(0);
});

test('a failed utterance keeps the live microphone stream open for the next turn', async ({ page }) => {
  const assistantJs = fs.readFileSync(path.join(process.cwd(), 'public/js/farast-assistant.js'), 'utf8');
  await page.setContent(`<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="csrf-token" content="test-csrf"></head><body data-assistant-auth="1">
<script>
  window.__recognitionStarts = 0;
  window.__wsInstances = [];
  window.SpeechRecognition = class { start() { window.__recognitionStarts++; this.onstart?.(); } stop() { this.onend?.(); } };
  class FakeWebSocket extends EventTarget {
    constructor(url) { super(); this.url=url; this.readyState=0; window.__wsInstances.push(this); setTimeout(()=>{this.readyState=1;this.dispatchEvent(new Event('open'));},0); }
    send(data) { if(typeof data==='string' && JSON.parse(data).type==='start') queueMicrotask(()=>this.emit({type:'ready'})); }
    close() { this.readyState=3; }
    emit(data) { this.onmessage?.({data:JSON.stringify(data)}); }
  }
  FakeWebSocket.OPEN=1; window.WebSocket=FakeWebSocket;
  window.AudioContext = class { constructor(){this.state='running';this.sampleRate=48000;this.destination={};this.audioWorklet=null;} createMediaStreamSource(){return {connect(){},disconnect(){}};} createScriptProcessor(){return {onaudioprocess:null,connect(){},disconnect(){}};} resume(){return Promise.resolve();} close(){this.state='closed';return Promise.resolve();} };
  Object.defineProperty(navigator,'mediaDevices',{configurable:true,value:{getUserMedia:async()=>({getTracks:()=>[{stop(){}}]})}});
  window.fetch=async(url)=> {
    if(url==='/editor/voice/status') return new Response(JSON.stringify({ok:true,mode:'gateway'}),{status:200,headers:{'Content-Type':'application/json'}});
    if(url==='/assistant/voice/stream-token') return new Response(JSON.stringify({ok:true,token:'mock-token',websocket_url:'wss://voice.example.test'}),{status:200,headers:{'Content-Type':'application/json'}});
    if(url==='/assistant/history') return new Response(JSON.stringify({ok:true,messages:[]}),{status:200,headers:{'Content-Type':'application/json'}});
    return new Response(JSON.stringify({ok:false}),{status:404,headers:{'Content-Type':'application/json'}});
  };
</script></body></html>`);
  await page.addScriptTag({ content: assistantJs });
  await page.locator('.farast-assistant-launcher').click();
  await page.locator('.farast-voice-mic').click();
  await expect.poll(() => page.evaluate(() => window.__wsInstances.length)).toBe(1);
  await expect.poll(() => page.evaluate(() => window.__wsInstances[0].readyState)).toBe(1);
  await page.evaluate(() => window.__wsInstances[0].emit({type:'provider_error',status:503}));
  await expect(page.locator('[data-fa-voice-title]')).toContainText('گوش می‌دهم');
  await expect(page.locator('[data-fa-voice-caption]')).toContainText('تشخیص این نوبت گفتار ناموفق بود');
  await expect.poll(() => page.evaluate(() => window.__wsInstances[0].readyState)).toBe(1);
  await expect.poll(() => page.evaluate(() => window.__recognitionStarts)).toBe(0);
});


test('assistant batches adjacent streamed sentences into continuous server audio chunks without browser speech APIs', async ({ page }) => {
  const assistantJs = fs.readFileSync(path.join(process.cwd(), 'public/js/farast-assistant.js'), 'utf8');
  await page.setContent(`<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="csrf-token" content="test-csrf"></head><body data-assistant-auth="1">
<script>
  window.__spokenChunks = [];
  window.__streamSentAll = false;
  window.__deviceSpeechCalls = 0;
  window.SpeechRecognition = class { start() { throw new Error('browser recognition must not run'); } };
  window.SpeechSynthesisUtterance = class {};
  window.speechSynthesis = { cancel() {}, speak() { window.__deviceSpeechCalls++; } };
  window.WebSocket = undefined;
  window.AudioContext = undefined;
  Object.defineProperty(navigator, 'mediaDevices', { configurable: true, value: undefined });
  window.Audio = class {
    constructor(){this.duration=2;this.handlers={};this.ended=false;this.paused=false;}
    addEventListener(name,fn){this.handlers[name]=fn;}
    removeEventListener(name){delete this.handlers[name];}
    load(){this.handlers.canplay?.();}
    play(){setTimeout(()=>{this.ended=true;this.handlers.ended?.();},20);return Promise.resolve();}
    pause(){this.paused=true;this.handlers.pause?.();}
  };
  window.fetch = async (url, options={}) => {
    if(url==='/assistant/history') return new Response(JSON.stringify({ok:true,messages:[]}),{status:200,headers:{'Content-Type':'application/json'}});
    if(url==='/assistant/chat/stream') {
      const encoder=new TextEncoder();
      return new Response(new ReadableStream({start(controller){
        controller.enqueue(encoder.encode('data: {"delta":"جمله اول. "}\\n\\n'));
        setTimeout(()=>controller.enqueue(encoder.encode('data: {"delta":"جمله دوم. "}\\n\\n')),500);
        setTimeout(()=>{window.__streamSentAll=true;controller.enqueue(encoder.encode('data: {"delta":"جمله سوم."}\\n\\ndata: {"done":true}\\n\\n'));controller.close();},1000);
      }}),{status:200,headers:{'Content-Type':'text/event-stream'}});
    }
    if(url==='/assistant/speak'){window.__spokenChunks.push(JSON.parse(options.body).text);return new Response(new Blob(['audio'],{type:'audio/mpeg'}),{status:200,headers:{'Content-Type':'audio/mpeg'}});}
    return new Response(JSON.stringify({ok:false}),{status:404,headers:{'Content-Type':'application/json'}});
  };
</script></body></html>`);
  await page.addScriptTag({ content: assistantJs });
  await page.locator('.farast-assistant-launcher').click();
  await page.locator('.farast-voice-mic').click();
  await expect(page.locator('.farast-assistant-form')).toBeVisible();
  await page.locator('.farast-assistant-form input[name="message"]').fill('سلام فراست');
  await page.locator('.farast-assistant-send').click();
  await expect.poll(() => page.evaluate(() => window.__spokenChunks.length)).toBeGreaterThanOrEqual(1);
  expect(await page.evaluate(() => window.__streamSentAll)).toBe(false);
  await expect.poll(() => page.evaluate(() => window.__spokenChunks.length)).toBe(2);
  expect(await page.evaluate(() => window.__spokenChunks)).toEqual(['جمله اول. جمله دوم.','جمله سوم.']);
  expect(await page.evaluate(() => window.__deviceSpeechCalls)).toBe(0);
});

test('assistant keeps the conversation composer visible and shows the private gateway error returned by the server', async ({ page }) => {
  const assistantJs = fs.readFileSync(path.join(process.cwd(), 'public/js/farast-assistant.js'), 'utf8');
  const assistantCss = fs.readFileSync(path.join(process.cwd(), 'public/css/farast-assistant.css'), 'utf8');
  const diagnostic = 'برای پاسخ‌گویی در حالت خودمیزبان، این تنظیمات لازم است: FARAST_AI_GATEWAY_URL، FARAST_AI_GATEWAY_TOKEN. هیچ سرویس ابری فراخوانی نمی‌شود.';
  await page.setContent(`<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="csrf-token" content="test-csrf"></head><body data-assistant-auth="1">
<script>
  window.fetch = async (url) => {
    if (url === '/assistant/history') return new Response(JSON.stringify({ok:true,messages:[]}), {status:200,headers:{'Content-Type':'application/json'}});
    if (url === '/assistant/voice/status') return new Response(JSON.stringify({ok:true,local_only:true,chat_gateway_ready:false,chat_gateway_message: ${JSON.stringify(diagnostic)}}), {status:200,headers:{'Content-Type':'application/json'}});
    if (url === '/assistant/chat/stream') return new Response(JSON.stringify({ok:false,code:'private_ai_gateway_not_configured',message: ${JSON.stringify(diagnostic)}}), {status:503,headers:{'Content-Type':'application/json'}});
    return new Response(JSON.stringify({ok:false}), {status:404,headers:{'Content-Type':'application/json'}});
  };
</script></body></html>`);
  await page.addStyleTag({ content: assistantCss });
  await page.addScriptTag({ content: assistantJs });
  await page.locator('.farast-assistant-launcher').click();
  await expect(page.locator('.farast-assistant-form')).toBeVisible();
  await expect(page.locator('[data-fa-messages]')).toBeVisible();
  await expect(page.locator('[data-fa-status]')).toContainText('هیچ درخواست ابری ارسال نمی‌شود');
  await page.locator('.farast-assistant-form input[name="message"]').fill('سلام');
  await page.locator('.farast-assistant-send').click();
  await expect(page.locator('.farast-assistant-msg.user')).toContainText('سلام');
  await expect(page.locator('[data-fa-messages]')).toContainText('FARAST_AI_GATEWAY_URL');
  await expect(page.locator('[data-fa-voice-title]')).toContainText('پاسخ‌گویی ناموفق بود');
  await expect(page.locator('[data-fa-status]')).toContainText('FARAST_AI_GATEWAY_URL');
});


test('assistant panel keeps every control inside desktop and mobile viewports', async ({ page }) => {
  const assistantJs = fs.readFileSync(path.join(process.cwd(), 'public/js/farast-assistant.js'), 'utf8');
  const assistantCss = fs.readFileSync(path.join(process.cwd(), 'public/css/farast-assistant.css'), 'utf8');
  await page.setContent(`<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="csrf-token" content="test-csrf"></head><body data-assistant-auth="0"></body></html>`);
  await page.addStyleTag({ content: assistantCss });
  await page.addScriptTag({ content: assistantJs });

  for (const viewport of [{ width: 1440, height: 900 }, { width: 390, height: 844 }, { width: 320, height: 568 }]) {
    await page.setViewportSize(viewport);
    await page.locator('.farast-assistant-launcher').click();
    await expect(page.locator('.farast-assistant-close')).toBeFocused();
    const panel = page.locator('.farast-assistant-panel');
    await expect(panel).toBeVisible();
    await expect(panel).toHaveAttribute('role', 'dialog');
    await expect(page.locator('.farast-assistant-launcher')).toHaveAttribute('aria-controls', 'farast-assistant-panel');
    await expect(page.locator('.farast-voice-mic')).toBeVisible();
    await expect(page.locator('[data-fa-stop-speech]')).toHaveCount(0);
    await page.keyboard.press('Escape');
    await expect(panel).toBeVisible();
    await page.locator('.farast-assistant-launcher').click();
    await expect(panel).toBeVisible();
    await expect(page.locator('.farast-assistant-form input[name="message"]')).toBeVisible();

    const geometry = await page.evaluate(() => {
      const panel = document.querySelector('.farast-assistant-panel').getBoundingClientRect();
      const controls = [
        document.querySelector('.farast-voice-mic'),
        document.querySelector('.farast-assistant-form input[name="message"]')
      ].map((element) => element.getBoundingClientRect());
      return {
        viewport: { width: window.innerWidth, height: window.innerHeight },
        panel: { top: panel.top, right: panel.right, bottom: panel.bottom, left: panel.left },
        controls: controls.map((rect) => ({ top: rect.top, right: rect.right, bottom: rect.bottom, left: rect.left }))
      };
    });

    expect(geometry.panel.top).toBeGreaterThanOrEqual(0);
    expect(geometry.panel.left).toBeGreaterThanOrEqual(0);
    expect(geometry.panel.right).toBeLessThanOrEqual(geometry.viewport.width);
    expect(geometry.panel.bottom).toBeLessThanOrEqual(geometry.viewport.height);
    for (const control of geometry.controls) {
      expect(control.top).toBeGreaterThanOrEqual(geometry.panel.top);
      expect(control.left).toBeGreaterThanOrEqual(geometry.panel.left);
      expect(control.right).toBeLessThanOrEqual(geometry.panel.right);
      expect(control.bottom).toBeLessThanOrEqual(geometry.panel.bottom);
    }
    await page.locator('.farast-assistant-close').click();
    await expect(page.locator('.farast-assistant-launcher')).toBeFocused();
  }
});


test('only the assistant close button stops the live gateway and releases the microphone', async ({ page }) => {
  const assistantJs = fs.readFileSync(path.join(process.cwd(), 'public/js/farast-assistant.js'), 'utf8');
  await page.setContent(`<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="csrf-token" content="test-csrf"></head><body data-assistant-auth="1">
<script>
  window.__wsInstances = [];
  window.__getUserMediaCalls = 0;
  window.__stoppedTracks = 0;
  class FakeWebSocket extends EventTarget {
    constructor(url) { super(); this.url=url; this.readyState=0; this.sent=[]; window.__wsInstances.push(this); setTimeout(()=>{this.readyState=1;this.dispatchEvent(new Event('open'));},0); }
    send(data) { this.sent.push(data); if(typeof data==='string' && JSON.parse(data).type==='start') queueMicrotask(()=>this.emit({type:'ready'})); }
    close() { this.readyState=3; }
    emit(data) { this.onmessage?.({data:JSON.stringify(data)}); }
  }
  FakeWebSocket.OPEN=1; window.WebSocket=FakeWebSocket;
  window.AudioContext=class {
    constructor(){this.state='running';this.sampleRate=48000;this.destination={};}
    createMediaStreamSource(){return {connect(){},disconnect(){}};}
    createScriptProcessor(){window.__scriptProcessor={onaudioprocess:null,connect(){},disconnect(){}};return window.__scriptProcessor;}
    resume(){return Promise.resolve();}
    close(){this.state='closed';return Promise.resolve();}
  };
  window.AudioWorkletNode=undefined;
  Object.defineProperty(window,'isSecureContext',{configurable:true,value:true});
  Object.defineProperty(navigator,'mediaDevices',{configurable:true,value:{getUserMedia:async()=>{window.__getUserMediaCalls++;return {getTracks:()=>[{stop(){window.__stoppedTracks++;}}]};}}});
  window.fetch=async(url)=>{
    if(url==='/editor/voice/status')return new Response(JSON.stringify({ok:true,mode:'gateway'}),{status:200,headers:{'Content-Type':'application/json'}});
    if(url==='/assistant/voice/stream-token')return new Response(JSON.stringify({ok:true,token:'mock-token',websocket_url:'wss://voice.example.test'}),{status:200,headers:{'Content-Type':'application/json'}});
    if(url==='/assistant/history')return new Response(JSON.stringify({ok:true,messages:[]}),{status:200,headers:{'Content-Type':'application/json'}});
    return new Response(JSON.stringify({ok:false}),{status:404});
  };
</script></body></html>`);
  await page.addScriptTag({ content: assistantJs });
  await page.locator('.farast-assistant-launcher').click();
  await page.locator('.farast-voice-mic').click();
  await expect.poll(() => page.evaluate(() => window.__wsInstances.length)).toBe(1);
  await expect.poll(() => page.evaluate(() => window.__stoppedTracks)).toBe(0);
  await expect.poll(() => page.evaluate(() => window.__wsInstances[0].readyState)).toBe(1);
  await expect.poll(() => page.evaluate(() => window.__getUserMediaCalls)).toBe(1);
  await page.locator('.farast-assistant-close').click();
  await expect(page.locator('.farast-assistant-panel')).toBeHidden();
  await expect.poll(() => page.evaluate(() => window.__stoppedTracks)).toBe(1);
  expect(await page.evaluate(() => window.__wsInstances[0].readyState)).toBe(3);
  expect(await page.evaluate(() => window.__wsInstances[0].sent.some((item) => typeof item === 'string' && JSON.parse(item).type === 'stop'))).toBe(true);
});


test('assistant animations are disabled when reduced motion is requested', async ({ page }) => {
  const assistantJs = fs.readFileSync(path.join(process.cwd(), 'public/js/farast-assistant.js'), 'utf8');
  const assistantCss = fs.readFileSync(path.join(process.cwd(), 'public/css/farast-assistant.css'), 'utf8');
  await page.emulateMedia({ reducedMotion: 'reduce' });
  await page.setContent(`<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="csrf-token" content="test-csrf"></head><body data-assistant-auth="0"></body></html>`);
  await page.addStyleTag({ content: assistantCss });
  await page.addScriptTag({ content: assistantJs });
  await page.locator('.farast-assistant-launcher').click();
  await page.locator('.farast-assistant-panel').evaluate((panel) => panel.classList.add('is-listening'));
  await page.locator('.farast-voice-mic').evaluate((mic) => mic.classList.add('is-recording'));
  const motion = await page.evaluate(() => ({
    orbAnimation: getComputedStyle(document.querySelector('.farast-voice-orb span')).animationName,
    micAnimation: getComputedStyle(document.querySelector('.farast-voice-mic')).animationName,
    micTransition: getComputedStyle(document.querySelector('.farast-voice-mic')).transitionDuration
  }));
  expect(motion.orbAnimation).toBe('none');
  expect(motion.micAnimation).toBe('none');
  expect(motion.micTransition).toBe('0s');
});


test('live gateway unavailability never starts batch recording and leaves text input available', async ({ page }) => {
  const assistantJs = fs.readFileSync(path.join(process.cwd(), 'public/js/farast-assistant.js'), 'utf8');
  await page.setContent(`<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="csrf-token" content="test-csrf"></head><body data-assistant-auth="1">
<script>
  window.WebSocket = undefined;
  window.AudioContext = undefined;
  window.__micCalls = 0;
  window.__recorderInstances = 0;
  window.__transcribeCalls = 0;
  Object.defineProperty(window, 'isSecureContext', { configurable: true, value: true });
  Object.defineProperty(navigator, 'mediaDevices', { configurable: true, value: { getUserMedia: async () => { window.__micCalls++; return { getTracks: () => [{ stop() {} }] }; } } });
  window.MediaRecorder = class { constructor() { window.__recorderInstances++; } start() {} stop() {} };
  window.fetch = async (url) => {
    if (url === '/assistant/history') return new Response(JSON.stringify({ok:true,messages:[]}), {status:200,headers:{'Content-Type':'application/json'}});
    if (url === '/editor/voice/status') return new Response(JSON.stringify({ok:true,mode:'none',message:'درگاه زنده در دسترس نیست.'}), {status:200,headers:{'Content-Type':'application/json'}});
    if (url === '/assistant/voice/status') return new Response(JSON.stringify({ok:true,local_only:false,remote_batch_enabled:true,server_stt:true,server_stt_token_configured:true}), {status:200,headers:{'Content-Type':'application/json'}});
    if (url === '/assistant/transcribe') { window.__transcribeCalls++; return new Response(JSON.stringify({ok:true,text:'نباید اجرا شود'}), {status:200,headers:{'Content-Type':'application/json'}}); }
    return new Response(JSON.stringify({ok:false}), {status:404,headers:{'Content-Type':'application/json'}});
  };
</script></body></html>`);
  await page.addScriptTag({ content: assistantJs });
  await page.locator('.farast-assistant-launcher').click();
  await page.locator('.farast-voice-mic').click();
  await expect(page.locator('[data-fa-voice-title]')).toContainText('ورودی متنی در دسترس است');
  await expect(page.locator('.farast-assistant-form')).toBeVisible();
  expect(await page.evaluate(() => window.__micCalls)).toBe(0);
  expect(await page.evaluate(() => window.__recorderInstances)).toBe(0);
  expect(await page.evaluate(() => window.__transcribeCalls)).toBe(0);
});


test('assistant streams complete TTS chunks while keeping full-duplex capture active', async ({ page }) => {
  const assistantJs = fs.readFileSync(path.join(process.cwd(), 'public/js/farast-assistant.js'), 'utf8');
  await page.setContent(`<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="csrf-token" content="test-csrf"></head><body data-assistant-auth="1">
<script>
  window.__wsInstances = [];
  window.__spokenChunks = [];
  window.__chatSignal = null;
  window.__resolveChat = null;
  window.__playCount = 0;
  window.__pauseOnce = true;
  window.__track = { enabled: true, stop() {} };
  class FakeWebSocket extends EventTarget {
    constructor(url) { super(); this.url=url; this.readyState=0; this.sent=[]; window.__wsInstances.push(this); setTimeout(()=>{this.readyState=1;this.dispatchEvent(new Event('open'));},0); }
    send(data) { this.sent.push(data); if(typeof data==='string' && JSON.parse(data).type==='start') queueMicrotask(()=>this.emit({type:'ready'})); }
    close() { this.readyState=3; }
    emit(data) { this.onmessage?.({data:JSON.stringify(data)}); }
  }
  FakeWebSocket.OPEN=1; window.WebSocket=FakeWebSocket;
  window.AudioContext=class {
    constructor(){this.state='running';this.sampleRate=48000;this.destination={};}
    createMediaStreamSource(){return {connect(){},disconnect(){}};}
    createScriptProcessor(){window.__scriptProcessor={onaudioprocess:null,connect(){},disconnect(){}};return window.__scriptProcessor;}
    resume(){return Promise.resolve();}
    close(){this.state='closed';return Promise.resolve();}
  };
  window.AudioWorkletNode=undefined;
  Object.defineProperty(window,'isSecureContext',{configurable:true,value:true});
  Object.defineProperty(navigator,'mediaDevices',{configurable:true,value:{getUserMedia:async()=>({getTracks:()=>[window.__track]})}});
  window.Audio=class extends EventTarget {
    constructor(){super();this.duration=2;this.ended=false;this.paused=true;this.src='';window.__audio=this;}
    load(){this.ended=false;this.paused=true;this.dispatchEvent(new Event('canplay'));}
    play(){this.ended=false;this.paused=false;if(this.onplay){window.__playCount++;this.onplay();if(window.__pauseOnce){window.__pauseOnce=false;this.paused=true;this.dispatchEvent(new Event('pause'));}}return Promise.resolve();}
    finish(){this.ended=true;this.paused=true;this.dispatchEvent(new Event('ended'));this.onended?.();}
    pause(){this.paused=true;this.dispatchEvent(new Event('pause'));this.onpause?.();}
  };
  window.fetch=async(url,options={})=>{
    if(url==='/assistant/history')return new Response(JSON.stringify({ok:true,messages:[]}),{status:200,headers:{'Content-Type':'application/json'}});
    if(url==='/assistant/voice/status')return new Response(JSON.stringify({ok:true,local_only:false,chat_gateway_ready:true}),{status:200,headers:{'Content-Type':'application/json'}});
    if(url==='/editor/voice/status')return new Response(JSON.stringify({ok:true,mode:'gateway'}),{status:200,headers:{'Content-Type':'application/json'}});
    if(url==='/assistant/voice/stream-token')return new Response(JSON.stringify({ok:true,token:'mock-token',websocket_url:'wss://voice.example.test'}),{status:200,headers:{'Content-Type':'application/json'}});
    if(url==='/assistant/chat/stream'){window.__chatSignal=options.signal;return await new Promise(resolve=>{window.__resolveChat=()=>resolve(new Response('data: {"delta":"بخش اول. بخش دوم."}\\n\\ndata: {"done":true}\\n\\n',{status:200,headers:{'Content-Type':'text/event-stream'}}));});}
    if(url==='/assistant/speak'){window.__spokenChunks.push(JSON.parse(options.body).text);return new Response(new Blob(['audio'],{type:'audio/mpeg'}),{status:200,headers:{'Content-Type':'audio/mpeg'}});}
    return new Response(JSON.stringify({ok:false}),{status:404,headers:{'Content-Type':'application/json'}});
  };
</script></body></html>`);
  await page.addScriptTag({ content: assistantJs });
  await page.locator('.farast-assistant-launcher').click();
  await page.locator('.farast-voice-mic').click();
  await expect.poll(()=>page.evaluate(()=>window.__wsInstances.length)).toBe(1);
  await expect.poll(()=>page.evaluate(()=>window.__wsInstances[0].readyState)).toBe(1);
  await page.locator('.farast-assistant-form input[name="message"]').fill('لطفاً پاسخ بده');
  await page.locator('.farast-assistant-send').click();
  await expect.poll(()=>page.evaluate(()=>typeof window.__resolveChat==='function')).toBe(true);
  expect(await page.evaluate(()=>window.__track.enabled)).toBe(true);
  await page.evaluate(()=>window.__wsInstances[0].emit({type:'speech_started'}));
  expect(await page.evaluate(()=>window.__chatSignal.aborted)).toBe(false);
  expect(await page.evaluate(()=>window.__track.enabled)).toBe(true);
  await page.evaluate(()=>window.__resolveChat());
  await expect.poll(()=>page.evaluate(()=>window.__playCount)).toBe(2);
  expect(await page.evaluate(()=>window.__spokenChunks)).toEqual(['بخش اول. بخش دوم.']);
  expect(await page.evaluate(()=>window.__track.enabled)).toBe(true);
  await page.evaluate(()=>window.__wsInstances[0].emit({type:'speech_started'}));
  expect(await page.evaluate(()=>window.__chatSignal.aborted)).toBe(false);
  await page.evaluate(()=>{const audio=window.__audio; if(audio)audio.finish();});
  await expect.poll(()=>page.evaluate(()=>window.__playCount)).toBe(2);
  expect(await page.evaluate(()=>window.__spokenChunks)).toEqual(['بخش اول. بخش دوم.']);
  expect(await page.evaluate(()=>window.__track.enabled)).toBe(true);
  await page.evaluate(()=>window.__audio.finish());
  await expect.poll(()=>page.evaluate(()=>window.__track.enabled)).toBe(true);
  const firstSignal = await page.evaluate(()=>window.__chatSignal);
  await page.locator('.farast-assistant-form input[name="message"]').fill('یک پاسخ دیگر بده');
  await page.locator('.farast-assistant-send').click();
  await expect.poll(()=>page.evaluate(signal=>window.__chatSignal!==signal,firstSignal)).toBe(true);
  await expect.poll(()=>page.evaluate(()=>!!window.__scriptProcessor)).toBe(true);
  const framesBeforeSpeaking = await page.evaluate(()=>window.__wsInstances[0].sent.filter(x=>x instanceof ArrayBuffer).length);
  await page.evaluate(()=>{
    const inputBuffer={getChannelData:()=>new Float32Array(4096).fill(0.1)};
    const outputBuffer={getChannelData:()=>new Float32Array(4096)};
    for(let i=0;i<5;i++)window.__scriptProcessor.onaudioprocess({inputBuffer,outputBuffer});
  });
  expect(await page.evaluate(()=>window.__chatSignal.aborted)).toBe(false);
  expect(await page.evaluate(()=>window.__wsInstances[0].sent.filter(x=>x instanceof ArrayBuffer).length)).toBeGreaterThan(framesBeforeSpeaking);
});

test('live assistant aggregates segmented final ASR events before starting a chat turn', async ({ page }) => {
  const assistantJs = fs.readFileSync(path.join(process.cwd(), 'public/js/farast-assistant.js'), 'utf8');
  await page.setContent(`<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="csrf-token" content="test-csrf"></head><body data-assistant-auth="1">
<script>
  window.__chatPayloads = [];
  window.__wsInstances = [];
  class FakeWebSocket extends EventTarget {
    constructor(url) { super(); this.url = url; this.readyState = 0; this.sent = []; window.__wsInstances.push(this); setTimeout(() => { this.readyState = 1; this.dispatchEvent(new Event('open')); }, 0); }
    send(data) { this.sent.push(data); if (typeof data === 'string' && JSON.parse(data).type === 'start') queueMicrotask(() => this.emit({ type: 'ready', provider: 'mock-provider' })); }
    close() { this.readyState = 3; }
    emit(data) { this.onmessage?.({ data: JSON.stringify(data) }); }
  }
  FakeWebSocket.OPEN = 1;
  window.WebSocket = FakeWebSocket;
  window.AudioContext = class {
    constructor() { this.state = 'running'; this.sampleRate = 48000; this.destination = {}; }
    createMediaStreamSource() { return { connect() {}, disconnect() {} }; }
    createScriptProcessor() { window.__scriptProcessor = { onaudioprocess: null, connect() {}, disconnect() {} }; return window.__scriptProcessor; }
    resume() { return Promise.resolve(); }
    close() { this.state = 'closed'; return Promise.resolve(); }
  };
  window.AudioWorkletNode = undefined;
  Object.defineProperty(navigator, 'mediaDevices', { configurable: true, value: { getUserMedia: async () => ({ getTracks: () => [{ stop() {} }] }) } });
  window.Audio = class {
    constructor() { this.duration = 2; this.handlers = {}; }
    addEventListener(n, fn) { this.handlers[n] = fn; }
    removeEventListener(n) { delete this.handlers[n]; }
    load() { this.handlers.canplay?.(); }
    play() { return Promise.resolve(); }
    pause() {}
  };
  window.fetch = async (url, options = {}) => {
    if (url === '/editor/voice/status') return new Response(JSON.stringify({ ok: true, mode: 'gateway' }), { status: 200, headers: { 'Content-Type': 'application/json' } });
    if (url === '/assistant/voice/stream-token') return new Response(JSON.stringify({ ok: true, token: 'mock-signed-token', websocket_url: 'wss://voice.example.test' }), { status: 200, headers: { 'Content-Type': 'application/json' } });
    if (url === '/assistant/history') return new Response(JSON.stringify({ ok: true, messages: [] }), { status: 200, headers: { 'Content-Type': 'application/json' } });
    if (url === '/assistant/chat/stream') { window.__chatPayloads.push(JSON.parse(options.body)); return new Response('data: {"delta":"پاسخ زنده"}\\n\\ndata: {"done":true}\\n\\n', { status: 200, headers: { 'Content-Type': 'text/event-stream' } }); }
    if (url === '/assistant/speak') return new Response(new Blob(['audio'], { type: 'audio/mpeg' }), { status: 200, headers: { 'Content-Type': 'audio/mpeg' } });
    return new Response(JSON.stringify({ ok: false }), { status: 404, headers: { 'Content-Type': 'application/json' } });
  };
</script></body></html>`);
  await page.addScriptTag({ content: assistantJs });
  await page.locator('.farast-assistant-launcher').click();
  await page.locator('.farast-voice-mic').click();

  await expect.poll(async () => page.evaluate(() => window.__wsInstances.length)).toBe(1);
  expect(await page.evaluate(() => window.__wsInstances[0].url)).toBe('wss://voice.example.test/v1/stream');
  await expect.poll(async () => page.evaluate(() => !!window.__scriptProcessor)).toBe(true);
  await page.evaluate(() => {
    const inputBuffer = { getChannelData: () => new Float32Array(4096).fill(0.1) };
    const outputBuffer = { getChannelData: () => new Float32Array(4096) };
    window.__scriptProcessor.onaudioprocess({ inputBuffer, outputBuffer });
    window.__scriptProcessor.onaudioprocess({ inputBuffer, outputBuffer });
  });
  expect(await page.evaluate(() => window.__wsInstances[0].sent.some((item) => typeof item !== 'string'))).toBe(true);
  await expect(page.locator('[data-fa-voice-title]')).toContainText('گوش می‌دهم');
  await page.evaluate(() => window.__wsInstances[0].emit({ type: 'interim', text: 'سلام فراست' }));
  await expect(page.locator('[data-fa-voice-caption]')).toContainText('سلام فراست');
  await page.evaluate(() => window.__wsInstances[0].emit({ type: 'final', text: 'سلام' }));
  await page.waitForTimeout(500);
  await page.evaluate(() => window.__wsInstances[0].emit({ type: 'interim', text: 'فراست' }));
  await page.waitForTimeout(900);
  expect(await page.evaluate(() => window.__chatPayloads.length)).toBe(0);
  await page.evaluate(() => window.__wsInstances[0].emit({ type: 'final', text: 'فراست' }));
  await expect.poll(async () => page.evaluate(() => window.__chatPayloads.length), { timeout: 5000 }).toBe(1);
  expect(await page.evaluate(() => window.__chatPayloads[0].message)).toBe('سلام فراست');
});

test('live assistant keeps one microphone session open across consecutive voice turns', async ({ page }) => {
  const assistantJs = fs.readFileSync(path.join(process.cwd(), 'public/js/farast-assistant.js'), 'utf8');
  await page.setContent(`<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="csrf-token" content="test-csrf"></head><body data-assistant-auth="1">
<script>
  window.__wsInstances = [];
  window.__chatPayloads = [];
  window.__getUserMediaCalls = 0;
  window.__audioEnds = 0;
  class FakeWebSocket extends EventTarget {
    constructor(url) { super(); this.url=url; this.readyState=0; this.sent=[]; window.__wsInstances.push(this); setTimeout(()=>{this.readyState=1;this.dispatchEvent(new Event('open'));},0); }
    send(data) { this.sent.push(data); if(typeof data==='string' && JSON.parse(data).type==='start') queueMicrotask(()=>this.emit({type:'ready'})); }
    close() { this.readyState=3; }
    emit(data) { this.onmessage?.({data:JSON.stringify(data)}); }
  }
  FakeWebSocket.OPEN=1; window.WebSocket=FakeWebSocket;
  window.AudioContext=class {
    constructor(){this.state='running';this.sampleRate=48000;this.destination={};}
    createMediaStreamSource(){return {connect(){},disconnect(){}};}
    createScriptProcessor(){window.__scriptProcessor={onaudioprocess:null,connect(){},disconnect(){}};return window.__scriptProcessor;}
    resume(){return Promise.resolve();}
    close(){this.state='closed';return Promise.resolve();}
  };
  window.AudioWorkletNode=undefined;
  Object.defineProperty(navigator,'mediaDevices',{configurable:true,value:{getUserMedia:async()=>{window.__getUserMediaCalls++;return {getTracks:()=>[{stop(){}}]};}}});
  window.Audio=class extends EventTarget {
    constructor(){super();this.duration=1;this.ended=false;this.paused=true;}
    load(){this.ended=false;this.dispatchEvent(new Event('canplay'));}
    play(){this.paused=false;if(this.onplay){this.onplay();setTimeout(()=>{if(this.paused)return;this.ended=true;this.paused=true;window.__audioEnds++;this.dispatchEvent(new Event('ended'));},5);}return Promise.resolve();}
    pause(){this.paused=true;this.dispatchEvent(new Event('pause'));}
  };
  window.fetch=async(url,options={})=>{
    if(url==='/editor/voice/status')return new Response(JSON.stringify({ok:true,mode:'gateway'}),{status:200,headers:{'Content-Type':'application/json'}});
    if(url==='/assistant/voice/stream-token')return new Response(JSON.stringify({ok:true,token:'mock-token',websocket_url:'wss://voice.example.test'}),{status:200,headers:{'Content-Type':'application/json'}});
    if(url==='/assistant/history')return new Response(JSON.stringify({ok:true,messages:[]}),{status:200,headers:{'Content-Type':'application/json'}});
    if(url==='/assistant/voice/status')return new Response(JSON.stringify({ok:true,local_only:false,chat_gateway_ready:true}),{status:200,headers:{'Content-Type':'application/json'}});
    if(url==='/assistant/chat/stream'){window.__chatPayloads.push(JSON.parse(options.body));return new Response('data: {"delta":"پاسخ کامل فراست."}\\n\\ndata: {"done":true}\\n\\n',{status:200,headers:{'Content-Type':'text/event-stream'}});}
    if(url==='/assistant/speak')return new Response(new Blob(['audio'],{type:'audio/mpeg'}),{status:200,headers:{'Content-Type':'audio/mpeg'}});
    return new Response(JSON.stringify({ok:false}),{status:404,headers:{'Content-Type':'application/json'}});
  };
</script></body></html>`);
  await page.addScriptTag({ content: assistantJs });
  await page.locator('.farast-assistant-launcher').click();
  await page.locator('.farast-voice-mic').click();
  await expect.poll(()=>page.evaluate(()=>window.__wsInstances.length)).toBe(1);
  await expect.poll(()=>page.evaluate(()=>!!window.__scriptProcessor)).toBe(true);

  await page.evaluate(()=>window.__wsInstances[0].emit({type:'final',text:'سلام فراست'}));
  await expect.poll(()=>page.evaluate(()=>window.__chatPayloads.length),{timeout:8000}).toBe(1);
  await expect.poll(()=>page.evaluate(()=>window.__audioEnds),{timeout:8000}).toBe(1);
  await expect.poll(()=>page.locator('[data-fa-status]').textContent()).toContain('در حال گوش دادن');

  await page.evaluate(()=>window.__wsInstances[0].emit({type:'final',text:'یک سؤال دیگر دارم'}));
  await expect.poll(()=>page.evaluate(()=>window.__chatPayloads.length),{timeout:8000}).toBe(2);
  expect(await page.evaluate(()=>window.__chatPayloads.map(x=>x.message))).toEqual(['سلام فراست','یک سؤال دیگر دارم']);
  expect(await page.evaluate(()=>window.__wsInstances.length)).toBe(1);
  expect(await page.evaluate(()=>window.__getUserMediaCalls)).toBe(1);
});


test('live assistant preserves a finalized ASR transcript when the provider errors immediately afterward', async ({ page }) => {
  const assistantJs = fs.readFileSync(path.join(process.cwd(), 'public/js/farast-assistant.js'), 'utf8');
  await page.setContent(`<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="csrf-token" content="test-csrf"></head><body data-assistant-auth="1">
<script>
  window.__wsInstances = [];
  window.__chatPayloads = [];
  class FakeWebSocket extends EventTarget {
    constructor(url) { super(); this.url=url; this.readyState=0; this.sent=[]; window.__wsInstances.push(this); setTimeout(()=>{this.readyState=1;this.dispatchEvent(new Event('open'));},0); }
    send(data) { this.sent.push(data); if(typeof data==='string' && JSON.parse(data).type==='start') queueMicrotask(()=>this.emit({type:'ready'})); }
    close() { this.readyState=3; }
    emit(data) { this.onmessage?.({data:JSON.stringify(data)}); }
  }
  FakeWebSocket.OPEN=1; window.WebSocket=FakeWebSocket;
  window.AudioContext=class {
    constructor(){this.state='running';this.sampleRate=48000;this.destination={};}
    createMediaStreamSource(){return {connect(){},disconnect(){}};}
    createScriptProcessor(){window.__scriptProcessor={onaudioprocess:null,connect(){},disconnect(){}};return window.__scriptProcessor;}
    resume(){return Promise.resolve();}
    close(){this.state='closed';return Promise.resolve();}
  };
  window.AudioWorkletNode=undefined;
  Object.defineProperty(navigator,'mediaDevices',{configurable:true,value:{getUserMedia:async()=>({getTracks:()=>[{stop(){}}]})}});
  window.Audio=class extends EventTarget {
    constructor(){super();this.duration=1;this.paused=true;}
    load(){this.dispatchEvent(new Event('canplay'));}
    play(){this.paused=false;this.onplay?.();return Promise.resolve();}
    pause(){this.paused=true;}
  };
  window.fetch=async(url,options={})=>{
    if(url==='/editor/voice/status')return new Response(JSON.stringify({ok:true,mode:'gateway'}),{status:200,headers:{'Content-Type':'application/json'}});
    if(url==='/assistant/voice/stream-token')return new Response(JSON.stringify({ok:true,token:'mock-token',websocket_url:'wss://voice.example.test'}),{status:200,headers:{'Content-Type':'application/json'}});
    if(url==='/assistant/history')return new Response(JSON.stringify({ok:true,messages:[]}),{status:200,headers:{'Content-Type':'application/json'}});
    if(url==='/assistant/voice/status')return new Response(JSON.stringify({ok:true,local_only:false,chat_gateway_ready:true}),{status:200,headers:{'Content-Type':'application/json'}});
    if(url==='/assistant/chat/stream'){window.__chatPayloads.push(JSON.parse(options.body));return new Response('data: {"delta":"پاسخ فراست."}\\n\\ndata: {"done":true}\\n\\n',{status:200,headers:{'Content-Type':'text/event-stream'}});}
    if(url==='/assistant/speak')return new Response(new Blob(['audio'],{type:'audio/mpeg'}),{status:200,headers:{'Content-Type':'audio/mpeg'}});
    return new Response(JSON.stringify({ok:false}),{status:404,headers:{'Content-Type':'application/json'}});
  };
</script></body></html>`);
  await page.addScriptTag({ content: assistantJs });
  await page.locator('.farast-assistant-launcher').click();
  await page.locator('.farast-voice-mic').click();
  await expect.poll(()=>page.evaluate(()=>window.__wsInstances.length)).toBe(1);
  await page.evaluate(()=>{
    window.__wsInstances[0].emit({type:'final',text:'درخواست نهایی‌شده'});
    window.__wsInstances[0].emit({type:'provider_error',code:'temporary_provider_failure'});
  });
  await expect.poll(()=>page.evaluate(()=>window.__chatPayloads.length),{timeout:8000}).toBe(1);
  expect(await page.evaluate(()=>window.__chatPayloads[0].message)).toBe('درخواست نهایی‌شده');
});


test('live assistant queues speech received while answering and processes it after the first reply', async ({ page }) => {
  const assistantJs = fs.readFileSync(path.join(process.cwd(), 'public/js/farast-assistant.js'), 'utf8');
  await page.setContent(`<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="csrf-token" content="test-csrf"></head><body data-assistant-auth="1">
<script>
  window.__wsInstances = [];
  window.__chatPayloads = [];
  window.__firstSignal = null;
  window.__resolveFirst = null;
  window.__audioPlayCount = 0;
  window.__ttsPlaying = false;
  window.__audioEnded = false;
  window.URL.createObjectURL = (blob) => blob.type === 'audio/wav' ? 'blob:silent-prime' : 'blob:assistant-tts';
  class FakeWebSocket extends EventTarget {
    constructor(url) { super(); this.url=url; this.readyState=0; this.sent=[]; window.__wsInstances.push(this); setTimeout(()=>{this.readyState=1;this.dispatchEvent(new Event('open'));},0); }
    send(data) { this.sent.push(data); if(typeof data==='string' && JSON.parse(data).type==='start') queueMicrotask(()=>this.emit({type:'ready'})); }
    close() { this.readyState=3; }
    emit(data) { this.onmessage?.({data:JSON.stringify(data)}); }
  }
  FakeWebSocket.OPEN=1; window.WebSocket=FakeWebSocket;
  window.AudioContext=class {
    constructor(){this.state='running';this.sampleRate=48000;this.destination={};}
    createMediaStreamSource(){return {connect(){},disconnect(){}};}
    createScriptProcessor(){window.__scriptProcessor={onaudioprocess:null,connect(){},disconnect(){}};return window.__scriptProcessor;}
    resume(){return Promise.resolve();}
    close(){this.state='closed';return Promise.resolve();}
  };
  window.AudioWorkletNode=undefined;
  window.__micTrack={enabled:true,kind:'audio',stop(){}};
  Object.defineProperty(navigator,'mediaDevices',{configurable:true,value:{getUserMedia:async()=>({getTracks:()=>[window.__micTrack],getAudioTracks:()=>[window.__micTrack]})}});
  window.Audio=class extends EventTarget {
    constructor(){super();this.duration=1;this.ended=false;this.paused=true;}
    load(){this.ended=false;this.dispatchEvent(new Event('canplay'));}
    play(){this.paused=false;this.onplay?.();if(this.src==='blob:assistant-tts'){window.__audioPlayCount++;window.__ttsPlaying=true;window.__audioEnded=false;}setTimeout(()=>{if(!this.paused){this.ended=true;this.paused=true;window.__ttsPlaying=false;window.__audioEnded=true;this.dispatchEvent(new Event('ended'));}},900);return Promise.resolve();}
    pause(){this.paused=true;window.__ttsPlaying=false;this.dispatchEvent(new Event('pause'));}
  };
  window.fetch=async(url,options={})=>{
    if(url==='/editor/voice/status')return new Response(JSON.stringify({ok:true,mode:'gateway'}),{status:200,headers:{'Content-Type':'application/json'}});
    if(url==='/assistant/voice/stream-token')return new Response(JSON.stringify({ok:true,token:'mock-token',websocket_url:'wss://voice.example.test'}),{status:200,headers:{'Content-Type':'application/json'}});
    if(url==='/assistant/history')return new Response(JSON.stringify({ok:true,messages:[]}),{status:200,headers:{'Content-Type':'application/json'}});
    if(url==='/assistant/voice/status')return new Response(JSON.stringify({ok:true,local_only:false,chat_gateway_ready:false}),{status:200,headers:{'Content-Type':'application/json'}});
    if(url==='/assistant/chat/stream'){
      const payload=JSON.parse(options.body);window.__chatPayloads.push(payload);
      if(window.__chatPayloads.length===1){window.__firstSignal=options.signal;return await new Promise(resolve=>{window.__resolveFirst=()=>resolve(new Response('data: {"delta":"پاسخ نخست تمام شد."}\\n\\ndata: {"done":true}\\n\\n',{status:200,headers:{'Content-Type':'text/event-stream'}}));});}
      return new Response('data: {"delta":"پاسخ دوم آماده است."}\\n\\ndata: {"done":true}\\n\\n',{status:200,headers:{'Content-Type':'text/event-stream'}});
    }
    if(url==='/assistant/speak')return new Response(new Blob(['audio'],{type:'audio/mpeg'}),{status:200,headers:{'Content-Type':'audio/mpeg'}});
    return new Response(JSON.stringify({ok:false}),{status:404});
  };
</script></body></html>`);
  await page.addScriptTag({ content: assistantJs });
  await page.locator('.farast-assistant-launcher').click();
  await page.locator('.farast-voice-mic').click();
  await expect.poll(()=>page.evaluate(()=>window.__wsInstances.length)).toBe(1);
  await page.locator('.farast-assistant-form input[name="message"]').fill('پرسش نخست');
  await page.locator('.farast-assistant-send').click();
  await expect.poll(()=>page.evaluate(()=>typeof window.__resolveFirst==='function')).toBe(true);

  await page.evaluate(()=>window.__wsInstances[0].emit({type:'final',text:'پرسش دوم که هنگام پاسخ رسید'}));
  await page.waitForTimeout(1000);
  expect(await page.evaluate(()=>window.__chatPayloads.length)).toBe(1);
  expect(await page.evaluate(()=>window.__firstSignal.aborted)).toBe(false);

  await page.evaluate(()=>window.__resolveFirst());
  await expect.poll(()=>page.evaluate(()=>window.__audioPlayCount),{timeout:8000}).toBe(1);
  await page.locator('.farast-voice-mic').click();
  expect(await page.evaluate(()=>window.__firstSignal.aborted)).toBe(false);
  expect(await page.evaluate(()=>window.__ttsPlaying)).toBe(true);
  expect(await page.evaluate(()=>window.__micTrack.enabled)).toBe(false);
  await expect.poll(()=>page.evaluate(()=>window.__audioEnded),{timeout:3000}).toBe(true);
  expect(await page.locator('[data-fa-voice-title]').textContent()).not.toBe('در حال صحبت');
  expect(await page.evaluate(()=>window.__micTrack.enabled)).toBe(false);
  await page.locator('.farast-voice-mic').click();
  await expect.poll(()=>page.evaluate(()=>window.__micTrack.enabled),{timeout:4000}).toBe(true);
  await expect.poll(()=>page.evaluate(()=>{
    const socket=window.__wsInstances[window.__wsInstances.length-1];
    return !!socket&&socket.readyState===1;
  }),{timeout:8000}).toBe(true);
  await page.evaluate(()=>{
    const socket=window.__wsInstances[window.__wsInstances.length-1];
    socket.emit({type:'final',text:'پرسش سوم هنگام پخش صدا'});
  });
  await page.waitForTimeout(200);
  expect(await page.evaluate(()=>window.__chatPayloads.length)).toBe(2);
  expect(await page.evaluate(()=>window.__firstSignal.aborted)).toBe(false);

  await expect.poll(()=>page.evaluate(()=>window.__chatPayloads.length),{timeout:8000}).toBe(2);
  await expect.poll(()=>page.evaluate(()=>window.__chatPayloads.length),{timeout:8000}).toBe(3);
  expect(await page.evaluate(()=>window.__chatPayloads.map(item=>item.message))).toEqual([
    'پرسش نخست',
    'پرسش دوم که هنگام پاسخ رسید',
    'پرسش سوم هنگام پخش صدا'
  ]);
  expect(await page.evaluate(()=>window.__firstSignal.aborted)).toBe(false);
  await expect(page.locator('[data-fa-messages]')).toContainText('پاسخ دوم آماده است.');
});
