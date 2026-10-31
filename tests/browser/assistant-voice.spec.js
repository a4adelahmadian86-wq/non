const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

test('closing the global voice assistant closes its live WebSocket and microphone', async ({ page }) => {
  const assistantJs = fs.readFileSync(path.join(process.cwd(), 'public/js/farast-assistant.js'), 'utf8');
  await page.setContent(`<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="csrf-token" content="test-csrf"></head><body data-assistant-auth="1">
<script>
  window.__stoppedTracks = 0;
  window.__wsInstances = [];
  class FakeWebSocket extends EventTarget {
    constructor(url) { super(); this.url=url; this.readyState=0; window.__wsInstances.push(this); setTimeout(()=>{this.readyState=1;this.dispatchEvent(new Event('open'));},0); }
    send(data) { if(typeof data==='string'&&JSON.parse(data).type==='start')queueMicrotask(()=>this.onmessage?.({data:JSON.stringify({type:'ready'})})); }
    close() { this.readyState=3; }
  }
  FakeWebSocket.OPEN=1; window.WebSocket=FakeWebSocket;
  window.AudioContext=class {
    constructor(){this.state='running';this.sampleRate=48000;this.destination={};}
    createMediaStreamSource(){return {connect(){},disconnect(){}};}
    createScriptProcessor(){return {onaudioprocess:null,connect(){},disconnect(){}};}
    resume(){return Promise.resolve();}
    close(){this.state='closed';return Promise.resolve();}
  };
  window.AudioWorkletNode=undefined;
  Object.defineProperty(navigator,'mediaDevices',{configurable:true,value:{getUserMedia:async()=>({getTracks:()=>[{stop(){window.__stoppedTracks++;}}]})}});
  window.fetch=async(url)=>{
    if(url==='/editor/voice/status')return new Response(JSON.stringify({ok:true,mode:'gateway'}),{status:200,headers:{'Content-Type':'application/json'}});
    if(url==='/assistant/voice/stream-token')return new Response(JSON.stringify({ok:true,token:'mock-token',websocket_url:'ws://127.0.0.1:8010'}),{status:200,headers:{'Content-Type':'application/json'}});
    if(url==='/assistant/history')return new Response(JSON.stringify({ok:true,messages:[]}),{status:200,headers:{'Content-Type':'application/json'}});
    return new Response(JSON.stringify({ok:false}),{status:404,headers:{'Content-Type':'application/json'}});
  };
</script></body></html>`);
  await page.addScriptTag({content:assistantJs});
  await page.locator('.farast-assistant-launcher').click();
  await page.locator('.farast-voice-mic').click();
  await expect.poll(()=>page.evaluate(()=>window.__wsInstances.length)).toBe(1);
  await expect.poll(()=>page.evaluate(()=>window.__stoppedTracks)).toBe(0);
  await page.keyboard.press('Escape');
  await expect(page.locator('.farast-assistant-panel')).toBeHidden();
  await expect.poll(()=>page.evaluate(()=>window.__stoppedTracks)).toBe(1);
  expect(await page.evaluate(()=>window.__wsInstances[0].readyState)).toBe(3);
});

test('gateway-unavailable live assistant keeps the microphone closed instead of batch recording', async ({ page }) => {
  const assistantJs = fs.readFileSync(path.join(process.cwd(), 'public/js/farast-assistant.js'), 'utf8');
  await page.setContent(`<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="csrf-token" content="test-csrf"></head><body data-assistant-auth="1">
<script>
  window.__recognitionStarts = 0;
  window.__micRequests = 0;
  window.WebSocket = undefined;
  window.AudioContext = undefined;
  window.SpeechRecognition = class {
    start() { window.__recognitionStarts++; this.onstart?.(); }
    stop() { this.onend?.(); }
  };
  Object.defineProperty(navigator, 'mediaDevices', { configurable: true, value: {
    getUserMedia: async () => {
      window.__micRequests++;
      return { getTracks: () => [{ stop() {} }] };
    }
  }});
  window.MediaRecorder = class {
    static isTypeSupported() { return true; }
    constructor(stream, options = {}) { this.mimeType = options.mimeType || 'audio/webm'; this.state = 'inactive'; window.__recorder = this; }
    start() { this.state = 'recording'; }
    stop() { this.state = 'inactive'; this.onstop?.(); }
  };
  window.fetch = async (url) => {
    if (url === '/assistant/history') return new Response(JSON.stringify({ ok: true, messages: [] }), { status: 200, headers: { 'Content-Type': 'application/json' } });
    if (url === '/assistant/voice/status') return new Response(JSON.stringify({ ok: true, server_stt: true, server_tts: true, server_stt_token_configured: true, server_tts_token_configured: true }), { status: 200, headers: { 'Content-Type': 'application/json' } });
    return new Response(JSON.stringify({ ok: false }), { status: 404, headers: { 'Content-Type': 'application/json' } });
  };
</script></body></html>`);
  await page.addScriptTag({ content: assistantJs });
  await page.locator('.farast-assistant-launcher').click();
  await page.locator('.farast-voice-mic').click();

  await expect(page.locator('.farast-assistant-form')).toBeVisible();
  expect(await page.evaluate(() => window.__recognitionStarts)).toBe(0);
  expect(await page.evaluate(() => window.__micRequests)).toBe(0);
  expect(await page.evaluate(() => window.__recorder)).toBeUndefined();
});

