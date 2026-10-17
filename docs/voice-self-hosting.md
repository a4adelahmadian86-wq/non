# FARAST self-hosted voice deployment

## Deployment boundary

The main Laravel application can remain on shared PHP hosting. Speech inference cannot realistically be installed in a normal shared-host account: run ASR and TTS on a separate private Linux VPS or a trusted machine that the Laravel host can reach. This integration deliberately does not call Groq, Gemini, Google Speech, Azure Speech, OpenAI, or browser speech synthesis for the global assistant's speech input/output.

The assistant is fail-closed: without configured ASR/TTS endpoints, voice capture or playback reports that the engine is not configured instead of silently switching to a different provider or voice.

## Laravel environment

Set these values in the hosting control panel's server-side environment settings (never in JavaScript or a public file):

```dotenv
FARAST_VOICE_ASR_URL=https://YOUR-PRIVATE-VOICE-HOST/v1/transcribe
FARAST_VOICE_ASR_TOKEN=replace-with-a-long-random-secret
FARAST_VOICE_TTS_URL=https://YOUR-PRIVATE-VOICE-HOST/v1/synthesize
FARAST_VOICE_TTS_TOKEN=replace-with-a-long-random-secret
FARAST_VOICE_TTS_VOICE_ID=farast-fa-male-v1
```

Use HTTPS with a valid certificate. Restrict the inference service by firewall/IP allowlist where possible. The same secret may be used for both endpoints, but separate secrets are preferable. After changing environment variables, clear Laravel's config cache using the hosting panel/SSH if available.

## ASR endpoint contract

Laravel sends a multipart POST to `FARAST_VOICE_ASR_URL`:
- file field: `audio` (WebM/Opus, MP4, OGG, WAV, MP3 or M4A; maximum 15 MiB)
- form field: `locale` (currently `fa-IR`, `en-US` or `ar-SA`)
- header: `Authorization: Bearer <FARAST_VOICE_ASR_TOKEN>` when configured

Return HTTP 200 JSON such as `{"text":"متن تشخیص‌داده‌شده"}`. Return a non-2xx status on inference failure. The service must decode browser audio (typically via FFmpeg) and run a pinned multilingual Whisper/faster-whisper model; validate Persian accuracy against real recordings before production.

## TTS endpoint contract

Laravel sends JSON to `FARAST_VOICE_TTS_URL`:
```json
{"text":"پاسخ دستیار","language":"fa-IR","voice_id":"farast-fa-male-v1"}
```

Return HTTP 200 with raw audio bytes and a valid `Content-Type`, preferably `audio/wav` or `audio/mpeg`. Do not return JSON/base64. The TTS host must map `farast-fa-male-v1` to one pinned single-speaker Persian voice model and keep the model version, speaker, sample rate, and synthesis settings fixed. Do not rotate speakers or use browser TTS as a fallback. A Persian single-speaker VITS model can be evaluated for this role, but license, voice quality, pronunciation, latency, and commercial-use terms must be checked before adoption.

## Routes

- `GET /assistant/voice/status` — authenticated status; reports whether the endpoints are configured.
- `POST /assistant/transcribe` — authenticated multipart audio transcription.
- `POST /assistant/speak` — authenticated text-to-audio proxy. Laravel keeps endpoint tokens server-side and returns the audio stream to the browser.

## Important limitation

These Laravel changes define the integration boundary, not a running inference host. The shared host alone cannot supply local Whisper/TTS model inference. Do not mark the assistant production-ready until both private endpoints are deployed, reachable from the hosting server, and tested with Persian audio on the actual production domain. The existing chat/LLM path is still the site's existing Farast AI orchestration and is separate from this ASR/TTS change; full API-independent language generation also requires a separately hosted LLM runtime and corresponding adapter.
