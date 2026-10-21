/* FARAST local Whisper worker: keeps model download and inference off the UI thread. */
const ESM_URLS = [
  'https://esm.sh/@huggingface/transformers@3.5.0',
  'https://cdn.jsdelivr.net/npm/@huggingface/transformers@3.5.0/+esm',
  'https://unpkg.com/@huggingface/transformers@3.5.0/dist/transformers.js?module'
];
let tf = null, transcriber = null, currentModel = '', loading = null;
let queue = Promise.resolve();

async function loadLibrary(id) {
  if (tf) return tf;
  let lastError = null;
  for (const url of ESM_URLS) {
    try {
      const module = await import(url);
      if (module && typeof module.pipeline === 'function') {
        tf = module;
        try { tf.env.useBrowserCache = true; tf.env.allowRemoteModels = true; } catch {}
        try { if (tf.env.backends && tf.env.backends.onnx && tf.env.backends.onnx.wasm) tf.env.backends.onnx.wasm.numThreads = 1; } catch {}
        return tf;
      }
    } catch (error) { lastError = error; }
  }
  throw new Error('کتابخانهٔ مدل محلی از CDN بارگذاری نشد: ' + String(lastError && lastError.message || '').slice(0, 100));
}

async function getPipeline(model, id) {
  if (transcriber && currentModel === model) return transcriber;
  if (loading) await loading;
  if (transcriber && currentModel === model) return transcriber;
  loading = (async () => {
    const library = await loadLibrary(id);
    // Do not keep two large speech models resident at once.
    transcriber = null;
    currentModel = '';
    const progress = info => {
      const percent = Number(info && info.progress);
      const message = Number.isFinite(percent) && percent > 0
        ? 'دانلود/بارگذاری مدل محلی: ' + Math.round(percent) + '%'
        : 'دانلود/بارگذاری مدل محلی…';
      self.postMessage({ id, type: 'progress', message });
    };
    try {
      transcriber = await library.pipeline('automatic-speech-recognition', model, {
        quantized: true, device: 'wasm', progress_callback: progress
      });
    } catch (firstError) {
      try {
        library.env.remoteHost = 'https://hf-mirror.com/';
        library.env.remotePathTemplate = '{model}/resolve/{revision}/';
      } catch {}
      try {
        transcriber = await library.pipeline('automatic-speech-recognition', model, {
          quantized: true, device: 'wasm', progress_callback: progress
        });
      } catch (secondError) {
        throw new Error('مدل Whisper قابل دانلود/اجرا نیست: ' + String(secondError && secondError.message || firstError && firstError.message || '').slice(0, 120));
      }
    }
    currentModel = model;
    return transcriber;
  })();
  try { return await loading; } finally { loading = null; }
}

async function handle(message) {
  const id = message && message.id;
  try {
    const model = String(message && message.model || 'Xenova/whisper-base');
    const pipeline = await getPipeline(model, id);
    if (message.type === 'load') {
      self.postMessage({ id, type: 'loaded', output: true });
      return;
    }
    if (message.type !== 'transcribe') throw new Error('Unknown local Whisper worker operation');
    const audio = message.audio instanceof Float32Array ? message.audio : new Float32Array(message.audio || []);
    if (!audio.length) { self.postMessage({ id, type: 'result', output: { text: '' } }); return; }
    const output = await pipeline(audio, message.options || { task: 'transcribe', language: 'farsi' });
    self.postMessage({ id, type: 'result', output });
  } catch (error) {
    self.postMessage({ id, type: 'error', message: String(error && error.message || error || 'Whisper worker failed') });
  }
}

self.onmessage = event => {
  const message = event.data;
  queue = queue.then(() => handle(message), () => handle(message));
};