import assert from 'node:assert/strict';
import { setTimeout as delay } from 'node:timers/promises';
import { createSherpaOnnxStream } from '../providers/sherpa-onnx.mjs';

process.env.SHERPA_ONNX_WS_URL ||= 'ws://127.0.0.1:6006';

const errors = [];
const stream = createSherpaOnnxStream({}, 'fa-IR', {
  error: error => errors.push(error.message),
});

const timeout = new AbortController();
try {
  await Promise.race([
    stream.ready,
    delay(180_000, undefined, { signal: timeout.signal }).then(() => { throw new Error('sherpa_sidecar_start_timeout'); }),
  ]);
} finally {
  timeout.abort();
}

// Send 1.2 seconds of signed PCM silence. The adapter converts it to float32 and
// the actual Sherpa model must accept/decode it without a browser or cloud fallback.
const frame = Buffer.alloc(3200);
for (let i = 0; i < 12; i += 1) stream.write(frame);
await delay(1500);
await stream.close();

assert.deepEqual(errors, []);
process.stdout.write('Sherpa sidecar WebSocket/model smoke test passed.\\n');
