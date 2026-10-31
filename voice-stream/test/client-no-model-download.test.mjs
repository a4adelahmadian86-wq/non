import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';
import { test } from 'node:test';

const here = dirname(fileURLToPath(import.meta.url));
const project = resolve(here, '../..');

test('browser voice client never installs or runs a speech model on the device', async () => {
  const client = await readFile(resolve(project, 'public/js/farast-voice.js'), 'utf8');
  const forbidden = [
    /SpeechRecognition/,
    /webkitSpeechRecognition/,
    /processLocally/,
    /\.install\s*\(/,
    /downloadable|downloading/i,
    /onnxruntime/i,
    /transformers\.js/i,
    /model[_-]?url/i,
    /\.onnx\b/i,
    /\.tflite\b/i,
  ];

  for (const pattern of forbidden) {
    assert.doesNotMatch(client, pattern, `client bundle contains forbidden model path: ${pattern}`);
  }

  assert.match(client, /getUserMedia/);
  assert.match(client, /audioWorklet\.addModule/);
  assert.match(client, /new WebSocket/);
});

test('local-only Laravel mode refuses batch transcription rather than falling back', async () => {
  const controller = await readFile(resolve(project, 'app/Http/Controllers/VoiceController.php'), 'utf8');
  assert.match(controller, /if\(\$localVoiceOnly\)return response\(\)->json\(\['ok'=>false/);
});
