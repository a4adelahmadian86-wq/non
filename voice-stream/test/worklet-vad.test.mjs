import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';
import vm from 'node:vm';
import { test } from 'node:test';

const here = dirname(fileURLToPath(import.meta.url));
const project = resolve(here, '../..');

test('PCM worklet suppresses long silence and sends speech with a 1.5s endpoint tail', async () => {
  const source = await readFile(resolve(project, 'public/js/farast-voice-worklet.js'), 'utf8');
  const sent = [];
  let Processor;
  class FakeAudioWorkletProcessor {
    constructor() {
      this.port = { postMessage: buffer => sent.push(new Int16Array(buffer)) };
    }
  }
  const context = {
    AudioWorkletProcessor: FakeAudioWorkletProcessor,
    sampleRate: 48000,
    registerProcessor: (_name, processor) => { Processor = processor; },
  };
  vm.runInNewContext(source, context);
  assert.equal(typeof Processor, 'function');

  const processor = new Processor();
  const output = new Float32Array(128);
  const runFrame = value => processor.process(
    [[new Float32Array(4800).fill(value)]],
    [[output]],
  );

  runFrame(0);
  runFrame(0);
  assert.equal(sent.length, 0, 'idle silence should not be sent to the ASR server');

  runFrame(0.1);
  assert.equal(sent.length, 1, 'speech frame should be sent');
  assert.equal(sent[0].length, 1600);
  assert.ok(sent[0][100] > 3000);

  for (let i = 0; i < 15; i += 1) runFrame(0);
  assert.equal(sent.length, 16, 'send the configured 1.5 second silence hangover');
  runFrame(0);
  assert.equal(sent.length, 16, 'suppress silence after the endpoint tail');
  assert.ok(output.every(sample => sample === 0), 'worklet output must remain silent to avoid feedback');
});
