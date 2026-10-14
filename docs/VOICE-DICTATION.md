# Voice dictation: free-first behavior

FARAST uses a free-first voice workflow:

- **Start free** uses the browser's Web Speech API and inserts recognized final segments through the existing `/editor/voice/insert` endpoint and canonical editor kernel. It does not call FARAST's paid transcription provider or require a FARAST API key.
- Where the browser exposes on-device recognition (`processLocally`, `available()`, and `install()`), the free mode requests local processing and can install the browser's language pack. Once installed, a supported browser can recognize speech offline.
- The Web Speech API is not implemented consistently across browsers or languages. When on-device recognition is not exposed, the browser may use a remote speech service; the UI explicitly labels this possibility. FARAST cannot guarantee that browser fallback is offline.
- **Online** remains an explicit alternative using the configured server-side voice stream provider. **File transcription** continues to use the existing authenticated provider/quota flow and may incur provider or account charges.

## Verification checklist

1. Open an editable document in a secure context (HTTPS or localhost).
2. Put the caret at the intended insertion point, open **تایپ صوتی**, and select **شروع رایگان**.
3. Grant microphone permission and speak Persian or another listed language.
4. Confirm final segments enter the document and persist through the editor's canonical kernel/save path.
5. Stop dictation and reopen the document to confirm persistence.
6. In a browser supporting on-device recognition, verify the local language pack is available/installed and the UI says local recognition.
7. Verify unsupported browser/language errors do not silently switch the local-only path to a cloud provider.

This feature does not make all FARAST AI or file-transcription operations free. It removes FARAST provider/API costs from the free browser-dictation path; browser support and processing location remain platform-dependent.
