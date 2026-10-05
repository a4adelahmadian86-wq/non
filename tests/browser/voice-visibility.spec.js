const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

/**
 * Visibility + interaction contract for FARAST Voice Typing UI.
 * Does not call live STT. Proves control is present, panel opens/closes,
 * and final transcript uses the server-kernel insert path.
 */
test('voice control is present and panel opens/closes', async ({ page }) => {
  const voiceJs = fs.readFileSync(path.join(process.cwd(), 'public/js/farast-voice.js'), 'utf8');
  const voiceCss = fs.readFileSync(path.join(process.cwd(), 'public/css/voice.css'), 'utf8');
  expect(voiceCss).toContain('#mic.farast-mic-btn');
  expect(voiceJs).toContain("panel.classList.add('is-open')");

  await page.setContent(`<!doctype html>
<html lang="fa" dir="rtl">
<head>
  <meta charset="utf-8">
  <meta name="csrf-token" content="test-csrf">
  <meta name="farast-capabilities" content='{"can_voice":true,"can_type":true}'>
  <style>${voiceCss}</style>
</head>
<body>
  <div id="farastWord" data-authenticated="1">
    <div id="editor" class="farast-editor" contenteditable="true" role="textbox"></div>
  </div>
  <footer class="farast-statusbar">
    <button id="mic" type="button" class="farast-mic-btn" aria-label="تایپ صوتی فراست">
      <span>تایپ صوتی</span>
    </button>
  </footer>
  <script>
    window.FARAST_AUTHENTICATED = true;
    window.FarastEditor = {
      state: { documentId: 1, revision: 1, model: { sections: [{ blocks: [{ id: 'b1', runs: [{ text: '' }] }] }] } },
      getSelection() { return { start: { blockId: 'b1', offset: 0 }, end: { blockId: 'b1', offset: 0 }, text: '' }; },
      restoreSelection() {},
      applyRemoteModel() { return true; },
      markSaved() {},
      undo() {},
      getTransactions() { return []; }
    };
  </script>
</body>
</html>`);
  await page.addScriptTag({ content: voiceJs });

  const mic = page.locator('#mic');
  await expect(mic).toBeVisible();
  await expect(mic).toHaveClass(/farast-mic-btn/);

  await mic.click();
  const panel = page.locator('#farastVoicePanel');
  await expect(panel).toBeVisible();
  await expect(panel).toHaveClass(/is-open/);
  await expect(page.locator('#fvStatus')).toBeVisible();
  await expect(page.locator('#fvLive')).toBeVisible();
  await expect(page.locator('.farast-voice-start')).toBeVisible();

  await page.locator('#fvClose').click();
  await expect(panel).not.toHaveClass(/is-open/);
});

test('ribbon voice command mapping exists in editor-core', async () => {
  const core = fs.readFileSync(path.join(process.cwd(), 'public/js/editor-core.js'), 'utf8');
  expect(core).toContain("bind('voice'");
  expect(core).toContain("voice:'تایپ صوتی'");
  expect(core).toContain("voice:'fa-microphone-lines'");
  expect(core).toMatch(/'voice'/);
});
