import assert from 'node:assert/strict';
import { once } from 'node:events';
import { test } from 'node:test';
import { WebSocketServer } from 'ws';
import { createSherpaOnnxStream, pcm16ToFloat32Buffer } from '../providers/sherpa-onnx.mjs';

test('converts signed 16-bit little-endian PCM to float32 samples', () => {
  const pcm = Buffer.alloc(4);
  pcm.writeInt16LE(16384, 0);
  pcm.writeInt16LE(-16384, 2);
  const converted = pcm16ToFloat32Buffer(pcm);
  assert.equal(converted.readFloatLE(0), 0.5);
  assert.equal(converted.readFloatLE(4), -0.5);
});

test('uses a private local WebSocket and forwards streaming transcripts', async t => {
  const server = new WebSocketServer({ host: '127.0.0.1', port: 0 });
  t.after(() => new Promise(resolve => server.close(resolve)));
  await once(server, 'listening');
  const address = server.address();
  process.env.SHERPA_ONNX_WS_URL = `ws://127.0.0.1:${address.port}`;

  let receivedAudio;
  let receivedDone;
  server.on('connection', socket => {
    socket.on('message', (data, isBinary) => {
      if (isBinary) {
        receivedAudio = Buffer.from(data);
        socket.send(JSON.stringify({ text: 'سلام', segment: 0, is_final: false }));
        socket.send(JSON.stringify({ text: 'سلام فراست', segment: 0, is_final: true }));
      } else if (data.toString() === 'Done') {
        receivedDone = true;
      }
    });
  });

  const events = [];
  let markReady;
  const ready = new Promise(resolve => { markReady = resolve; });
  const stream = createSherpaOnnxStream({}, 'fa-IR', {
    ready: markReady,
    interim: text => events.push(['interim', text]),
    final: text => events.push(['final', text]),
    error: error => events.push(['error', error.message]),
  });

  await ready;
  const pcm = Buffer.alloc(4);
  pcm.writeInt16LE(16384, 0);
  pcm.writeInt16LE(-16384, 2);
  stream.write(pcm);

  await new Promise(resolve => setTimeout(resolve, 30));
  assert.ok(receivedAudio);
  assert.equal(receivedAudio.readFloatLE(0), 0.5);
  assert.equal(receivedAudio.readFloatLE(4), -0.5);
  assert.deepEqual(events, [['interim', 'سلام'], ['final', 'سلام فراست']]);

  stream.close();
  await new Promise(resolve => setTimeout(resolve, 20));
  assert.equal(receivedDone, true);
});

test('rejects non-private WebSocket endpoints', () => {
  const original = process.env.SHERPA_ONNX_WS_URL;
  process.env.SHERPA_ONNX_WS_URL = 'ws://example.com:6006';
  try {
    assert.throws(() => createSherpaOnnxStream({}, 'fa-IR'), /must_be_private/);
  } finally {
    if (original === undefined) delete process.env.SHERPA_ONNX_WS_URL;
    else process.env.SHERPA_ONNX_WS_URL = original;
  }
});
