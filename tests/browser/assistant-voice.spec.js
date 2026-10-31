const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

test('voice assistant does not use batch recording when the live gateway is missing', async ({ page }) => {
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

test('user speech interrupts server TTS while keeping the same live microphone stream open', async ({ page }) => {
  const assistantJs = fs.readFileSync(path.join(process.cwd(), 'public/js/farast-assistant.js'), 'utf8');
  await page.setContent(`<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="csrf-token" content="test-csrf"></head><body data-assistant-auth="1">
<script>
  window.__wsInstances = [];
  window.__chatPayloads = [];
  window.__audioInstances = [];
  window.__stoppedTracks = 0;
  class FakeWebSocket extends EventTarget {
    constructor(url) { super(); this.url=url; this.readyState=0; this.sent=[]; window.__wsInstances.push(this); setTimeout(()=>{this.readyState=1;this.dispatchEvent(new Event('open'));},0); }
    send(data) { this.sent.push(data); if(typeof data==='string' && JSON.parse(data).type==='start') queueMicrotask(()=>this.emit({type:'ready',provider:'mock-local'})); }
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
  Object.defineProperty(navigator,'mediaDevices',{configurable:true,value:{getUserMedia:async()=>({getTracks:()=>[{stop(){window.__stoppedTracks++;}}]})}});
  window.Audio=class {
    constructor(){this.duration=2;this.handlers={};this.paused=false;window.__audioInstances.push(this);}
    addEventListener(name,fn){this.handlers[name]=fn;}
    removeEventListener(name){delete this.handlers[name];}
    load(){this.handlers.canplay?.();}
    play(){this.playing=true;return Promise.resolve();}
    pause(){this.paused=true;this.handlers.pause?.();}
  };
  window.fetch=async(url,options={})=>{
    if(url==='/editor/voice/status')return new Response(JSON.stringify({ok:true,mode:'gateway'}),{status:200,headers:{'Content-Type':'application/json'}});
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

  await page.evaluate(()=>{
    const inputBuffer={getChannelData:()=>new Float32Array(4096).fill(0.1)};
    const outputBuffer={getChannelData:()=>new Float32Array(4096)};
    for(let i=0;i<4;i++)window.__scriptProcessor.onaudioprocess({inputBuffer,outputBuffer});
  });
  await expect.poll(()=>page.evaluate(()=>window.__audioInstances[0].paused)).toBe(true);
  expect(await page.evaluate(()=>window.__wsInstances.length)).toBe(1);
  expect(await page.evaluate(()=>window.__wsInstances[0].readyState)).toBe(1);
  expect(await page.evaluate(()=>window.__stoppedTracks)).toBe(0);
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


test('assistant streams sentence-sized server audio chunks without browser speech APIs', async ({ page }) => {
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
  await expect.poll(() => page.evaluate(() => window.__spokenChunks.length)).toBe(3);
  expect(await page.evaluate(() => window.__spokenChunks)).toEqual(['جمله اول.','جمله دوم.','جمله سوم.']);
  expect(await page.evaluate(() => window.__deviceSpeechCalls)).toBe(0);
});

test('assistant keeps the conversation composer visible and shows the private gateway error returned by the server', async ({ page }) => {
  const assistantJs = fs.readFileSync(path.join(process.cwd(), 'public/js/farast-assistant.js'), 'utf8');
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
  await page.addScriptTag({ content: assistantJs });
  await page.locator('.farast-assistant-launcher').click();
  await expect(page.locator('.farast-assistant-form')).toBeVisible();
  await expect(page.locator('[data-fa-messages]')).toBeVisible();
  await expect(page.locator('[data-fa-status]')).toContainText('هیچ سرویس ابری فراخوانی نمی‌شود');
  await page.locator('.farast-assistant-form input[name="message"]').fill('سلام');
  await page.locator('.farast-assistant-send').click();
  await expect(page.locator('[data-fa-messages]')).toContainText('FARAST_AI_GATEWAY_URL');
  await expect(page.locator('[data-fa-voice-title]')).toContainText('درگاه پاسخ‌گویی تنظیم نشده');
});
