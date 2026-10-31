const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

test('voice hub never installs browser speech or downloads a speech model', async ({ page }) => {
  const source = fs.readFileSync(path.join(process.cwd(), 'public/js/editor-voice-hub.js'), 'utf8');
  expect(source).not.toMatch(/SpeechRecognition|webkitSpeechRecognition|processLocally|transformers\\.js|Xenova\\/whisper|localWhisper|\\.onnx\\b|\\.tflite\\b/i);

  await page.setContent('<!doctype html><html><head><meta charset="utf-8"><meta name="csrf-token" content="test-csrf"></head><body><div id="farastWord" data-authenticated="1"></div></body></html>');
  await page.addScriptTag({ content: "window.__getUserMediaCalls=0;window.__transcribeCalls=0;Object.defineProperty(navigator,'mediaDevices',{configurable:true,value:{getUserMedia:async()=>{window.__getUserMediaCalls++;return {getTracks:()=>[{stop(){}}]};}}});window.MediaRecorder=class {};window.fetch=async(url)=>{if(url==='/editor/voice/status')return new Response(JSON.stringify({ok:true,mode:'none',gateway:false,reason:'local_voice_gateway_unavailable'}),{status:200,headers:{'Content-Type':'application/json'}});if(url==='/editor/voice/transcribe'){window.__transcribeCalls++;return new Response(JSON.stringify({ok:true,text:'must not be called'}),{status:200,headers:{'Content-Type':'application/json'}});}return new Response(JSON.stringify({ok:false}),{status:404,headers:{'Content-Type':'application/json'}});};" });
  await page.addScriptTag({ content: source });
  const api = await page.evaluate(() => ({ hasNative: window.FarastVoiceHub.hasNative(), localWhisper: typeof window.FarastVoiceHub.localWhisper, engines: window.FarastVoiceHub.engines().map(x => x.id) }));
  expect(api.hasNative).toBe(false);
  expect(api.localWhisper).toBe('undefined');
  expect(api.engines).not.toContain('native');
  expect(api.engines).not.toContain('local-whisper');

  const error = await page.evaluate(async () => {
    try { await window.FarastVoiceHub.startCloud({ lang: () => 'fa-IR', onStatus() {}, onLive() {}, onInterim() {}, onFinal() {}, onError() {}, onStopped() {} }); return ''; }
    catch (e) { return e.message; }
  });
  expect(error).toContain('درگاه زندهٔ گفتار');
  expect(await page.evaluate(() => window.__getUserMediaCalls)).toBe(0);
  expect(await page.evaluate(() => window.__transcribeCalls)).toBe(0);
});
