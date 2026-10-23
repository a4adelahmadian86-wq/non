const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

test('closing the global voice assistant stops microphone capture and discards pending audio', async ({ page }) => {
  const assistantJs = fs.readFileSync(path.join(process.cwd(), 'public/js/farast-assistant.js'), 'utf8');
  await page.setContent(`<!doctype html>
<html lang="fa" dir="rtl">
<head><meta charset="utf-8"><meta name="csrf-token" content="test-csrf"></head>
<body data-assistant-auth="1">
<script>
  window.__stoppedTracks = 0;
  window.__transcribeCalled = false;
  Object.defineProperty(window, 'SpeechRecognition', { configurable: true, value: undefined });
  Object.defineProperty(window, 'webkitSpeechRecognition', { configurable: true, value: undefined });
  Object.defineProperty(navigator, 'mediaDevices', { configurable: true, value: {
    getUserMedia: async () => ({ getTracks: () => [{ stop() { window.__stoppedTracks++; } }] })
  }});
  window.MediaRecorder = class {
    static isTypeSupported() { return true; }
    constructor(stream, options = {}) { this.stream = stream; this.mimeType = options.mimeType || 'audio/webm'; this.state = 'inactive'; window.__recorder = this; }
    start() { this.state = 'recording'; }
    stop() {
      this.state = 'inactive';
      this.ondataavailable?.({ data: new Blob(['pending-audio'], { type: this.mimeType }) });
      this.onstop?.();
    }
  };
  window.fetch = async (url) => {
    if (url === '/assistant/voice/status') return new Response(JSON.stringify({ ok: true, server_stt: true, server_tts: true, server_stt_token_configured: true, server_tts_token_configured: true }), { status: 200, headers: { 'Content-Type': 'application/json' } });
    if (url === '/assistant/history') return new Response(JSON.stringify({ ok: true, messages: [] }), { status: 200, headers: { 'Content-Type': 'application/json' } });
    if (url === '/assistant/transcribe') { window.__transcribeCalled = true; return new Response(JSON.stringify({ ok: true, text: 'این متن نباید ارسال شود' }), { status: 200, headers: { 'Content-Type': 'application/json' } }); }
    return new Response(JSON.stringify({ ok: false }), { status: 400, headers: { 'Content-Type': 'application/json' } });
  };
</script>
</body></html>`);
  await page.addScriptTag({ content: assistantJs });
  await page.locator('.farast-assistant-launcher').click();
  await page.locator('.farast-voice-mic').click();

  await expect.poll(async () => page.evaluate(() => window.__recorder?.state)).toBe('recording');
  await page.keyboard.press('Escape');
  await expect(page.locator('.farast-assistant-panel')).toBeHidden();
  await expect.poll(async () => page.evaluate(() => window.__stoppedTracks)).toBeGreaterThan(0);
  await expect.poll(async () => page.evaluate(() => window.__transcribeCalled)).toBe(false);
});

