import WebSocket from 'ws';

const MAX_PENDING_CHUNKS = 80;
const MAX_BUFFERED_BYTES = 2 * 1024 * 1024;

export function pcm16ToFloat32Buffer(buffer) {
  if (!Buffer.isBuffer(buffer) || buffer.length % 2 !== 0) throw new Error('sherpa_onnx_invalid_pcm16_frame');
  const samples = buffer.length / 2;
  const output = Buffer.allocUnsafe(samples * 4);
  for (let i = 0; i < samples; i += 1) {
    const sample = buffer.readInt16LE(i * 2);
    output.writeFloatLE(sample / 32768, i * 4);
  }
  return output;
}

function resolveLocalUrl() {
  const value = String(process.env.SHERPA_ONNX_WS_URL || '').trim();
  if (!value) throw new Error('sherpa_onnx_ws_url_missing');

  let url;
  try {
    url = new URL(value);
  } catch {
    throw new Error('sherpa_onnx_ws_url_invalid');
  }

  if (!['ws:', 'wss:'].includes(url.protocol) || url.username || url.password) {
    throw new Error('sherpa_onnx_ws_url_invalid');
  }

  const host = url.hostname.toLowerCase().replace(/^\[|\]$/g, '');
  const ipv4 = host.match(/^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})$/)?.slice(1).map(Number);
  const privateIpv4 = ipv4 && ipv4.every(part => part >= 0 && part <= 255) && (
    ipv4[0] === 10 ||
    ipv4[0] === 127 ||
    (ipv4[0] === 172 && ipv4[1] >= 16 && ipv4[1] <= 31) ||
    (ipv4[0] === 192 && ipv4[1] === 168)
  );
  const privateIpv6 = host === '::1' || host.startsWith('fc') || host.startsWith('fd') || host.startsWith('fe80:');
  const allowedHosts = String(process.env.SHERPA_ONNX_ALLOWED_HOSTS || '').split(',').map(item => item.trim().toLowerCase()).filter(Boolean);
  if (!(host === 'localhost' || privateIpv4 || privateIpv6 || allowedHosts.includes(host))) {
    throw new Error('sherpa_onnx_ws_url_must_be_private');
  }

  return url.toString();
}

export function createSherpaOnnxStream(_config, locale, handlers = {}) {
  if (locale !== 'fa-IR') throw new Error('sherpa_onnx_locale_unsupported');

  const url = resolveLocalUrl();
  const socket = new WebSocket(url, { perMessageDeflate: false, maxPayload: 1024 * 1024 });
  let closed = false;
  let closing = false;
  let ready = false;
  let failed = false;
  let closePromise = null;
  let resolveClose = null;
  let closeTimer = null;
  const pending = [];
  let resolveReady;
  let rejectReady;
  const readyPromise = new Promise((resolve, reject) => { resolveReady = resolve; rejectReady = reject; });
  readyPromise.catch(() => {});

  function finishClose() {
    if (closed) return;
    closed = true;
    clearTimeout(closeTimer);
    try {
      if (socket.readyState === WebSocket.OPEN) socket.close(1000, 'normal');
      else if (socket.readyState === WebSocket.CONNECTING) socket.terminate();
    } catch {}
    resolveClose?.();
    resolveClose = null;
  }

  const fail = error => {
    if (closed || failed) return;
    if (closing) {
      finishClose();
      return;
    }
    failed = true;
    const failure = error instanceof Error ? error : new Error(String(error || 'sherpa_onnx_error'));
    if (!ready) {
      rejectReady(failure);
      return;
    }
    handlers.error?.(failure);
  };

  const flush = () => {
    if (!ready || closed || socket.readyState !== WebSocket.OPEN) return;
    while (pending.length && socket.readyState === WebSocket.OPEN) {
      const chunk = pending.shift();
      socket.send(chunk, error => {
        if (error) fail(error);
      });
    }
  };

  socket.on('open', () => {
    ready = true;
    resolveReady();
    flush();
    handlers.ready?.();
  });

  socket.on('message', raw => {
    const rawText = raw.toString();
    if (rawText.trim() === 'Done!') {
      handlers.end?.();
      if (closing) finishClose();
      return;
    }
    try {
      const message = JSON.parse(rawText);
      const text = String(message?.text || '').trim();
      if (!text) return;

      const final = message.is_final === true || message.is_eof === true;
      if (final) {
        handlers.final?.(text);
      } else {
        handlers.interim?.(text);
      }
    } catch (error) {
      fail(error);
    }
  });

  socket.on('error', fail);
  socket.on('close', (code, reason) => {
    if (closing) {
      finishClose();
      return;
    }
    if (!closed) {
      fail(new Error(`sherpa_onnx_ws_closed_${code}_${String(reason || '')}`));
    }
  });

  return {
    ready: readyPromise,
    write(buffer) {
      if (closed || closing || failed || !buffer?.length) return;
      const chunk = pcm16ToFloat32Buffer(buffer);
      if (!chunk.length) return;

      if (ready && socket.readyState === WebSocket.OPEN) {
        if (socket.bufferedAmount > MAX_BUFFERED_BYTES) {
          fail(new Error('sherpa_onnx_backpressure'));
          return;
        }
        socket.send(chunk, error => {
          if (error) fail(error);
        });
        return;
      }

      if (pending.length >= MAX_PENDING_CHUNKS) {
        fail(new Error('sherpa_onnx_startup_backpressure'));
        return;
      }
      pending.push(chunk);
    },
    close() {
      if (closePromise) return closePromise;
      if (closed) return Promise.resolve();
      closing = true;
      pending.length = 0;
      closePromise = new Promise(resolve => {
        resolveClose = resolve;
        closeTimer = setTimeout(finishClose, 5000);
        closeTimer.unref?.();
        if (socket.readyState === WebSocket.OPEN) {
          socket.send('Done', error => {
            if (error) finishClose();
          });
        } else {
          if (!ready) rejectReady(new Error('sherpa_onnx_closed_before_ready'));
          finishClose();
        }
      });
      return closePromise;
    },
  };
}
