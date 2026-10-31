# FARAST live voice dictation

Speech recognition for voice dictation runs on the FARAST server through the authenticated WebSocket gateway.

## Client/server boundary

- The browser asks for microphone permission and captures mono audio.
- Browser echo cancellation, noise suppression, automatic gain control, and the shipped AudioWorklet may perform lightweight signal preparation and PCM resampling.
- Audio frames are streamed to the server while the user speaks. Interim text is shown during speech; final segments are inserted through the existing `/editor/voice/insert` endpoint and canonical editor kernel.
- ASR model weights, ONNX Runtime, WASM inference packages, and speech language packs are stored and executed on the server only. The browser must not use Web Speech API recognition, install language packs, or download speech models.
- If the live server gateway is unavailable, voice dictation reports that it is unavailable. It must not silently switch to browser recognition or batch recording after the user expects live dictation.

## Verification checklist

1. Open an editable document in a secure context (HTTPS or localhost).
2. Put the caret at the intended insertion point and open **تایپ صوتی**.
3. Start the server live gateway and grant microphone permission.
4. Speak Persian continuously; confirm interim text appears before stopping.
5. Pause naturally; confirm a final segment is inserted once and is not duplicated.
6. Continue speaking without restarting the microphone; confirm subsequent segments are inserted in order.
7. Stop dictation and reopen the document to confirm persistence.
8. Inspect browser network/storage to verify that no speech model, ONNX/WASM model package, or language pack was downloaded or cached on the device.

This feature uses client CPU only for lightweight microphone capture and audio preparation. Speech inference remains on the server.