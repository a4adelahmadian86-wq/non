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


## Dedicated Python inference service

A deployable FastAPI service has been added under [`voice-service/`](../tree/main/voice-service). It is separate from Laravel and exposes authenticated `/v1/transcribe` and `/v1/synthesize` endpoints. Follow `voice-service/README.md` for deployment and model setup.

**Always-on limitation:** Render Free sleeps after 15 minutes without inbound traffic and has no persistent disk. Do not use a free Render instance for production voice inference or try to defeat sleep with periodic pings. An always-on deployment needs a paid service instance and persistent storage; no live service is created automatically by these Git commits.


## Persian live streaming ASR (server-side Sherpa-ONNX)

The live microphone path uses the private Sherpa-ONNX sidecar under `voice-stream/sherpa-sidecar/`. The sidecar downloads the pinned Persian Nemotron ONNX files into its server-side persistent `/models` volume at container startup and verifies their SHA-256 hashes. The browser never downloads model weights, ONNX Runtime, model-related WASM, or a language pack.

Run the sidecar and the Node voice gateway on the same private network. Set `SHERPA_ONNX_WS_URL` to the sidecar's private WebSocket address; for a private Docker service name, also set `SHERPA_ONNX_ALLOWED_HOSTS` to that exact hostname. Do not publish the sidecar port publicly.

After verifying that the gateway can reach the sidecar, set `FARAST_VOICE_LOCAL_SPEECH_ENABLED=true` on the Laravel server and run the `SherpaOnnxVoiceProviderSeeder`. In this mode, provider routing is restricted to Sherpa and batch transcription is disabled. A sidecar failure returns a visible error; it does not fall back to a cloud provider, browser recognition, or client-side model.

The Persian streaming model supports `fa-IR` only. The browser performs microphone capture, echo cancellation/noise suppression when provided by the browser, PCM resampling/format conversion, and WebSocket transport. No speech model, model runtime, model WASM, or language pack is downloaded to the browser.

## Self-hosted Persian TTS

A local Piper sidecar is included at `voice-stream/piper-sidecar/`. It uses the MIT-licensed Mana Persian Piper voice (about 63.5 MB) and returns WAV audio from `POST /v1/synthesize`. Its Docker image downloads the pinned ONNX model during the server image build. It does not use Microsoft Edge TTS or another cloud synthesis provider.

Run it on the same private server network as Laravel, then configure:

```dotenv
FARAST_VOICE_TTS_URL=http://farast-piper-tts:7007/v1/synthesize
FARAST_VOICE_TTS_TOKEN=
FARAST_VOICE_TTS_VOICE_ID=farast-fa-male-v1
```

The TTS sidecar currently has no public authentication layer; keep it private and allow inbound traffic only from the Laravel application. Do not expose port 7007 to the public internet.

## Self-hosted LLM requirement

When `FARAST_VOICE_LOCAL_SPEECH_ENABLED=true`, the global assistant's streaming language model must be reached through `FARAST_AI_GATEWAY_URL` and `FARAST_AI_GATEWAY_TOKEN`, pointing to your own private inference gateway. The Groq route, legacy non-streaming AI route, and all automatic cloud fallbacks are disabled in this mode. If the private gateway is missing or fails, the assistant returns an explicit error instead of sending prompts to a cloud provider.

The local-only mode also defaults `FARAST_VOICE_TTS_URL` to empty; configure it explicitly to the private Piper sidecar above. Without that setting, speech playback fails closed rather than using the former hosted TTS endpoint.

The assistant is not ready for user testing until the Sherpa ASR, Piper TTS, and self-hosted LLM gateway are all running on the target server and their full Persian voice conversation has been validated there.
