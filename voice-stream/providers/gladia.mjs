import WebSocket from 'ws';

const API_URL = 'https://api.gladia.io/v2/live';
const MAX_PENDING_CHUNKS = 80;

const languageCode = locale => {
  if (locale === 'fa-IR') return 'fa';
  if (locale === 'ar-SA') return 'ar';
  return 'en';
};

export function createGladiaStream(config, locale, handlers) {
  const key = String(config.credentials?.api_key || config.credentials?.key || '');
  if (!key) throw new Error('gladia_credentials_missing');

  let socket = null;
  let closed = false;
  const pending = [];

  const flushPending = () => {
    if (!socket || socket.readyState !== WebSocket.OPEN) return;
    while (pending.length && socket.readyState === WebSocket.OPEN) {
      socket.send(pending.shift());
    }
  };

  const start = async () => {
    const response = await fetch(API_URL, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'x-gladia-key': key,
      },
      body: JSON.stringify({
        model: 'solaria-1',
        encoding: 'wav/pcm',
        sample_rate: 16000,
        bit_depth: 16,
        channels: 1,
        language_config: {
          languages: [languageCode(locale)],
          code_switching: true,
        },
        messages_config: {
          receive_partial_transcripts: true,
          receive_final_transcripts: true,
        },
      }),
    });

    if (!response.ok) {
      const body = await response.text().catch(() => '');
      throw new Error(`gladia_http_${response.status}_${body.slice(0,120)}`);
    }

    const data = await response.json();
    if (!data?.url) throw new Error('gladia_session_url_missing');

    socket = new WebSocket(data.url);
    socket.on('open', () => {
      flushPending();
      handlers.ready?.();
    });
    socket.on('message', raw => {
      try {
        const message = JSON.parse(raw.toString());
        if (message?.type !== 'transcript') return;
        const text = String(message?.data?.utterance?.text || '').trim();
        if (!text) return;
        const final = Boolean(message?.data?.is_final);
        if (final) handlers.final?.(text);
        else handlers.interim?.(text);
      } catch (e) {
        handlers.error?.(e);
      }
    });
    socket.on('error', error => handlers.error?.(error));
    socket.on('close', (code, reason) => {
      if (!closed && code !== 1000) handlers.error?.(new Error(`gladia_ws_${code}_${String(reason || '')}`));
    });
  };

  start().catch(error => handlers.error?.(error));

  return {
    write(buffer) {
      if (closed || !buffer?.length) return;
      if (socket?.readyState === WebSocket.OPEN) {
        socket.send(buffer);
        return;
      }
      if (pending.length >= MAX_PENDING_CHUNKS) pending.shift();
      pending.push(Buffer.from(buffer));
    },
    close() {
      closed = true;
      pending.length = 0;
      if (!socket) return;
      try {
        if (socket.readyState === WebSocket.OPEN) socket.send(JSON.stringify({type:'stop_recording'}));
      } catch {}
      try { socket.close(1000); } catch {}
      socket = null;
    },
  };
}