test('microphone permission failure switches to text without a second audio attempt', async ({ page }) => {
  const assistantJs = fs.readFileSync(path.join(process.cwd(), 'public/js/farast-assistant.js'), 'utf8');
  await page.setContent(`<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="csrf-token" content="test-csrf"></head><body data-assistant-auth="1">
<script>
  window.__micRequests = 0;
  window.__recognitionStarts = 0;
  window.WebSocket = class {};
  window.AudioContext = class { createMediaStreamSource(){return {connect(){},disconnect(){}};} createScriptProcessor(){return {connect(){},disconnect(){}};} close(){return Promise.resolve();} };
  Object.defineProperty(window, 'SpeechRecognition', { configurable: true, value: undefined });
  Object.defineProperty(window, 'webkitSpeechRecognition', { configurable: true, value: undefined });
  Object.defineProperty(navigator, 'mediaDevices', { configurable: true, value: {
    getUserMedia: async () => { window.__micRequests++; const error = new Error('Not available'); error.name = 'NotAllowedError'; throw error; }
  }});
  window.fetch = async (url) => {
    if (url === '/editor/voice/status') return new Response(JSON.stringify({ ok: true, mode: 'gateway' }), { status: 200, headers: { 'Content-Type': 'application/json' } });
    if (url === '/assistant/voice/stream-token') return new Response(JSON.stringify({ ok: true, token: 'mock-token', websocket_url: 'ws://127.0.0.1:8010' }), { status: 200, headers: { 'Content-Type': 'application/json' } });
    if (url === '/assistant/history') return new Response(JSON.stringify({ ok: true, messages: [] }), { status: 200, headers: { 'Content-Type': 'application/json' } });
    if (url === '/assistant/voice/status') return new Response(JSON.stringify({ ok: true, server_stt: true, server_tts: true, server_stt_token_configured: true, server_tts_token_configured: true }), { status: 200, headers: { 'Content-Type': 'application/json' } });
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
  await expect(page.locator('.farast-assistant-form')).toBeVisible();
  await expect(page.locator('.farast-assistant-panel')).not.toHaveClass(/is-listening/);
  await expect.poll(() => page.evaluate(() => window.__recognitionStarts)).toBe(0);
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


test('assistant starts speaking while the response is still streaming and queues sentence-sized audio chunks', async ({ page }) => {
  const assistantJs = fs.readFileSync(path.join(process.cwd(), 'public/js/farast-assistant.js'), 'utf8');
  await page.setContent(`<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="csrf-token" content="test-csrf"></head><body data-assistant-auth="1">
<script>
  window.__spokenChunks = [];
  window.__streamSentAll = false;
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
  await expect.poll(() => page.evaluate(() => window.__spokenChunks.length)).toBe(3);
  expect(await page.evaluate(() => window.__spokenChunks)).toEqual(['جمله اول.','جمله دوم.','جمله سوم.']);
});


test('live voice session stays open and accepts the next turn after TTS finishes', async ({ page }) => {
  const assistantJs = fs.readFileSync(path.join(process.cwd(), 'public/js/farast-assistant.js'), 'utf8');
  await page.setContent(`<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="csrf-token" content="test-csrf"></head><body data-assistant-auth="1">
<script>
  window.__chatPayloads = [];
  window.__wsInstances = [];
  window.__micTrackStopped = false;
  window.__audioInstances = [];
  class FakeWebSocket extends EventTarget {
    constructor(url) { super(); this.url=url; this.readyState=0; this.sent=[]; window.__wsInstances.push(this); setTimeout(()=>{this.readyState=1;this.dispatchEvent(new Event('open'));},0); }
    send(data) { this.sent.push(data); if(typeof data==='string'&&JSON.parse(data).type==='start')queueMicrotask(()=>this.emit({type:'ready'})); }
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
  Object.defineProperty(navigator,'mediaDevices',{configurable:true,value:{getUserMedia:async()=>({getTracks:()=>[{stop(){window.__micTrackStopped=true;}}]})}});
  window.Audio=class {
    constructor(){this.duration=1;this.handlers={};this.paused=true;this.ended=false;window.__audioInstances.push(this);}
    addEventListener(n,fn){this.handlers[n]=fn;}
    removeEventListener(n){delete this.handlers[n];}
    load(){this.handlers.canplay?.();}
    play(){this.paused=false;this.onplay?.();setTimeout(()=>{this.ended=true;this.paused=true;this.handlers.ended?.();},80);return Promise.resolve();}
    pause(){this.pauseCount=(this.pauseCount||0)+1;this.paused=true;this.handlers.pause?.();}
  };
  window.fetch=async(url,options={})=>{
    if(url==='/editor/voice/status')return new Response(JSON.stringify({ok:true,mode:'gateway'}),{status:200,headers:{'Content-Type':'application/json'}});
    if(url==='/assistant/voice/stream-token')return new Response(JSON.stringify({ok:true,token:'mock-token',websocket_url:'wss://voice.example.test'}),{status:200,headers:{'Content-Type':'application/json'}});
    if(url==='/assistant/history')return new Response(JSON.stringify({ok:true,messages:[]}),{status:200,headers:{'Content-Type':'application/json'}});
    if(url==='/assistant/chat/stream'){window.__chatPayloads.push(JSON.parse(options.body));return new Response('data: {"delta":"پاسخ فراست."}\\n\\ndata: {"done":true}\\n\\n',{status:200,headers:{'Content-Type':'text/event-stream'}});}
    if(url==='/assistant/speak')return new Response(new Blob(['audio'],{type:'audio/mpeg'}),{status:200,headers:{'Content-Type':'audio/mpeg'}});
    return new Response(JSON.stringify({ok:false}),{status:404,headers:{'Content-Type':'application/json'}});
  };
</script></body></html>`);
  await page.addScriptTag({content:assistantJs});
  await page.locator('.farast-assistant-launcher').click();
  await page.locator('.farast-voice-mic').click();
  await expect.poll(()=>page.evaluate(()=>window.__wsInstances.length)).toBe(1);
  await expect.poll(()=>page.evaluate(()=>!!window.__scriptProcessor)).toBe(true);
  const speak = async (text) => {
    await page.evaluate(() => {
      const inputBuffer={getChannelData:()=>new Float32Array(4096).fill(0.1)};
      const outputBuffer={getChannelData:()=>new Float32Array(4096)};
      window.__scriptProcessor.onaudioprocess({inputBuffer,outputBuffer});
      window.__scriptProcessor.onaudioprocess({inputBuffer,outputBuffer});
    });
    await page.evaluate((value)=>window.__wsInstances[0].emit({type:'final',text:value}),text);
  };
  await speak('سلام');
  await expect.poll(()=>page.evaluate(()=>window.__chatPayloads.length),{timeout:5000}).toBe(1);
  await expect.poll(()=>page.evaluate(()=>window.__audioInstances[0]?.ended)).toBe(true);
  await expect(page.locator('[data-fa-voice-title]')).toContainText('گوش می‌دهم');
  expect(await page.evaluate(()=>window.__wsInstances[0].readyState)).toBe(1);
  expect(await page.evaluate(()=>window.__micTrackStopped)).toBe(false);
  await speak('سوال دوم');
  await expect.poll(()=>page.evaluate(()=>window.__chatPayloads.length),{timeout:5000}).toBe(2);
  expect(await page.evaluate(()=>window.__chatPayloads[1].message)).toBe('سوال دوم');
  expect(await page.evaluate(()=>window.__wsInstances.length)).toBe(1);
});

test('speaking during assistant audio interrupts TTS and routes the new speech through the same live session', async ({ page }) => {
  const assistantJs = fs.readFileSync(path.join(process.cwd(), 'public/js/farast-assistant.js'), 'utf8');
  await page.setContent(`<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="csrf-token" content="test-csrf"></head><body data-assistant-auth="1">
<script>
  window.__chatPayloads=[];window.__wsInstances=[];window.__audioInstances=[];
  class FakeWebSocket extends EventTarget {
    constructor(url){super();this.url=url;this.readyState=0;this.sent=[];window.__wsInstances.push(this);setTimeout(()=>{this.readyState=1;this.dispatchEvent(new Event('open'));},0);}
    send(data){this.sent.push(data);if(typeof data==='string'&&JSON.parse(data).type==='start')queueMicrotask(()=>this.emit({type:'ready'}));}
    close(){this.readyState=3;}
    emit(data){this.onmessage?.({data:JSON.stringify(data)});}
  }
  FakeWebSocket.OPEN=1;window.WebSocket=FakeWebSocket;
  window.AudioContext=class{constructor(){this.state='running';this.sampleRate=48000;this.destination={};}createMediaStreamSource(){return{connect(){},disconnect(){}};}createScriptProcessor(){window.__scriptProcessor={onaudioprocess:null,connect(){},disconnect(){}};return window.__scriptProcessor;}resume(){return Promise.resolve();}close(){this.state='closed';return Promise.resolve();}};
  window.AudioWorkletNode=undefined;
  Object.defineProperty(navigator,'mediaDevices',{configurable:true,value:{getUserMedia:async()=>({getTracks:()=>[{stop(){}}]})}});
  window.Audio=class{
    constructor(){this.duration=1;this.handlers={};this.paused=true;this.ended=false;this.pauseCount=0;window.__audioInstances.push(this);}
    addEventListener(n,fn){this.handlers[n]=fn;}removeEventListener(n){delete this.handlers[n];}load(){this.handlers.canplay?.();}
    play(){this.paused=false;this.onplay?.();return Promise.resolve();}
    pause(){this.pauseCount++;this.paused=true;this.handlers.pause?.();}
  };
  window.fetch=async(url,options={})=>{
    if(url==='/editor/voice/status')return new Response(JSON.stringify({ok:true,mode:'gateway'}),{status:200,headers:{'Content-Type':'application/json'}});
    if(url==='/assistant/voice/stream-token')return new Response(JSON.stringify({ok:true,token:'mock-token',websocket_url:'wss://voice.example.test'}),{status:200,headers:{'Content-Type':'application/json'}});
    if(url==='/assistant/history')return new Response(JSON.stringify({ok:true,messages:[]}),{status:200,headers:{'Content-Type':'application/json'}});
    if(url==='/assistant/chat/stream'){window.__chatPayloads.push(JSON.parse(options.body));return new Response('data: {"delta":"پاسخ فراست."}\\n\\ndata: {"done":true}\\n\\n',{status:200,headers:{'Content-Type':'text/event-stream'}});}
    if(url==='/assistant/speak')return new Response(new Blob(['audio'],{type:'audio/mpeg'}),{status:200,headers:{'Content-Type':'audio/mpeg'}});
    return new Response(JSON.stringify({ok:false}),{status:404,headers:{'Content-Type':'application/json'}});
  };
</script></body></html>`);
  await page.addScriptTag({content:assistantJs});
  await page.locator('.farast-assistant-launcher').click();
  await page.locator('.farast-voice-mic').click();
  await expect.poll(()=>page.evaluate(()=>window.__wsInstances.length)).toBe(1);
  await expect.poll(()=>page.evaluate(()=>!!window.__scriptProcessor)).toBe(true);
  const frame = async () => page.evaluate(() => {
    const inputBuffer={getChannelData:()=>new Float32Array(4096).fill(0.1)};
    const outputBuffer={getChannelData:()=>new Float32Array(4096)};
    window.__scriptProcessor.onaudioprocess({inputBuffer,outputBuffer});
  });
  await frame();await frame();
  await page.evaluate(()=>window.__wsInstances[0].emit({type:'final',text:'سلام'}));
  await expect.poll(()=>page.evaluate(()=>window.__chatPayloads.length),{timeout:5000}).toBe(1);
  await expect.poll(()=>page.evaluate(()=>window.__audioInstances[0]&&!window.__audioInstances[0].paused)).toBe(true);
  await frame();await frame();await frame();
  await expect.poll(()=>page.evaluate(()=>window.__audioInstances[0].pauseCount)).toBeGreaterThan(0);
  await expect.poll(()=>page.evaluate(()=>window.__wsInstances[0].sent.filter(item=>typeof item!=='string').length)).toBeGreaterThan(1);
  await page.evaluate(()=>window.__wsInstances[0].emit({type:'final',text:'یک سوال فوری'}));
  await expect.poll(()=>page.evaluate(()=>window.__chatPayloads.length),{timeout:5000}).toBe(2);
  expect(await page.evaluate(()=>window.__chatPayloads[1].message)).toBe('یک سوال فوری');
  expect(await page.evaluate(()=>window.__wsInstances.length)).toBe(1);
});
