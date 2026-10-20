const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

test('online voice uses configured chunked transcription when no websocket gateway is configured', async ({ page }) => {
  const voiceJs = fs.readFileSync(path.join(process.cwd(), 'public/js/farast-voice.js'), 'utf8');
  await page.setContent(`<!doctype html>
<html lang="fa" dir="rtl">
<head>
  <meta charset="utf-8">
  <meta name="csrf-token" content="test-csrf">
  <meta name="farast-capabilities" content='{"can_voice":true,"can_type":true}'>
</head>
<body>
  <div id="farastWord" data-authenticated="1">
    <div id="editor" class="farast-editor" contenteditable="true" role="textbox"></div>
  </div>
  <button id="mic" type="button">mic</button>
  <script>
    window.FARAST_AUTHENTICATED = true;
    window.__transcribeCalled = false;
    window.__insertCalled = false;
    window.__insertedText = '';
    Object.defineProperty(navigator, 'mediaDevices', { configurable: true, value: {
      getUserMedia: async () => ({ getTracks: () => [{ stop() {} }] })
    }});
    window.MediaRecorder = class {
      static isTypeSupported() { return true; }
      constructor(stream, options = {}) { this.stream = stream; this.mimeType = options.mimeType || 'audio/webm'; this.state = 'inactive'; }
      start() { this.state = 'recording'; }
      pause() { this.state = 'paused'; }
      resume() { this.state = 'recording'; }
      stop() {
        this.state = 'inactive';
        this.ondataavailable?.({ data: new Blob(['fake-audio'], { type: this.mimeType }) });
        this.onstop?.();
      }
    };
    window.fetch = async (url, options = {}) => {
      if (url === '/editor/voice/status') return new Response(JSON.stringify({ ok: true, mode: 'chunked' }), { status: 200, headers: { 'Content-Type': 'application/json' } });
      if (url === '/editor/voice/transcribe') {
        window.__transcribeCalled = true;
        if (!(options.body instanceof FormData) || !options.body.get('audio') || options.body.get('locale') !== 'fa-IR') return new Response(JSON.stringify({ ok: false, message: 'bad multipart request' }), { status: 400, headers: { 'Content-Type': 'application/json' } });
        return new Response(JSON.stringify({ ok: true, text: 'سلام دنیای صوتی', engine: 'mock-asr' }), { status: 200, headers: { 'Content-Type': 'application/json' } });
      }
      if (url === '/editor/voice/insert') {
        const body = JSON.parse(options.body || '{}');
        window.__insertCalled = true;
        window.__insertedText = body.text;
        return new Response(JSON.stringify({ ok: true, revision: 2, transaction_id: 'tx-voice-chunked', document_model: { plain_text: body.text } }), { status: 200, headers: { 'Content-Type': 'application/json' } });
      }
      return new Response(JSON.stringify({ ok: false, message: 'unexpected request' }), { status: 400, headers: { 'Content-Type': 'application/json' } });
    };
    window.FarastEditor = {
      state: { documentId: 7, revision: 1, model: { sections: [{ blocks: [{ id: 'b', runs: [{ text: '' }] }] }] } },
      getSelection() { return { start: { blockId: 'b', offset: 0 }, end: { blockId: 'b', offset: 0 } }; },
      applyRemoteModel() {},
      markSaved(revision) { this.state.revision = revision; },
      restoreSelection() { return true; }
    };
  </script>
</body>
</html>`);
  await page.addScriptTag({ content: voiceJs });
  await expect.poll(async () => page.evaluate(() => !!window.FarastVoiceRuntime?.open)).toBeTruthy();
  await page.evaluate(() => window.FarastVoiceRuntime.open());
  await page.locator('#fvStart').click();
  await expect(page.locator('#fvStatus')).toContainText('در حال ضبط');
  await page.locator('#fvStop').click();
  await expect.poll(async () => page.evaluate(() => window.__insertCalled)).toBeTruthy();
  const result = await page.evaluate(() => ({ transcribe: window.__transcribeCalled, inserted: window.__insertedText, engine: document.getElementById('fvEngine').textContent }));
  expect(result.transcribe).toBeTruthy();
  expect(result.inserted).toContain('سلام دنیای صوتی');
  expect(result.engine).toBe('mock-asr');
});
