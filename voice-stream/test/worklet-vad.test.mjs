import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';
import vm from 'node:vm';
import { test } from 'node:test';

const here = dirname(fileURLToPath(import.meta.url));
const project = resolve(here, '../..');

test('PCM worklet continuously sends silence and speech for server-side VAD pre-roll', async () => {
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
  assert.equal(sent.length, 2, 'silence must be sent so the server can maintain a pre-roll buffer');
  assert.equal(sent[0].length, 1600);
  assert.ok(sent[0].every(sample => sample === 0));

  runFrame(0.1);
  assert.equal(sent.length, 3, 'speech must be sent without client-side threshold clipping');
  assert.equal(sent[2].length, 1600);
  assert.ok(sent[2][100] > 3000);

  runFrame(0);
  assert.equal(sent.length, 4, 'silence after speech must reach server-side endpoint detection');
  assert.ok(output.every(sample => sample === 0), 'worklet output must remain silent to avoid feedback');
});
