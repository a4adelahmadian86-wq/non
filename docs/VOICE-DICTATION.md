# Voice dictation: free-first behavior

FARAST uses a free-first voice workflow:

- **Start free** uses the browser's Web Speech API and inserts recognized final segments through the existing `/editor/voice/insert` endpoint and canonical editor kernel. It does not call FARAST's paid transcription provider or require a FARAST API key.
- FARAST does not download Whisper/transformers model weights to a visitor's device. The selectable Whisper model path has been removed; speech inference must use the server-side API.
- Browser-native speech recognition is not a guaranteed server route and may use browser-managed services or language packs. It must not be described as the primary FARAST engine; use the authenticated server-side engine for predictable routing.
- **Online** remains an explicit alternative using the configured server-side voice stream provider. **File transcription** continues to use the existing authenticated provider/quota flow and may incur provider or account charges.

## Verification checklist

1. Open an editable document in a secure context (HTTPS or localhost).
2. Put the caret at the intended insertion point, open **تایپ صوتی**, and select **شروع رایگان**.
3. Grant microphone permission and speak Persian or another listed language.
4. Confirm final segments enter the document and persist through the editor's canonical kernel/save path.
5. Stop dictation and reopen the document to confirm persistence.
6. Verify the engine selector does not offer browser-local Whisper and that no Transformers.js model download is initiated.
7. Verify server errors are shown clearly and do not silently switch to local model inference.

This feature does not make all FARAST AI or file-transcription operations free. It removes FARAST provider/API costs from the free browser-dictation path; browser support and processing location remain platform-dependent.
