# Voice dictation: server-side AI only

FARAST must not download FARAST-managed AI models, model weights, tokenizers, or language data to a visitor's device.

- **Browser-native SpeechRecognition** may be offered only as a browser-managed capability. Its processing location, network use, and language-pack behavior are controlled by the browser/vendor, not FARAST; it is not a guaranteed fallback and must not be described as a FARAST-hosted or private inference path.
- **FARAST cloud dictation** sends microphone audio to the authenticated FARAST server API. The server selects a configured speech provider/runtime. The browser does not load Whisper/transformers.js or model weights.
- **Local OCR is disabled.** Tesseract.js and its language data must not be loaded in the browser. Image/handwriting OCR should be restored only through a server-side OCR endpoint. Until that endpoint is configured, the UI should show a clear unavailable message rather than download a model.
- **Custom STT endpoint** is an explicit user-configured server endpoint; it is not an automatic local-model fallback.
- The browser may use ordinary CPU/RAM for rendering, audio capture, encoding, and normal interface work. FARAST AI inference runs server-side.
- The visitor should not need a VPN. Any restricted upstream access must be handled from the server side where legally and technically available; Colab is temporary and cannot be treated as an always-on production dependency.

## Verification checklist

1. Open an editable document in a secure context (HTTPS or localhost).
2. Sign in, open **تایپ صوتی**, and use the configured server-side engine.
3. Confirm microphone audio is sent only to the intended FARAST API endpoint and transcription is returned.
4. Confirm no Transformers.js, Whisper weights, Tesseract.js, or OCR language data are fetched by the browser.
5. Confirm server errors are shown clearly and do not silently switch to local model inference.
6. Verify saved text persists through the editor's canonical kernel/save path.
7. Verify OCR clearly reports that server-side OCR is not configured until that endpoint is implemented.
