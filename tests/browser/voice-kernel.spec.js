const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

/**
 * Fixture-based kernel path test for FARAST voice final transcript.
 * Does not call live STT providers. Uses a mock Kernel that records
 * InsertText transactions and supports undo.
 */
test('mock final transcript enters Kernel InsertText and supports undo', async ({ page }) => {
  const voiceJs = fs.readFileSync(path.join(process.cwd(), 'public/js/farast-voice.js'), 'utf8');
  expect(voiceJs).not.toContain("kernel.execute('InsertText'");
  expect(voiceJs).toContain("'/editor/voice/insert'");
  expect(voiceJs).not.toContain('createTextNode');

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
    window.__voiceKernelLog = [];
    window.__voiceKernelText = '';
    window.__voiceKernelHistory = [];
    window.fetch = async (url, options = {}) => {
      if (url === '/editor/voice/insert') {
        const body = JSON.parse(options.body || '{}');
        window.__voiceKernelHistory.push(window.__voiceKernelText);
        window.__voiceKernelText += String(body.text || '');
        window.__voiceKernelLog.push({ command: 'InsertText', input: body.text, source: 'voice-server-kernel' });
        window.FarastEditor.state.revision += 1;
        return new Response(JSON.stringify({ ok: true, revision: window.FarastEditor.state.revision, transaction_id: 'tx-voice' }), { status: 200, headers: { 'Content-Type': 'application/json' } });
      }
      return new Response(JSON.stringify({ ok: false, message: 'unexpected voice request' }), { status: 400, headers: { 'Content-Type': 'application/json' } });
    };
    window.FarastEditor = {
      execute(name, input) {
        if (name === 'InsertText' || name === 'insertText') {
          window.__voiceKernelHistory.push(window.__voiceKernelText);
          const chunk = String(input ?? '');
          window.__voiceKernelText += chunk;
          window.__voiceKernelLog.push({ command: 'InsertText', input: chunk, source: 'voice-import-test' });
          document.getElementById('editor').textContent = window.__voiceKernelText;
          return true;
        }
        if (name === 'undo') {
          window.__voiceKernelText = window.__voiceKernelHistory.pop() ?? '';
          window.__voiceKernelLog.push({ command: 'Undo' });
          document.getElementById('editor').textContent = window.__voiceKernelText;
          return true;
        }
        return false;
      },
      undo() { return this.execute('undo'); },
      getTransactions() { return window.__voiceKernelLog.slice(); },
      getSelection() { return { start: { blockId: 'b', offset: 0 }, end: { blockId: 'b', offset: 0 } }; },
      reload() { return Promise.resolve(); },
      state: { documentId: 1, revision: 1, model: { sections: [{ blocks: [{ id: 'b', runs: [{ text: '' }] }] }] } }
    };
  </script>
</body>
</html>`);

  await page.addScriptTag({ content: voiceJs });

  await expect.poll(async () => page.evaluate(() => !!window.FarastVoiceRuntime?.applyFinalTranscript)).toBeTruthy();
  await expect.poll(async () => page.locator('#farastVoicePanel').count()).toBe(1);

  await page.evaluate(() => window.FarastVoiceRuntime.open());
  await expect(page.locator('#farastVoicePanel')).toHaveClass(/is-open/);

  await page.evaluate(async () => { await window.FarastVoiceRuntime.applyFinalTranscript('سلام فراست'); });
  const afterInsert = await page.evaluate(() => ({
    text: window.__voiceKernelText,
    txs: window.FarastEditor.getTransactions(),
    editor: document.getElementById('editor').textContent,
  }));
  expect(afterInsert.text).toContain('سلام فراست');
  expect(afterInsert.editor).toContain('سلام فراست');
  expect(afterInsert.txs.some(t => t.command === 'InsertText')).toBeTruthy();
  expect(afterInsert.txs.every(t => t.command !== 'DomTextNode')).toBeTruthy();

  await page.evaluate(() => window.FarastEditor.undo());
  const afterUndo = await page.evaluate(() => ({
    text: window.__voiceKernelText,
    txs: window.FarastEditor.getTransactions(),
  }));
  expect(afterUndo.text).not.toContain('سلام فراست');
  expect(afterUndo.txs.some(t => t.command === 'Undo')).toBeTruthy();
});

test('kernel missing blocks DOM insertion with explicit error', async ({ page }) => {
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
    <div id="editor" class="farast-editor" contenteditable="true"></div>
  </div>
  <button id="mic" type="button">mic</button>
</body>
</html>`);
  await page.addScriptTag({ content: voiceJs });
  await expect.poll(async () => page.evaluate(() => !!window.FarastVoiceRuntime?.applyFinalTranscript)).toBeTruthy();
  await page.evaluate(() => window.FarastVoiceRuntime.applyFinalTranscript('نباید درج شود'));
  const state = await page.evaluate(() => ({
    editor: document.getElementById('editor').textContent,
    error: document.getElementById('fvError')?.textContent || '',
  }));
  expect(state.editor).not.toContain('نباید درج شود');
  expect(state.error).toContain('هسته ویرایشگر');
});
