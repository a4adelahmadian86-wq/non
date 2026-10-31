const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

test('live editor dictation refuses batch mode and never opens the microphone without a gateway', async ({ page }) => {
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
    window.__micCalls = 0;
    window.__transcribeCalls = 0;
    Object.defineProperty(navigator, 'mediaDevices', { configurable: true, value: {
      getUserMedia: async () => { window.__micCalls++; return { getTracks: () => [{ stop() {} }] }; }
    }});
    window.FarastEditor = {
      state: { documentId: 7, revision: 1, model: { sections: [{ blocks: [{ id: 'b', runs: [{ text: '' }] }] }] } },
      getSelection() { return { start: { blockId: 'b', offset: 0 }, end: { blockId: 'b', offset: 0 } }; },
      applyRemoteModel() {}, markSaved(revision) { this.state.revision = revision; }, restoreSelection() { return true; }
    };
    window.fetch = async (url) => {
      if (url === '/editor/voice/status') return new Response(JSON.stringify({ ok: true, mode: 'chunked', chunked: true, gateway: false }), { status: 200, headers: { 'Content-Type': 'application/json' } });
      if (url === '/editor/voice/transcribe') { window.__transcribeCalls++; return new Response(JSON.stringify({ ok: true, text: 'unexpected' }), { status: 200, headers: { 'Content-Type': 'application/json' } }); }
      return new Response(JSON.stringify({ ok: false }), { status: 404, headers: { 'Content-Type': 'application/json' } });
    };
  </script>
</body>
</html>`);
  await page.addScriptTag({ content: voiceJs });
  await expect.poll(async () => !!(await page.evaluate(() => window.FarastVoiceRuntime?.open))).toBeTruthy();
  await page.evaluate(() => window.FarastVoiceRuntime.open());
  await page.locator('#fvStart').click();
  await expect.poll(() => page.locator('#fvError').textContent()).not.toBe('');
  expect(await page.evaluate(() => window.__micCalls)).toBe(0);
  expect(await page.evaluate(() => window.__transcribeCalls)).toBe(0);
});
