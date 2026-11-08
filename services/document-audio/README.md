# Farast Document Audio

A standalone, document-only Persian narration engine. It is intentionally isolated from the existing voice assistant, voice streaming gateway, ASR, and assistant TTS services. No files in `voice-stream/` are required or imported.

## Voice profile

- Stable application voice ID: `farast-document-fa-male-37-v1`
- Engine: Piper TTS
- Model: `fa_IR-amir-medium` (single-speaker Persian male voice)
- The “approximately 37-year-old” quality is a product voice direction, not a measurable guarantee in the model metadata.
- Model files are downloaded from a pinned Hugging Face revision; the ONNX model SHA-256 is verified before use.

## API

- `GET /health`: public liveness only.
- `GET /v1/ready`: authenticated readiness and model-file presence.
- `POST /v1/synthesize`: authenticated WAV synthesis.

Request body:

```json
{
  "text": "متن فارسی برای خواندن",
  "language": "fa-IR",
  "voice_id": "farast-document-fa-male-37-v1"
}
```

The API accepts one bounded text chunk at a time. The pinned Piper model is patched during the build to expose phoneme sample counts. The service maps those counts to source words and carries the timings in a private RIFF metadata chunk; Laravel validates and persists them beside the single final WAV. No document text is sent to an alignment provider. Laravel's document-audio job submits source-located chunks in document order, combines the returned PCM/WAV segments into one private audio artifact, and stores offsets linked to page/chunk locators. The browser can therefore highlight the corresponding text line while the single audio track plays and continue across page navigation.

## Deployment

Create a separate Render Web Service with root directory `services/document-audio`. Build command:

```sh
pip install -r requirements.txt && python download_model.py
```

Start command:

```sh
uvicorn main:app --host 0.0.0.0 --port $PORT --workers 1 --limit-concurrency 2 --timeout-keep-alive 5
```

Set `DOCUMENT_AUDIO_API_TOKEN` to a long random secret. Set Laravel's `FARAST_DOCUMENT_AUDIO_URL` to this service's `/v1/synthesize` endpoint and `FARAST_DOCUMENT_AUDIO_TOKEN` to the same secret. Do not reuse the existing voice-assistant token.

This service has no database, queue, user accounts, document storage, or access to source files. It receives only the text chunk needed for synthesis. It does not call Gemini, Groq, Gladia, or the existing assistant gateway.