test('live conversation streams interim transcript and sends the completed utterance automatically', async ({ page }) => {
  const assistantJs = fs.readFileSync(path.join(process.cwd(), 'public/js/farast-assistant.js'), 'utf8');
  await page.setContent(`<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="csrf-token" content="test-csrf"></head><body data-assistant-auth="1">
<script>
  window.__chatPayloads = [];
  window.SpeechRecognition = class {
    constructor() { window.__liveRecognition = this; }
    start() { this.onstart?.(); }
    stop() { this.onend?.(); }
  };
  window.Audio = class {
    constructor() { this.duration = 2; this.handlers = {}; this.paused = false; }
    addEventListener(name, fn) { this.handlers[name] = fn; }
    removeEventListener(name) { delete this.handlers[name]; }
    load() { this.handlers.canplay?.(); }
    play() { return Promise.resolve(); }
    pause() { this.paused = true; }
  };
  window.fetch = async (url, options = {}) => {
    if (url === '/assistant/history') return new Response(JSON.stringify({ ok: true, messages: [] }), { status: 200, headers: { 'Content-Type': 'application/json' } });
    if (url === '/assistant/chat') { window.__chatPayloads.push(JSON.parse(options.body)); return new Response(JSON.stringify({ ok: true, reply: 'سلام، در خدمتم.' }), { status: 200, headers: { 'Content-Type': 'application/json' } }); }
    if (url === '/assistant/speak') return new Response(new Blob(['audio'], { type: 'audio/mpeg' }), { status: 200, headers: { 'Content-Type': 'audio/mpeg' } });
    return new Response(JSON.stringify({ ok: false }), { status: 404, headers: { 'Content-Type': 'application/json' } });
  };
</script></body></html>`);
  await page.addScriptTag({ content: assistantJs });
  await page.locator('.farast-assistant-launcher').click();
  await page.locator('.farast-voice-mic').click();

  await expect.poll(async () => page.evaluate(() => !!window.__liveRecognition)).toBe(true);
  await page.evaluate(() => window.__liveRecognition.onresult({
    resultIndex: 0,
    results: [{ 0: { transcript: 'سلام' }, isFinal: false }]
  }));
  await expect(page.locator('[data-fa-voice-caption]')).toContainText('سلام');
  await page.evaluate(() => window.__liveRecognition.onresult({
    resultIndex: 0,
    results: [{ 0: { transcript: 'سلام فراست' }, isFinal: true }]
  }));
  await expect.poll(async () => page.evaluate(() => window.__chatPayloads.length)).toBe(1);
  expect(await page.evaluate(() => window.__chatPayloads[0].message)).toBe('سلام فراست');
});


test('microphone permission failure switches to text without a second audio attempt', async ({ page }) => {
  const assistantJs = fs.readFileSync(path.join(process.cwd(), 'public/js/farast-assistant.js'), 'utf8');
  await page.setContent(`<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="csrf-token" content="test-csrf"></head><body data-assistant-auth="1">
<script>
  window.__micRequests = 0;
  window.__recognitionStarts = 0;
  window.SpeechRecognition = class { start() { window.__recognitionStarts++; } };
  Object.defineProperty(navigator, 'mediaDevices', { configurable: true, value: {
    getUserMedia: async () => { window.__micRequests++; const error = new Error('Not available'); error.name = 'NotAllowedError'; throw error; }
  }});
  window.fetch = async (url) => {
    if (url === '/assistant/history') return new Response(JSON.stringify({ ok: true, messages: [] }), { status: 200, headers: { 'Content-Type': 'application/json' } });
    return new Response(JSON.stringify({ ok: false }), { status: 404, headers: { 'Content-Type': 'application/json' } });
  };
</script></body></html>`);
  await page.addScriptTag({ content: assistantJs });
  await page.locator('.farast-assistant-launcher').click();
  await page.locator('.farast-voice-mic').click();
  await expect(page.locator('[data-fa-voice-title]')).toContainText('گفت‌وگوی متنی');
  await expect.poll(() => page.evaluate(() => window.__micRequests)).toBe(1);
  await expect.poll(() => page.evaluate(() => window.__recognitionStarts)).toBe(0);
});

