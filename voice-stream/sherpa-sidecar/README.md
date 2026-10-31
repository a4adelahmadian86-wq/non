# Sherpa-ONNX local Persian streaming sidecar

This sidecar runs Persian streaming ASR on the server. It accepts binary float32 PCM samples at 16 kHz over a private WebSocket and returns JSON interim/final transcripts. The Node gateway converts the browser's signed 16-bit PCM frames to float32 before forwarding them.

## Model and server-side download

The server uses `mentisialog/sherpa-onnx-nemotron-asr-streaming-farsi-1120ms-int8` (OpenMDW 1.1). It supports Persian (`fa-IR`) and requires `feature_dim=128`. The four runtime files total about 667 MB. Their SHA-256 values are pinned in `download_model.py`; the startup downloader verifies each file and retains the upstream `LICENSE` and `NOTICE`.

**The model is downloaded only inside this server container into `/models`. Nothing from the ASR model, ONNX Runtime, a WASM model package, or a language pack is sent to the user's browser or device.** Keep `/models` on persistent server storage so container restarts do not download the weights again.

The model card's speed and accuracy figures are indicative only. Measure memory, latency, and Persian accuracy on the actual target machine before enabling it for users.

## Run with Docker

From this directory:

```sh
docker build -t farast-sherpa-fa .
docker volume create farast-sherpa-models
docker network create farast-voice-private
docker run -d --name farast-sherpa-fa \
  --network farast-voice-private \
  --restart unless-stopped \
  -e SHERPA_HOST=0.0.0.0 \
  -e SHERPA_PORT=6006 \
  -e SHERPA_MODEL_DIR=/models \
  -e SHERPA_NUM_THREADS=2 \
  -e SHERPA_MAX_CONCURRENT_SESSIONS=2 \
  -v farast-sherpa-models:/models \
  farast-sherpa-fa
```

The first server start downloads the model files to the persistent volume and checks their hashes. Later restarts reuse the verified files. If a file is missing or has a different hash, startup downloads it again or fails closed.

Do not publish port 6006 to the public internet. The sidecar must be reachable only from the Node voice gateway on a private network. If the gateway and sidecar use the Docker network above, set these server-side gateway variables:

```dotenv
SHERPA_ONNX_WS_URL=ws://farast-sherpa-fa:6006
SHERPA_ONNX_ALLOWED_HOSTS=farast-sherpa-fa
```

If both processes share the same host network namespace, `ws://127.0.0.1:6006` is also supported and does not require the hostname allowlist.

## Connect Laravel and the gateway

Set `FARAST_VOICE_LOCAL_SPEECH_ENABLED=true` in the Laravel server environment and run `php artisan db:seed --class=SherpaOnnxVoiceProviderSeeder` only after the sidecar and gateway are running and reachable. This creates the local `sherpa-onnx` provider for `fa-IR`. Local-only mode restricts provider routing to Sherpa and disables batch transcription; if the local engine fails, the request returns an error rather than switching to a cloud provider.

The local model is Persian-only. The editor/global assistant should request `fa-IR` when this local-only mode is enabled.

## Client/server boundary

- The browser only captures microphone audio, applies browser-provided echo cancellation/noise suppression, performs lightweight PCM conversion/resampling in the shipped AudioWorklet, and sends frames to the gateway.
- No speech recognition API, browser language pack, ASR model, ONNX Runtime, or model-related WASM is installed or downloaded on the user's device.
- Interim/final text is produced by the server-side Sherpa model. TTS and assistant responses also remain server-side; there is no browser speech-synthesis fallback.
- If the gateway or sidecar is unavailable, the UI reports that voice is unavailable. It does not switch to device recognition or batch recording.

## Protocol

- Gateway to sidecar: binary float32 little-endian PCM, mono, 16 kHz.
- Sidecar to gateway: JSON objects containing `text`, `segment`, and `is_final`.
- Gateway to sidecar: the text frame `Done` finishes the current stream.

## Operational limits

The model's memory use and CPU latency depend on the host and number of simultaneous sessions. The sidecar defaults to two inference threads and two concurrent sessions; adjust `SHERPA_NUM_THREADS` and `SHERPA_MAX_CONCURRENT_SESSIONS` after measuring the actual host. Do not deploy this model on a small free tier without verifying RAM and persistent disk capacity.