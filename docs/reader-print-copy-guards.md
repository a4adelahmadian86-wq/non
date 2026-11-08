# Reader print and copy safeguards

The reader applies best-effort browser restrictions to discourage casual extraction:

- Blocks browser print and save shortcuts (Ctrl/Cmd+P and Ctrl/Cmd+S), including when focus is in the search box.
- Blocks copy, cut, paste, text-selection start, drag-start and context-menu actions on the reader surface; editable reader controls remain usable.
- Replaces the reader with an online-only notice under print media.
- Excel preview HTML hides workbook content in print media and remains sandboxed in the reader iframe.
- PDF pages are rendered to canvas with the viewer watermark, and the text layer is non-selectable.

These are deterrents, not DRM. Browser extensions, developer tools, operating-system capture, screenshots, external cameras and modified clients can still capture visible content. The server-side access checks, private storage, upload-time sample isolation and viewer-specific watermarks remain the primary controls.
