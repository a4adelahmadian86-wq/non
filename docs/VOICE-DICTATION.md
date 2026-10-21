# Voice dictation: server-side AI only

FARAST must not download FARAST-managed AI models, model weights, tokenizers, or language data to a visitor's device.

- **Browser-native SpeechRecognition is disabled** for the FARAST voice workflow. Some browser implementations can install/download language packs for local processing; FARAST does not call these APIs or install packs.
- **FARAST cloud dictation** sends microphone audio to the authenticated Laravel endpoint, which proxies to Runflare's authenticated gateway. The gateway selects Cloudflare Workers AI Whisper, with a configured Render ASR fallback. The browser never loads Whisper/transformers.js or model weights.
- **Local OCR is disabled.** Tesseract.js and its language data must not be loaded in the browser. Image/handwriting OCR should be restored only through a server-side OCR endpoint. Until that endpoint is configured, the UI should show a clear unavailable message rather than download a model.
- **Custom STT endpoint** is an explicit user-configured server endpoint; it is not an automatic local-model fallback.
- The browser may use ordinary CPU/RAM for rendering, audio capture, encoding, and normal interface work. FARAST AI inference runs server-side.
- The visitor should not need a VPN. Upstream inference is called from the server side where technically and legally available. Free managed Colab is not used as a production inference server because its official terms allow termination of web-UI content-generation workloads.

## Verification checklist

1. Open an editable document in a secure context (HTTPS or localhost).
2. Sign in, open **تایپ صوتی**, and use the configured server-side engine.
3. Confirm microphone audio is sent only to the intended FARAST API endpoint and transcription is returned.
4. Confirm no Transformers.js, Whisper weights, Tesseract.js, or OCR language data are fetched by the browser.
5. Confirm server errors are shown clearly and do not silently switch to local model inference.
6. Verify saved text persists through the editor's canonical kernel/save path.
7. Verify OCR clearly reports that server-side OCR is not configured until that endpoint is implemented.


## Required deployment configuration

Set these variables in the Laravel server environment (never in browser JavaScript):

- `FARAST_AI_GATEWAY_URL`: the HTTPS base URL of the Runflare gateway, without `/v1/...`.
- `FARAST_AI_GATEWAY_TOKEN`: the same long random secret configured as `FARAST_GATEWAY_TOKEN` in the Runflare gateway service.

The Runflare gateway needs `CLOUDFLARE_ACCOUNT_ID` and `CLOUDFLARE_API_TOKEN` for Cloudflare Workers AI, plus `FARAST_LOCAL_LLM_URL` and `FARAST_LOCAL_LLM_API_KEY` if the local Qwen fallback is deployed. Cloudflare's free allocation is 10,000 Neurons/day shared across model calls; when exhausted, the gateway falls back to local Qwen or returns a service error if no fallback is configured.

After setting the variables, clear Laravel's cached configuration and verify the authenticated `/editor/voice/status`, `/assistant/voice/status`, `/assistant/transcribe`, `/assistant/speak`, and `/assistant/chat` routes against real deployed services. A green CI run only proves code/contracts; it does not prove that account credentials or upstream network access are working.
