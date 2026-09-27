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
  }

  process(inputs) {
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
        for (let i = 0; i < this.output.length; i++) {
          const sample = Math.max(-1, Math.min(1, this.output[i]));
          pcm[i] = sample < 0 ? sample * 32768 : sample * 32767;
        }
        this.port.postMessage(pcm.buffer, [pcm.buffer]);
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
