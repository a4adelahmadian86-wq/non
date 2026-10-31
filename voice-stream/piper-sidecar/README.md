# FARAST self-hosted Persian TTS

This sidecar synthesizes Persian speech locally on the server using Piper and the MIT-licensed Mana Persian voice. It does not call browser speech synthesis or an external TTS API.

## Runtime

- Python 3.11, Piper TTS, CPU inference
- Model: `MahtaFetrat/Mana-Persian-Piper`, pinned revision `d7ec7b55362a11c2b79907188bd6e71acc19d9b0`
- ONNX weights are downloaded by the server image build / CI only; never place them in the Laravel public directory or browser bundle.
- HTTP API: `POST /v1/synthesize` with JSON `{"text":"سلام"}`; returns WAV audio.
- Health: `GET /health`

## Run

```sh
docker build -t farast-piper-tts voice-stream/piper-sidecar
docker run --rm -p 7007:7007 farast-piper-tts
```

For a private service network, set Laravel `FARAST_VOICE_TTS_URL=http://farast-piper-tts:7007/v1/synthesize`. In local-only mode, the default TTS URL is empty so the app fails closed until this private endpoint is configured. Do not point it at a public/cloud speech provider.
