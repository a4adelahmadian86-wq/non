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
  assert.doesNotMatch(clients[0], /MediaRecorder|startChunked|\/editor\/voice\/transcribe/);
  assert.doesNotMatch(clients[1], /MediaRecorder|\/assistant\/transcribe|startBatchFallback|startBatchCapture/);
  assert.match(clients[1], /startGatewayLiveRecognition/);
  assert.match(clients[3], /output\.fill\(0\)/);
});

test('local-only Laravel voice keeps remote batch speech explicitly gated', async () => {
  const controller = await readFile(resolve(project, 'app/Http/Controllers/VoiceController.php'), 'utf8');
  const assistant = await readFile(resolve(project, 'app/Http/Controllers/GlobalAssistantController.php'), 'utf8');
  const services = await readFile(resolve(project, 'config/services.php'), 'utf8');
  assert.match(controller, /if\(\$localVoiceOnly\)return response\(\)->json\(\['ok'=>false/);
  assert.match(assistant, /services\.voice\.remote_batch_enabled/);
  assert.match(services, /FARAST_VOICE_REMOTE_BATCH_ENABLED/);
});


test('local-only voice defaults to the private Piper TTS sidecar', async () => {
  const services = await readFile(resolve(project, 'config/services.php'), 'utf8');
  const piper = await readFile(resolve(project, 'voice-stream/piper-sidecar/server.py'), 'utf8');
  const compose = await readFile(resolve(project, 'voice-stream/compose.yaml'), 'utf8');
  assert.match(services, /http:\/\/127\.0\.0\.1:7007\/v1\/synthesize/);
  assert.match(piper, /PiperVoice\.load/);
  assert.match(piper, /piper-mana-local/);
  assert.match(piper, /synthesize_wav/);
  assert.match(compose, /farast-piper-models/);
});


test('local Sherpa provider seeding is safe to rerun', async () => {
  const seeder = await readFile(resolve(project, 'database/seeders/SherpaOnnxVoiceProviderSeeder.php'), 'utf8');
  assert.match(seeder, /\$isNewAccount\s*=\s*!\s*\$account->exists/);
  assert.match(seeder, /if\s*\(\$isNewAccount\)\s*\$account->quota_used_seconds\s*=\s*0/);
});


test('local deployment includes a private Qwen gateway and waits for healthy voice services', async () => {
  const compose = await readFile(resolve(project, 'voice-stream/compose.yaml'), 'utf8');
  const setup = await readFile(resolve(project, 'voice-stream/setup-local.ps1'), 'utf8');
  const start = await readFile(resolve(project, 'voice-stream/start.cmd'), 'utf8');
  const localGateway = await readFile(resolve(project, 'voice-stream/local-ai-gateway/server.mjs'), 'utf8');
  assert.match(compose, /ollama\/ollama:0\.40\.13/);
  assert.match(compose, /qwen3:1\.7b/);
  assert.match(compose, /service_completed_successfully/);
  assert.match(compose, /farast-ollama-models:\/root\/\.ollama/);
  assert.match(compose, /\.env\.ai-runtime/);
  assert.match(compose, /\/health/);
  assert.match(setup, /FARAST_AI_GATEWAY_TOKEN/);
  assert.match(setup, /VOICE_STREAM_HEALTH_URL/);
  assert.match(setup, /\.env\.ai-runtime/);
  assert.match(start, /--profile local-ai up -d --build/);
  assert.match(start, /wait-ready\.ps1/);
  const waitReady = await readFile(resolve(project, 'voice-stream/wait-ready.ps1'), 'utf8');
  assert.match(waitReady, /6002\/health/);
  assert.match(waitReady, /7007\/health/);
  assert.match(waitReady, /7008\/ready/);
  assert.match(localGateway, /\/v1\/chat\/completions/);
  assert.match(localGateway, /authorization/i);
  assert.match(localGateway, /reasoning_effort:'none'/);
});
