# Synchronized prerecorded audio in the secure reader

The reader can play a prerecorded narration and follow its word timings. Audio is never synthesized in the browser while the reader is open.

## Upload from the product editor

1. Open Admin → Store → Edit product.
2. Add a PDF or DOCX preview (DOCX is converted to the reader's PDF representation).
3. In that preview's audio area, upload the narration file and a JSON timing file.
4. The audio is scanned and stored on the private disk. The reader streams it through an entitlement-checked endpoint; the original storage path is never exposed.
5. The JSON timings are saved with the preview and are available only in the reader page response.

Supported audio extensions: MP3, M4A, WAV, OGG and WebM. The maximum audio upload is 100 MiB.

## Timing JSON format

Use one entry per spoken word. Times are seconds from the beginning of the uploaded audio. `page` is the 1-based page number in the PDF displayed by the reader (for DOCX, the generated PDF page number).

```json
[
  { "text": "سلام", "start": 0.00, "end": 0.42, "page": 1 },
  { "text": "به", "start": 0.43, "end": 0.57, "page": 1 },
  { "text": "خوانشگر", "start": 0.58, "end": 1.35, "page": 1 },
  { "text": "خوش", "start": 1.40, "end": 1.65, "page": 1 },
  { "text": "آمدید", "start": 1.66, "end": 2.20, "page": 1 }
]
```

Entries must be ordered by start time; each end must be greater than its start. A segment may contain multiple words, but each word in that segment will share its time interval. For the best karaoke-style precision, provide one entry per word.

## Alignment quality

The reader uses the browser's audio clock and the supplied timestamps. It does not estimate word timing from total audio duration. Generate the timing file with a forced-alignment tool (for example, an aligner that supports Persian) using the exact final narration and the exact document text. Review names, numbers, punctuation, and page breaks manually. Text extraction from a PDF can differ from its visible reading order, especially in multi-column pages and complex tables.

## Behavior

- Play, pause, seek, speed and volume controls.
- Current word highlight, read-word styling and automatic scroll.
- Clicking a mapped word seeks narration to its start.
- Page changes follow the timing manifest.
- If a document has no audio track, the existing reader behaves exactly as before.

## Operational notes

- Audio and the PDF remain separate private assets; audio is not embedded in the source document.
- The audio endpoint supports HTTP byte ranges for seeking and checks the same sample/full entitlement as the preview.
- Word-level mapping is based on the PDF.js text layer. It works best when timing words match the extracted PDF text. If a word cannot be matched, playback continues without a false highlight.
