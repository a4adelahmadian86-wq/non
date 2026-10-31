class FarastPcmProcessor extends AudioWorkletProcessor {
  constructor() {
    super();
    this.inputRate = sampleRate;
    this.outputRate = 16000;
    this.ratio = this.inputRate / this.outputRate;
    this.buffer = [];
    this.position = 0;
    this.targetSamples = 1600;
    this.output = [];
    this.vadThreshold = 0.006;
    this.vadHangoverFrames = 15;
    this.vadHangover = 0;
  }

  process(inputs, outputs) {
    const output = outputs[0]?.[0];
    if (output) output.fill(0);
    const input = inputs[0]?.[0];
    if (!input) return true;

    for (let i = 0; i < input.length; i++) this.buffer.push(input[i]);

    while (this.position + 1 < this.buffer.length) {
      const left = Math.floor(this.position);
      const fraction = this.position - left;
      this.output.push(this.buffer[left] * (1 - fraction) + this.buffer[left + 1] * fraction);
      this.position += this.ratio;

      if (this.output.length >= this.targetSamples) {
        const pcm = new Int16Array(this.output.length);
        let level = 0;
        for (let i = 0; i < this.output.length; i++) {
          const sample = Math.max(-1, Math.min(1, this.output[i]));
          level += Math.abs(sample);
          pcm[i] = sample < 0 ? sample * 32768 : sample * 32767;
        }
        level /= Math.max(1, this.output.length);

        // Client-side, model-free VAD: send speech plus a 1.5s tail so the
        // server recognizer can detect endpoints; suppress long silent periods.
        const sendFrame = level >= this.vadThreshold || this.vadHangover > 0;
        if (level >= this.vadThreshold) this.vadHangover = this.vadHangoverFrames;
        else if (this.vadHangover > 0) this.vadHangover -= 1;

        if (sendFrame) this.port.postMessage(pcm.buffer, [pcm.buffer]);
        this.output = [];
      }
    }

    const remove = Math.floor(this.position);
    if (remove > 0) {
      this.buffer.splice(0, remove);
      this.position -= remove;
    }

    return true;
  }
}

registerProcessor('farast-pcm-processor', FarastPcmProcessor);
