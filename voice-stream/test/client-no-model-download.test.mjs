import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';
import { test } from 'node:test';

const here = dirname(fileURLToPath(import.meta.url));
const project = resolve(here, '../..');

test('all browser voice clients use server recognition and never install speech models', async () => {
  const paths = ['public/js/farast-voice.js', 'public/js/farast-assistant.js', 'public/js/editor-voice-hub.js', 'public/js/farast-voice-worklet.js'];
  const clients = await Promise.all(paths.map(path => readFile(resolve(project, path), 'utf8')));
  const forbidden = [
    /SpeechRecognition/,
    /webkitSpeechRecognition/,
    /processLocally/,
    /\.install\s*\(/,
    /downloadable|downloading/i,
    /onnxruntime/i,
    /transformers\.js/i,
    /Xenova\/whisper/i,
    /localWhisper/i,
    /model[_-]?url/i,
    /\.onnx\b/i,
    /\.tflite\b/i,
  ];
  for (const [index, client] of clients.entries()) {
    for (const pattern of forbidden) assert.doesNotMatch(client, pattern, `${paths[index]} contains forbidden client speech-model path: ${pattern}`);
  }
  assert.match(clients[0], /getUserMedia/);
  assert.match(clients[0], /audioWorklet\.addModule/);
  assert.match(clients[0], /new WebSocket/);
  assert.match(clients[1], /getUserMedia/);
  assert.match(clients[1], /new WebSocket/);
});

test('local-only Laravel mode refuses batch transcription rather than falling back', async () => {
  const controller = await readFile(resolve(project, 'app/Http/Controllers/VoiceController.php'), 'utf8');
  assert.match(controller, /if\(\$localVoiceOnly\)return response\(\)->json\(\['ok'=>false/);
});