test('unsupported voice browsers can continue through an accessible text fallback', async ({ page }) => {
  const assistantJs = fs.readFileSync(path.join(process.cwd(), 'public/js/farast-assistant.js'), 'utf8');
  const assistantCss = fs.readFileSync(path.join(process.cwd(), 'public/css/farast-assistant.css'), 'utf8');
  await page.setContent(`<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="csrf-token" content="test-csrf"></head><body data-assistant-auth="1">
<script>
  window.__chatPayloads = [];
  Object.defineProperty(window, 'SpeechRecognition', { configurable: true, value: undefined });
  Object.defineProperty(window, 'webkitSpeechRecognition', { configurable: true, value: undefined });
  Object.defineProperty(navigator, 'mediaDevices', { configurable: true, value: undefined });
  window.fetch = async (url, options = {}) => {
    if (url === '/assistant/history') return new Response(JSON.stringify({ ok: true, messages: [] }), { status: 200, headers: { 'Content-Type': 'application/json' } });
    if (url === '/assistant/voice/status') return new Response(JSON.stringify({ ok: true, server_stt: true, server_tts: true, server_stt_token_configured: true, server_tts_token_configured: true }), { status: 200, headers: { 'Content-Type': 'application/json' } });
    if (url === '/assistant/chat') { window.__chatPayloads.push(JSON.parse(options.body)); return new Response(JSON.stringify({ ok: true, reply: 'پاسخ متنی قابل مشاهده است.' }), { status: 200, headers: { 'Content-Type': 'application/json' } }); }
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
  window.fetch = async (url, options = {}) => {
    if (url === '/editor/voice/status') return new Response(JSON.stringify({ ok: true, mode: 'gateway' }), { status: 200, headers: { 'Content-Type': 'application/json' } });
    if (url === '/assistant/voice/stream-token') return new Response(JSON.stringify({ ok: true, token: 'mock-signed-token', websocket_url: 'wss://voice.example.test' }), { status: 200, headers: { 'Content-Type': 'application/json' } });
    if (url === '/assistant/history') return new Response(JSON.stringify({ ok: true, messages: [] }), { status: 200, headers: { 'Content-Type': 'application/json' } });
    if (url === '/assistant/chat') { window.__chatPayloads.push(JSON.parse(options.body)); return new Response(JSON.stringify({ ok: true, reply: 'پاسخ زنده' }), { status: 200, headers: { 'Content-Type': 'application/json' } }); }
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
  await page.evaluate(() => window.__wsInstances[0].emit({ type: 'final', text: 'سلام فراست' }));
  await expect.poll(async () => page.evaluate(() => window.__chatPayloads.length), { timeout: 5000 }).toBe(1);
  expect(await page.evaluate(() => window.__chatPayloads[0].message)).toBe('سلام فراست');
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
    if (url === '/assistant/chat') { window.__chatPayloads.push(JSON.parse(options.body)); return new Response(JSON.stringify({ ok: true, reply: 'پاسخ زنده' }), { status: 200, headers: { 'Content-Type': 'application/json' } }); }
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


test('opening the assistant keeps the microphone off until the user taps', async ({ page }) => {
  const assistantJs = fs.readFileSync(path.join(process.cwd(), 'public/js/farast-assistant.js'), 'utf8');
  await page.setContent(`<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="csrf-token" content="test-csrf"></head><body data-assistant-auth="1">
<script>
  window.WebSocket = undefined;
  window.__recognitionStarts = 0;
  window.SpeechRecognition = class {
    constructor() { window.__recognition = this; }
    start() { window.__recognitionStarts++; this.onstart?.(); }
    stop() { this.onend?.(); }
  };
  window.fetch = async (url) => {
    if (url === '/assistant/history') return new Response(JSON.stringify({ ok: true, messages: [] }), { status: 200, headers: { 'Content-Type': 'application/json' } });
    return new Response(JSON.stringify({ ok: false }), { status: 404, headers: { 'Content-Type': 'application/json' } });
  };
</script></body></html>`);
  await page.addScriptTag({ content: assistantJs });
  await page.locator('.farast-assistant-launcher').click();
  await expect.poll(() => page.evaluate(() => window.__recognitionStarts)).toBe(0);
  await page.locator('.farast-voice-mic').click();
  await expect.poll(() => page.evaluate(() => window.__recognitionStarts)).toBe(1);
  await page.locator('.farast-voice-mic').click();
  await expect(page.locator('.farast-assistant-panel')).not.toHaveClass(/is-listening/);
  await expect.poll(() => page.evaluate(() => window.__recognitionStarts)).toBe(1);
});



test('gateway connection startup failure does not auto-start browser recognition', async ({ page }) => {
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
  await expect(page.locator('[data-fa-voice-title]')).toContainText('میکروفون خاموش');
  await expect.poll(() => page.evaluate(() => window.__recognitionStarts)).toBe(0);
});

test('gateway failure keeps the microphone off until the user taps again', async ({ page }) => {
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
  await expect(page.locator('[data-fa-voice-title]')).toContainText('میکروفون خاموش');
  await expect(page.locator('[data-fa-voice-caption]')).toContainText('پردازش این بخش از صدا ناموفق بود');
  await expect.poll(() => page.evaluate(() => window.__recognitionStarts)).toBe(0);
});
