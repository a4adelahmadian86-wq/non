const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

test('Firefox cloud fallback records continuously and transcribes once on stop', async ({ page }) => {
  const source = fs.readFileSync(path.join(process.cwd(), 'public/js/editor-voice-hub.js'), 'utf8');
  await page.setContent('<!doctype html><html><head><meta charset="utf-8"><meta name="csrf-token" content="test-csrf"></head><body><div id="farastWord" data-authenticated="1"></div></body></html>');
  await page.addScriptTag({ content: `
    window.__getUserMediaCalls = 0;
    window.__recorderInstances = 0;
    window.__transcribeCalls = 0;
    window.__finalText = '';
    window.__stopped = false;
    Object.defineProperty(navigator, 'mediaDevices', { configurable: true, value: {
      getUserMedia: async () => {
        window.__getUserMediaCalls++;
        return { getTracks: () => [{ stop() {} }] };
      }
    }});
    window.MediaRecorder = class {
      static isTypeSupported() { return true; }
      constructor(stream, options = {}) {
        window.__recorderInstances++;
        this.mimeType = options.mimeType || 'audio/webm';
        this.state = 'inactive';
      }
      start() {
        this.state = 'recording';
        this.ondataavailable?.({ data: new Blob([new Array(2049).join('a')], { type: this.mimeType }) });
      }
      stop() {
        this.state = 'inactive';
        this.ondataavailable?.({ data: new Blob([new Array(2049).join('b')], { type: this.mimeType }) });
        this.onstop?.();
      }
    };
    window.fetch = async (url, options = {}) => {
      if (url === '/editor/voice/status') {
        return new Response(JSON.stringify({ ok: true, mode: 'chunked', chunked: true, gateway: false }), {
          status: 200, headers: { 'Content-Type': 'application/json' }
        });
      }
      if (url === '/editor/voice/transcribe') {
        window.__transcribeCalls++;
        const audio = options.body instanceof FormData ? options.body.get('audio') : null;
        if (!audio || audio.size < 30 || options.body.get('locale') !== 'fa-IR') {
          return new Response(JSON.stringify({ ok: false, message: 'invalid complete recording' }), {
            status: 400, headers: { 'Content-Type': 'application/json' }
          });
        }
        return new Response(JSON.stringify({ ok: true, text: 'این متن کامل و پیوسته است', engine: 'mock-whisper' }), {
          status: 200, headers: { 'Content-Type': 'application/json' }
        });
      }
      return new Response(JSON.stringify({ ok: false, message: 'unexpected request' }), {
        status: 400, headers: { 'Content-Type': 'application/json' }
      });
    };
  ` });
  await page.addScriptTag({ content: source });
  await expect.poll(() => page.evaluate(() => typeof window.FarastVoiceHub?.startCloud)).toBe('function');
  await page.evaluate(async () => {
    window.__voiceHandle = await window.FarastVoiceHub.startCloud({
      lang: () => 'fa-IR',
      onStatus: value => { window.__voiceStatus = value; },
      onLive: value => { window.__voiceLive = value; },
      onInterim: () => {},
      onFinal: value => { window.__finalText += value; },
      onError: value => { window.__voiceError = value; },
      onStopped: () => { window.__stopped = true; }
    }, 'test-csrf');
  });
  const beforeStop = await page.evaluate(() => ({
    getUserMedia: window.__getUserMediaCalls,
    recorders: window.__recorderInstances,
    requests: window.__transcribeCalls
  }));
  expect(beforeStop.getUserMedia).toBe(1);
  expect(beforeStop.recorders).toBe(1);
  expect(beforeStop.requests).toBe(0);

  await page.evaluate(() => window.__voiceHandle.stop());
  await expect.poll(() => page.evaluate(() => window.__stopped)).toBeTruthy();
  const afterStop = await page.evaluate(() => ({
    requests: window.__transcribeCalls,
    text: window.__finalText,
    error: window.__voiceError || ''
  }));
  expect(afterStop.requests).toBe(1);
  expect(afterStop.text).toContain('این متن کامل و پیوسته است');
  expect(afterStop.error).toBe('');
});


test('voice hub does not expose a browser-local Whisper model path', async ({ page }) => {
  const source = fs.readFileSync(path.join(process.cwd(), 'public/js/editor-voice-hub.js'), 'utf8');
  await page.setContent('<!doctype html><html><head></head><body><div id="farastWord" data-authenticated="1"></div></body></html>');
  await page.addScriptTag({ content: source });
  const result = await page.evaluate(() => ({
    hasLocalWhisperApi: Object.prototype.hasOwnProperty.call(window.FarastVoiceHub || {}, 'localWhisper'),
    engineIds: window.FarastVoiceHub?.engines?.().map(engine => engine.id) || []
  }));
  expect(result.hasLocalWhisperApi).toBe(false);
  expect(result.engineIds).not.toContain('local-whisper');
});


test('voice hub does not load browser-side AI model libraries or weights', async ({ page }) => {
  const source = fs.readFileSync(path.join(process.cwd(), 'public/js/editor-voice-hub.js'), 'utf8');
  await page.setContent('<!doctype html><html><head></head><body><div id="farastWord" data-authenticated="1"></div></body></html>');
  await page.addScriptTag({ content: source });
  const result = await page.evaluate(() => ({
    hasLocalWhisperApi: Object.prototype.hasOwnProperty.call(window.FarastVoiceHub || {}, 'localWhisper'),
    engineIds: window.FarastVoiceHub?.engines?.().map(engine => engine.id) || [],
    scriptRequests: [...document.scripts].map(script => script.src).filter(Boolean)
  }));
  expect(result.hasLocalWhisperApi).toBe(false);
  expect(result.engineIds).not.toContain('local-whisper');
  expect(source).not.toContain('@huggingface/transformers');
  expect(source).not.toContain('Xenova/whisper');
  expect(source).not.toContain('tesseract.js');
  expect(source).not.toContain('createWorker(');
  expect(result.scriptRequests).toEqual([]);
});
