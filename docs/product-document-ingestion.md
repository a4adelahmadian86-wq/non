# Product document ingestion and shared-host operation

## Data model

- `store_product_ingestions`: short-lived, authenticated quarantine sessions. A staged file expires after 30 minutes unless explicitly confirmed.
- `store_product_documents`: one processing profile per immutable product-file version, keyed by the source SHA-256.
- `store_product_document_chunks`: extracted text with source locators (PDF page, spreadsheet sheet/row, presentation slide/element, archive entry, or text line).
- `store_product_document_facts`: claims retained only when their evidence exactly matches a stored source chunk.
- `store_product_document_pages`: reader-ready, escaped HTML/text pages generated once during ingestion.
- `store_product_document_artifacts`: versioned private derivatives such as normalized reader PDFs, samples and synchronized audio.

Binary originals and derivatives stay on private storage disks. SQL stores metadata, text, hashes, locators and private storage paths; binary payloads do not belong in JSON or LONGTEXT columns. Large document text is split into bounded, source-located chunks rather than one unbounded field.

The schema uses ordinary indexed InnoDB-compatible relations and JSON/LONGTEXT columns. MySQL 8 or a supported MariaDB release is the intended shared-host database. SQLite remains suitable for local tests only.

## Upload and security boundary

1. The browser sends one selected file at a time from the multi-file queue to a private quarantine path. Files can be selected together or dropped anywhere on the product editor.
2. PHP validates the extension, detected MIME and file signature, computes SHA-256, inspects supported PDF/image/Office/archive structures, checks archive entry paths, links, compression ratios, expansion limits and active PDF actions, and returns a report before the user can confirm.
3. The confirmation endpoint re-runs the local checks and verifies the SHA-256 before moving the file into private product storage. A stale or altered staging file cannot be committed.
4. The static scanner is first-party and does not invoke ClamAV or another antivirus binary. It is a bounded structural/rule-based gate, not a full antivirus engine; it cannot promise detection of every unknown malware family. The file is never executed.
5. ZIP/TAR manifests are inspected without extracting files to disk. Nested archives are reported as nested and are not recursively unpacked during the lightweight preflight. The AI processing service later extracts only supported document members within its own strict byte/count limits.

## Queue on inexpensive shared hosting

The upload-confirm action writes a document row and dispatches `ProcessProductDocument` to the database-backed `documents` queue. After successful semantic study, `GenerateProductDocumentAudio` calls the dedicated `farast-document-audio` engine (separate from the existing voice assistant), creates one private WAV asset and stores per-chunk time offsets and source locators for reader synchronization.

No Redis or always-on queue daemon is required. Configure `QUEUE_CONNECTION=database`, migrate the standard `jobs` and `failed_jobs` tables, and register Laravel's scheduler every minute:

```sh
* * * * * cd /home/ACCOUNT/public_html && /usr/local/bin/php artisan schedule:run >> /dev/null 2>&1
```

The scheduler starts a bounded `queue:work --stop-when-empty` worker for `documents,documents-audio,default`. Queue `retry_after` is longer than the longest document/audio job timeout to prevent a slow job being reserved and executed twice. Adjust the PHP binary and project path for the host's cPanel configuration.

## Content study, samples and reader

- The model returns structured analysis, source-located facts and sample selectors only: PDF page numbers, PPTX slide numbers, XLSX sheet names, or the single page of an image. It never returns a generated sample file.
- Laravel validates the recommended page numbers against the source and creates PDF samples locally with the existing FPDI dependency. DOCX is normalized to PDF once by the existing PHPWord/Dompdf path before analysis and sample extraction.
- For XLSX, the selected sheets are rendered as source-located HTML/text pages. For a standalone image, the selected image is exposed through an entitlement-checked preview record beside its analyzed text. Other supported formats use stored source-located HTML/text sections. Reader preview records link to these database pages; the reader displays the saved representation rather than rebuilding it on each visit.
- The full HTML reader pages are created for `reader_only` products. PDF/DOCX use their private PDF representation. Access remains gated by the product's reader entitlement; a successful purchase does not create a direct-download URL when `download_policy=reader_only`.
- The document-only TTS job calls the dedicated Piper-based narration service with one chunk at a time, then stores one combined private WAV after semantic analysis. The existing voice assistant, streaming gateway and its models are not called or modified. Timing records map audio segments back to page/slide locators. The reader reuses the same audio asset while navigating document pages.
- Public assistant context is sample-only until a valid library entitlement exists. Paid document facts and chunks are hash-matched to the active source version and only evidence-backed facts are eligible for factual answers.

## Current processing limits

- Product upload preflight: 50 MiB per file; each staging session expires after 30 minutes.
- Product Supervisor: 25 MiB per source file and up to 120,000 extracted characters. Larger files remain stored with an explicit `awaiting_provider` state rather than being silently truncated and represented as complete.
- Archive inspection: bounded entry count, expanded bytes and compression ratio. Unsupported/encrypted archive structures are rejected; nested archives are disclosed and not executed or extracted in preflight.
- AI review is advisory. It never authorizes publication or overrides the server-side publication rules.

## Granular reader speech

The authenticated full-document reader supports speech at several selection levels:
- Clicking a word requests a short, exact WAV from the dedicated document-audio service.
- Clicking a sentence or paragraph in the structured HTML reader synthesizes that exact text.
- Selecting a phrase with the pointer synthesizes only the selected phrase.
- Clicking the page surface seeks within the pre-generated full-document WAV and stops at that page's timing boundary.
- The persistent player continues to read the complete document and synchronizes the active source segment.

Selection synthesis is restricted to the authenticated purchaser, the currently published reader-only product, and text that is verified as a substring of the selected extracted page. Requests are bounded to 2,000 characters, throttled, cached privately by source version and exact text, and never routed through the existing voice assistant.
