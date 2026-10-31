# Sherpa-ONNX local Persian streaming sidecar

This sidecar runs the Persian Nemotron streaming model locally. It accepts binary float32 PCM samples at 16 kHz over WebSocket and returns JSON interim/final transcripts. The Node gateway converts its browser-facing signed 16-bit PCM frames to float32 before forwarding them.

## Model

Download the files from [Nimaone/nemotron-asr-streaming-farsi-onnx](https://huggingface.co/Nimaone/nemotron-asr-streaming-farsi-onnx):

- `encoder.int8.onnx`
- `decoder.int8.onnx`
- `joiner.int8.onnx`
- `tokens.txt`

Place them in one local directory (default: `./model`). The model is Persian-only (`fa-IR`) and requires `feature_dim=128`. The ONNX weights total roughly 667 MB. The model card reports indicative CPU results; actual memory, latency, and accuracy must be measured on the target machine.

## Run with Docker

From this directory:

```sh
docker build -t farast-sherpa-fa .
docker run --rm --name farast-sherpa-fa \
  --network host \
  -e SHERPA_HOST=127.0.0.1 \
  -e SHERPA_PORT=6006 \
  -e SHERPA_MODEL_DIR=/models \
  -e SHERPA_NUM_THREADS=4 \
  -v "$(pwd)/model:/models:ro" \
  farast-sherpa-fa
```

On systems where host networking is unavailable, put the gateway and sidecar on the same private Docker network, bind the sidecar to `0.0.0.0`, and set `SHERPA_ONNX_WS_URL` on the gateway to the sidecar's private service address. Never publish port 6006 to the public internet.

## Connect the gateway

Set `SHERPA_ONNX_WS_URL=ws://127.0.0.1:6006` in the Node voice gateway environment. In Laravel, enable the local Sherpa voice-provider account (the provider must be named `sherpa-onnx` and support locale `fa-IR`). Do not enable that account until the sidecar is running and reachable from the gateway.

The adapter deliberately refuses public WebSocket hosts and does not fail over from a failed local Sherpa session to a cloud provider. If local recognition is unavailable, the session reports an error rather than silently sending audio to a third party.

## Protocol

- Client sends binary float32 little-endian PCM, mono, 16 kHz.
- Sidecar returns JSON objects containing `text`, `segment`, and `is_final`.
- Client sends the text frame `Done` to finish the current stream.
