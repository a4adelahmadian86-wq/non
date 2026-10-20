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
    if (url === '/assistant/voice/status') return new Response(JSON.stringify({ ok: true, server_stt: true, server_stt_token_configured: true }), { status: 200, headers: { 'Content-Type': 'application/json' } });
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
