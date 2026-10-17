# FARAST self-hosted voice service

This folder is a separate Python API for speech inference. It does not replace the Laravel application and does not call a third-party speech inference API. Laravel remains responsible for authentication, permissions, intent routing, document commands, transactions, history, and rendering.

## Important hosting reality

- Render Free is **not a permanent always-on host**. Free web services spin down after 15 minutes without inbound traffic, may restart at any time, and have no persistent disk. Do not use it as production voice hosting.
- This service loads local models into memory. The Render Free 512 MB / 0.1 CPU instance is not sufficient for this stack.
- For an always-on deployment, choose a paid Render instance with enough RAM (start by evaluating at least 4 GB; 8 GB is safer for CPU inference), attach a persistent disk mounted at `/models`, and monitor actual RAM/latency. Paid plans incur charges. Do not deploy a paid service unless you explicitly accept the price shown in your dashboard.
- The Laravel shared host can stay as-is. It only calls this service over HTTPS.
- No ping/cron trick is included to evade the free-tier sleep policy.

## API endpoints

- `GET /health`: basic readiness status, no secrets.
- `GET /v1/status`: authenticated readiness.
- `POST /v1/transcribe`: authenticated multipart form with `audio` and `locale`; returns `{"text":"..." }`.
- `POST /v1/synthesize`: authenticated JSON `{"text":"...","language":"fa-IR","voice_id":"farast-fa-male-v1"}`; returns WAV audio.

All API endpoints except `/health` require `Authorization: Bearer <FARAST_VOICE_TOKEN>`. Health intentionally discloses only readiness booleans. Put the same token in Laravel's `FARAST_VOICE_ASR_TOKEN` and `FARAST_VOICE_TTS_TOKEN`, and point both URLs at this service's `/v1/transcribe` and `/v1/synthesize` endpoints. Generate a long random token; never commit it.

## Deploy on Render

1. Connect GitHub at https://dashboard.render.com/.
2. Choose **New → Web Service**, then connect repository `a4adelahmadian86-wq/non`.
3. Set **Root Directory** to `voice-service`.
4. Choose **Docker** as runtime if the dashboard offers it for this repository.
5. Do not select Free for production. Review the current price and select a paid always-on instance only if you accept the charge. For a real model service, evaluate 4–8 GB RAM rather than the Free tier.
6. Add a persistent disk mounted at `/models`. Without persistent storage, model files may need to be downloaded again after redeploy/restart.
7. Add environment variable `FARAST_VOICE_TOKEN` (a unique random secret), plus the settings in `.env.example`.
8. Deploy, then check `https://YOUR-SERVICE.onrender.com/health`. It must show `asr_ready: true` and `tts_ready: true` before connecting Laravel. A 200 from `/health` alone does not mean the models are ready.

## Model installation (required)

### ASR

The first ASR initialization downloads the selected faster-whisper model from the model registry to the host cache. That is model-file retrieval, not a third-party inference API. The host must be able to reach the model registry during first boot, or the model cache must be pre-populated and persisted. Set `FARAST_ASR_MODEL=small` for a CPU-first starting point; measure Persian accuracy and latency before production.

### Fixed Persian TTS

The configured TTS checkpoint and config must be present at:
- `/models/persian-tts-male-vits/best_model.pth`
- `/models/persian-tts-male-vits/config.json`

Candidate single-speaker model: [Kamtera/persian-tts-male-vits](https://huggingface.co/Kamtera/persian-tts-male-vits) (OpenRAIL license). Before production, verify the repository's actual checkpoint filenames, model loading compatibility, license terms for your use, pronunciation quality, and commercial-use implications. Place the verified checkpoint/config on the persistent disk and set `FARAST_TTS_MODEL_PATH` / `FARAST_TTS_CONFIG_PATH` accordingly. This repository deliberately does not guess or silently switch to another voice if model loading fails.

The voice identifier `farast-fa-male-v1` is a configuration ID, **not proof that the model has been installed or tested**. Keep the same checkpoint, config, inference parameters, and voice ID pinned after validation.

## Local smoke test

With Docker and persistent model files available:

```sh
cd voice-service
cp .env.example .env
# Edit .env and set FARAST_VOICE_TOKEN to a long random secret.
docker build -t farast-voice .
docker run --rm --env-file .env -p 8000:8000 -v "$PWD/models:/models" farast-voice
curl -fsS http://127.0.0.1:8000/health
```

The TTS model must be placed under `voice-service/models/persian-tts-male-vits/` for this local example. Never commit model weights or secrets to Git.

## Connect Laravel

Set these server-side environment values in the Laravel hosting panel:

```dotenv
FARAST_VOICE_ASR_URL=https://YOUR-SERVICE.onrender.com/v1/transcribe
FARAST_VOICE_ASR_TOKEN=the-same-long-random-secret
FARAST_VOICE_TTS_URL=https://YOUR-SERVICE.onrender.com/v1/synthesize
FARAST_VOICE_TTS_TOKEN=the-same-long-random-secret
FARAST_VOICE_TTS_VOICE_ID=farast-fa-male-v1
```

After setting them, clear Laravel's config cache if available. Keep the service URL and token server-side; do not expose the token in JavaScript.

## Current readiness and limitations

This is a committed service implementation, not a claim of a successful live deployment. No Render service has been created from this repository, no model weights have been installed, and no live audio/browser test has run. The service reports model readiness separately and fails closed if either model is unavailable. Test with real Persian recordings and synthesized output before production.
