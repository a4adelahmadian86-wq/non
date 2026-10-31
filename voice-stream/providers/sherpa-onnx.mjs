import WebSocket from 'ws';

const MAX_PENDING_CHUNKS = 80;
const MAX_BUFFERED_BYTES = 2 * 1024 * 1024;

export function pcm16ToFloat32Buffer(buffer) {
  const samples = Math.floor(buffer.length / 2);
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
  if (!(host === 'localhost' || privateIpv4 || privateIpv6)) {
    throw new Error('sherpa_onnx_ws_url_must_be_private');
  }

  return url.toString();
}

export function createSherpaOnnxStream(_config, locale, handlers = {}) {
  if (locale !== 'fa-IR') throw new Error('sherpa_onnx_locale_unsupported');

  const url = resolveLocalUrl();
  const socket = new WebSocket(url, { perMessageDeflate: false, maxPayload: 1024 * 1024 });
  let closed = false;
  let ready = false;
  let failed = false;
  const pending = [];

  const fail = error => {
    if (closed || failed) return;
    failed = true;
    handlers.error?.(error instanceof Error ? error : new Error(String(error || 'sherpa_onnx_error')));
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
    flush();
    handlers.ready?.();
  });

  socket.on('message', raw => {
    try {
      const message = JSON.parse(raw.toString());
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
    if (!closed && code !== 1000) {
      fail(new Error(`sherpa_onnx_ws_closed_${code}_${String(reason || '')}`));
    }
  });

  return {
    write(buffer) {
      if (closed || failed || !buffer?.length) return;
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
      if (closed) return;
      closed = true;
      pending.length = 0;
      if (socket.readyState === WebSocket.OPEN) {
        try {
          socket.send('Done');
        } catch {}
        const closeTimer = setTimeout(() => {
          try { socket.close(1000, 'normal'); } catch {}
        }, 300);
        closeTimer.unref?.();
      } else {
        try { socket.terminate(); } catch {}
      }
    },
  };
}
