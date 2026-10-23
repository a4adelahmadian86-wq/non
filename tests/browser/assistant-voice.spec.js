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


test('unsupported voice browsers can continue through an accessible text fallback', async ({ page }) => {
  const assistantJs = fs.readFileSync(path.join(process.cwd(), 'public/js/farast-assistant.js'), 'utf8');
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
